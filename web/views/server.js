import {$, element} from '../dom.js';
import {can, state} from '../state.js';
import {renderUpdate} from './updates.js';
import {connectionControls, renderConnection} from './connection.js';
import {backupControls} from './backups.js';

export function controls() {
  const status = state.snapshot?.status;
  const pending = state.pending.has(state.selectedServer);
  const locked = state.submitting || !state.available || pending;
  const actionLocked = state.submitting || !state.catalogAvailable || !state.selectedServer;
  const recovery = status?.recovery_required;
  $('#server-select').disabled = state.submitting || !state.catalogAvailable;
  $('#profile-fields').disabled = state.submitting || !can('catalog.write');
  $('#start').disabled = actionLocked || recovery || !can('server.start');
  $('#stop').disabled = actionLocked || !can('server.stop');
  $('#restart').disabled = actionLocked || recovery || !can('server.restart');
  $('#setup').disabled = locked || recovery || !can('server.setup') || !status?.installed || status.running || status.starting;
  for (const [id, permission] of [['start', 'server.start'], ['stop', 'server.stop'], ['restart', 'server.restart']]) {
    $(`#${id}`).title = !can(permission) ? 'Deine Rolle erlaubt diese Aktion nicht.' : 'Als Auftrag annehmen; beim Ausführen wird der aktuelle Serverzustand geprüft.';
  }
  $('#control-hint').textContent = !state.catalogAvailable ? 'Serverprofile werden geladen …'
    : recovery ? 'Eine Weltwiederherstellung ist offen. Start und Neustart benötigen zuerst den Rückweg unter Weltsicherungen.'
      : can('server.stop') ? 'Start, Stopp und Neustart werden als Aufträge nacheinander ausgeführt. Statusabfragen laufen unabhängig weiter.'
        : can('server.start') ? 'Du darfst freigegebene Server starten. Stoppen und Neustart sind mit deiner Rolle nicht freigegeben.'
          : 'Deine Rolle erlaubt das Ansehen; Serverbefehle sind nicht freigegeben.';
  $('#config-fields').disabled = locked || recovery || state.configLoading || !can('config.write') || !state.config || status?.running || status?.starting;
  $('#refresh').disabled = state.submitting;
  $('#refresh-logs').disabled = state.submitting;
  $('#check-updates').disabled = state.submitting || !state.selectedServer || !can('updates.check') || state.pending.has(`updates:${state.selectedServer}`);
  $('#new-moment').disabled = !state.catalogAvailable || !can('community.write');
  for (const node of document.querySelectorAll('[data-permission]')) node.hidden = !can(node.dataset.permission);
  for (const node of document.querySelectorAll('[data-local-owner]')) node.hidden = state.principal?.id !== 'local-owner' || !can('discord.write');
  for (const button of document.querySelectorAll('#discord button')) button.disabled = state.submitting || !can('discord.write');
  connectionControls();
  $('#access-note').hidden = state.principal?.role === 'owner';
  backupControls();
  if (pending && state.available) {
    $('#server-state').textContent = '◌ Auftrag läuft';
    $('#process-note').textContent = 'Fortschritt und Ergebnis unter Aufträge.';
  } else if (recovery) {
    $('#server-state').textContent = 'Wiederherstellung offen'; $('#server-state').className = 'pill error';
    $('#process-note').textContent = can('backups.manage') ? 'Unter Weltsicherungen den vorherigen Stand zurückholen.' : 'Der Besitzer muss die Weltwiederherstellung abschließen.';
  }
  if (status) $('#settings-hint').textContent = recovery ? 'Wiederherstellung offen. Unter Weltsicherungen den vorherigen Stand zurückholen.'
    : pending ? 'Für dieses Profil läuft ein Auftrag. Andere Profile bleiben bedienbar.'
    : status.starting ? 'Der Serverstarter arbeitet noch. Fortschritt unter Serverlog → Starter / Startfehler.'
      : status.running ? 'Der Server läuft. Stoppe ihn vor dem Bearbeiten, damit er deine Änderungen nicht überschreibt.'
        : !status.configured ? 'Bitte den Server zuerst unter Übersicht vorbereiten.'
          : state.configLoading ? 'Einstellungen werden geladen …' : 'Der Server ist gestoppt. Du kannst seine Einstellungen jetzt bearbeiten.';
}

export function renderCatalog(catalog) {
  state.catalog = catalog;
  const selector = $('#server-select');
  if (JSON.stringify(catalog.servers) !== selector.dataset.profiles) {
    selector.replaceChildren(); $('#profile-list').replaceChildren();
    for (const profile of catalog.servers) {
      const option = element('option', profile.label); option.value = profile.id; selector.append(option);
      const row = element('div', undefined, 'profile-entry');
      row.append(element('strong', profile.label), element('small', `${profile.game}${profile.server_directory ? ` · ${profile.server_directory}` : ''}`));
      $('#profile-list').append(row);
    }
    selector.dataset.profiles = JSON.stringify(catalog.servers);
  }
  selector.value = state.selectedServer;
  if (!$('#profile-game').options.length) {
    for (const game of catalog.games.filter(game => game.server_adapter !== false)) {
      const option = element('option', game.label); option.value = game.id;
      option.dataset.directory = game.default_directory || ''; option.dataset.launcher = String(game.external_launcher || false);
      $('#profile-game').append(option);
    }
  }
}

export function renderStatus(data) {
  state.snapshot = data;
  const {status, summary} = data;
  renderUpdate(data.update);
  renderConnection(data.connection);
  $('#server-name').textContent = summary.title;
  $('#game-label').textContent = `${status.game_label.toUpperCase()} · DEDICATED SERVER`;
  $('#profile-caption').textContent = status.profile_label + (status.external_launcher ? ' · Start und Discord über deinen vorhandenen Starter.' : '');
  for (const [index, id] of ['slot-count', 'preset-label', 'query-port'].entries()) {
    const metric = summary.metrics[index];
    $(`#metric-label-${index}`).textContent = metric?.label.toUpperCase() || '—';
    $(`#${id}`).textContent = metric?.value === '' ? '—' : metric?.value ?? '—';
  }
  $('#process-id').textContent = status.processes?.map(p => p.pid).join(', ') || '—';
  const pending = state.pending.get(state.selectedServer);
  $('#server-state').textContent = pending ? '◌ Auftrag läuft' : status.known === false ? 'Prozessstatus unbekannt' : status.starting ? '◌ Starter arbeitet' : status.running ? '● Prozess läuft' : '○ Gestoppt';
  $('#server-state').className = 'pill' + (status.running ? ' running' : '');
  $('#process-note').textContent = pending ? 'Fortschritt und Ergebnis unter Aufträge.' : status.starting ? 'Fortschritt im Starterprotokoll prüfen.'
    : status.running ? can('logs.read') ? 'Verbindungsbereitschaft im Serverlog prüfen.' : 'Prozess läuft. Spielbereitschaft ist noch nicht bestätigt.' : 'Bereit, wenn du es bist.';
  $('#setup-panel').hidden = status.configured || !can('server.setup');
  $('#installation-check').textContent = status.installed ? 'Server gefunden' : 'Serverprogramm fehlt';
  $('#configuration-check').textContent = data.config_error ? 'Konfiguration nicht lesbar' : status.configured ? 'Konfiguration vorhanden' : 'Erstes Setup ausstehend';
  $('#install-path').textContent = status.directory || '';
  $('#discord-status').textContent = status.discord_configured ? 'Webhook hinterlegt' : 'Noch kein Webhook hinterlegt';
  $('#webhook-state').textContent = status.discord_configured ? 'WEBHOOK HINTERLEGT' : 'NICHT VERBUNDEN';
  $('#webhook-help').textContent = status.discord_configured
    ? 'Ein Webhook ist gespeichert. Leer lassen, um ihn beizubehalten. Die URL wird nicht zurück an den Browser übertragen.'
    : 'Die URL wird nur lokal gespeichert.';
  if (!state.discordDirty) $('#auto-notify').checked = status.auto_notify || false;
  controls();
}

export function renderEvents(events) {
  const container = $('#events');
  if (container.dataset.revision === JSON.stringify(events)) return;
  container.dataset.revision = JSON.stringify(events);
  container.replaceChildren();
  if (!events.length) container.append(element('p', 'Noch keine Aktivitäten. Deine Reise beginnt hier.', 'empty'));
  for (const entry of events.slice(0, 6)) {
    const row = element('div', undefined, 'event');
    const timestamp = element('time', new Date(entry.time).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'}));
    timestamp.dateTime = entry.time; timestamp.title = new Date(entry.time).toLocaleString('de-DE');
    row.append(timestamp, element('span', entry.message)); container.append(row);
  }
}
