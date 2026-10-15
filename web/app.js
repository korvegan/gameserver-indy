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

const presetNames = {Default: 'Standard', Relaxed: 'Entspannt', Hard: 'Schwer', Survival: 'Überleben', Custom: 'Eigene Regeln'};

function notice(message, error = false) {
  const box = $('#notice');
  box.textContent = message;
  box.classList.toggle('error', error);
  box.hidden = !message;
}

async function api(path, body) {
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
  for (const key of ['name', 'slotCount', 'queryPort', 'gameSettingsPreset', 'voiceChatMode']) {
    form.elements.namedItem(key).value = config[key] ?? '';
  }
  for (const key of ['enableVoiceChat', 'enableTextChat']) form.elements.namedItem(key).checked = Boolean(config[key]);
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
  for (const key of ['name', 'gameSettingsPreset', 'voiceChatMode']) result[key] = form.elements.namedItem(key).value;
  for (const key of ['slotCount', 'queryPort']) result[key] = Number(form.elements.namedItem(key).value);
  for (const key of ['enableVoiceChat', 'enableTextChat']) result[key] = form.elements.namedItem(key).checked;
  for (const input of document.querySelectorAll('[data-group]')) result.userGroups[Number(input.dataset.group)].password = input.value;
  return result;
}

function render(data) {
  snapshot = data;
  const {status, config: current, events} = data;
  $('#server-name').textContent = current?.name || 'Enshrouded Indy';
  $('#slot-count').textContent = current?.slotCount ?? '—';
  $('#preset-label').textContent = presetNames[current?.gameSettingsPreset] || '—';
  $('#query-port').textContent = current?.queryPort ?? '—';
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
  } finally { refreshing = false; }
}

async function mutate(path, body = {}) {
  if (busy) return;
  busy = true; controls(); notice('Wird ausgeführt …');
  try {
    const result = await api(path, body);
    if (path === '/api/config' || path === '/api/setup') { dirty = false; rawDirty = false; }
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
  $('#breadcrumb').textContent = {overview: 'Übersicht', settings: 'Einstellungen', logs: 'Serverlog', discord: 'Discord'}[view];
  if (view === 'logs') loadLogs();
}

for (const button of document.querySelectorAll('[data-view]')) button.addEventListener('click', () => go(button.dataset.view));
for (const button of document.querySelectorAll('[data-go]')) button.addEventListener('click', () => go(button.dataset.go));
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
  try { token = (await api('/api/session')).token; await refresh(); }
  catch (error) { notice(error.message, true); }
  controls();
  setInterval(() => { if (!busy && !refreshing) refresh(); }, 8000);
})();
