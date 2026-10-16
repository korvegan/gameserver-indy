import {api} from './api.js';
import {$, element, notice} from './dom.js';
import {state} from './state.js';

const watching = new Set(), completed = new Set();
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
  if (!active(initial) || watching.has(initial.id) || completed.has(initial.id)) return;
  watching.add(initial.id);
  track(initial);
  update();
  (async () => {
    try {
      for (;;) {
        let job;
        try { job = canonical((await api(`/api/jobs?id=${encodeURIComponent(initial.id)}`)).job); }
        catch (error) {
          if (state.selectedServer === initial.server_id) notice(`Auftragsstatus nicht erreichbar: ${error.message}`, true);
          await new Promise(resolve => setTimeout(resolve, 2000));
          continue;
        }
        if (state.selectedServer === job.server_id) { mergeJobs([job]); renderJobs(); }
        if (!active(job)) {
          track(job);
          update();
          if (!completed.has(job.id)) { completed.add(job.id); await finish(job); }
          return;
        }
        track(job); update();
        await new Promise(resolve => setTimeout(resolve, document.hidden ? 2000 : 500));
      }
    } finally { watching.delete(initial.id); }
  })().catch(error => notice(error.message, true));
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
