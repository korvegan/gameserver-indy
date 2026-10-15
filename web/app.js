import {api, setToken} from './api.js';
import {$, notice} from './dom.js';
import {can, replaceSelection, state} from './state.js';
import {bindForms, clearConfig, populateConfig} from './forms.js';
import {bindNavigation, navigate} from './navigation.js';
import {acceptJob, mergeJobs, renderJobs, watchJob} from './jobs.js';
import {controls, renderCatalog, renderEvents, renderStatus} from './views/server.js';
import {loadLogs} from './views/logs.js';
import {loadGames} from './views/catalog.js';
import {bindCommunity, isCommunityHash, loadCommunity} from './views/community.js';
import {renderUpdate} from './views/updates.js';
import {bindConnections, renderConnection} from './views/connection.js';
import {bindAccess, loadAccess} from './views/access.js';

let statusRequest = 0, configRequest = 0, controller;
const jobContexts = new Map();
const mayOpenFailureLogs = context => context && context.target === state.selectedServer
  && context.selectionEpoch === state.selectionEpoch && context.navigationEpoch === state.navigationEpoch
  && ['overview', 'logs'].includes(state.view) && can('logs.read');

async function loadConfig() {
  if (!can('config.read') || state.dirty || state.view !== 'settings') return;
  const generation = ++configRequest, epoch = state.selectionEpoch, target = state.selectedServer;
  state.configLoading = true; controls();
  try {
    const data = await api('/api/config', undefined, target);
    if (generation !== configRequest || epoch !== state.selectionEpoch || state.dirty || state.view !== 'settings') return;
    if (JSON.stringify(state.config) !== JSON.stringify(data.config) || !state.schema) populateConfig(data.config, data.game_profile);
  } catch (error) {
    if (generation === configRequest && epoch === state.selectionEpoch) notice(error.message, true);
  } finally {
    if (generation === configRequest && epoch === state.selectionEpoch) { state.configLoading = false; controls(); }
  }
}

async function refreshCatalog() {
  const catalog = await api('/api/servers');
  if (!catalog.servers.some(profile => profile.id === state.selectedServer)) replaceSelection(catalog.servers[0]?.id || '');
  state.catalogAvailable = true; renderCatalog(catalog); controls();
}

async function refresh() {
  if (!state.selectedServer) return;
  const generation = ++statusRequest, epoch = state.selectionEpoch, target = state.selectedServer;
  controller?.abort(); controller = new AbortController();
  const signal = controller.signal;
  state.refreshing = true; controls();
  const current = () => generation === statusRequest && epoch === state.selectionEpoch;
  try {
    const data = await api('/api/status', undefined, target, signal);
    if (!current()) return;
    state.available = true; renderStatus(data);
    $('#status-updated').textContent = `Zuletzt geladen: ${new Date().toLocaleTimeString('de-DE')}`;
    if (data.config_error) notice(data.config_error, true);
    const tasks = [(async () => {
      const {jobs} = await api('/api/jobs', undefined, target, signal);
      if (!current()) return;
      mergeJobs(jobs); renderJobs();
      for (const job of jobs) watchJob(job, controls, finishJob);
    })()];
    if (can('config.read') && state.view === 'overview') tasks.push((async () => {
      const {events} = await api('/api/events', undefined, target, signal);
      if (current()) renderEvents(events);
    })());
    if (state.view === 'logs' && can('logs.read')) tasks.push(loadLogs());
    if (state.view === 'settings') tasks.push(loadConfig());
    const results = await Promise.allSettled(tasks);
    if (current()) for (const result of results) {
      if (result.status === 'rejected' && result.reason.name !== 'AbortError') notice(result.reason.message, true);
    }
  } catch (error) {
    if (!current() || error.name === 'AbortError') return;
    state.available = false;
    renderConnection();
    $('#server-state').textContent = 'Status nicht erreichbar'; $('#server-state').className = 'pill error';
    $('#status-updated').textContent = 'Stand veraltet · Aktualisierung fehlgeschlagen';
    $('#process-note').textContent = 'Windows-/WSL-Verbindung prüfen.';
    notice(error.message, true);
  } finally {
    if (current()) { state.refreshing = false; controls(); }
  }
}

async function selectServer(target, openOverview = false) {
  if (state.dirty && !confirm('Ungespeicherte Spieleinstellungen verwerfen und das Serverprofil wechseln?')) {
    $('#server-select').value = state.selectedServer; return false;
  }
  replaceSelection(target); clearConfig(); $('#server-select').value = target;
  $('#server-name').textContent = state.catalog.servers.find(profile => profile.id === target)?.label || 'Serverprofil';
  $('#server-state').textContent = 'Status wird geprüft'; $('#status-updated').textContent = '';
  for (const id of ['slot-count', 'preset-label', 'query-port', 'process-id']) $(`#${id}`).textContent = '—';
  $('#log-source').value = 'server'; $('#log-updated').textContent = ''; $('#log-content').textContent = 'Logs werden geladen …';
  renderEvents([]); renderJobs(); controls();
  renderUpdate();
  renderConnection();
  if (openOverview) await go('overview');
  await refresh(); return true;
}

async function go(next, {keepNotice = false} = {}) {
  if (state.accessDirty && next !== 'access') {
    if (!confirm('Ungespeicherte Freigabe verwerfen und die Ansicht wechseln?')) return;
    state.accessDirty = false;
  }
  const permission = {settings: 'config.read', profiles: 'catalog.write', discord: 'discord.write', logs: 'logs.read', access: 'access.manage'}[next];
  if (permission && !can(permission)) return notice('Für diesen Bereich fehlt die Berechtigung.', true);
  if (!navigate(next)) return;
  if (!keepNotice) notice('');
  const heading = $(`#${next} h1`); heading?.setAttribute('tabindex', '-1'); heading?.focus({preventScroll: true});
  if (next === 'settings') await loadConfig();
  if (next === 'logs') await loadLogs();
  if (next === 'games') await loadGames(target => selectServer(target, true));
  if (next === 'community') await loadCommunity();
  if (next === 'access') await loadAccess();
}

async function finishJob(job) {
  const context = jobContexts.get(job.id); jobContexts.delete(job.id);
  const label = state.catalog.servers.find(profile => profile.id === job.server_id)?.label || job.server_id;
  if (state.selectedServer === job.server_id && job.result?.update) renderUpdate(job.result.update);
  if (state.selectedServer === job.server_id) await refresh();
  const selected = state.selectedServer === job.server_id;
  const warning = job.result?.warning;
  notice(`${selected ? '' : `${label}: `}${job.message}${warning ? ` Hinweis: ${warning}` : ''}`, job.state !== 'succeeded' || Boolean(warning));
  if (mayOpenFailureLogs(context) && job.state !== 'succeeded' && ['start', 'restart'].includes(job.operation)) {
    state.logSource = 'launcher'; $('#log-source').value = 'launcher'; await go('logs', {keepNotice: true});
  }
}

async function runOperation(operation) {
  const target = state.selectedServer;
  const context = {target, selectionEpoch: state.selectionEpoch, navigationEpoch: state.navigationEpoch};
  if (state.submitting || state.pending.has(target)) return;
  state.submitting = true; controls(); notice('Auftrag wird angelegt …');
  let job;
  try { job = (await api('/api/jobs', {operation}, target)).job; }
  catch (error) {
    notice(error.message, true);
    if (mayOpenFailureLogs(context) && ['start', 'restart'].includes(operation)) {
      state.logSource = 'launcher'; $('#log-source').value = 'launcher'; await go('logs', {keepNotice: true});
    }
  } finally { state.submitting = false; controls(); }
  if (job) { jobContexts.set(job.id, context); acceptJob(job, controls, finishJob); }
}

async function mutate(path, body = {}) {
  if (state.submitting) return;
  if (path === '/api/servers' && state.dirty && !confirm('Ungespeicherte Spieleinstellungen verwerfen und zum neuen Serverprofil wechseln?')) return;
  const target = state.selectedServer, epoch = state.selectionEpoch;
  const {discordRevision, messageRevision} = state;
  state.submitting = true; controls(); notice('Wird ausgeführt …');
  try {
    const result = await api(path, body, target);
    if (path === '/api/servers') {
      replaceSelection(result.server_id); clearConfig(); state.profileDirty = false; await refreshCatalog();
    } else if ((path === '/api/config' || path === '/api/setup') && epoch === state.selectionEpoch) {
      state.dirty = false; state.rawDirty = false; state.config = null; state.schema = null;
    }
    if (path === '/api/discord/settings' && discordRevision === state.discordRevision) {
      state.discordDirty = false; $('#webhook').value = ''; $('#clear-webhook').checked = false;
    }
    if (path === '/api/discord/send' && messageRevision === state.messageRevision) state.messageDirty = false;
    await refresh(); notice(result.message + (result.warning ? ` Hinweis: ${result.warning}` : ''), Boolean(result.warning));
  } catch (error) { notice(error.message, true); }
  finally { state.submitting = false; controls(); }
}

bindNavigation(go); bindForms(mutate); bindConnections(); bindCommunity(); bindAccess();
$('#check-updates').addEventListener('click', async () => {
  const target = state.selectedServer;
  if (state.submitting || state.pending.has(`updates:${target}`)) return;
  state.submitting = true; controls();
  try {
    const {job} = await api('/api/updates/check', {}, target);
    acceptJob(job, controls, finishJob);
  } catch (error) { notice(error.message, true); }
  finally { state.submitting = false; controls(); }
});
$('#server-select').addEventListener('change', () => selectServer($('#server-select').value));
$('#refresh').addEventListener('click', refresh);
$('#refresh-logs').addEventListener('click', loadLogs);
$('#log-source').addEventListener('change', () => { state.logSource = $('#log-source').value; loadLogs(); });
$('#setup').addEventListener('click', () => mutate('/api/setup'));
$('#start').addEventListener('click', () => runOperation('start'));
for (const operation of ['stop', 'restart']) $(`#${operation}`).addEventListener('click', () => {
  if (confirm(operation === 'stop' ? 'Server stoppen? Verbundene Spieler werden getrennt.' : 'Server neu starten? Verbundene Spieler werden getrennt.')) runOperation(operation);
});
$('#refresh-community').addEventListener('click', loadCommunity);
window.addEventListener('hashchange', () => { if (isCommunityHash()) go('community'); });
document.addEventListener('visibilitychange', () => { if (!document.hidden && !['games', 'community'].includes(state.view)) refresh(); });

(async () => {
  try {
    const session = await api('/api/session'); setToken(session.token); state.principal = session.principal;
    $('#identity-label').textContent = {owner: session.access_mode === 'private' ? 'Besitzer · privat' : 'Besitzer · lokal', family: 'Familienbediener', viewer: 'Lesezugriff'}[session.principal.role] || 'Zugriff eingeschränkt';
    if (session.access_mode === 'private') $('#location-label').textContent = 'Privater Familienzugang';
    await refreshCatalog(); await refresh();
    if (isCommunityHash()) await go('community');
  } catch (error) { notice(error.message, true); }
  controls();
  setInterval(() => { if (!document.hidden && !state.submitting && !state.refreshing && !['games', 'community'].includes(state.view)) refresh(); }, 8000);
})();
