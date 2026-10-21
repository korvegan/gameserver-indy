import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';

let request = 0, editing, saving = false, loading = false;
let connectionDirty = false, userDirty = false;
const updateDirty = () => { state.accessDirty = connectionDirty || userDirty; };
export function discardAccessDrafts() { connectionDirty = false; userDirty = false; updateDirty(); }
const roles = {owner: 'Besitzer · voller Zugriff', family: 'Familie · ansehen und starten', viewer: 'Nur ansehen'};
const operations = {'/api/session': 'Anmeldung', '/api/access': 'Freigaben ansehen', '/api/access/users': 'Zugang ändern', '/api/access/settings': 'Private Verbindung ändern',
  'access.configure': 'Private Verbindung speichern', 'access.user': 'Zugang ändern', '/api/jobs': 'Serveraktion', 'server.start': 'Server starten', 'server.stop': 'Server stoppen', 'server.restart': 'Server neu starten',
  '/api/state': 'Besitzerdaten lesen', '/api/config': 'Spieleinstellungen', '/api/logs': 'Serverlog', '/api/status': 'Serverstatus', '/api/community': 'Gruppenmoment', '/api/community/export': 'Chronikexport',
  '/api/discord/send': 'Discord-Mitteilung', '/api/servers': 'Serverprofile', '/api/setup': 'Server vorbereiten'};
const outcomes = {'200': 'Erfolgreich', '202': 'Auftrag angenommen', '403': 'Zugriff abgelehnt', '404': 'Nicht gefunden', '409': 'Konflikt', '500': 'Fehler', requested: 'Angefragt', saved: 'Gespeichert', enabled: 'Freigegeben', revoked: 'Gesperrt'};

function resetUser() {
  editing = undefined; $('#access-user-form').reset(); $('#access-user-login').disabled = false;
  $('#access-user-enabled').checked = true; $('#access-user-role').value = 'family';
  $('#access-user-title').textContent = 'Eine Person freigeben'; userDirty = false; updateDirty();
  roleFields();
}

function roleFields() { $('#access-server-choice').hidden = $('#access-user-role').value === 'owner'; }

function editUser(user) {
  if (saving || loading || (userDirty && !confirm('Ungespeicherte Personenfreigabe verwerfen?'))) return;
  editing = user; $('#access-user-login').value = user.login; $('#access-user-login').disabled = true;
  $('#access-user-name').value = user.name; $('#access-user-role').value = user.role;
  $('#access-user-enabled').checked = user.enabled;
  for (const input of $('#access-server-choice').querySelectorAll('input')) input.checked = user.server_ids?.includes(input.value) || false;
  $('#access-user-title').textContent = `${user.name} · Freigabe bearbeiten`; userDirty = false; updateDirty(); roleFields();
  $('#access-user-name').focus();
}

function render(data, keepConnection = false, keepUser = false) {
  const configuration = data.settings;
  if (!keepConnection) {
    $('#family-enabled').checked = configuration.enabled; $('#family-hostname').value = configuration.hostname; $('#family-port').value = configuration.port;
    connectionDirty = false;
  }
  const ready = configuration.enabled && data.runtime.active && data.runtime.hostname === configuration.hostname && data.runtime.port === configuration.port;
  $('#family-state').textContent = !configuration.enabled ? 'Privater Familienzugang deaktiviert.' : ready
    ? 'Privater Empfang bereit. Erreichbarkeit vom anderen Gerät prüfen.' : 'Einrichtung gespeichert. Verwaltung neu starten und die private Verbindung prüfen.';
  if (data.runtime.error) $('#family-state').textContent = data.runtime.error;
  const link = $('#family-address'); link.hidden = !configuration.hostname;
  link.textContent = configuration.hostname ? 'https://' + configuration.hostname : '';
  link.href = configuration.hostname ? 'https://' + configuration.hostname : '#';
  if (!keepUser) {
    const profiles = $('#access-server-inputs'); profiles.replaceChildren();
    for (const server of data.servers) {
      const label = element('label', undefined, 'check-row'); const input = element('input'); input.type = 'checkbox'; input.name = 'server_ids'; input.value = server.id;
      label.append(input, element('span', server.label)); profiles.append(label);
    }
  }
  const list = $('#access-users'); list.replaceChildren();
  for (const user of data.users) {
    const row = element('article', undefined, 'access-user');
    row.append(element('strong', `${user.name}${user.enabled ? '' : ' · gesperrt'}`), element('p', user.login, 'path'),
      element('p', `${roles[user.role]} · ${user.server_ids === null ? 'Alle Server' : user.server_ids.map(id => data.servers.find(s => s.id === id)?.label || id).join(', ') || 'Keine Serverfreigabe'}`, 'muted'));
    const button = element('button', 'Freigabe bearbeiten', 'button secondary'); button.type = 'button'; button.addEventListener('click', () => editUser(user)); row.append(button); list.append(row);
  }
  if (!data.users.length) list.append(element('p', 'Noch keine Personen freigegeben. Der lokale Besitzerzugang bleibt verfügbar.', 'muted'));
  const audit = $('#access-audit'); audit.replaceChildren();
  const names = new Map(data.users.map(user => [user.id, user.name])); names.set('local-owner', 'Besitzer · lokal'); names.set('unrecognized', 'Nicht bestätigter Zugang');
  for (const entry of data.audit) {
    const row = element('li'); row.append(element('time', new Date(entry.at).toLocaleString('de-DE')),
      element('span', ` · ${names.get(entry.actor) || entry.actor} · ${operations[entry.operation] || 'Verwaltungszugriff'}${entry.resource ? ' · ' + (names.get(entry.resource) || data.servers.find(s => s.id === entry.resource)?.label || entry.resource) : ''} · ${outcomes[entry.outcome] || entry.outcome}`)); audit.append(row);
  }
  if (!keepUser) resetUser();
  updateDirty();
}

export async function loadAccess(preserveDrafts = false) {
  if (!can('access.manage') || (state.accessDirty && !preserveDrafts) || saving) return;
  const generation = ++request, epoch = state.navigationEpoch;
  loading = true; $('#access').setAttribute('aria-busy', 'true');
  for (const fieldset of document.querySelectorAll('#access fieldset')) fieldset.disabled = true;
  try {
    const data = await api('/api/access');
    if (generation === request && epoch === state.navigationEpoch && state.view === 'access' && (preserveDrafts || !state.accessDirty)) render(data, preserveDrafts && connectionDirty, preserveDrafts && userDirty);
  } catch (error) { if (generation === request && state.view === 'access') notice(error.message, true); }
  finally {
    if (generation === request) {
      loading = false; $('#access').setAttribute('aria-busy', 'false');
      for (const fieldset of document.querySelectorAll('#access fieldset')) fieldset.disabled = false;
    }
  }
}

async function save(path, body) {
  if (saving) return;
  saving = true;
  for (const input of document.querySelectorAll('#access fieldset,#access button')) input.disabled = true;
  try {
    const result = await api(path, body);
    if (path === '/api/access/users') userDirty = false; else connectionDirty = false;
    updateDirty();
    if (body.id === state.principal.id) { location.reload(); return; }
    saving = false;
    if (state.view === 'access') { notice(result.message); await loadAccess(true); }
  } catch (error) { if (state.view === 'access') notice(error.message, true); }
  finally {
    saving = false;
    for (const fieldset of document.querySelectorAll('#access fieldset')) fieldset.disabled = loading;
    for (const button of document.querySelectorAll('#access button')) button.disabled = false;
  }
}

export function bindAccess() {
  $('#family-settings').addEventListener('input', () => { connectionDirty = true; updateDirty(); });
  $('#access-user-form').addEventListener('input', () => { userDirty = true; updateDirty(); });
  $('#access-user-role').addEventListener('change', roleFields);
  $('#access-user-cancel').addEventListener('click', () => {
    if (!userDirty || confirm('Ungespeicherte Personenfreigabe verwerfen?')) resetUser();
  });
  $('#access-refresh').addEventListener('click', () => {
    if (state.accessDirty && !confirm('Ungespeicherte Freigabe verwerfen und neu laden?')) return;
    discardAccessDrafts(); loadAccess();
  });
  $('#family-settings').addEventListener('submit', event => {
    event.preventDefault();
    save('/api/access/settings', {enabled: $('#family-enabled').checked, hostname: $('#family-hostname').value.trim().toLowerCase(), port: Number($('#family-port').value)});
  });
  $('#access-user-form').addEventListener('submit', event => {
    event.preventDefault(); const role = $('#access-user-role').value;
    if (role === 'owner' && !confirm('Dieser persönliche Besitzerzugang darf alle Server konfigurieren und weitere Personen freigeben. Speichern?')) return;
    save('/api/access/users', {...(editing ? {id: editing.id, revision: editing.revision} : {}),
      login: $('#access-user-login').value, name: $('#access-user-name').value, role,
      server_ids: role === 'owner' ? null : [...document.querySelectorAll('#access-server-choice input:checked')].map(input => input.value), enabled: $('#access-user-enabled').checked});
  });
}
