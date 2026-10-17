import {api} from './api.js';
import {notice} from './dom.js';
import {state} from './state.js';

// Live transport never repeats a mutation. Snapshot/cursor and revisions close reconnect races.
export function connectJobStream({snapshot, job, controls, change}) {
  let stream, timer, stopped = false, paused = document.hidden, attempt = 0;
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
      if (JSON.stringify(session.principal) !== JSON.stringify(state.principal)
          || (session.grant_revision ?? null) !== state.grantRevision) revoke();
    } catch (error) { if (error.status === 403) revoke(); }
  }
  function retry(delay = 2000) { if (!stopped && !paused && timer === undefined) timer = setTimeout(() => { timer = undefined; connect(); }, delay); }
  async function connect() {
    if (stopped || paused) return;
    const generation = ++attempt;
    const currentAttempt = () => !stopped && !paused && generation === attempt;
    try {
      await identity(); if (!currentAttempt()) return;
      const initial = await api('/api/jobs'); if (!currentAttempt()) return;
      await snapshot(initial.jobs); if (!currentAttempt()) return;
      stream?.close();
      const current = new EventSource('/api/job-events?after=' + initial.cursor); stream = current;
      current.addEventListener('open', () => { if (!stopped && stream === current) say('Live verbunden'); });
      current.addEventListener('job', event => { if (!stopped && stream === current) { try { job(JSON.parse(event.data)); } catch { say('Live-Ereignis nicht lesbar'); } } });
      current.addEventListener('change', event => { if (!stopped && stream === current) { try { change(JSON.parse(event.data)); } catch { say('Änderung nicht lesbar'); } } });
      current.addEventListener('access-revoked', revoke);
      current.addEventListener('capacity', () => {
        if (stopped || stream !== current) return;
        current.close(); say('Liveplatz belegt · weitere Dashboard-Tabs schließen'); retry(15000);
      });
      current.addEventListener('error', async () => {
        if (stopped || stream !== current) return;
        current.close(); say('Live-Verbindung wird wiederhergestellt …'); await identity(); if (stream === current) retry();
      });
    } catch (error) {
      if (error.status === 403) revoke();
      else { say('Live-Verbindung unterbrochen · Abgleich nach Wiederverbindung'); retry(); }
    }
  }
  window.addEventListener('beforeunload', () => { stopped = true; clearTimeout(timer); stream?.close(); });
  document.addEventListener('visibilitychange', () => {
    paused = document.hidden;
    if (paused) { ++attempt; clearTimeout(timer); timer = undefined; stream?.close(); stream = undefined; say('Live pausiert'); }
    else if (!stopped) connect();
  });
  connect();
}
