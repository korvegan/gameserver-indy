import {$} from './dom.js';
import {state} from './state.js';

const names = {overview: 'Übersicht', settings: 'Einstellungen', logs: 'Serverlog', discord: 'Discord',
  profiles: 'Serverprofile', games: 'Spiele / Mods', community: 'Sturmklingen'};

export function navigate(next) {
  if (!Object.hasOwn(names, next)) return;
  state.navigationEpoch++;
  state.view = next;
  for (const section of document.querySelectorAll('.view')) section.hidden = section.id !== next;
  for (const button of document.querySelectorAll('[data-view]')) {
    button.classList.toggle('active', button.dataset.view === next);
    if (button.dataset.view === next) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
  }
  $('#breadcrumb').textContent = names[next];
  $('.server-picker').hidden = next === 'games' || next === 'community';
  return next;
}

export function bindNavigation(go) {
  for (const button of document.querySelectorAll('[data-view]')) button.addEventListener('click', () => go(button.dataset.view));
  for (const button of document.querySelectorAll('[data-go]')) button.addEventListener('click', () => go(button.dataset.go));
}
