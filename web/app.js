'use strict';

const $ = (selector) => document.querySelector(selector);
const form = $('#config-form');
let token = '';
let snapshot = null;
let config = null;
let view = 'overview';
let dirty = false;
let rawDirty = false;
let discordDirty = false;
let busy = false;
let refreshing = false;
let available = false;
let catalogAvailable = false;
let selectedServer = '';
try { selectedServer = localStorage.getItem('gameserver-indy-server') || ''; } catch { /* Storage may be unavailable. */ }

function notice(message, error = false) {
  const box = $('#notice');
  box.textContent = message;
  box.classList.toggle('error', error);
  box.hidden = !message;
}

async function api(path, body) {
  if (body !== undefined) body = {...body, server_id: selectedServer || undefined};
  else if (selectedServer && (path === '/api/state' || path === '/api/logs')) path += `?server=${encodeURIComponent(selectedServer)}`;
  const options = {cache: 'no-store'};
  if (body !== undefined) Object.assign(options, {
    method: 'POST', headers: {'Content-Type': 'application/json', 'X-Manager-Token': token}, body: JSON.stringify(body),
  });
  let response;
  try { response = await fetch(path, options); }
  catch { throw new Error('Die lokale Verwaltung ist nicht erreichbar. Bitte das Startfenster prüfen.'); }
  const data = await response.json();
  if (!response.ok) throw new Error(data.error || 'Die Anfrage ist fehlgeschlagen.');
  return data;
}

function controls() {
  const status = snapshot?.status;
  const locked = busy || !available;
  $('#server-select').disabled = busy || refreshing || !catalogAvailable;
  $('#profile-fields').disabled = locked;
  $('#start').disabled = locked || !status?.installed || !status?.configured || status.running;
  $('#stop').disabled = locked || !status?.running;
  $('#restart').disabled = locked || !status?.running;
  $('#setup').disabled = locked || !status?.installed || status.running;
  $('#config-fields').disabled = locked || !config || status?.running;
  $('#refresh').disabled = busy;
  $('#refresh-logs').disabled = busy;
  for (const button of document.querySelectorAll('#discord button')) button.disabled = busy || !token;
  if (status) $('#settings-hint').textContent = status.running
    ? 'Der Server läuft. Stoppe ihn vor dem Bearbeiten, damit er deine Änderungen nicht überschreibt.'
    : !config ? 'Bitte den Server zuerst unter Übersicht vorbereiten.'
      : 'Der Server ist gestoppt. Du kannst seine Einstellungen jetzt bearbeiten.';
}

function markDirty() {
  dirty = true;
  $('#unsaved').hidden = false;
}

function populateConfig(value) {
  config = structuredClone(value);
  if (!config) return;
  $('#game-fields').replaceChildren();
  for (const field of snapshot.game_profile.fields) {
    const label = document.createElement('label');
    if (field.wide) label.className = 'wide';
    label.append(document.createTextNode(field.label));
    const input = document.createElement(field.type === 'select' ? 'select' : 'input');
    input.name = field.key;
    if (field.type === 'select') {
      for (const [value, text] of Object.entries(field.options)) {
        const option = document.createElement('option'); option.value = value; option.textContent = text; input.append(option);
      }
      if (config[field.key] && !Object.hasOwn(field.options, config[field.key])) {
        const option = document.createElement('option'); option.value = config[field.key]; option.textContent = config[field.key]; input.append(option);
      }
    } else {
      input.type = field.type; input.autocomplete = field.type === 'password' ? 'new-password' : 'off';
    }
    if (field.min !== undefined) input.min = field.min;
    if (field.max !== undefined) input.max = field.max;
    input.required = Boolean(field.required);
    if (field.type === 'checkbox') input.checked = Boolean(config[field.key]);
    else input.value = config[field.key] ?? '';
    if (field.lock_with_external_launcher && snapshot.status.external_launcher) input.readOnly = true;
    label.append(input);
    $('#game-fields').append(label);
    if (field.help) {
      const help = document.createElement('small'); help.className = 'field-help'; help.textContent = field.help;
      const helpId = `field-help-${$('#game-fields').children.length}`; help.id = helpId; input.setAttribute('aria-describedby', helpId); label.append(help);
    }
  }
  $('#roles-section').hidden = !snapshot.game_profile.role_groups;
  $('#groups').replaceChildren();
  (config.userGroups || []).forEach((group, index) => {
    const row = document.createElement('div'); row.className = 'group-row';
    const name = document.createElement('strong'); name.textContent = group.name;
    const label = document.createElement('label'); label.textContent = 'Gruppenpasswort';
    const input = document.createElement('input');
    input.type = 'password'; input.value = group.password; input.autocomplete = 'new-password';
    input.dataset.group = String(index); input.id = `group-${index}`;
    input.setAttribute('aria-label', `Passwort für ${group.name}`); label.append(input);
    const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'button secondary';
    toggle.textContent = 'Anzeigen'; toggle.setAttribute('aria-controls', input.id); toggle.setAttribute('aria-pressed', 'false');
    toggle.addEventListener('click', () => {
      input.type = input.type === 'password' ? 'text' : 'password';
      toggle.textContent = input.type === 'text' ? 'Verbergen' : 'Anzeigen';
      toggle.setAttribute('aria-pressed', String(input.type === 'text'));
    });
    row.append(name, label, toggle); $('#groups').append(row);
  });
  $('#json-editor').value = JSON.stringify(config, null, 2);
  $('#unsaved').hidden = true;
}

function fromForm() {
  const result = structuredClone(config);
  for (const field of snapshot.game_profile.fields) {
    const input = form.elements.namedItem(field.key);
    result[field.key] = field.type === 'checkbox' ? input.checked
      : field.type === 'number' && field.value_type !== 'string' ? Number(input.value) : input.value;
  }
  for (const input of document.querySelectorAll('[data-group]')) result.userGroups[Number(input.dataset.group)].password = input.value;
  return result;
}

function render(data) {
  snapshot = data;
  const {status, config: current, events} = data;
  selectedServer = status.server_id;
  $('#server-name').textContent = current?.name || current?.['server name'] || status.profile_label;
  $('#game-label').textContent = `${status.game_label.toUpperCase()} · DEDICATED SERVER`;
  $('#profile-caption').textContent = status.profile_label + (status.external_launcher ? ' · Start und Discord über deinen vorhandenen Starter.' : '');
  for (const [index, id] of ['slot-count', 'preset-label', 'query-port'].entries()) {
    const metric = data.game_profile.metrics[index]; const value = current?.[metric.key];
    $(`#metric-label-${index}`).textContent = metric.label.toUpperCase();
    $(`#${id}`).textContent = (metric.options?.[value] ?? value) || '—';
  }
  const selector = $('#server-select');
  if (JSON.stringify(data.servers) !== selector.dataset.profiles) {
    selector.replaceChildren(); $('#profile-list').replaceChildren();
    for (const profile of data.servers) {
      const option = document.createElement('option'); option.value = profile.id; option.textContent = profile.label; selector.append(option);
      const row = document.createElement('div'); row.className = 'profile-entry';
      const name = document.createElement('strong'); name.textContent = profile.label;
      const path = document.createElement('small'); path.textContent = `${profile.game} · ${profile.server_directory}`;
      row.append(name, path); $('#profile-list').append(row);
    }
    selector.dataset.profiles = JSON.stringify(data.servers);
  }
  selector.value = selectedServer;
  if (!$('#profile-game').options.length) {
    for (const game of data.games) {
      const option = document.createElement('option'); option.value = game.id; option.textContent = game.label;
      option.dataset.directory = game.default_directory; option.dataset.launcher = String(game.external_launcher); $('#profile-game').append(option);
    }
  }
  $('#process-id').textContent = status.processes.map(p => p.pid).join(', ') || '—';
  $('#server-state').textContent = status.running ? '● Prozess läuft' : '○ Gestoppt';
  $('#server-state').className = 'pill' + (status.running ? ' running' : '');
  $('#process-note').textContent = status.running ? 'Verbindungsbereitschaft im Serverlog prüfen.' : 'Bereit, wenn du es bist.';
  $('#setup-panel').hidden = status.configured;
  $('#installation-check').textContent = status.installed ? 'Server gefunden' : 'Serverprogramm fehlt';
  $('#configuration-check').textContent = status.configured ? 'Konfiguration vorhanden' : 'Erstes Setup ausstehend';
  $('#install-path').textContent = status.directory;
  $('#discord-status').textContent = status.discord_configured ? 'Webhook hinterlegt' : 'Noch kein Webhook hinterlegt';
  $('#webhook-state').textContent = status.discord_configured ? 'WEBHOOK HINTERLEGT' : 'NICHT VERBUNDEN';
  $('#webhook-help').textContent = status.discord_configured
    ? 'Ein Webhook ist gespeichert. Leer lassen, um ihn beizubehalten. Die URL wird nicht zurück an den Browser übertragen.'
    : 'Die URL wird nur lokal gespeichert.';
  if (!discordDirty) $('#auto-notify').checked = status.auto_notify;
  if (!dirty) {
    if (JSON.stringify(config) !== JSON.stringify(current)) populateConfig(current);
    $('#unsaved').hidden = true;
  }
  $('#events').replaceChildren();
  if (!events.length) {
    const p = document.createElement('p'); p.className = 'empty'; p.textContent = 'Noch keine Aktivitäten. Deine Reise beginnt hier.';
    $('#events').append(p);
  }
  for (const entry of events.slice(0, 6)) {
    const row = document.createElement('div'); row.className = 'event';
    const timestamp = document.createElement('time'); timestamp.dateTime = entry.time;
    timestamp.textContent = new Date(entry.time).toLocaleTimeString('de-DE', {hour: '2-digit', minute: '2-digit'});
    timestamp.title = new Date(entry.time).toLocaleString('de-DE');
    const text = document.createElement('span'); text.textContent = entry.message;
    row.append(timestamp, text); $('#events').append(row);
  }
  controls();
}

async function refresh() {
  if (refreshing) return;
  refreshing = true;
  controls();
  try {
    const data = await api('/api/state');
    available = true; render(data);
    if (view === 'logs') await loadLogs();
  } catch (error) {
    available = false;
    $('#server-state').textContent = 'Status nicht erreichbar';
    $('#server-state').className = 'pill error';
    $('#process-note').textContent = 'Windows-/WSL-Verbindung prüfen.';
    notice(error.message, true); controls();
  } finally { refreshing = false; controls(); }
}

async function mutate(path, body = {}) {
  if (busy) return;
  busy = true; controls(); notice('Wird ausgeführt …');
  try {
    const result = await api(path, body);
    if (path === '/api/servers') { selectedServer = result.server_id; dirty = false; rawDirty = false; config = null; rememberServer(); }
    if (path === '/api/config' || path === '/api/setup') { dirty = false; rawDirty = false; config = null; }
    if (path === '/api/discord/settings') {
      discordDirty = false; $('#webhook').value = ''; $('#clear-webhook').checked = false;
    }
    await refresh();
    notice(result.message + (result.warning ? ` Hinweis: ${result.warning}` : ''), Boolean(result.warning));
  } catch (error) { notice(error.message, true); }
  finally { busy = false; controls(); }
}

async function loadLogs() {
  try {
    const data = await api('/api/logs');
    $('#log-file').textContent = data.file || 'Serverkonsole';
    $('#log-content').textContent = data.text;
  } catch (error) { notice(error.message, true); }
}

function go(next) {
  view = next;
  for (const section of document.querySelectorAll('.view')) section.hidden = section.id !== view;
  for (const button of document.querySelectorAll('[data-view]')) {
    button.classList.toggle('active', button.dataset.view === view);
    if (button.dataset.view === view) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
  }
  $('#breadcrumb').textContent = {overview: 'Übersicht', settings: 'Einstellungen', logs: 'Serverlog', discord: 'Discord', profiles: 'Serverprofile'}[view];
  if (view === 'logs') loadLogs();
}

for (const button of document.querySelectorAll('[data-view]')) button.addEventListener('click', () => go(button.dataset.view));
for (const button of document.querySelectorAll('[data-go]')) button.addEventListener('click', () => go(button.dataset.go));
function rememberServer() { try { localStorage.setItem('gameserver-indy-server', selectedServer); } catch { /* Optional preference. */ } }
$('#server-select').addEventListener('change', async () => {
  const next = $('#server-select').value;
  if (dirty && !confirm('Ungespeicherte Spieleinstellungen verwerfen und das Serverprofil wechseln?')) { $('#server-select').value = selectedServer; return; }
  selectedServer = next; rememberServer(); dirty = false; rawDirty = false; config = null; available = false;
  $('#json-editor').value = ''; $('#game-fields').replaceChildren(); $('#groups').replaceChildren();
  $('#log-content').textContent = 'Logs werden geladen …';
  controls(); await refresh();
});
$('#profile-game').addEventListener('change', () => {
  $('#profile-directory').value = $('#profile-game').selectedOptions[0].dataset.directory;
  $('#profile-launcher').disabled = $('#profile-game').selectedOptions[0].dataset.launcher !== 'true';
});
$('#profile-form').addEventListener('submit', event => {
  event.preventDefault();
  mutate('/api/servers', {id: $('#profile-id').value, game: $('#profile-game').value, label: $('#profile-label').value,
    server_directory: $('#profile-directory').value, config_path: $('#profile-config').value,
    launcher_script: $('#profile-game').selectedOptions[0].dataset.launcher === 'true' ? $('#profile-launcher').value : ''});
});
$('#refresh').addEventListener('click', refresh);
$('#refresh-logs').addEventListener('click', loadLogs);
$('#setup').addEventListener('click', () => mutate('/api/setup'));
$('#start').addEventListener('click', () => mutate('/api/start'));
for (const action of ['stop', 'restart']) $(`#${action}`).addEventListener('click', () => {
  if (confirm(action === 'stop' ? 'Server stoppen? Verbundene Spieler werden getrennt.' : 'Server neu starten? Verbundene Spieler werden getrennt.')) mutate(`/api/${action}`);
});
form.addEventListener('input', (event) => {
  if (event.target.id === 'json-editor') { rawDirty = true; markDirty(); return; }
  markDirty();
  if (!rawDirty) $('#json-editor').value = JSON.stringify(fromForm(), null, 2);
});
form.addEventListener('submit', (event) => {
  event.preventDefault();
  if (rawDirty) return notice('Im JSON-Editor liegen Änderungen. Bitte „JSON prüfen und speichern“ verwenden.', true);
  mutate('/api/config', {config: fromForm()});
});
$('#save-json').addEventListener('click', () => {
  try { mutate('/api/config', {config: JSON.parse($('#json-editor').value)}); }
  catch { notice('Das JSON enthält einen Syntaxfehler. Bitte die Eingabe prüfen.', true); }
});
$('#discord-form').addEventListener('input', () => { discordDirty = true; });
$('#discord-form').addEventListener('submit', (event) => {
  event.preventDefault();
  mutate('/api/discord/settings', {webhook_url: $('#webhook').value, auto_notify: $('#auto-notify').checked, clear_webhook: $('#clear-webhook').checked});
});
$('#discord-message').addEventListener('input', () => { $('#message-counter').textContent = `${$('#discord-message').value.length} / 2000 Zeichen`; });
$('#message-form').addEventListener('submit', (event) => { event.preventDefault(); mutate('/api/discord/send', {message: $('#discord-message').value}); });
window.addEventListener('beforeunload', (event) => { if (dirty || discordDirty) { event.preventDefault(); event.returnValue = ''; } });

(async () => {
  try {
    token = (await api('/api/session')).token;
    const catalog = await api('/api/servers');
    if (!catalog.servers.some(profile => profile.id === selectedServer)) selectedServer = catalog.servers[0].id;
    $('#server-select').replaceChildren(...catalog.servers.map(profile => {
      const option = document.createElement('option'); option.value = profile.id; option.textContent = profile.label; return option;
    }));
    $('#server-select').value = selectedServer;
    catalogAvailable = true;
    await refresh();
  }
  catch (error) { notice(error.message, true); }
  controls();
  setInterval(() => { if (!busy && !refreshing) refresh(); }, 8000);
})();
