import {$, element, notice} from './dom.js';
import {state} from './state.js';
import {renderWorkspaceJobs} from './views/workspace.js';

const completed = new Set();
const outstanding = new Map(), versions = new Map();
const active = job => job.state === 'queued' || job.state === 'running';
const names = {queued: 'Wartet', running: 'Läuft', succeeded: 'Abgeschlossen', failed: 'Fehlgeschlagen', interrupted: 'Unterbrochen'};
const operations = {start: 'Starten', stop: 'Stoppen', restart: 'Neu starten', setup: 'Vorbereiten', config: 'Einstellungen speichern', check_updates: 'Steam-Update prüfen'};
Object.assign(operations, {backup: 'Welt sichern', backup_verify: 'Sicherung prüfen', backup_pin: 'Sicherung anheften / lösen', backup_settings: 'Sicherungsautomatik speichern', backup_restore: 'Welt wiederherstellen', backup_recover: 'Vorherigen Stand zurückholen'});
const reservation = job => ({check_updates: 'updates', refresh_connections: 'connections', discord_send: 'discord'}[job.operation]
  ? `${{check_updates: 'updates', refresh_connections: 'connections', discord_send: 'discord'}[job.operation]}:${job.server_id}` : job.server_id);
function canonical(job) {
  const old = versions.get(job.id);
  const newer = !old || (job.revision && old.revision ? Number(job.revision) >= Number(old.revision) : job.updated_at >= old.updated_at);
  if (newer) versions.set(job.id, job);
  return newer ? job : old;
}

function track(job) {
  if (active(job)) outstanding.set(job.id, job); else outstanding.delete(job.id);
  const key = reservation(job);
  const pending = [...outstanding.values()].filter(item => reservation(item) === key)
    .sort((a, b) => (a.state === 'running' ? -1 : 0) - (b.state === 'running' ? -1 : 0) || a.created_at.localeCompare(b.created_at));
  if (pending.length) state.pending.set(key, pending[0]); else state.pending.delete(key);
}

export function mergeJobs(jobs) {
  const known = new Map(state.latestJobs.map(job => [job.id, job]));
  for (const job of jobs) {
    const current = canonical(job);
    if (current.server_id === state.selectedServer) known.set(current.id, current);
  }
  state.latestJobs = [...known.values()].filter(job => job.server_id === state.selectedServer).sort((a, b) => (b.sequence && a.sequence ? b.sequence-a.sequence : b.created_at.localeCompare(a.created_at))).slice(0, 20);
}

export function renderJobs() {
  renderWorkspaceJobs();
  const container = $('#job-list');
  container.replaceChildren();
  if (!state.latestJobs.length) container.append(element('p', 'Noch keine Aufträge für dieses Profil.', 'empty'));
  for (const job of state.latestJobs.slice(0, 5)) {
    const row = element('div', undefined, 'job-row'); row.dataset.jobId = job.id; row.dataset.state = job.state;
    const title = element('strong', `${operations[job.operation] || job.operation} · ${names[job.state]}`);
    const detail = element('span', `${job.message}${job.state === 'queued' && job.queue_position ? ` Warteschlange: Position ${job.queue_position}.` : ''}`, 'muted');
    const identity = element('small', `Auftrag ${job.id.slice(0, 8)} · ${new Date(job.updated_at).toLocaleString('de-DE')}`, 'muted');
    row.append(title, detail, identity); container.append(row);
  }
}

export function watchJob(initial, update, finish) {
  initial = canonical(initial);
  if (!active(initial) || completed.has(initial.id)) return;
  track(initial);
  update();
}

export async function syncJobs(jobs, update, finish) {
  // A long-hidden tab may still track a command older than the recent history window.
  // Resolve it once on reconnect, never on a repeating timer.
  const visible = new Set(jobs.map(job => job.id));
  const missing = [...outstanding.keys()].filter(id => !visible.has(id));
  const recovered = await Promise.all(missing.map(async id => (await api('/api/jobs?id='+encodeURIComponent(id))).job));
  jobs = [...jobs, ...recovered];
  for (const job of jobs) {
    if (active(job) || outstanding.has(job.id)) acceptJob(job, update, finish);
    else canonical(job);
  }
  mergeJobs(jobs); renderJobs();
}

export function acceptJob(job, update, finish) {
  job = canonical(job);
  if (state.selectedServer === job.server_id) { mergeJobs([job]); renderJobs(); }
  if (active(job)) watchJob(job, update, finish);
  else {
    track(job); update();
    if (!completed.has(job.id)) { completed.add(job.id); finish(job).catch(error => notice(error.message, true)); }
  }
  return job;
}
import {api} from './api.js';
