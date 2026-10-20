import {$} from './dom.js';
import {state} from './state.js';
import {serverViews} from './views/workspace.js';

const names = {fleet: 'Serverübersicht', overview: 'Übersicht', settings: 'Einstellungen', logs: 'Serverlog', discord: 'Discord',
  profiles: 'Serverprofile', games: 'Spiele / Mods', community: 'Sturmklingen', access: 'Familienzugang', backups: 'Weltsicherungen', whatsapp: 'WhatsApp', updates: 'Steam-Updates'};

export function navigate(next) {
  if (!Object.hasOwn(names, next)) return;
  state.navigationEpoch++;
  state.view = next;
  $('#server-workspace').hidden = !serverViews.has(next);
  $('#back-to-fleet').hidden = !serverViews.has(next);
  $('#breadcrumb-trail').hidden = serverViews.has(next);
  $('#message-channels').hidden = !['discord', 'whatsapp'].includes(next);
  for (const section of document.querySelectorAll('.view')) section.hidden = section.id !== next;
  for (const button of document.querySelectorAll('[data-view]')) {
    const active = button.closest('.sidebar') && button.dataset.view === 'fleet' ? next === 'fleet' || serverViews.has(next)
      : button.getAttribute('role') === 'tab' && button.dataset.view === 'discord' ? ['discord', 'whatsapp'].includes(next) : button.dataset.view === next;
    button.classList.toggle('active', active);
    if (button.getAttribute('role') === 'tab') {
      button.setAttribute('aria-selected', String(active)); button.tabIndex = active ? 0 : -1;
      if (button.dataset.view === 'discord') button.setAttribute('aria-controls', next === 'whatsapp' ? 'whatsapp' : 'discord');
    } else if (active) button.setAttribute('aria-current', 'page'); else button.removeAttribute('aria-current');
  }
  $('#breadcrumb').textContent = names[next];
  if ($('#mobile-navigation').open) $('#mobile-navigation').close();
  window.dispatchEvent(new CustomEvent('indy:navigate', {detail: next}));
  return next;
}

export function bindNavigation(go) {
  const sidebar = $('.sidebar'), drawer = $('#mobile-navigation'), toggle = $('#navigation-toggle');
  const mobile = window.matchMedia('(max-width: 900px)');
  const placeNavigation = () => {
    if (!mobile.matches && drawer.open) drawer.close();
    (mobile.matches ? drawer : $('#desktop-navigation')).append(sidebar);
  };
  mobile.addEventListener('change', placeNavigation); placeNavigation();
  toggle.addEventListener('click', () => { drawer.showModal(); toggle.setAttribute('aria-expanded', 'true'); });
  $('#navigation-close').addEventListener('click', () => drawer.close());
  drawer.addEventListener('close', () => toggle.setAttribute('aria-expanded', 'false'));
  drawer.addEventListener('click', event => {
    const box = drawer.getBoundingClientRect();
    if (event.target === drawer && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) drawer.close();
  });
  for (const button of document.querySelectorAll('[data-view]')) button.addEventListener('click', () => go(button.dataset.view));
  for (const button of document.querySelectorAll('[data-go]')) button.addEventListener('click', () => go(button.dataset.go));
}
