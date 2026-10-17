import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';

let editing, eventId, image, preview, imageRequest = 0, openRequest = 0, processing = false, saving = false, saved, opening;
const editor = () => $('#moment-editor');

function instances(selected = '') {
  const select = $('#moment-instance');
  select.replaceChildren(Object.assign(element('option', 'Ohne Serverzuordnung'), {value: ''}));
  for (const profile of state.catalog.servers.filter(item => item.game === $('#moment-game').value)) {
    select.append(Object.assign(element('option', profile.label), {value: profile.id}));
  }
  select.value = selected;
}

function clearPreview() {
  if (preview) URL.revokeObjectURL(preview);
  preview = undefined; image = undefined;
  $('#moment-preview').hidden = true; $('#moment-preview').removeAttribute('src');
}

export async function openMoment(event) {
  if (!can('community.write') || saving) return;
  if (editor().open && state.communityDirty && !confirm('Ungespeicherten Chronikentwurf verwerfen?')) return;
  opening?.();
  const generation = ++openRequest, epoch = state.navigationEpoch;
  try {
    const [options, profiles] = await Promise.all([api('/api/community/options'),api('/api/community/members')]);
    if (generation !== openRequest || epoch !== state.navigationEpoch || state.view !== 'community') return;
    editing = event; eventId = event?.id || `moment-${crypto.randomUUID()}`;
    $('#moment-form').reset(); clearPreview(); imageRequest++; processing = false;
    $('#moment-error').textContent = ''; $('#moment-image-state').textContent = '';
    const select = $('#moment-game'); select.replaceChildren();
    for (const game of options.games) select.append(Object.assign(element('option', game.label), {value: game.id}));
    select.value = event?.game_id || state.catalog.servers.find(item => item.id === state.selectedServer)?.game || options.games[0]?.id;
    select.disabled = Boolean(event); instances(event?.instance_id || (!event ? state.selectedServer : ''));
    $('#moment-instance').disabled = Boolean(event);
    $('#moment-campaigns').replaceChildren(...options.campaigns.map(item => Object.assign(element('option'), {value: item.label})));
    for (const [id, value] of Object.entries({title: event?.title || '', description: event?.description || '',
      campaign: event?.campaign_label || event?.campaign_id || '', badge: event?.badge_label || event?.badge_id || '',
      members: event?.member_ids.filter(id => !profiles.members.some(member=>member.id===id)).map(id => event.member_names?.[id] || id).join('\n') || ''})) $(`#moment-${id}`).value = value;
    const participants = $('#moment-participants'); participants.replaceChildren();
    for (const member of profiles.members.filter(member => member.enabled || event?.member_ids.includes(member.id))) {
      const label = element('label',undefined,'checkbox-label');
      const input = element('input'); input.type = 'checkbox'; input.value = member.id; input.checked = event?.member_ids.includes(member.id) || false;
      label.append(input,document.createTextNode(event?.member_names?.[member.id] || member.name)); participants.append(label);
    }
    const date = event?.occurred_at ? new Date(event.occurred_at) : event ? null : new Date();
    $('#moment-date').value = date && !Number.isNaN(date.getTime()) ? new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 16) : '';
    $('#moment-editor-title').textContent = event ? 'Einen Moment korrigieren' : 'Einen Moment festhalten';
    $('#moment-reason-label').hidden = !event; $('#moment-reason').required = Boolean(event);
    $('#remove-moment').hidden = !event; $('#moment-clear-image').parentElement.hidden = !event?.media_id;
    if (event?.media_id) {
      $('#moment-preview').src = `/api/community/media?event=${encodeURIComponent(event.id)}&revision=${event.revision}`;
      $('#moment-preview').hidden = false;
    }
    $('#save-moment').disabled = false; state.communityDirty = false;
    $('#moment-detail').close(); editor().showModal();
  } catch (error) { notice(error.message, true); }
}

async function prepareImage(file) {
  if (!['image/png', 'image/jpeg', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) throw new Error('Bitte PNG, JPEG oder WebP mit höchstens 10 MiB wählen.');
  const bitmap = await createImageBitmap(file);
  try {
    if (bitmap.width * bitmap.height > 40 * 1024 * 1024) throw new Error('Dieses Bild hat zu viele Pixel. Bitte vorher verkleinern.');
    let side = 1280, blob;
    do {
      const ratio = Math.min(1, side / Math.max(bitmap.width, bitmap.height));
      const canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(bitmap.width * ratio)); canvas.height = Math.max(1, Math.round(bitmap.height * ratio));
      const context = canvas.getContext('2d'); context.fillStyle = '#101813'; context.fillRect(0, 0, canvas.width, canvas.height);
      context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
      blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/jpeg', .78));
      if (!blob) throw new Error('Das Bild konnte nicht als Vorschau gespeichert werden.');
      side *= .75;
    } while (blob.size > 256 * 1024 && side > 200);
    if (blob.size > 256 * 1024) throw new Error('Die Bildvorschau ist zu groß. Bitte ein kleineres Bild wählen.');
    const encoded = await new Promise((resolve, reject) => {
      const reader = new FileReader(); reader.onload = () => resolve(reader.result.split(',')[1]);
      reader.onerror = () => reject(new Error('Bilddatei konnte nicht gelesen werden.')); reader.readAsDataURL(blob);
    });
    return {encoded, blob};
  } finally { bitmap.close(); }
}

async function submit(remove = false) {
  if (saving || processing) return;
  if (remove && (!$('#moment-reason').reportValidity() || !confirm('Diesen Moment aus der Chronik entfernen? Die bisherigen Versionen bleiben im Archiv nachvollziehbar.'))) return;
  saving = true; $('#moment-fields').disabled = true; $('#cancel-moment').disabled = true; $('#moment-error').textContent = '';
  try {
    const date = $('#moment-date').value;
    const body = {id: eventId, revision: editing ? editing.revision + 1 : 1, reason: $('#moment-reason').value};
    if (remove) body.remove = true;
    else Object.assign(body, {title: $('#moment-title').value, description: $('#moment-description').value,
      game_id: $('#moment-game').value, instance_id: $('#moment-instance').value, campaign: $('#moment-campaign').value,
      members: $('#moment-members').value.split('\n').map(name => name.trim()).filter(Boolean), badge: $('#moment-badge').value,
      participant_ids: [...$('#moment-participants input:checked')].map(input=>input.value),
      occurred_at: date ? new Date(date).toISOString() : '', image, clear_image: $('#moment-clear-image').checked});
    const result = await api('/api/community', body);
    state.communityDirty = false; editor().close(); clearPreview();
    notice(remove ? 'Moment entfernt. Die bisherigen Versionen bleiben archiviert.' : result.message);
    await saved(remove ? null : result.event_id, !editing && !remove);
  } catch (error) { $('#moment-error').textContent = error.message; }
  finally { saving = false; $('#moment-fields').disabled = false; $('#cancel-moment').disabled = false; }
}

export function bindMomentForm(onSaved, onOpening) {
  saved = onSaved; opening = onOpening;
  $('#new-moment').addEventListener('click', () => openMoment());
  $('#moment-game').addEventListener('change', () => instances());
  $('#moment-form').addEventListener('input', () => { state.communityDirty = true; });
  $('#moment-form').addEventListener('submit', event => { event.preventDefault(); submit(); });
  $('#moment-clear-image').addEventListener('change', () => {
    imageRequest++; clearPreview(); $('#moment-image').value = ''; processing = false; $('#save-moment').disabled = false;
    if (!$('#moment-clear-image').checked && editing?.media_id) {
      $('#moment-preview').src = `/api/community/media?event=${encodeURIComponent(editing.id)}&revision=${editing.revision}`;
      $('#moment-preview').hidden = false;
    }
    $('#moment-image-state').textContent = $('#moment-clear-image').checked ? 'Der Screenshot wird beim Speichern entfernt.' : '';
  });
  $('#remove-moment').addEventListener('click', () => submit(true));
  const close = () => {
    if (saving || (state.communityDirty && !confirm('Ungespeicherten Chronikentwurf verwerfen?'))) return;
    state.communityDirty = false; imageRequest++; clearPreview(); editor().close();
  };
  $('#cancel-moment').addEventListener('click', close);
  editor().addEventListener('cancel', event => { event.preventDefault(); close(); });
  $('#moment-image').addEventListener('change', async () => {
    const generation = ++imageRequest, file = $('#moment-image').files[0];
    clearPreview(); processing = Boolean(file); $('#save-moment').disabled = processing;
    if (!file) return;
    $('#moment-image-state').textContent = 'Bildvorschau wird vorbereitet …';
    try {
      const result = await prepareImage(file);
      if (generation !== imageRequest || !editor().open) return;
      image = result.encoded; preview = URL.createObjectURL(result.blob);
      $('#moment-preview').src = preview; $('#moment-preview').hidden = false; $('#moment-clear-image').checked = false;
      $('#moment-image-state').textContent = `Vorschau bereit · ${Math.ceil(result.blob.size / 1024)} KiB`;
    } catch (error) {
      if (generation === imageRequest) { $('#moment-image-state').textContent = error.message; $('#moment-image').value = ''; }
    } finally { if (generation === imageRequest) { processing = false; $('#save-moment').disabled = false; } }
  });
}
