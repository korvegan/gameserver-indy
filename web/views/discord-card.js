import {api, requestId} from '../api.js';
import {$, notice} from '../dom.js';
import {can, state} from '../state.js';
let generation = 0, current, busy = false;
let configured = false;
export function discordCardControls() {
  $('#create-discord-card').disabled = busy || state.submitting || !current || !configured || !can('discord.write');
  $('#disable-discord-card').disabled = busy || state.submitting || !current?.enabled || !can('discord.write');
}
export function clearDiscordCard() {
  generation++; current = null; configured = false;
  $('#discord-card-state').textContent = 'Statuskarte wird geladen …';
  $('#create-discord-card').disabled = true; $('#disable-discord-card').disabled = true;
}
export async function loadDiscordCard() {
  if (state.view !== 'discord' || !can('discord.write') || !state.selectedServer) return;
  const own = ++generation, epoch = state.selectionEpoch, target = state.selectedServer;
  try {
    const data = await api('/api/discord', undefined, target);
    if (own !== generation || epoch !== state.selectionEpoch || state.view !== 'discord') return;
    current = data.card; configured = data.discord.configured;
    const labels = {none:'Noch keine Statuskarte',creating:'Anlegen nicht bestätigt · vor erneutem Versand im Kanal prüfen',
      active:'Statuskarte aktiv',updating:'Aktualisierung läuft',retry:'Discord-Wartezeit',blocked:'Aktualisierung gesperrt',uncertain:'Versand unklar'};
    $('#discord-card-state').textContent = `${labels[current.state] || current.state}${current.enabled ? '' : ' · automatische Aktualisierung aus'}${current.updated_at ? ` · Stand ${new Date(current.updated_at).toLocaleString('de-DE')}` : ''}${current.error ? ` · ${current.error}` : ''}`;
    $('#create-discord-card').textContent = current.state === 'none' ? 'Statuskarte anlegen' : 'Neue Statuskarte anlegen';
    discordCardControls();
  } catch (error) { if (own === generation) { clearDiscordCard(); $('#discord-card-state').textContent = error.message; } }
}
export function bindDiscordCard(accepted) {
  for (const [selector, action] of [['#create-discord-card','create'],['#disable-discord-card','disable']]) {
    $(selector).addEventListener('click', async () => {
      if (busy || !current || !can('discord.write')) return;
      const target = state.selectedServer, epoch = state.selectionEpoch;
      const replace = current.state !== 'none';
      if (action === 'create' && !confirm(replace ? 'Eine neue Nachricht anlegen? Prüfe zuerst den Kanal: Die bisherige Karte bleibt dort bestehen. Ein unklarer Versand könnte bereits angekommen sein.' : 'Eine Statuskarte in Discord anlegen und künftig automatisch aktualisieren?')) return;
      busy = true; $('#create-discord-card').disabled = true; $('#disable-discord-card').disabled = true;
      try {
        const result = await api('/api/discord/card',{action, replace, generation:current.generation, request_id:requestId()},target);
        if (result.job) accepted(result.job);
        if (epoch === state.selectionEpoch) { notice(result.message || 'Statuskarte als Auftrag angenommen.'); await loadDiscordCard(); }
      } catch (error) { if (epoch === state.selectionEpoch) notice(error.message,true); }
      finally { busy = false; await loadDiscordCard(); }
    });
  }
}
