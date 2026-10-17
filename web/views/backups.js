import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';
import {bindWorlds,loadWorlds,resetWorlds} from './worlds.js';

let request = 0, previewRequest = 0, run;
const stamp = value => value ? new Date(value).toLocaleString('de-DE') : 'Nicht bestätigt';
const size = value => `${(value / 1024 / 1024).toLocaleString('de-DE', {maximumFractionDigits: 1})} MiB`;

export function resetBackups() {
  resetWorlds();
  request++; previewRequest++;
  $('#backup-list').replaceChildren(); $('#backup-target').textContent = 'Weltziel wird geladen …';
  $('#backup-last').textContent = ''; $('#backup-progress').textContent = '';
  $('#backup-recovery').hidden = true; $('#backup-create').hidden = false;
  $('#backup-auto').checked = false;
}

export function backupControls() {
  const locked = state.submitting || state.pending.has(state.selectedServer) || !state.available;
  const offline = !state.snapshot?.status.running && !state.snapshot?.status.starting;
  for (const button of document.querySelectorAll('#backups .backup-action')) {
    button.disabled = locked || (button.dataset.offline === 'true' && (!offline || state.refreshing));
  }
  $('#backup-auto').disabled = locked;
}

function action(label, operation, payload, offline = false) {
  const button = element('button', label, 'button secondary backup-action');
  button.type = 'button'; button.dataset.offline = String(offline);
  button.addEventListener('click', () => run(operation, payload));
  return button;
}

async function restore(identifier, recovery = false) {
  const target = state.selectedServer, epoch = state.selectionEpoch, navigation = state.navigationEpoch;
  const generation = ++previewRequest;
  try {
    const {preview} = await api(`/api/backups?${recovery ? 'recovery=true' : `id=${encodeURIComponent(identifier)}`}`, undefined, target);
    if (generation !== previewRequest || epoch !== state.selectionEpoch || navigation !== state.navigationEpoch || state.view !== 'backups') return;
    const warning = `${state.catalog.servers.find(profile => profile.id === target)?.label}\n`
      + `Stand: ${stamp(preview.created_at)}\nWelt: ${preview.target.world}\nKonfiguration: ${preview.target.files['config/server']}\n\n`
      + (recovery ? 'Den zuvor gesicherten Stand zurückholen.' : 'Der aktuelle Stand wird zuerst gesichert und angeheftet. Danach werden Welt und zugehörige Konfiguration ersetzt.')
      + '\nDer Server muss gestoppt bleiben. Bitte währenddessen auch keinen Desktop-Starter verwenden.\n\nZum Bestätigen WIEDERHERSTELLEN eingeben:';
    const confirmation = prompt(warning);
    if (confirmation === preview.confirmation) await run(recovery ? 'backup_recover' : 'backup_restore', {...preview, confirmation});
    else if (confirmation !== null) notice('Wiederherstellung nicht bestätigt.', true);
  } catch (error) {
    if (epoch === state.selectionEpoch && navigation === state.navigationEpoch) notice(error.message, true);
  }
}

export async function loadBackups() {
  if (!can('backups.manage') || state.view !== 'backups') return;
  void loadWorlds();
  const generation = ++request, target = state.selectedServer, epoch = state.selectionEpoch, navigation = state.navigationEpoch;
  const current = () => generation === request && epoch === state.selectionEpoch && navigation === state.navigationEpoch && state.view === 'backups';
  try {
    const data = await api('/api/backups', undefined, target);
    if (!current()) return;
    $('#backup-target').textContent = data.target ? `${data.target.world}\nKonfiguration: ${data.target.files['config/server']}` : (data.error || 'Weltziel unbekannt.');
    const latest = data.backups.find(item => item.verified_at);
    $('#backup-last').textContent = latest ? `Letzte verifizierte Sicherung: ${stamp(latest.created_at)}` : 'Noch keine verifizierte Weltsicherung.';
    $('#backup-progress').textContent = data.progress && data.progress.phase !== 'idle'
      ? `${data.progress.phase}${data.progress.total ? ` · ${data.progress.completed}/${data.progress.total} Dateien` : ''}` : (data.error || 'Bereit. Sicherungen werden ausschließlich bei gestopptem Server erstellt.');
    $('#backup-auto').checked = data.automatic_after_stop;
    $('#backup-recovery').hidden = !data.recovery_required;
    $('#backup-create').hidden = data.recovery_required;
    const list = $('#backup-list'); list.replaceChildren();
    if (!data.backups.length) list.append(element('p', 'Sichere eure Welt nach dem regulären Serverstopp.', 'empty'));
    for (const item of data.backups) {
      const row = element('article', undefined, 'backup-entry'); row.dataset.backupId = item.id;
      row.append(element('strong', item.error || stamp(item.created_at)));
      if (item.error) { list.append(row); continue; }
      row.append(element('p', `${item.count} Dateien · ${size(item.bytes)} · Steam-Build ${item.build || 'unbekannt'}${item.pinned ? ' · Angeheftet' : ''}`, 'muted'));
      row.append(element('p', `Prüfsummen zuletzt bestätigt: ${stamp(item.verified_at)}${item.reason === 'before_restore' ? ' · Rückweg vor Wiederherstellung' : ''}`, 'field-help'));
      const buttons = element('div', undefined, 'backup-actions');
      buttons.append(action('Prüfen', 'backup_verify', {backup_id: item.id}), action(item.pinned ? 'Lösen' : 'Anheften', 'backup_pin', {backup_id: item.id, pinned: !item.pinned}));
      if (!data.recovery_required) {
        const restoreButton = element('button', 'Wiederherstellen', 'button secondary backup-action'); restoreButton.type = 'button'; restoreButton.dataset.offline = 'true';
        restoreButton.addEventListener('click', () => restore(item.id)); buttons.append(restoreButton);
      }
      row.append(buttons); list.append(row);
    }
    backupControls();
  } catch (error) { if (current()) notice(error.message, true); }
}

export function bindBackups(runOperation) {
  bindWorlds(runOperation);
  run = runOperation;
  $('#backup-refresh').addEventListener('click', loadBackups);
  $('#backup-create').addEventListener('click', () => run('backup'));
  $('#backup-auto').addEventListener('change', () => run('backup_settings', {automatic_after_stop: $('#backup-auto').checked}));
  $('#backup-recover').addEventListener('click', () => restore(null, true));
}
