import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';

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
      card.append(element('p', game.server_adapter ? 'Serververwaltung verfügbar.' : 'Kein Serveradapter eingerichtet.'));
      const features = element('ul', undefined, 'capability-list');
      for (const [key, label] of Object.entries({readiness: 'Spielbereitschaft', world_backup: 'Weltsicherung', update_detection: 'Steam-Updates', mods: 'Modverwaltung'})) {
        const status = game.capabilities[key]?.state;
        features.append(element('li', `${label}: ${status === 'supported' ? 'Verfügbar' : status === 'unsupported' ? 'Nicht unterstützt' : 'Noch nicht eingerichtet / geprüft'}`));
      }
      card.append(features);
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
