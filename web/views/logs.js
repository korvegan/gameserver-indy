import {api} from '../api.js';
import {$, notice} from '../dom.js';
import {state} from '../state.js';

let request = 0;
export async function loadLogs() {
  const current = ++request;
  const server = state.selectedServer, source = state.logSource, epoch = state.selectionEpoch;
  try {
    const data = await api(`/api/logs?source=${encodeURIComponent(source)}`, undefined, server);
    if (current !== request || epoch !== state.selectionEpoch || source !== state.logSource || state.view !== 'logs') return;
    $('#log-file').textContent = data.file || 'Serverkonsole';
    $('#log-updated').textContent = data.updated_at ? `Dateistand: ${new Date(data.updated_at).toLocaleString('de-DE')}` : '';
    $('#log-content').textContent = data.text;
  } catch (error) {
    if (current === request && epoch === state.selectionEpoch && source === state.logSource && state.view === 'logs') notice(error.message, true);
  }
}
