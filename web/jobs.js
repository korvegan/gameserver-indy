import {api} from './api.js';
import {$, element, notice} from './dom.js';
import {state} from './state.js';

const watching = new Set(), completed = new Set();
const active = job => job.state === 'queued' || job.state === 'running';
const names = {queued: 'Wartet', running: 'Läuft', succeeded: 'Abgeschlossen', failed: 'Fehlgeschlagen', interrupted: 'Unterbrochen'};
const operations = {start: 'Starten', stop: 'Stoppen', restart: 'Neu starten', setup: 'Vorbereiten', config: 'Einstellungen speichern', check_updates: 'Steam-Update prüfen'};
Object.assign(operations, {backup: 'Welt sichern', backup_verify: 'Sicherung prüfen', backup_pin: 'Sicherung anheften / lösen', backup_settings: 'Sicherungsautomatik speichern', backup_restore: 'Welt wiederherstellen', backup_recover: 'Vorherigen Stand zurückholen'});
const reservation = job => job.operation === 'check_updates' ? `updates:${job.server_id}` : job.server_id;

export function mergeJobs(jobs) {
  const known = new Map(state.latestJobs.map(job => [job.id, job]));
  for (const job of jobs) {
    if (!known.has(job.id) || job.updated_at >= known.get(job.id).updated_at) known.set(job.id, job);
  }
  state.latestJobs = [...known.values()].sort((a, b) => b.created_at.localeCompare(a.created_at)).slice(0, 20);
}

export function renderJobs() {
  const container = $('#job-list');
  container.replaceChildren();
  if (!state.latestJobs.length) container.append(element('p', 'Noch keine Aufträge für dieses Profil.', 'empty'));
  for (const job of state.latestJobs.slice(0, 5)) {
    const row = element('div', undefined, 'job-row'); row.dataset.jobId = job.id;
    const title = element('strong', `${operations[job.operation] || job.operation} · ${names[job.state]}`);
    const detail = element('span', job.message, 'muted');
    const identity = element('small', `Auftrag ${job.id.slice(0, 8)} · ${new Date(job.updated_at).toLocaleString('de-DE')}`, 'muted');
    row.append(title, detail, identity); container.append(row);
  }
}

export function watchJob(initial, update, finish) {
  if (!active(initial) || watching.has(initial.id) || completed.has(initial.id)) return;
  watching.add(initial.id);
  state.pending.set(reservation(initial), initial);
  update();
  (async () => {
    try {
      for (;;) {
        let job;
        try { job = (await api(`/api/jobs?id=${encodeURIComponent(initial.id)}`)).job; }
        catch (error) {
          if (state.selectedServer === initial.server_id) notice(`Auftragsstatus nicht erreichbar: ${error.message}`, true);
          await new Promise(resolve => setTimeout(resolve, 2000));
          continue;
        }
        if (state.selectedServer === job.server_id) { mergeJobs([job]); renderJobs(); }
        if (!active(job)) {
          completed.add(job.id);
          if (state.pending.get(reservation(job))?.id === job.id) state.pending.delete(reservation(job));
          update(); await finish(job); return;
        }
        state.pending.set(reservation(job), job); update();
        await new Promise(resolve => setTimeout(resolve, document.hidden ? 2000 : 500));
      }
    } finally { watching.delete(initial.id); }
  })().catch(error => notice(error.message, true));
}

export function acceptJob(job, update, finish) {
  if (state.selectedServer === job.server_id) { mergeJobs([job]); renderJobs(); }
  if (active(job)) watchJob(job, update, finish);
  else if (!completed.has(job.id)) { completed.add(job.id); finish(job).catch(error => notice(error.message, true)); }
}
