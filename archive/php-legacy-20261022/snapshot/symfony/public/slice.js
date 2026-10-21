const $ = selector => document.querySelector(selector);
const jobs = new Map(), requestVersions = new Map();
let selected = $('#server-select').value, submitting = false;
let token = $('meta[name="manager-token"]').content;
const permissions = JSON.parse(document.body.dataset.permissions);
let revoked = false, events, reconnectTimer;
const phases = {queued: 'Wartet', running: 'Läuft', succeeded: 'Abgeschlossen', failed: 'Fehlgeschlagen', interrupted: 'Unterbrochen'};
const operations = {start: 'Starten', stop: 'Stoppen', restart: 'Neu starten'};
function notice(message) { $('#notice').textContent = message; }
function mergeJob(job) {
  if (!jobs.has(job.id) || Number(job.revision) > Number(jobs.get(job.id).revision)) jobs.set(job.id, job);
}
function controls() { for (const button of document.querySelectorAll('[data-operation]')) button.disabled = revoked || !selected || !permissions.includes('server.' + button.dataset.operation) || submitting; }
async function api(path, body) {
  const response = await fetch(path, {cache: 'no-store', ...(body ? {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Manager-Token': token}, body: JSON.stringify(body)} : {})});
  const data = await response.json();
  if (!response.ok) {
    if (data.code === 'session_expired') {
      const session = await (await fetch('/api/session', {cache: 'no-store'})).json();
      if (session.role !== document.body.dataset.role) location.reload();
      else token = session.token;
      throw new Error('Sitzung aktualisiert. Der Befehl wurde nicht ausgeführt; bitte erneut beauftragen.');
    }
    throw new Error(data.error || 'Anfrage fehlgeschlagen.');
  }
  return data;
}
async function refreshStatus(target = selected) {
  const revision = (requestVersions.get(target) || 0) + 1;
  requestVersions.set(target, revision);
  try {
    const {status} = await api('/api/status?server=' + encodeURIComponent(target));
    if (target !== selected || requestVersions.get(target) !== revision) return;
    $('#server-state').textContent = status.known === false ? 'Prozessstatus unbekannt' : status.running
      ? `Prozess läuft${status.ready ? (document.body.dataset.driver === 'managed' ? ' · Sitzung bereit' : ' · Testprogramm bereit') : ''} · PID ${status.pid}` : 'Gestoppt';
  } catch (error) { if (target === selected && requestVersions.get(target) === revision) $('#server-state').textContent = 'Status unbekannt · ' + error.message; }
}
function renderJobs() {
  const container = $('#jobs'); container.replaceChildren();
  for (const job of [...jobs.values()].filter(job => job.server_id === selected).sort((a, b) => b.sequence - a.sequence).slice(0, 10)) {
    const row = document.createElement('article'); row.dataset.jobId = job.id; row.dataset.state = job.state;
    const title = document.createElement('strong'); title.textContent = `${operations[job.operation]} · ${phases[job.state]}`;
    const text = document.createElement('p'); text.textContent = job.message;
    const identity = document.createElement('small'); identity.textContent = `Auftrag ${job.id.slice(0, 8)} · Reihenfolge ${job.sequence}`;
    row.append(title, text, identity); container.append(row);
  }
  if (!container.childElementCount) container.textContent = 'Noch keine Aufträge für dieses Testprofil.';
}
for (const button of document.querySelectorAll('[data-operation]')) button.addEventListener('click', async () => {
  if (submitting || revoked || !selected || !permissions.includes('server.' + button.dataset.operation)) return;
  const target = selected, operation = button.dataset.operation;
  submitting = true; controls(); notice('Auftrag wird angenommen …');
  try {
    const requestId = [...crypto.getRandomValues(new Uint8Array(16))].map(value => value.toString(16).padStart(2, '0')).join('');
    const {job} = await api('/api/jobs', {server_id: target, operation, request_id: requestId});
    mergeJob(job);
    renderJobs(); notice(`Auftrag ${job.id.slice(0, 8)} angenommen.`);
  } catch (error) { notice(error.message); }
  finally { submitting = false; controls(); }
});
$('#server-select').addEventListener('change', () => {
  selected = $('#server-select').value;
  $('#server-title').textContent = $('#server-select').selectedOptions[0].textContent;
  $('#server-state').textContent = 'Status wird geladen …';
  renderJobs(); refreshStatus();
});
$('#refresh').addEventListener('click', () => refreshStatus());
function revokeAccess() {
  clearTimeout(reconnectTimer); reconnectTimer = undefined;
  revoked = true; events?.close(); controls();
  $('#live-state').textContent = 'Freigabe geändert'; notice('Deine Freigabe wurde geändert. Bitte die Seite neu laden.');
}
function reconnect() {
  if (revoked || reconnectTimer !== undefined) return;
  reconnectTimer = setTimeout(() => { reconnectTimer = undefined; connectEvents(); }, 2000);
}
async function checkSession() {
  if (revoked) return;
  try {
    const response = await fetch('/api/session', {cache: 'no-store'});
    if (response.status === 403) revokeAccess();
    else if (response.ok && (await response.json()).token !== token) revokeAccess();
  } catch { /* A transport interruption alone does not revoke command rights. */ }
}
async function connectEvents() {
  if (revoked) return;
  try {
    // Snapshot and cursor share one DB snapshot; resume only newer events, without a gap.
    const {jobs: initial, cursor} = await api('/api/jobs');
    for (const job of initial) mergeJob(job);
    renderJobs(); refreshStatus();
    const stream = new EventSource('/api/job-events?after=' + cursor); events = stream;
    stream.addEventListener('open', () => { if (!revoked && events === stream) $('#live-state').textContent = 'Live verbunden'; });
    stream.addEventListener('error', async () => {
      if (revoked || events !== stream) return;
      // HTTP failures can permanently close EventSource. Own one bounded retry loop.
      stream.close();
      $('#live-state').textContent = 'Live-Verbindung wird erneuert …';
      await checkSession();
      if (!revoked && events === stream) reconnect();
    });
    stream.addEventListener('job', event => {
      if (revoked || events !== stream) return;
      const job = JSON.parse(event.data); mergeJob(job); renderJobs();
      if (['succeeded', 'failed', 'interrupted'].includes(job.state)) refreshStatus(job.server_id);
    });
    stream.addEventListener('access-revoked', revokeAccess);
  } catch (error) {
    notice(error.message); await checkSession();
    if (!revoked) { $('#live-state').textContent = 'Live-Verbindung wird erneuert …'; reconnect(); }
  }
}
connectEvents();
refreshStatus(); controls();
