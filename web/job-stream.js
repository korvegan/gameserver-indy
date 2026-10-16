import {api} from './api.js';
import {notice} from './dom.js';
import {state} from './state.js';

// Live transport never repeats a mutation. Snapshot/cursor and revisions close reconnect races.
export function connectJobStream({snapshot, job, controls}) {
  let stream, timer, stopped = false;
  const label = document.querySelector('#job-live-state');
  if (label) label.hidden = false;
  const say = text => { if (label) label.textContent = text; };
  function revoke() {
    stopped = true; clearTimeout(timer); stream?.close();
    if (state.principal) state.principal = {...state.principal, permissions: []};
    controls(); say('Freigabe geändert'); notice('Deine Freigabe wurde geändert. Bitte die Seite neu laden.', true);
  }
  async function identity() {
    try {
      const session = await api('/api/session');
      if (JSON.stringify(session.principal) !== JSON.stringify(state.principal)) revoke();
    } catch (error) { if (error.status === 403) revoke(); }
  }
  function retry() { if (!stopped && timer === undefined) timer = setTimeout(() => { timer = undefined; connect(); }, 2000); }
  async function connect() {
    if (stopped) return;
    try {
      await identity(); if (stopped) return;
      const initial = await api('/api/jobs'); if (stopped) return;
      snapshot(initial.jobs);
      const current = new EventSource('/api/job-events?after=' + initial.cursor); stream = current;
      current.addEventListener('open', () => { if (!stopped && stream === current) say('Live verbunden'); });
      current.addEventListener('job', event => { if (!stopped && stream === current) { try { job(JSON.parse(event.data)); } catch { say('Live-Ereignis nicht lesbar'); } } });
      current.addEventListener('access-revoked', revoke);
      current.addEventListener('error', async () => {
        if (stopped || stream !== current) return;
        current.close(); say('Live-Verbindung wird wiederhergestellt …'); await identity(); retry();
      });
    } catch (error) {
      if (error.status === 403) revoke();
      else { say('Live-Verbindung unterbrochen · Auftragsabfragen bleiben aktiv'); retry(); }
    }
  }
  window.addEventListener('beforeunload', () => { stopped = true; clearTimeout(timer); stream?.close(); });
  connect();
}
