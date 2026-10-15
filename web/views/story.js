import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';
import {landscape, illustration, WIDTH, HEIGHT} from './story-art.js';

let generation = 0, focusRequest = 0, chapter, root, active, animation, scale = 1, center = [WIDTH / 2, HEIGHT / 2];
let filters = {}, labels = new Map(), chapters = [], moments = new Map(), openDetail, drag, suppressClick = false;
const reduced = matchMedia('(prefers-reduced-motion: reduce)');
const view = () => $('#tapestry-viewport');
const isCurrent = (id, epoch) => id === generation && epoch === state.navigationEpoch && state.view === 'community';

function stop(message = '') {
  animation?.cancel(); animation = undefined;
  active?.classList.remove('scene-revealing'); active = undefined;
  $('#story-pause').hidden = true; $('#story-skip').hidden = true;
  if (message) $('#story-status').textContent = message;
}

function frame() {
  const width = WIDTH / scale, height = HEIGHT / scale;
  center[0] = Math.max(width / 2, Math.min(WIDTH - width / 2, center[0]));
  center[1] = Math.max(height / 2, Math.min(HEIGHT - height / 2, center[1]));
  root?.setAttribute('viewBox', `${center[0] - width / 2} ${center[1] - height / 2} ${width} ${height}`);
  view().classList.toggle('zoomed', scale > 1);
  $('#story-zoom-level').textContent = `${Math.round(scale * 100)} %`;
  $('#story-zoom-out').disabled = scale <= 1; $('#story-zoom-in').disabled = scale >= 3;
}
function zoom(value) { scale = Math.max(1, Math.min(3, value)); frame(); }
function fit() { scale = 1; center = [WIDTH / 2, HEIGHT / 2]; frame(); }

function pause() {
  if (!animation || animation.playState !== 'running') return;
  animation.pause();
  $('#story-pause').textContent = 'Enthüllung fortsetzen';
  $('#story-status').textContent = 'Enthüllung pausiert. Das Standbild bleibt erhalten.';
}

function reveal(node) {
  stop();
  if (reduced.matches) { $('#story-status').textContent = 'Gemeinsamer Moment festgehalten. Standbild für reduzierte Bewegung.'; return; }
  active = node; node.classList.add('scene-revealing');
  animation = node.querySelector('.scene-art').animate([
    {opacity: .15, transform: 'translateY(12px)', filter: 'brightness(1.6)'},
    {opacity: 1, transform: 'translateY(0)', filter: 'brightness(1)', offset: .72},
    {opacity: 1, transform: 'translateY(0)', filter: 'brightness(1)'}
  ], {duration: 4000, easing: 'ease-out'});
  animation.onfinish = () => stop('Ein weiterer Teil eurer Geschichte. Für immer an seinem Platz.');
  $('#story-pause').textContent = 'Enthüllung pausieren'; $('#story-pause').hidden = false; $('#story-skip').hidden = false;
  $('#story-status').textContent = 'Ein gemeinsamer Moment wird Teil eurer Geschichte …';
  if (document.hidden || state.view !== 'community') pause('hidden');
}

function render(data) {
  if (!root || chapter !== data.chapter) {
    stop(); chapter = data.chapter; root = landscape(chapter);
    view().replaceChildren(root); fit();
  }
  moments = new Map(data.events.map(event => [event.id, event])); chapters = data.chapters;
  for (const node of root.querySelectorAll('.tapestry-scene')) if (!moments.has(node.dataset.eventId)) { if (node === active) stop(); node.remove(); }
  for (const event of data.events) {
    let node = root.querySelector(`#${CSS.escape(event.scene.id)}`);
    if (!node || node.dataset.revision !== String(event.revision)) {
      const replacement = illustration(event, labels.get(event.game_id) || event.game_id);
      replacement.addEventListener('click', click => {
        click.preventDefault(); if (suppressClick) return;
        stop(); openDetail(event.id);
      });
      // SVG links support Enter natively; don't install a second keyboard action.
      if (node) { if (node === active) stop(); node.replaceWith(replacement); } else root.append(replacement);
      node = replacement;
    }
  }
  for (const node of root.querySelectorAll('[data-empty-slot]')) node.setAttribute('visibility', data.events.some(e => e.scene.slot === Number(node.dataset.emptySlot)) ? 'hidden' : 'visible');
  const select = $('#story-chapter');
  const choices = chapters.some(item => item.id === chapter) ? chapters : [...chapters, {id: chapter, count: 0}].sort((a, b) => a.id - b.id);
  select.replaceChildren(...choices.map(item => Object.assign(element('option', `Kapitel ${item.id + 1} · ${item.count} ${item.count === 1 ? 'Moment' : 'Momente'}`), {value: item.id})));
  select.value = chapter;
  $('#story-previous').disabled = !chapters.some(item => item.id < chapter);
  $('#story-next').disabled = !chapters.some(item => item.id > chapter);
  const list = $('#story-scenes'); list.replaceChildren();
  for (const event of data.events) {
    const item = element('li'), link = element('a', event.title); link.href = '#moment-' + event.id;
    link.addEventListener('click', click => { click.preventDefault(); openDetail(event.id); });
    item.append(link, element('span', ` · ${labels.get(event.game_id) || event.game_id} · ${event.member_ids.map(id => event.member_names?.[id] || id).join(', ')} · ${event.source === 'manual' ? 'manuell bestätigt' : event.source}`)); list.append(item);
  }
  if (!data.events.length) list.append(element('li', 'Noch kein Moment in diesem Kapitel und dieser Auswahl.'));
  $('#story-status').textContent = data.events.length ? `Kapitel ${chapter + 1}. Szenen öffnen eure Erinnerungen. Die Textansicht steht unter dem Bild.` : 'Eure Landschaft wartet auf den ersten gemeinsamen Moment.';
}

export async function loadStory(nextFilters, nextLabels, current = () => true, selected = chapter) {
  filters = {...nextFilters}; labels = nextLabels;
  const id = ++generation, epoch = state.navigationEpoch;
  $('#story-board').setAttribute('aria-busy', 'true');
  try {
    const args = {...filters}; if (selected !== undefined && selected !== null) args.chapter = selected;
    const data = await api('/api/community/story?' + new URLSearchParams(args));
    if (!isCurrent(id, epoch) || !current()) return false;
    render(data); return true;
  } catch (error) { if (isCurrent(id, epoch) && current()) notice(error.message, true); return false; }
  finally { if (id === generation) $('#story-board').setAttribute('aria-busy', 'false'); }
}

export async function focusScene(event, celebrate = true) {
  const request = ++focusRequest, epoch = state.navigationEpoch;
  const current = () => request === focusRequest && epoch === state.navigationEpoch && state.view === 'community' && !$('#moment-editor').open && !$('#moment-detail').open;
  if (chapter !== event.scene.chapter || !document.getElementById(event.scene.id)) {
    if (!await loadStory(filters, labels, current, event.scene.chapter)) return;
  }
  if (!current()) return;
  const node = document.getElementById(event.scene.id);
  if (!node) { notice('Dieser Moment liegt außerhalb der gewählten Filter. Bitte die Filter zurücksetzen.'); return; }
  fit(); node.focus({preventScroll: true}); node.scrollIntoView({behavior: 'instant', block: 'nearest'});
  if (celebrate) reveal(node);
}

export function cancelStoryFocus() { focusRequest++; }

function exportSVG() {
  if (!root) return;
  const copy = root.cloneNode(true); copy.setAttribute('viewBox', `0 0 ${WIDTH} ${HEIGHT}`);
  copy.setAttribute('width', WIDTH); copy.setAttribute('height', HEIGHT);
  copy.querySelectorAll('.scene-focus-frame').forEach(node => node.remove());
  copy.querySelectorAll('[class]').forEach(node => node.removeAttribute('class'));
  // A still has no external resources, event handlers, script or remote links.
  copy.querySelectorAll('a').forEach(node => { node.removeAttribute('href'); node.removeAttribute('tabindex'); });
  const url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(copy)], {type: 'image/svg+xml'}));
  const link = element('a'); link.href = url; link.download = `sturmklingen-kapitel-${chapter + 1}.svg`; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

export function bindStory(details) {
  openDetail = details;
  $('#story-chapter').addEventListener('change', event => { cancelStoryFocus(); loadStory(filters, labels, () => true, Number(event.target.value)); });
  for (const [id, direction] of [['story-previous', -1], ['story-next', 1]]) $(`#${id}`).addEventListener('click', () => {
    const target = direction < 0 ? chapters.filter(item => item.id < chapter).at(-1) : chapters.find(item => item.id > chapter);
    if (target) { cancelStoryFocus(); loadStory(filters, labels, () => true, target.id); }
  });
  $('#story-zoom-in').addEventListener('click', () => zoom(scale + .5));
  $('#story-zoom-out').addEventListener('click', () => zoom(scale - .5));
  $('#story-fit').addEventListener('click', fit);
  $('#story-export').addEventListener('click', exportSVG);
  $('#story-skip').addEventListener('click', () => stop('Enthüllung übersprungen. Euer Moment ist im Bild gespeichert.'));
  $('#story-pause').addEventListener('click', () => {
    if (!animation) return;
    if (animation.playState === 'running') pause();
    else { animation.play(); $('#story-pause').textContent = 'Enthüllung pausieren'; $('#story-status').textContent = 'Enthüllung wird fortgesetzt …'; }
  });
  reduced.addEventListener('change', () => { if (reduced.matches) stop('Standbild für reduzierte Bewegung.'); });
  const hidden = () => { if (document.hidden || state.view !== 'community') pause('hidden'); };
  document.addEventListener('visibilitychange', hidden);
  new MutationObserver(hidden).observe($('#community'), {attributes: true, attributeFilter: ['hidden']});
  new IntersectionObserver(entries => { if (!entries[0].isIntersecting) pause('offscreen'); }, {threshold: 0}).observe(view());
  view().addEventListener('keydown', event => {
    if (event.target !== root || scale <= 1 || !['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) return;
    event.preventDefault(); center[event.key === 'ArrowLeft' || event.key === 'ArrowRight' ? 0 : 1] += (event.key === 'ArrowLeft' || event.key === 'ArrowUp' ? -1 : 1) * 60 / scale; frame();
  });
  view().addEventListener('pointerdown', event => {
    suppressClick = false;
    if (!root || scale <= 1 || event.button !== 0) return;
    drag = {pointer: event.pointerId, x: event.clientX, y: event.clientY, center: [...center], matrix: root.getScreenCTM().inverse()};
  });
  view().addEventListener('pointermove', event => {
    if (!drag || event.pointerId !== drag.pointer) return;
    const dx = event.clientX - drag.x, dy = event.clientY - drag.y;
    if (!suppressClick && Math.hypot(dx, dy) < 6) return;
    suppressClick = true; view().setPointerCapture(event.pointerId);
    center = [drag.center[0] - dx * drag.matrix.a, drag.center[1] - dy * drag.matrix.d]; frame();
  });
  const release = () => { drag = undefined; setTimeout(() => { suppressClick = false; }, 0); };
  view().addEventListener('pointerup', release); view().addEventListener('pointercancel', release);
}
