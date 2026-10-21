import {api} from '../api.js';
import {$,element,notice} from '../dom.js';
import {state,can} from '../state.js';
let request = 0, bound = false;
export function resetMaintenance() { request++; $('#maintenance-banner').hidden = true; $('#maintenance-plan').replaceChildren(); }
export function bindMaintenance() {
  if (bound) return; bound = true;
  $('#maintenance-form').addEventListener('submit',event => {
    event.preventDefault(); act({action:'create',operation:$('#maintenance-operation').value,delay:Number($('#maintenance-delay').value)});
  });
}
async function act(body) {
  const target = state.selectedServer;
  try { const result = await api(`/api/maintenance/${encodeURIComponent(target)}`,body); notice(result.message); if (target === state.selectedServer) await loadMaintenance(); }
  catch (error) { notice(error.message,true); }
}
export async function loadMaintenance() {
  const generation = ++request, target = state.selectedServer, epoch = state.selectionEpoch;
  try {
    const {players,plan} = await api(`/api/maintenance/${encodeURIComponent(target)}`);
    if (generation !== request || target !== state.selectedServer || epoch !== state.selectionEpoch) return;
    const occupancy = `${players.count === null ? 'Unbekannt' : players.count} · ${players.source}${players.observed_at ? ` · ${new Date(players.observed_at).toLocaleTimeString('de-DE')}` : ''}`;
    $('#maintenance-players').textContent = occupancy; $('#players-summary').textContent = players.count === null ? 'Unbekannt' : '0 · Server gestoppt';
    const active = plan && ['waiting_approval','countdown','queued'].includes(plan.state) && !['succeeded','failed','interrupted'].includes(plan.job_state);
    const text = !plan ? 'Keine Wartung geplant.' : `${plan.operation === 'restart' ? 'Neustart' : 'Stopp'}: ${
      plan.state === 'waiting_approval' ? 'wartet auf Besitzerfreigabe' : plan.state === 'countdown' ? `freigegeben, frühestens ${new Date(plan.execute_at * 1000).toLocaleTimeString('de-DE')}`
        : plan.state === 'cancelled' ? 'abgebrochen' : plan.message || 'Auftrag angenommen'}.`;
    $('#maintenance-banner').textContent = text; $('#maintenance-banner').hidden = !active;
    const root = $('#maintenance-plan'); root.replaceChildren(element('p',text));
    $('#maintenance-form').hidden = !can('server.restart');
    if (can('server.restart') && plan && ['waiting_approval','countdown'].includes(plan.state)) {
      for (const [action,label] of [['approve','Trotz unbekannter Belegung freigeben'],['cancel','Wartungsplan abbrechen']]) {
        if (action === 'approve' && plan.state !== 'waiting_approval') continue;
        const button = element('button',label,'button secondary'); button.type = 'button'; root.append(button);
        button.addEventListener('click', () => {
          if (action === 'approve' && !confirm('Wartung ausdrücklich freigeben? Nach dem Vorlauf kann die laufende Runde beendet werden. Spielerbelegung ist nicht zuverlässig bekannt.')) return;
          act({action,id:plan.id});
        });
      }
    }
  } catch (error) { if (generation === request && epoch === state.selectionEpoch) $('#maintenance-plan').textContent = error.message; }
}
