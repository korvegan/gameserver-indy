import {api} from '../api.js';
import {$, element, icon} from '../dom.js';
import {state} from '../state.js';

export const serverViews = new Set(['overview', 'settings', 'logs', 'backups', 'updates', 'discord', 'whatsapp']);
const artwork = {
  enshrouded: {label: 'Enshrouded', caption: 'Die Welt von Embervale'},
  aska: {label: 'ASKA', caption: 'Euer Wikingerdorf'},
};
const snapshots = new Map();
const observations = new Map();
let fleetGeneration = 0, fleetBusy = false, selectedProfile;

// Move each existing view once. Polls update values, never rebuild forms or scenes.
export function initWorkspace(selectServer, go) {
  for (const id of serverViews) {
    const view = $(`#${id}`); $('#server-panels').append(view);
    view.setAttribute('role', 'tabpanel'); view.setAttribute('aria-labelledby', `tab-${id === 'whatsapp' ? 'discord' : id}`);
  }
  $('#workspace-heading-actions').append($('#refresh'));
  $('#workspace-identity').append($('#server-name'));
  $('#workspace-state').append($('#game-label'), $('#server-state'), $('#status-updated'), $('#process-note'));
  $('#workspace-actions').append($('#start'), $('#stop'), $('#restart'));
  $('#workspace-actions').before($('#profile-caption'));
  for (const [id, name, label] of [['refresh', 'refresh', 'Aktualisieren'], ['start', 'play', 'Server starten'], ['stop', 'stop', 'Stoppen'], ['restart', 'refresh', 'Neu starten']]) {
    $('#' + id).replaceChildren(icon(name), document.createTextNode(label));
  }
  $('#workspace-setup').append($('#setup-panel'), $('#control-hint'), $('#access-note'));
  $('#overview .page-heading h1').textContent = 'Server im Überblick';
  $('#overview .subtitle').remove();
  const metrics = $('#overview .server-details'); metrics.classList.add('overview-metrics');
  $('#overview .server-card').replaceWith(metrics);
  const join = $('#join-heading').closest('section'), network = element('details', undefined, 'network-details');
  network.id = 'network-details'; network.append(element('summary', 'Beitrittsdetails · lokal, Heimnetz und extern'), join); $('#overview').append(network);
  $('#logs .page-heading').after($('#job-list').closest('section'));
  $('#logs').append($('#events').closest('section'));
  $('#workspace-job-state').after($('#job-live-state'));
  $('#updates').append($('#updates-heading').closest('section'));
  $('#updates').append($('#update-assistant'));
  const technical = element('details', undefined, 'network-details');
  technical.dataset.permission = 'config.read';
  technical.append(element('summary', 'Installation und technische Angaben'), $('#install-path').closest('section'));
  const help = element('details', undefined, 'network-details');
  help.append(element('summary', 'Wie funktionieren Serverbefehle?'), element('p', 'Start, Stopp und Neustart werden nacheinander ausgeführt. Beim Ausführen wird der aktuelle Serverzustand geprüft. Ein laufender Prozess bestätigt noch keinen erfolgreichen Beitritt.'));
  $('#overview').append(help);
  $('#discord .page-heading').after($('#discord-status').closest('section'));
  $('#overview .overview-grid').replaceWith(technical);
  const metricsPanel = element('div', undefined, 'overview-summaries');
  for (const [id, title, value, target] of [['readiness-summary', 'Spielbereitschaft', 'Nicht bestätigt', 'logs'],
    ['players-summary', 'Spielerbelegung', 'Unbekannt', 'logs'], ['steam-summary', 'Steam-Version', 'Prüfung offen', 'updates'],
    ['backup-summary', 'Weltsicherungen', '72 Stunden · Pins bleiben', 'backups']]) {
    const button = element('button', undefined, 'overview-summary'); button.type = 'button';
    const content = element('span');
    content.append(element('small', title), Object.assign(element('strong', value), {id}));
    if (id === 'readiness-summary') content.append(Object.assign(element('small',''),{id:'readiness-evidence'}));
    button.append(icon({'readiness-summary':'server', 'players-summary':'users', 'steam-summary':'disc', 'backup-summary':'database'}[id]), content, icon('chevron'));
    button.addEventListener('click', () => go(target));
    if (target === 'backups') button.dataset.permission = 'backups.manage';
    if (target === 'logs') button.dataset.permission = 'jobs.read';
    metricsPanel.append(button);
  }
  metrics.before(metricsPanel);
  $('#world-list').addEventListener('click', event => {
    const button = event.target.closest('[data-select-server]');
    if (button) selectServer(button.dataset.selectServer, true);
  });
  $('#open-join-details').addEventListener('click', async () => { await go('overview'); $('#network-details').open = true; $('#network-details').scrollIntoView({block: 'nearest'}); });
  $('.server-tabs').addEventListener('keydown', event => {
    if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || event.target.getAttribute('role') !== 'tab') return;
    const tabs = [...$('.server-tabs').querySelectorAll('[role=tab]')].filter(node => !node.hidden);
    const index = tabs.indexOf(event.target), next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
      : (index + (event.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length;
    event.preventDefault(); tabs[next].click(); tabs[next].focus({preventScroll: true});
  });
  for (const id of serverViews) {
    const heading = $('#' + id + ' .page-heading h1');
    if (heading) heading.replaceWith(element('h2', heading.textContent));
  }
  for (const [details, name] of [[$('#performance-panel'),'activity'], [technical,'profiles'], [network,'users'], [help,'help']]) {
    details.querySelector('summary').prepend(icon(name));
    details.querySelector('summary').append(icon('chevron'));
  }
}

function observationLabel(data) {
  if (!data) return 'Status noch nicht geladen';
  const status = data.status;
  return status.known === false ? 'Prozessstatus unbekannt' : status.starting ? 'Starter arbeitet' : status.running ? 'Prozess läuft' : 'Gestoppt';
}
export function renderWorkspace() {
  const profile = state.catalog.servers.find(server => server.id === state.selectedServer), game = profile?.game;
  $('#server-context-name').textContent = profile?.label || 'Serverprofil wird geladen …';
  $('#server-context').disabled = !profile || !state.catalogAvailable;
  $('#server-detail').dataset.game = Object.hasOwn(artwork, game) ? game : 'generic';
  if (selectedProfile !== state.selectedServer + ':' + game) {
    selectedProfile = state.selectedServer + ':' + game;
    const style = artwork[game];
    for (const id of ['game-banner-art', 'server-context-art']) {
      const image = $(`#${id}`); image.hidden = !style;
      if (style) image.src = `/artwork/${game}-hero.jpg`; else image.removeAttribute('src');
    }
    $('#server-detail').setAttribute('aria-label', `${profile?.label || 'Serverprofil'} · Serverbereiche`);
    $('#game-label').textContent = style?.label || game || 'Spielserver';
    $('#profile-caption').textContent = 'Profil: ' + (profile?.label || 'wird geladen');
    $('#readiness-summary').textContent = 'Unbekannt';
    $('#readiness-evidence').textContent = '';
  }
  const list = $('#world-list'), signature = JSON.stringify([state.catalogAvailable, state.catalog.servers.map(({id, game, label}) => ({id, game, label}))]);
  if (list.dataset.catalog !== signature) {
    list.dataset.catalog = signature; list.replaceChildren();
    for (const entry of state.catalog.servers) {
      const card = element('button', undefined, 'world-card'); card.type = 'button'; card.dataset.selectServer = entry.id;
      card.dataset.game = Object.hasOwn(artwork, entry.game) ? entry.game : 'generic';
      const gameLabel = artwork[entry.game]?.label || state.catalog.games.find(game => game.id === entry.game)?.label || entry.game;
      card.setAttribute('aria-label', `${gameLabel} · ${entry.label} öffnen`);
      card.setAttribute('aria-describedby', `world-status-${entry.id}`);
      const media = element('span', undefined, 'world-media');
      if (Object.hasOwn(artwork, entry.game)) {
        const image = element('img'); image.src = `/artwork/${entry.game}-hero.jpg`; image.alt = ''; image.className = 'world-art';
        media.append(image);
      } else media.append(icon('server'));
      const status = element('span', '', 'world-status'); status.id = `world-status-${entry.id}`;
      const body = element('span', undefined, 'world-card-body'), identity = element('span', undefined, 'world-identity');
      identity.append(element('strong', gameLabel), element('small', '', 'world-profile'));
      const statusGroup = element('span', undefined, 'world-observation'); statusGroup.append(status, element('small', 'Serverstatus'));
      body.append(identity, statusGroup);
      const open = element('span', undefined, 'world-selection'); open.append(element('span', 'Server öffnen'), icon('arrow'));
      card.append(media, body, open);
      list.append(card);
    }
    if (!state.catalog.servers.length) list.append(element('p', state.catalogAvailable ? 'Keine Serverprofile freigegeben.' : 'Serverprofile werden geladen …', 'empty'));
    const allowed = new Set(state.catalog.servers.map(server => server.id));
    for (const id of snapshots.keys()) if (!allowed.has(id)) snapshots.delete(id);
  }
  $('#world-count').textContent = `${state.catalog.servers.length} Profile`;
  for (const card of list.children) {
    if (!card.dataset.selectServer) continue;
    card.disabled = state.submitting || !state.catalogAvailable;
    const cached = snapshots.get(card.dataset.selectServer);
    const profile = state.catalog.servers.find(server => server.id === card.dataset.selectServer);
    card.querySelector('.world-profile').textContent = 'Profil: ' + profile.label;
    card.querySelector('.world-status').textContent = observationLabel(cached);
    card.querySelector('.world-status').classList.toggle('running', Boolean(cached?.status.running && cached.status.known !== false));
    card.title = cached?.observedAt ? `Stand: ${new Date(cached.observedAt).toLocaleTimeString('de-DE')}` : 'Noch keine bestätigte Statusabfrage';
  }
}

export function adoptWorkspaceStatus(data) {
  observations.set(state.selectedServer, (observations.get(state.selectedServer) || 0) + 1);
  snapshots.set(state.selectedServer, {...data, observedAt: Date.now()});
  const evidence = data.status.readiness;
  $('#readiness-summary').textContent = data.status.known === false ? 'Unbekannt' : evidence?.label || (data.status.running ? 'Nicht bestätigt' : data.status.starting ? 'Starter arbeitet' : 'Server gestoppt');
  $('#readiness-summary').title = evidence ? [evidence.source, evidence.observed_at ? `Beobachtet: ${new Date(evidence.observed_at).toLocaleString('de-DE')}` : '',
    'Lokaler Nachweis; externer Beitritt bleibt separat zu prüfen.'].filter(Boolean).join(' · ') : '';
  $('#readiness-evidence').textContent = evidence?.source ? `${evidence.source} · beobachtet ${new Date(evidence.observed_at).toLocaleTimeString('de-DE')}` : '';
  renderWorkspace();
}
export function markWorkspaceUnknown() {
  observations.set(state.selectedServer, (observations.get(state.selectedServer) || 0) + 1);
  snapshots.set(state.selectedServer, {status: {known: false}, observedAt: Date.now()});
  $('#readiness-summary').textContent = 'Unbekannt'; renderWorkspace();
}
export async function refreshFleet() {
  if (fleetBusy) return;
  fleetBusy = true; const generation = ++fleetGeneration;
  const servers = state.catalog.servers.filter(server => server.id !== state.selectedServer);
  try {
    for (let offset = 0; offset < servers.length; offset += 2) await Promise.allSettled(servers.slice(offset, offset + 2).map(async profile => {
      if (profile.id === state.selectedServer) return;
      const revision = (observations.get(profile.id) || 0) + 1; observations.set(profile.id, revision);
      const data = await api('/api/status', undefined, profile.id).catch(() => ({status: {known: false}}));
      if (generation !== fleetGeneration || observations.get(profile.id) !== revision || profile.id === state.selectedServer || !state.catalog.servers.some(server => server.id === profile.id)) return;
      snapshots.set(profile.id, {...data, observedAt: Date.now()}); renderWorkspace();
    }));
  } finally { fleetBusy = false; }
}
export function renderWorkspaceJobs() {
  const jobs = state.latestJobs.filter(job => ['queued', 'running'].includes(job.state));
  $('#workspace-job-state').textContent = jobs.length ? `${jobs.length} Auftrag${jobs.length === 1 ? '' : 'e'} · ${jobs.some(job => job.state === 'running') ? 'wird ausgeführt' : 'angenommen'}`
    : 'Keine offenen Aufträge';
  $('#workspace-job-count').textContent = jobs.length ? `(${jobs.length})` : '';
}
