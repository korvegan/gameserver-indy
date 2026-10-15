import {$, notice} from './dom.js';
import {state} from './state.js';

const fieldPath = field => field.path || [field.key];
const fieldValue = (config, field) => fieldPath(field).reduce((value, key) => value?.[key], config) ?? field.default;

export function clearConfig() {
  $('#json-editor').value = '';
  $('#game-fields').replaceChildren();
  $('#groups').replaceChildren();
  $('#unsaved').hidden = true;
}

export function populateConfig(value, schema) {
  state.config = structuredClone(value);
  state.schema = schema;
  clearConfig();
  if (!value) return;
  for (const field of schema.fields) {
    const current = fieldValue(state.config, field);
    const label = document.createElement('label');
    if (field.wide) label.className = 'wide';
    label.append(document.createTextNode(field.label));
    const input = document.createElement(field.type === 'select' ? 'select' : 'input');
    input.name = field.key;
    if (field.type === 'select') {
      for (const [value, text] of Object.entries(field.options)) {
        const option = document.createElement('option'); option.value = value; option.textContent = text; input.append(option);
      }
      if (current && !Object.hasOwn(field.options, current)) {
        const option = document.createElement('option'); option.value = current; option.textContent = current; input.append(option);
      }
    } else {
      input.type = field.type; input.autocomplete = field.type === 'password' ? 'new-password' : 'off';
    }
    if (field.min !== undefined) input.min = field.min;
    if (field.max !== undefined) input.max = field.max;
    input.required = Boolean(field.required);
    if (field.type === 'checkbox') input.checked = Boolean(current);
    else input.value = current ?? '';
    if (field.lock_with_external_launcher && state.snapshot?.status.external_launcher) input.readOnly = true;
    label.append(input); $('#game-fields').append(label);
    if (field.help) {
      const help = document.createElement('small'); help.className = 'field-help'; help.textContent = field.help;
      help.id = `field-help-${$('#game-fields').children.length}`;
      input.setAttribute('aria-describedby', help.id); label.append(help);
    }
  }
  $('#roles-section').hidden = !schema.role_groups;
  (state.config.userGroups || []).forEach((group, index) => {
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
  $('#json-editor').value = JSON.stringify(state.config, null, 2);
}

export function fromForm() {
  const result = structuredClone(state.config);
  for (const field of state.schema.fields) {
    const input = $('#config-form').elements.namedItem(field.key);
    const path = fieldPath(field), key = path.at(-1);
    let target = result;
    for (const parent of path.slice(0, -1)) target = target[parent] ??= {};
    target[key] = field.type === 'checkbox' ? input.checked
      : field.type === 'number' && field.value_type !== 'string' ? Number(input.value) : input.value;
  }
  for (const input of document.querySelectorAll('[data-group]')) result.userGroups[Number(input.dataset.group)].password = input.value;
  return result;
}

export function bindForms(mutate) {
  $('#config-form').addEventListener('input', event => {
    state.dirty = true; $('#unsaved').hidden = false;
    if (event.target.id === 'json-editor') state.rawDirty = true;
    else if (!state.rawDirty) $('#json-editor').value = JSON.stringify(fromForm(), null, 2);
  });
  $('#config-form').addEventListener('submit', event => {
    event.preventDefault();
    if (state.rawDirty) return notice('Im JSON-Editor liegen Änderungen. Bitte „JSON prüfen und speichern“ verwenden.', true);
    mutate('/api/config', {config: fromForm()});
  });
  $('#save-json').addEventListener('click', () => {
    try { mutate('/api/config', {config: JSON.parse($('#json-editor').value)}); }
    catch { notice('Das JSON enthält einen Syntaxfehler. Bitte die Eingabe prüfen.', true); }
  });
  $('#discord-form').addEventListener('input', () => { state.discordDirty = true; state.discordRevision++; });
  $('#discord-form').addEventListener('submit', event => {
    event.preventDefault();
    mutate('/api/discord/settings', {webhook_url: $('#webhook').value, auto_notify: $('#auto-notify').checked, clear_webhook: $('#clear-webhook').checked});
  });
  $('#discord-message').addEventListener('input', () => {
    state.messageDirty = true;
    state.messageRevision++;
    $('#message-counter').textContent = `${$('#discord-message').value.length} / 2000 Zeichen`;
  });
  $('#message-form').addEventListener('submit', event => { event.preventDefault(); mutate('/api/discord/send', {message: $('#discord-message').value}); });
  $('#profile-game').addEventListener('change', () => {
    $('#profile-directory').value = $('#profile-game').selectedOptions[0].dataset.directory;
    $('#profile-launcher').disabled = $('#profile-game').selectedOptions[0].dataset.launcher !== 'true';
  });
  $('#profile-form').addEventListener('input', () => { state.profileDirty = true; });
  $('#profile-form').addEventListener('submit', event => {
    event.preventDefault();
    mutate('/api/servers', {id: $('#profile-id').value, game: $('#profile-game').value, label: $('#profile-label').value,
      server_directory: $('#profile-directory').value, config_path: $('#profile-config').value,
      launcher_script: $('#profile-game').selectedOptions[0].dataset.launcher === 'true' ? $('#profile-launcher').value : ''});
  });
  window.addEventListener('beforeunload', event => {
    if (state.dirty || state.discordDirty || state.messageDirty || state.profileDirty || state.communityDirty || state.accessDirty) { event.preventDefault(); event.returnValue = ''; }
  });
}
