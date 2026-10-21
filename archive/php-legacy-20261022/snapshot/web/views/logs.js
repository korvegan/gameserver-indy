import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';
import {filteredGroups} from '../log-lines.js';

let request = 0, latest = '', context = '', paused = false;
const selection = () => `${state.selectionEpoch}:${state.selectedServer}:${state.logSource}`;
export function clearLogs() {
  ++request; latest = ''; context = '';
  $('#log-content').textContent = 'Logs werden geladen …'; $('#log-span').textContent = '';
  $('#log-file').textContent = 'Protokoll wird geladen …'; $('#log-updated').textContent = '';
  $('#export-logs').removeAttribute('href');
}
export function renderLogs(reset = false) {
  const target = $('#log-content'), top = target.scrollTop;
  const bottom = target.scrollHeight - target.clientHeight - top < 16;
  target.replaceChildren();
  if (context !== selection()) { target.textContent = 'Logs werden geladen …'; $('#log-span').textContent = ''; return; }
  const query = $('#log-search').value, level = $('#log-level').value;
  const groups = filteredGroups(latest, query, level), raw = $('#log-raw').checked;
  const stamps = latest.split('\n').map(line => line.match(/^\d{4}-\d\d-\d\dT\S+/)?.[0]).filter(Boolean);
  const filters = [query ? 'Suche aktiv' : '', level !== 'all' ? $('#log-level').selectedOptions[0].textContent : '', paused ? 'PAUSIERT' : ''].filter(Boolean);
  $('#log-span').textContent = `${groups.reduce((n, group) => n + group.lines.length, 0)} / ${latest.split('\n').length} Zeilen · ${filters.join(' · ') || 'keine Filter'} · ${stamps.length ? `${stamps[0]} – ${stamps.at(-1)}` : 'Zeitbereich im Log nicht datiert'}`;
  if (raw) target.textContent = groups.flatMap(group => group.lines).join('\n');
  else {
  for (const group of groups.slice(-200)) {
    if (!group.shader || group.lines.length === 1) { target.append(element('div', group.lines.join('\n'))); continue; }
    const details = element('details', undefined, 'shader-group');
    const span = group.first && group.last ? `${group.first} – ${group.last}` : 'ohne Zeitangabe';
    details.append(element('summary', `Shader-Hinweis · ${group.lines.length} Wiederholungen · ${span}`), element('pre', group.lines.join('\n')));
    target.append(details);
  }
  }
  if (!groups.length) target.textContent = 'Keine passenden Zeilen. Filter zurücksetzen zeigt den gesamten Ausschnitt.';
  target.scrollTop = reset ? 0 : bottom ? target.scrollHeight : top;
}
export function toggleLogsPause() {
  paused = !paused; ++request;
  $('#pause-logs').textContent = paused ? 'Fortsetzen' : 'Pause';
  $('#pause-logs').setAttribute('aria-pressed', String(paused));
  if (paused) $('#log-span').textContent += ' · PAUSIERT';
  else loadLogs({force:true});
}
export function resetLogFilters() {
  $('#log-search').value = ''; $('#log-level').value = 'all'; renderLogs(true);
}
export async function loadLogs({force = false} = {}) {
  if (paused && !force && context === selection()) return;
  const current = ++request;
  if (context !== selection()) {
    renderLogs(); $('#log-file').textContent = 'Protokoll wird geladen …'; $('#log-updated').textContent = '';
    $('#export-logs').removeAttribute('href');
  }
  const server = state.selectedServer, source = state.logSource, epoch = state.selectionEpoch;
  try {
    const data = await api(`/api/logs?source=${encodeURIComponent(source)}`, undefined, server);
    if (current !== request || epoch !== state.selectionEpoch || source !== state.logSource || state.view !== 'logs') return;
    $('#log-file').textContent = `${data.profile_label || server} · ${data.file || 'Serverkonsole'}`;
    $('#log-updated').textContent = [data.updated_at ? `Dateistand: ${new Date(data.updated_at).toLocaleString('de-DE')}` : '',
      data.process_started_at ? `Prozessstart: ${new Date(data.process_started_at).toLocaleString('de-DE')}` : 'Prozessstart unbekannt',
      'Logstart nicht automatisch bestätigt'].filter(Boolean).join(' · ');
    $('#export-logs').href = `/api/logs/export?server=${encodeURIComponent(server)}&source=${encodeURIComponent(source)}`;
    latest = data.text; context = selection(); renderLogs();
  } catch (error) {
    if (current === request && epoch === state.selectionEpoch && source === state.logSource && state.view === 'logs') notice(error.message, true);
  }
}
