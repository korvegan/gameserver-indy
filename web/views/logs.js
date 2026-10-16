import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';
import {logGroups} from '../log-lines.js';

let request = 0, latest = '', context = '';
const selection = () => `${state.selectionEpoch}:${state.selectedServer}:${state.logSource}`;
export function renderLogs() {
  const target = $('#log-content'); target.replaceChildren();
  if (context !== selection()) { target.textContent = 'Logs werden geladen …'; $('#log-span').textContent = ''; return; }
  const groups = logGroups(latest), raw = $('#log-raw').checked;
  $('#log-span').textContent = `${latest.split('\n').length} gelesene Zeilen · ${raw ? 'Einzelzeilen' : 'bis zu 200 Einträge, Shader zusammengefasst'}`;
  if (raw) { target.textContent = latest; return; }
  for (const group of groups.slice(-200)) {
    if (!group.shader || group.lines.length === 1) { target.append(element('div', group.lines.join('\n'))); continue; }
    const details = element('details', undefined, 'shader-group');
    const span = group.first && group.last ? `${group.first} – ${group.last}` : 'ohne Zeitangabe';
    details.append(element('summary', `Shader-Hinweis · ${group.lines.length} Wiederholungen · ${span}`), element('pre', group.lines.join('\n')));
    target.append(details);
  }
}
export async function loadLogs() {
  const current = ++request;
  if (context !== selection()) renderLogs();
  const server = state.selectedServer, source = state.logSource, epoch = state.selectionEpoch;
  try {
    const data = await api(`/api/logs?source=${encodeURIComponent(source)}`, undefined, server);
    if (current !== request || epoch !== state.selectionEpoch || source !== state.logSource || state.view !== 'logs') return;
    $('#log-file').textContent = data.file || 'Serverkonsole';
    $('#log-updated').textContent = data.updated_at ? `Dateistand: ${new Date(data.updated_at).toLocaleString('de-DE')}` : '';
    latest = data.text; context = selection(); renderLogs();
  } catch (error) {
    if (current === request && epoch === state.selectionEpoch && source === state.logSource && state.view === 'logs') notice(error.message, true);
  }
}
