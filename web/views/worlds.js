import {api} from '../api.js';
import {$,element,notice} from '../dom.js';
import {state} from '../state.js';
let signature = '', generation = 0, run;
export function resetWorlds() { generation++; signature = ''; $('#world-management').replaceChildren(); }
export function bindWorlds(operation) { run = operation; }
export async function loadWorlds() {
  const target = state.selectedServer, request = ++generation, epoch = state.selectionEpoch;
  try {
    const data = await api(`/api/worlds/${encodeURIComponent(target)}`);
    if (request !== generation || target !== state.selectedServer || epoch !== state.selectionEpoch || state.view !== 'backups') return;
    const next = JSON.stringify([target,data]); if (signature === next) return; signature = next;
    const root = $('#world-management'); root.replaceChildren(element('h2','Spielwelten'),element('p',data.message,'muted'));
    for (const world of data.worlds) {
      const row = element('p', `${world.name}${world.id === data.current.id ? ' · ausgewählt' : ''}`); row.append(element('small', `${world.path || 'Speicherort unbekannt'}${world.save_id ? ` · Save-ID: ${world.save_id}` : ''}`));
      if (data.can_change && world.id !== data.current.id) {
        const button = element('button','Welt auswählen','button secondary'); button.type = 'button'; row.append(button);
        button.addEventListener('click', () => submit({action:'switch',world_id:world.id},data.current.revision));
      }
      root.append(row);
    }
    if (!data.can_change) return;
    const details = element('details'), summary = element('summary','Neue Kampagne oder Testkopie vorbereiten'), form = element('form',undefined,'settings-grid'); details.append(summary,form); root.append(details);
    const label = element('label','Weltname'), name = element('input'); name.required = true; name.maxLength = 100; label.append(name); form.append(label);
    const typeLabel = element('label','Art'), type = element('select'); type.setAttribute('aria-label','Art'); typeLabel.append(type); form.append(typeLabel);
    for (const [value,text] of [['new','Neue leere Kampagne'],['clone','Geprüfte Kopie der ausgewählten Welt']]) { const option = element('option',text); option.value = value; type.append(option); }
    form.append(element('p','Server und Starter müssen gestoppt bleiben. Eine neue Welt entsteht beim nächsten ausdrücklichen Spielserverstart; der Manager startet sie nicht.','muted'),element('button','Separat vorbereiten','button secondary'));
    form.addEventListener('submit',event => { event.preventDefault(); submit({action:type.value,name:name.value},data.current.revision); });
  } catch (error) { if (request === generation && epoch === state.selectionEpoch && state.view === 'backups') $('#world-management').replaceChildren(element('p',error.message,'muted')); }
}
async function submit(body, revision) {
  if (!confirm('Gestoppten Server beibehalten, vorher Welt und Konfiguration sichern und diese Weltaktion ausführen? Keine Bestandswelt wird gelöscht.')) return;
  try { await run('world',{...body,revision,confirmation:'WELTWECHSEL'}); } catch (error) { notice(error.message,true); }
}
