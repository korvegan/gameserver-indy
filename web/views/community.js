import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';

let request = 0, fingerprint = '';
export async function loadCommunity() {
  const generation = ++request;
  try {
    const {events} = await api('/api/community');
    if (generation !== request || state.view !== 'community') return;
    $('#community-state').textContent = events.length ? `${events.length} gemeinsame Momente. Eure Geschichte bleibt erhalten.` : 'Noch keine gemeinsamen Momente gespeichert.';
    if (fingerprint !== JSON.stringify(events)) {
      fingerprint = JSON.stringify(events);
      $('#chronicle').replaceChildren(); $('#story-scenes').replaceChildren();
      for (const event of events) {
        const card = element('article', undefined, 'panel'); card.dataset.eventId = event.id;
        const date = event.occurred_at || event.recorded_at;
        const timestamp = element('time', new Date(date).toLocaleString('de-DE'), 'muted'); timestamp.dateTime = date;
        card.append(element('p', event.source === 'test' ? 'TESTMOMENT' : 'GEMEINSAM ERLEBT', 'eyebrow'),
          element('h3', event.title), timestamp, element('p', event.description || '', 'muted'));
        const awards = element('ul', undefined, 'badge-list');
        for (const award of event.awards) awards.append(element('li', `✦ ${award.member_id} · ${award.badge_id}`));
        card.append(awards);
        const showScene = element('button', 'Szene ansehen', 'button secondary');
        showScene.addEventListener('click', () => {
          const scene = document.getElementById(event.scene.id);
          scene.focus({preventScroll: true});
          scene.scrollIntoView({behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest'});
        });
        card.append(showScene); $('#chronicle').append(card);
        const scene = element('article', undefined, 'story-scene'); scene.id = event.scene.id; scene.tabIndex = -1;
        scene.dataset.eventId = event.id; scene.dataset.revision = event.revision;
        scene.append(element('span', '⚑', 'scene-mark'), element('h3', event.title), element('p', `${event.game_id} · ${event.member_ids.length} gemeinsame Auszeichnungen`, 'muted'));
        $('#story-scenes').append(scene);
      }
    }
    if (location.hash.startsWith('#scene-')) document.getElementById(location.hash.slice(1))?.focus({preventScroll: true});
  } catch (error) {
    if (generation === request && state.view === 'community') {
      $('#community-state').textContent = 'Die Chronik konnte nicht aktualisiert werden. Bereits geladene Momente bleiben sichtbar.';
      notice(error.message, true);
    }
  }
}
