import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {state} from '../state.js';

let generation = 0, latest;
const number = (value, suffix = '') => Number.isFinite(value) ? `${value.toLocaleString('de-DE',{maximumFractionDigits:1})}${suffix}` : 'Unbekannt';
const memory = value => number(Number.isFinite(value) ? value / 1073741824 : null, ' GiB');
function render() {
  if (!latest) return;
  const now = Date.now()/1000, from = now - Number($('#performance-range').value);
  const samples = latest.samples.filter(sample => sample.at >= from), sample = latest.samples.at(-1);
  $('#performance-summary').textContent = latest.stale ? 'Messung fehlt oder ist veraltet.' :
    `Server CPU ${number(sample?.cpu_percent,' %')} · RAM ${memory(sample?.memory_bytes)} · Prozess-I/O ${number(Number.isFinite(sample?.io_bytes_sec) ? sample.io_bytes_sec/1048576 : null,' MiB/s')} · Rechner CPU ${number(sample?.host_cpu_percent,' %')} · RAM ${memory(sample?.host_memory_used_bytes)}`;
  const svg = document.createElementNS('http://www.w3.org/2000/svg','svg'); svg.setAttribute('viewBox','0 0 600 150');
  svg.setAttribute('role','img'); svg.setAttribute('aria-label','CPU-Verlauf: Server blau, Rechner gelb; Skala null bis hundert Prozent. Lücken sind unbekannte Daten.');
  for (const [key,color] of [['cpu_percent','#91d0e0'],['host_cpu_percent','#e5bc7b']]) {
    let path = '', previous;
    for (const item of samples) {
      if (!Number.isFinite(item[key])) { previous = undefined; continue; }
      const x = 600*(item.at-from)/(now-from), y = 145-1.4*Math.max(0,Math.min(100,item[key]));
      path += `${previous && item.at-previous.at<90 ? 'L' : 'M'}${x.toFixed(1)},${y.toFixed(1)} `; previous = item;
    }
    const line = document.createElementNS(svg.namespaceURI,'path'); line.setAttribute('d',path); line.setAttribute('fill','none'); line.setAttribute('stroke',color); line.setAttribute('stroke-width','2'); svg.append(line);
  }
  for (const marker of latest.markers.filter(marker => marker.type==='stutter' && marker.at>=from)) {
    const x=600*(marker.at-from)/(now-from), line=document.createElementNS(svg.namespaceURI,'path');
    line.setAttribute('d',`M${x},0V150`); line.setAttribute('stroke','#e99382'); const title=document.createElementNS(svg.namespaceURI,'title'); title.textContent=marker.label; line.append(title); svg.append(line);
  }
  $('#performance-chart').replaceChildren(svg);
  const rows = $('#performance-history'); rows.replaceChildren();
  for (const item of samples.slice(-12).reverse()) {
    const row=element('p',`${new Date(item.at*1000).toLocaleTimeString('de-DE')} · Server ${number(item.cpu_percent,' %')} / ${memory(item.memory_bytes)} · Rechner ${number(item.host_cpu_percent,' %')} / ${memory(item.host_memory_used_bytes)}`,'field-help'); rows.append(row);
  }
  const markers = $('#performance-markers'); markers.replaceChildren();
  const names = {start:'Start',stop:'Stopp',restart:'Neustart',backup:'Weltsicherung',backup_restore:'Wiederherstellung',check_updates:'Updateprüfung',config:'Konfiguration speichern',succeeded:'abgeschlossen',running:'läuft',queued:'angenommen',failed:'fehlgeschlagen',interrupted:'unterbrochen'};
  for (const item of latest.markers.filter(marker=>marker.at>=from).slice(0,20)) {
    const label = item.label.split(' · ').map(part=>names[part] || part).join(' · ');
    markers.append(element('p',`${new Date(item.at*1000).toLocaleString('de-DE')} · ${label}`,'field-help'));
  }
}
export function clearPerformance() {
  ++generation; latest=undefined;
  $('#performance-summary').textContent='Messung wird bei geöffneter Ansicht geladen.';
  for (const id of ['performance-chart','performance-history','performance-markers']) $('#'+id).replaceChildren();
}
export async function loadPerformance() {
  if (!$('#performance-panel').open || state.view!=='overview') return;
  const current=++generation, target=state.selectedServer, epoch=state.selectionEpoch;
  try {
    const value=await api('/api/performance',undefined,target);
    if (current!==generation || epoch!==state.selectionEpoch || state.view!=='overview') return;
    latest=value; render();
  } catch(error) { if(current===generation) $('#performance-summary').textContent=error.message; }
}
export function bindPerformance() {
  $('#performance-panel').addEventListener('toggle',loadPerformance);
  $('#performance-range').addEventListener('change',render);
  $('#mark-stutter').addEventListener('click',async()=>{
    const target=state.selectedServer, epoch=state.selectionEpoch, button=$('#mark-stutter'); button.disabled=true;
    try {
      await api(`/api/performance/markers?server=${encodeURIComponent(target)}`,{note:$('#performance-note').value});
      if(epoch===state.selectionEpoch) { $('#performance-note').value=''; await loadPerformance(); notice('Ruckler-Zeitpunkt festgehalten.'); }
    } catch(error) { if(epoch===state.selectionEpoch) notice(error.message,true); }
    finally { button.disabled=false; }
  });
}
