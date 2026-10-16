import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';

let data, groups = [], groupsLoaded = false, busy = false, loading = false;
let generation = 0, draftRevision = 0, settingsRevision = 0, qrURL, draftGroup;
const labels = {NOT_INSTALLED: 'Dienst noch nicht eingerichtet', UNAVAILABLE: 'Dienst nicht erreichbar',
  STOPPED: 'Noch nicht verknüpft', STARTING: 'Verbindung wird vorbereitet', SCAN_QR_CODE: 'QR-Code mit dem Handy scannen',
  WORKING: 'WhatsApp verbunden', FAILED: 'Verknüpfung prüfen', PASSKEY_REQUIRED: 'Bestätigung auf dem Handy erforderlich',
  PASSKEY_CONFIRMATION_REQUIRED: 'Bestätigung auf dem Handy erforderlich'};
const local = () => state.principal?.id === 'local-owner' && state.principal?.permissions.includes('discord.write');

function clearQR() {
  if (qrURL) URL.revokeObjectURL(qrURL);
  qrURL = undefined; $('#wa-qr').removeAttribute('src'); $('#wa-qr').hidden = true;
}

function controls() {
  $('#wa-connect').disabled = !local() || busy || !data || ['NOT_INSTALLED', 'WORKING', 'STARTING', 'SCAN_QR_CODE'].includes(data.state);
  $('#wa-disconnect').disabled = !local() || busy || !data || ['NOT_INSTALLED', 'UNAVAILABLE', 'STOPPED'].includes(data.state);
  $('#wa-refresh').disabled = busy || loading;
  const conflict = state.whatsappDirty && data && settingsRevision !== data.settings.revision;
  $('#wa-save').disabled = !local() || busy || loading || !data || conflict;
  $('#wa-discard').hidden = !state.whatsappDirty;
  $('#wa-discard').disabled = busy || loading;
  $('#wa-conflict').hidden = !conflict;
  for (const button of document.querySelectorAll('[data-wa-send]')) {
    const preview = data?.previews.find(item => item.server_id === button.dataset.waSend);
    button.disabled = !local() || busy || loading || state.whatsappDirty || data?.state !== 'WORKING'
      || !data.settings.group_id || !data.settings.server_ids.includes(preview.server_id)
      || preview.readiness.state !== 'ready' || !preview.message || Boolean(preview.delivery);
  }
}

function renderGroups() {
  const selected = state.whatsappDirty ? draftGroup?.id : data.settings.group_id;
  $('#wa-group').replaceChildren(element('option', 'Bitte Zielgruppe auswählen …'));
  $('#wa-group').firstChild.value = '';
  const options = [...groups];
  if (data.settings.group_id && !options.some(group => group.id === data.settings.group_id)) {
    options.push({id: data.settings.group_id, label: data.settings.group_label});
  }
  if (state.whatsappDirty && draftGroup?.id && !options.some(group => group.id === draftGroup.id)) options.push(draftGroup);
  for (const group of options) {
    const option = element('option', `${group.label} · ${group.id.replace('@g.us', '').slice(-6)}`);
    option.value = group.id; $('#wa-group').append(option);
  }
  $('#wa-group').value = selected;
}

function render() {
  $('#wa-state').textContent = labels[data.state] || 'Verbindung prüfen';
  $('#wa-error').textContent = data.error || '';
  if (data.state !== 'SCAN_QR_CODE') clearQR();
  if (!state.whatsappDirty) {
    draftGroup = undefined;
    settingsRevision = data.settings.revision;
    $('#wa-enabled').checked = data.settings.enabled;
  }
  renderGroups();
  const selected = state.whatsappDirty
    ? new Set([...document.querySelectorAll('[data-wa-server]:checked')].map(input => input.dataset.waServer))
    : new Set(data.settings.server_ids);
  const container = $('#wa-servers'); container.replaceChildren();
  for (const preview of data.previews) {
    const row = element('div', undefined, 'profile-entry');
    const label = element('label', undefined, 'checkbox-label');
    const check = element('input'); check.type = 'checkbox'; check.dataset.waServer = preview.server_id;
    check.checked = selected.has(preview.server_id); label.append(check, document.createTextNode(preview.label));
    const delivery = {sent: 'Bereits gemeldet', sending: 'Versand noch nicht bestätigt', uncertain: 'Versand unklar – zuerst in WhatsApp prüfen'}[preview.delivery] || 'Noch nicht gemeldet';
    row.append(label, element('p', `${preview.readiness.label} · ${delivery}`, 'muted'));
    if (preview.message) row.append(element('pre', preview.message, 'wa-preview'));
    if (preview.error) row.append(element('p', preview.error, 'field-help'));
    const button = element('button', 'Bereitschaft jetzt melden', 'button secondary');
    button.type = 'button'; button.dataset.waSend = preview.server_id;
    const targetRevision = data.settings.revision;
    button.addEventListener('click', () => act('/api/whatsapp/send', {server_id: preview.server_id, revision: targetRevision}));
    row.append(button); container.append(row);
  }
  controls();
}

export async function loadWhatsApp(refreshGroups = false) {
  if (!local() || state.view !== 'whatsapp' || busy) return;
  const request = ++generation, epoch = state.navigationEpoch;
  const current = () => request === generation && epoch === state.navigationEpoch && state.view === 'whatsapp';
  loading = true; controls();
  try {
    const result = await api('/api/whatsapp');
    if (!current()) return;
    data = result;
    if (data.state !== 'WORKING') { groups = []; groupsLoaded = false; }
    render();
    if (data.state === 'WORKING' && (refreshGroups || !groupsLoaded)) {
      const result = await api('/api/whatsapp/groups');
      if (!current()) return;
      groups = result.groups; groupsLoaded = true; renderGroups();
    }
    if (data.state === 'SCAN_QR_CODE') {
      const response = await fetch('/api/whatsapp/qr', {cache: 'no-store'});
      if (!response.ok) throw new Error('QR-Code wird erneuert. Bitte Status aktualisieren.');
      const blob = await response.blob();
      if (!current()) return;
      clearQR(); qrURL = URL.createObjectURL(blob); $('#wa-qr').src = qrURL; $('#wa-qr').hidden = false;
    }
  } catch (error) { if (current()) notice(error.message, true); }
  finally { if (request === generation) { loading = false; controls(); } }
}

async function act(path, body = {}, completed) {
  if (busy || !local()) return;
  busy = true; generation++; loading = false; clearQR(); controls();
  const epoch = state.navigationEpoch;
  try {
    const result = await api(path, body);
    completed?.(result);
    if (epoch === state.navigationEpoch && state.view === 'whatsapp') notice(result.message);
  } catch (error) {
    if (epoch === state.navigationEpoch && state.view === 'whatsapp') notice(error.message, true);
  } finally { busy = false; await loadWhatsApp(); controls(); }
}

export function bindWhatsApp() {
  $('#wa-refresh').addEventListener('click', () => loadWhatsApp(true));
  $('#wa-connect').addEventListener('click', () => act('/api/whatsapp/connect'));
  $('#wa-disconnect').addEventListener('click', () => {
    if (confirm('Nur das Verwaltungsgerät von WhatsApp abmelden und automatische Meldungen deaktivieren?')) {
      state.whatsappDirty = false; draftRevision++; groups = []; groupsLoaded = false;
      act('/api/whatsapp/disconnect');
    }
  });
  $('#wa-form').addEventListener('change', () => {
    const id = $('#wa-group').value;
    draftGroup = groups.find(group => group.id === id) || (draftGroup?.id === id ? draftGroup :
      {id, label: data?.settings.group_id === id ? data.settings.group_label : id});
    state.whatsappDirty = true; draftRevision++; controls();
  });
  $('#wa-discard').addEventListener('click', () => {
    if (busy || loading) return;
    state.whatsappDirty = false; draftGroup = undefined; draftRevision++;
    loadWhatsApp(true);
  });
  $('#wa-form').addEventListener('submit', event => {
    event.preventDefault();
    const revision = draftRevision;
    act('/api/whatsapp/settings', {group_id: $('#wa-group').value, enabled: $('#wa-enabled').checked,
      server_ids: [...document.querySelectorAll('[data-wa-server]:checked')].map(input => input.dataset.waServer),
      revision: settingsRevision}, result => {
      settingsRevision = result.revision;
      if (revision === draftRevision) state.whatsappDirty = false;
    });
  });
  setInterval(() => {
    if (!document.hidden && state.view === 'whatsapp' && !busy && !loading && !state.whatsappDirty) loadWhatsApp();
    if (state.view !== 'whatsapp') clearQR();
  }, 10000);
}
