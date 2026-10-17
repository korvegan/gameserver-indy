import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';
import {modSection} from './mods.js';

let request = 0;
export async function loadGames(openServer) {
  const generation = ++request;
  try {
    const {games} = await api('/api/games');
    if (generation !== request || state.view !== 'games') return;
    const container = $('#game-catalog'); container.replaceChildren();
    for (const game of games) {
      const card = element('article', undefined, 'panel');
      card.append(element('p', 'DEINE SPIELE', 'eyebrow'), element('h2', game.label));
      const instances = state.catalog.servers.filter(server => server.game === game.id);
      card.append(element('p', instances.length ? `${instances.length} Serverprofil${instances.length > 1 ? 'e' : ''} eingerichtet.` : 'Kein Serverprofil eingerichtet.', 'muted'));
      card.append(element('p', game.capabilities.process_control?.state === 'supported' ? 'Serververwaltung verfügbar.'
        : game.server_adapter ? 'Konfigurationsadapter verfügbar. Prozesssteuerung noch nicht freigegeben.' : 'Kein Serveradapter eingerichtet.'));
      const features = element('ul', undefined, 'capability-list');
      for (const [key, label] of Object.entries({configuration:'Konfiguration', process_control:'Prozesssteuerung', readiness: 'Spielbereitschaft', save:'Speicherbefehl', world_backup: 'Weltsicherung', update_detection: 'Steam-Updates', players:'Spielerabfrage', rcon:'RCON', mods: 'Modverwaltung'})) {
        const capability = game.capabilities[key], status = capability?.state;
        const row = element('li', `${label}: ${status === 'supported' ? 'Verfügbar' : status === 'unsupported' ? 'Nicht unterstützt' : 'Unbekannt / nicht eingerichtet'}`);
        if (capability?.reason) { row.append(element('small', capability.reason)); }
        features.append(row);
      }
      card.append(features);
      card.append(modSection(game));
      for (const instance of instances) {
        const button = element('button', `${instance.label} öffnen`, 'button secondary');
        button.addEventListener('click', () => openServer(instance.id)); card.append(button);
      }
      container.append(card);
    }
    $('#games-state').textContent = games.length ? 'Spiele und Serverprofile werden getrennt verwaltet. Modquellen werden je Spiel eingerichtet.' : 'Noch keine Spiele eingetragen.';
  } catch (error) {
    if (generation === request && state.view === 'games') {
      $('#games-state').textContent = 'Der Spielekatalog ist gerade nicht erreichbar.';
      notice(error.message, true);
    }
  }
}
