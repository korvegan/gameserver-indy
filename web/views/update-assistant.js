import {api} from '../api.js';
import {$,element,notice} from '../dom.js';
import {state,can} from '../state.js';
let request = 0, signature = '', run;
export function bindUpdateAssistant(operation) { run = operation; }
export function resetUpdateAssistant() { request++; signature = ''; $('#update-assistant').replaceChildren(); }
export async function loadUpdateAssistant() {
  if (!can('backups.manage') || state.view !== 'updates') return;
  const generation = ++request, target = state.selectedServer, epoch = state.selectionEpoch;
  try {
    const data = await api(`/api/update-assistant/${encodeURIComponent(target)}`);
    if (generation !== request || target !== state.selectedServer || epoch !== state.selectionEpoch || state.view !== 'updates') return;
    const next = JSON.stringify([target,data]); if (signature === next) return; signature = next;
    const root = $('#update-assistant'); root.replaceChildren(element('h2','Update-Assistent'));
    root.append(element('p','1. Runde beenden und Server regulär stoppen. 2. Gesichert vorbereiten. 3. Dedicated Server in Steam aktualisieren. 4. Änderungen prüfen. 5. Ausdrücklich starten und Beitritt prüfen.','muted'));
    if (data.error) root.append(element('p',data.error));
    const session = data.session;
    if (!session || session.state !== 'waiting_steam') {
      root.append(element('p',session?.message || 'Es wird kein automatischer Download oder Serverstart ausgeführt.'));
      const button = element('button','Update gesichert vorbereiten','button secondary'); button.type = 'button'; root.append(button);
      button.disabled = !data.configuration_revision || !data.installed || data.installed.busy || !data.installed.complete;
      button.addEventListener('click', () => { if (confirm('Gestoppten Server sichern und während des manuellen Steam-Schritts für Starts und Dateiänderungen sperren?')) action('update_prepare',{revision:data.configuration_revision}); });
      return;
    }
    root.append(element('p',`Wartet auf Steam · Build vorher ${session.before_build} · jetzt ${data.installed?.build || 'unbekannt'}${data.installed?.busy ? ' · Steam arbeitet noch' : ''}`));
    root.append(element('p',`Sicherung ${session.backup_id} angeheftet. Start, Konfigurations- und Weltänderungen bleiben bis zur Prüfung gesperrt. Stopp und Status sind weiterhin möglich.`,'muted'));
    if (data.diff) {
      for (const [key,label] of [['added','Neue Felder'],['removed','Entfernte Felder'],['changed','Geänderte Felder'],['external_changed','Geänderte externe Dateien']]) root.append(element('p',`${label}: ${data.diff[key].join(', ') || 'keine'}`));
      root.append(element('p','Werte und Zugangsdaten werden hier ausgeblendet. Vorhandene Dateien werden nicht überschrieben.','muted'));
      const label = element('label'), ack = element('input'); ack.type = 'checkbox'; label.append(ack,document.createTextNode('Angezeigte Konfigurations-/Starteränderungen bewusst geprüft')); root.append(label);
      for (const [operation,text] of [['update_finish','Steam-Abschluss prüfen und freigeben'],['update_abort','Assistent bewusst beenden']]) {
        const button = element('button',text,'button secondary'); button.type = 'button'; root.append(button);
        button.addEventListener('click', () => { if (operation === 'update_abort' && !confirm('Assistent beenden? Das setzt weder Programmversion noch Welt zurück. Ein unvollständiger Steam-Download bleibt gesperrt.')) return;
          action(operation,{session_id:session.id,acknowledge_changes:ack.checked,review_revision:data.diff.revision}); });
      }
    }
  } catch (error) { if (generation === request && epoch === state.selectionEpoch) $('#update-assistant').textContent = error.message; }
}
async function action(operation,payload) { try { await run(operation,payload); } catch (error) { notice(error.message,true); } }
