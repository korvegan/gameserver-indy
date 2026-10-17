import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';
import {bindMomentForm, openMoment} from './chronicle-form.js';
import {bindStory, cancelStoryFocus, focusScene, loadStory} from './story.js';
import {openCommunityPublishing} from './community-discord.js';

let request = 0, detailRequest = 0, nextCursor, token, currentDetail;
let filters = {}, events = new Map(), labels = new Map();
const sourceNames = {manual: 'MANUELL BESTÄTIGT', test: 'TESTMOMENT', 'verified-game': 'SPIELNACHWEIS', 'steam-import': 'HISTORISCHER STEAM-IMPORT'};
const query = extra => new URLSearchParams({...filters, ...extra}).toString();
export const isCommunityHash = () => /^#(scene-|moment-)/.test(location.hash);

function awards(event) {
  const list = element('ul', undefined, 'badge-list');
  for (const award of event.awards) list.append(element('li', `✦ ${event.member_names?.[award.member_id] || award.member_id} · ${event.badge_label || award.badge_id}`));
  return list;
}

function context(event) {
  return `${labels.get(event.game_id) || event.game_id}${event.campaign_label || event.campaign_id ? ` · ${event.campaign_label || event.campaign_id}` : ''}`;
}

function image(event) {
  const node = element('img', undefined, 'moment-image');
  node.src = `/api/community/media?event=${encodeURIComponent(event.id)}&revision=${event.revision}`;
  node.alt = `Screenshot zu ${event.title}`; node.loading = 'lazy';
  return node;
}

function render() {
  const chronicle = $('#chronicle');
  for (const node of chronicle.querySelectorAll('[data-event-id]')) if (!events.has(node.dataset.eventId)) node.remove();
  chronicle.querySelector('.empty')?.remove();
  if (!events.size) chronicle.append(element('p', 'Hier beginnt eure Geschichte. Haltet euren ersten gemeinsamen Moment fest oder passt die Filter an.', 'empty'));
  let index = 0;
  for (const event of events.values()) {
    let card = [...chronicle.children].find(node => node.dataset.eventId === event.id);
    if (!card) { card = element('article', undefined, 'panel chronicle-card'); card.dataset.eventId = event.id; }
    if (card.dataset.revision !== String(event.revision)) {
      card.dataset.revision = event.revision;
      const date = event.occurred_at || event.recorded_at;
      const timestamp = element('time', `${event.occurred_at ? 'Erlebt' : 'Erfasst'}: ${new Date(date).toLocaleString('de-DE')}`, 'muted'); timestamp.dateTime = date;
      card.replaceChildren(element('p', sourceNames[event.source] || 'QUELLE UNBEKANNT', 'eyebrow'), element('h3', event.title),
        element('p', context(event), 'muted'), timestamp, element('p', event.description || '', 'muted'));
      if (event.media_id) card.append(image(event));
      card.append(awards(event));
      const actions = element('div', undefined, 'chronicle-actions');
      const details = element('a', 'Details', 'button secondary'); details.href = `#moment-${event.id}`;
      details.addEventListener('click', click => { click.preventDefault(); showDetail(event.id); });
      const show = element('button', 'Szene ansehen', 'button secondary'); show.addEventListener('click', () => focusScene(event));
      actions.append(details, show); card.append(actions);
    }
    if (chronicle.children[index] !== card) chronicle.insertBefore(card, chronicle.children[index] || null);
    index++;
  }
  $('#chronicle-more').hidden = !nextCursor;
  $('#chronicle-export').href = '/api/community/export?' + query();
}

async function options(current) {
  const data = await api('/api/community/options');
  if (!current()) return;
  labels = new Map(data.games.map(item => [item.id, item.label]));
  for (const [name, entries] of [['game', data.games], ['campaign', data.campaigns], ['member', data.members]]) {
    const select = $(`#chronicle-filters [name="${name}"]`), previous = select.value;
    select.replaceChildren(select.options[0]);
    const unique = new Map(entries.map(item => [item.id, item.label || item.name || item.id]));
    for (const [id, label] of unique) select.append(Object.assign(element('option', label), {value: id}));
    select.value = previous;
  }
}

export async function loadCommunity(append = false, resetChapter = false) {
  cancelStoryFocus();
  append = append === true;
  const generation = ++request, epoch = state.navigationEpoch;
  const current = () => generation === request && epoch === state.navigationEpoch && state.view === 'community';
  $('#chronicle-more').disabled = true;
  $('#chronicle-filters').setAttribute('aria-busy', 'true');
  try {
    const result = await api('/api/community?' + query(append && nextCursor ? {cursor: nextCursor} : {}));
    if (!current()) return;
    await options(current);
    if (!current()) return;
    if (!append) events = new Map();
    for (const event of result.events) events.set(event.id, event);
    nextCursor = result.next_cursor;
    $('#community-state').textContent = result.total ? `${result.total} gemeinsame Momente. ${events.size} geladen. Eure Geschichte bleibt erhalten.` : 'Noch keine gemeinsamen Momente in dieser Auswahl.';
    if (!append) { token = result.change_token; $('#community-news').hidden = true; }
    else if (result.change_token !== token) announceChanges();
    render();
    if (!append) await loadStory(filters, labels, current, resetChapter ? null : undefined);
    if (!current()) return;
    if (location.hash.startsWith('#moment-')) await showDetail(location.hash.slice(8), false);
    if (location.hash.startsWith('#scene-')) {
      const sceneId = location.hash.slice(1), eventId = sceneId.slice(6);
      const {event} = await api('/api/community/event?id=' + encodeURIComponent(eventId));
      if (!current() || location.hash !== '#' + sceneId) return;
      await focusScene(event, false);
    }
  } catch (error) {
    if (current()) { $('#community-state').textContent = 'Die Chronik konnte nicht aktualisiert werden. Bereits geladene Momente bleiben sichtbar.'; notice(error.message, true); }
  } finally {
    if (current()) { $('#chronicle-more').disabled = false; $('#chronicle-filters').setAttribute('aria-busy', 'false'); }
  }
}

export async function showDetail(id, updateHash = true) {
  cancelStoryFocus();
  const generation = ++detailRequest, epoch = state.navigationEpoch;
  try {
    const data = await api('/api/community/event?id=' + encodeURIComponent(id));
    if (generation !== detailRequest || epoch !== state.navigationEpoch || state.view !== 'community' || $('#moment-editor').open) return;
    currentDetail = data.event;
    const event = data.event, body = $('#moment-detail-body');
    $('#moment-detail-title').textContent = event.removed ? `${event.title} · entfernt` : event.title;
    body.replaceChildren(element('p', sourceNames[event.source] || event.source, 'eyebrow'), element('p', context(event), 'muted'),
      element('p', event.description || ''), element('p', event.occurred_at ? `Erlebt: ${new Date(event.occurred_at).toLocaleString('de-DE')}` : 'Ursprünglicher Zeitpunkt unbekannt.'),
      element('p', `Erfasst / korrigiert: ${new Date(event.recorded_at).toLocaleString('de-DE')} · Version ${event.revision}`, 'muted'), awards(event));
    if (event.media_id) body.append(image(event));
    if (event.author_name) body.append(element('p',`Festgehalten von ${event.author_name} · Beteiligte siehe Auszeichnungen`,'muted'));
    if (can('community.export')) {
      const publish = element('button','In Discord teilen','button secondary'); publish.type = 'button';
      publish.addEventListener('click',()=>openCommunityPublishing(event)); body.append(publish);
    }
    const link = element('a', 'Dauerhafter Link zu diesem Moment', 'path'); link.href = `#moment-${event.id}`; body.append(link);
    if (!event.removed) {
      const replay = element('button', 'Moment noch einmal feiern', 'button secondary');
      replay.addEventListener('click', () => {
        $('#moment-detail').close(); focusScene(event);
      }); body.append(replay);
    }
    if (data.history.length) {
      const historyList = element('details', undefined, 'chronicle-history'); historyList.append(element('summary', `Versionsverlauf (${data.history.length})`));
      for (const revision of data.history) {
        const row = element('article', undefined, 'chronicle-history');
        row.append(element('strong', `Version ${revision.revision}${revision.removed ? ' · entfernt' : ''} · ${revision.title}`), element('p', revision.correction_reason || 'Ursprünglicher Eintrag'),
          element('p', `${new Date(revision.recorded_at).toLocaleString('de-DE')} · ${revision.actor_id || 'Quelle: ' + revision.source}`, 'muted'), element('p', revision.description || ''), awards(revision));
        historyList.append(row);
      } body.append(historyList);
    }
    $('#edit-moment').hidden = !can('community.write') || event.source !== 'manual' || Boolean(event.removed) || Boolean(event.challenge_id);
    if (updateHash) history.replaceState(null, '', '#moment-' + id);
    if (!$('#moment-detail').open) $('#moment-detail').showModal();
  } catch (error) { if (generation === detailRequest && state.view === 'community') notice(error.message, true); }
}

export function bindCommunity() {
  bindStory(showDetail, () => { detailRequest++; });
  bindMomentForm(async (id, created) => {
    const epoch = state.navigationEpoch, draft = detailRequest;
    if (created) {
      filters = {};
      for (const input of $('#chronicle-filters').querySelectorAll('select,input')) input.value = '';
      history.replaceState(null, '', location.pathname + location.search);
    }
    await loadCommunity(false, created);
    if (!id || state.view !== 'community') return;
    if (created) {
      const {event} = await api('/api/community/event?id=' + encodeURIComponent(id));
      if (state.view === 'community' && epoch === state.navigationEpoch && draft === detailRequest && !$('#moment-editor').open) await focusScene(event);
    } else await showDetail(id);
  }, () => { detailRequest++; cancelStoryFocus(); });
  $('#chronicle-more').addEventListener('click', () => loadCommunity(true));
  $('#close-moment-detail').addEventListener('click', () => $('#moment-detail').close());
  $('#moment-detail').addEventListener('close', () => { if (location.hash.startsWith('#moment-')) history.replaceState(null, '', location.pathname + location.search); });
  $('#edit-moment').addEventListener('click', () => openMoment(currentDetail));
  $('#chronicle-filters').addEventListener('submit', event => {
    event.preventDefault(); filters = Object.fromEntries([...new FormData(event.target)].filter(([, value]) => value)); loadCommunity(false, true);
  });
  $('#chronicle-filters').addEventListener('reset', () => { filters = {}; loadCommunity(false, true); });
  setInterval(async () => {
    if (document.hidden || state.view !== 'community' || !token || $('#moment-editor').open || $('#moment-detail').open) return;
    const generation = request, epoch = state.navigationEpoch;
    try {
      const result = await api('/api/community?' + query({limit: 1}));
      if (generation !== request || epoch !== state.navigationEpoch || state.view !== 'community') return;
      if (result.change_token !== token) {
        announceChanges();
      }
    } catch { /* Keep the last good reading position; explicit refresh reports errors. */ }
  }, 30000);
}

function announceChanges() {
  $('#community-news').textContent = 'Neue oder korrigierte Momente sind verfügbar. Mit „Chronik aktualisieren“ laden, wenn du bereit bist.';
  $('#community-news').hidden = false;
}
