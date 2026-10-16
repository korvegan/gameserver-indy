import {api, onSessionExpired, requestId, setToken} from './api.js';
import {$, notice} from './dom.js';
import {can, replaceSelection, state} from './state.js';
import {bindForms, clearConfig, populateConfig} from './forms.js';
import {bindNavigation, navigate} from './navigation.js';
import {acceptJob, mergeJobs, renderJobs, watchJob} from './jobs.js';
import {controls, renderCatalog, renderEvents, renderStatus} from './views/server.js';
import {loadLogs, renderLogs} from './views/logs.js';
import {loadGames} from './views/catalog.js';
import {bindCommunity, isCommunityHash, loadCommunity} from './views/community.js';
import {renderUpdate} from './views/updates.js';
import {bindConnections, renderConnection} from './views/connection.js';
import {bindAccess, discardAccessDrafts, loadAccess} from './views/access.js';
import {bindBackups, loadBackups, resetBackups} from './views/backups.js';
import {bindWhatsApp, loadWhatsApp} from './views/whatsapp.js';
import {initWorkspace, markWorkspaceUnknown, refreshFleet, renderWorkspace} from './views/workspace.js';

let statusRequest = 0, configRequest = 0, controller;
const jobContexts = new Map(), requestContexts = new Map();
onSessionExpired(async () => {
  const session = await api('/api/session');
  if (JSON.stringify(session.principal) !== JSON.stringify(state.principal)
      || (state.apiVersion >= 2 && (session.grant_revision ?? null) !== state.grantRevision)) { location.reload(); throw new Error('Zugriff geändert'); }
  setToken(session.token);
  state.apiVersion = session.api_version || 1;
});
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
    state.configRevision = data.revision ?? null;
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
    if (can('config.read') && ['overview', 'logs'].includes(state.view)) tasks.push((async () => {
      const {events} = await api('/api/events', undefined, target, signal);
      if (current()) renderEvents(events);
    })());
    tasks.push(refreshFleet());
    if (state.view === 'logs' && can('logs.read')) tasks.push(loadLogs());
    if (state.view === 'settings') tasks.push(loadConfig());
    if (state.view === 'backups') tasks.push(loadBackups());
    const results = await Promise.allSettled(tasks);
    if (current()) for (const result of results) {
      if (result.status === 'rejected' && result.reason.name !== 'AbortError') notice(result.reason.message, true);
    }
  } catch (error) {
    if (!current() || error.name === 'AbortError') return;
    state.available = false;
    markWorkspaceUnknown();
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
  if (state.connectionDirty && !confirm('Ungespeicherte Beitrittsadresse verwerfen und das Serverprofil wechseln?')) {
    $('#server-select').value = state.selectedServer; return false;
  }
  if (state.dirty && !confirm('Ungespeicherte Spieleinstellungen verwerfen und das Serverprofil wechseln?')) {
    $('#server-select').value = state.selectedServer; return false;
  }
  replaceSelection(target); clearConfig(); $('#server-select').value = target;
  renderWorkspace();
  $('#server-name').textContent = state.catalog.servers.find(profile => profile.id === target)?.label || 'Serverprofil';
  $('#server-state').textContent = 'Status wird geprüft'; $('#status-updated').textContent = '';
  for (const id of ['slot-count', 'preset-label', 'query-port', 'process-id']) $(`#${id}`).textContent = '—';
  $('#log-source').value = 'server'; $('#log-updated').textContent = ''; $('#log-content').textContent = 'Logs werden geladen …';
  renderEvents([]); renderJobs(); controls();
  renderUpdate();
  renderConnection();
  resetBackups();
  if (openOverview) await go('overview');
  await refresh(); return true;
}

async function go(next, {keepNotice = false} = {}) {
  if (next === 'whatsapp' && state.principal?.id !== 'local-owner') return notice('WhatsApp wird am lokalen Besitzerzugang eingerichtet.', true);
  if (state.accessDirty && next !== 'access') {
    if (!confirm('Ungespeicherte Freigabe verwerfen und die Ansicht wechseln?')) return;
    discardAccessDrafts();
  }
  const permission = {settings: 'config.read', profiles: 'catalog.write', discord: 'discord.write', logs: 'jobs.read', access: 'access.manage', backups: 'backups.manage'}[next];
  if (permission && !can(permission)) return notice('Für diesen Bereich fehlt die Berechtigung.', true);
  if (!navigate(next)) return;
  if (!keepNotice) notice('');
  const heading = $(`#${next} h1`); heading?.setAttribute('tabindex', '-1'); heading?.focus({preventScroll: true});
  if (next === 'settings') await loadConfig();
  if (next === 'logs' && can('logs.read')) await loadLogs();
  if (next === 'games') await loadGames(target => selectServer(target, true));
  if (next === 'community') await loadCommunity();
  if (next === 'access') await loadAccess();
  if (next === 'backups') await loadBackups();
  if (next === 'whatsapp') await loadWhatsApp();
}

async function finishJob(job) {
  const context = jobContexts.get(job.id) || requestContexts.get(job.request_id); jobContexts.delete(job.id); requestContexts.delete(job.request_id);
  if (job.state === 'succeeded' && context?.mutation && context.target === state.selectedServer && context.selectionEpoch === state.selectionEpoch) {
    if (['/api/config', '/api/setup'].includes(context.mutation) && context.formRevision === state.formRevision) {
      state.dirty = false; state.rawDirty = false; state.config = null; state.schema = null; state.configRevision = null; $('#unsaved').hidden = true;
    }
    if (context.mutation === '/api/discord/send' && context.messageRevision === state.messageRevision) state.messageDirty = false;
  }
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

async function runOperation(operation, payload = {}) {
  if (state.submitting) return;
  const target = state.selectedServer;
  const context = {target, selectionEpoch: state.selectionEpoch, navigationEpoch: state.navigationEpoch};
  const id = state.apiVersion >= 2 ? requestId() : null;
  if (id) requestContexts.set(id, context);
  state.submitting = true; controls(); notice('Auftrag wird angelegt …');
  let job;
  try { job = (await api('/api/jobs', {...payload, operation, ...(id ? {request_id: id} : {})}, target)).job; }
  catch (error) {
    notice(error.message, true);
    if (error.code !== 'session_expired' && mayOpenFailureLogs(context) && ['start', 'restart'].includes(operation)) {
      state.logSource = 'launcher'; $('#log-source').value = 'launcher'; await go('logs', {keepNotice: true});
    }
  } finally { state.submitting = false; controls(); }
  if (job) { if (!id || requestContexts.has(id)) jobContexts.set(job.id, context); acceptJob(job, controls, finishJob); }
  else if (id) requestContexts.delete(id);
}

async function mutate(path, body = {}) {
  if (state.submitting) return;
  if (path === '/api/servers' && state.connectionDirty && !confirm('Ungespeicherte Beitrittsadresse verwerfen und zum neuen Serverprofil wechseln?')) return;
  if (path === '/api/servers' && state.dirty && !confirm('Ungespeicherte Spieleinstellungen verwerfen und zum neuen Serverprofil wechseln?')) return;
  const target = state.selectedServer, epoch = state.selectionEpoch;
  const connectionDraft = $('#join-public-host').value;
  const {discordRevision, messageRevision} = state;
  const queuedPath = state.apiVersion >= 2 && ['/api/config', '/api/setup', '/api/discord/send'].includes(path);
  const id = queuedPath ? requestId() : null;
  const context = {target, selectionEpoch: epoch, navigationEpoch: state.navigationEpoch, mutation: path, formRevision: state.formRevision, messageRevision};
  if (id) requestContexts.set(id, context);
  state.submitting = true; controls(); notice('Wird ausgeführt …');
  try {
    const result = await api(path, {...body, ...(id ? {request_id: id} : {})}, target);
    if (result.job) {
      if (requestContexts.has(id)) jobContexts.set(result.job.id, context);
      const current = acceptJob(result.job, controls, finishJob);
      if (['queued', 'running'].includes(current.state)) notice('Auftrag angenommen. Der Entwurf bleibt bis zum erfolgreichen Abschluss erhalten.');
      return;
    }
    if (path === '/api/servers') {
      if (epoch === state.selectionEpoch && (!state.connectionDirty || $('#join-public-host').value === connectionDraft)) {
        replaceSelection(result.server_id); clearConfig(); renderConnection();
      }
      state.profileDirty = false; await refreshCatalog();
    } else if ((path === '/api/config' || path === '/api/setup') && epoch === state.selectionEpoch) {
      state.dirty = false; state.rawDirty = false; state.config = null; state.schema = null;
    }
    if (path === '/api/discord/settings' && discordRevision === state.discordRevision) {
      state.discordDirty = false; $('#webhook').value = ''; $('#clear-webhook').checked = false;
    }
    if (path === '/api/discord/send' && messageRevision === state.messageRevision) state.messageDirty = false;
    await refresh(); notice(result.message + (result.warning ? ` Hinweis: ${result.warning}` : ''), Boolean(result.warning));
  } catch (error) { if (id) requestContexts.delete(id); notice(error.message, true); }
  finally { state.submitting = false; controls(); }
}

initWorkspace(selectServer, go);
bindNavigation(go); bindForms(mutate); bindConnections(refresh, job => acceptJob(job, controls, finishJob)); bindCommunity(); bindAccess(); bindBackups(runOperation);
bindWhatsApp();
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
$('#log-raw').addEventListener('change', renderLogs);
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
    state.apiVersion = session.api_version || 1; state.grantRevision = session.grant_revision ?? null;
    $('#identity-label').textContent = {owner: session.access_mode === 'private' ? 'Besitzer · privat' : 'Besitzer · lokal', family: 'Familienbediener', viewer: 'Lesezugriff'}[session.principal.role] || 'Zugriff eingeschränkt';
    if (session.access_mode === 'private') $('#location-label').textContent = 'Privater Familienzugang';
    await refreshCatalog(); await refresh();
    if (state.apiVersion >= 2) {
      const {connectJobStream} = await import('./job-stream.js');
      connectJobStream({
        snapshot: jobs => { mergeJobs(jobs); renderJobs(); for (const job of jobs) watchJob(job, controls, finishJob); },
        job: job => acceptJob(job, controls, finishJob), controls,
      });
    }
    if (isCommunityHash()) await go('community');
  } catch (error) { notice(error.message, true); }
  controls();
  setInterval(() => { if (!document.hidden && !state.submitting && !state.refreshing && !['games', 'community'].includes(state.view)) refresh(); }, 8000);
})();
