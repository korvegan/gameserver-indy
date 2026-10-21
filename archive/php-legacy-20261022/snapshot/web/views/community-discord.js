import {api, requestId} from '../api.js';
import {$, notice} from '../dom.js';
import {can, state} from '../state.js';
let selected, snapshot, generation = 0, busy = false, accepted;
export async function loadCommunityDiscord() {
  if (state.view !== 'community' || !can('community.export') || !$('#community-discord').open) return;
  const own = ++generation, epoch = state.navigationEpoch;
  try {
    const data = await api('/api/community/discord'+(selected ? `?event=${encodeURIComponent(selected.id)}` : ''));
    if (own !== generation || epoch !== state.navigationEpoch || state.view !== 'community') return;
    snapshot = data;
    $('#community-discord-state').textContent = !data.private_origin_configured ? 'Zuerst den privaten Familienzugang einrichten.'
      : data.configured ? 'Eigener Gemeinschaftskanal eingerichtet. Jede Veröffentlichung wird einzeln freigegeben.' : 'Noch kein Gemeinschafts-Webhook eingerichtet.';
    $('#community-discord-media').checked = data.allow_media;
    $('#community-summary').disabled = busy || !data.configured;
    $('#publish-community-event').disabled = busy || !data.configured || !selected;
    $('#publish-community-media').disabled = !data.allow_media || !selected?.media_id || selected?.removed;
    $('#community-publication-title').textContent = selected ? `${selected.title} · Version ${selected.revision}` : 'Einen Moment in der Chronik öffnen und „In Discord teilen“ wählen.';
    const row = selected && data.publications.find(row=>row.event_id === selected.id);
    $('#community-publication-state').textContent = row ? `Discord: ${{sent:'Veröffentlicht',sending:'Versand nicht abschließend bestätigt',uncertain:'Ausgang unklar',limited:'Discord-Wartezeit',blocked:'Veröffentlichung gesperrt'}[row.state] || row.state}${row.error ? ` · ${row.error}` : ''}` : '';
    const summary = data.publications.find(row=>row.event_id === 'community-summary');
    $('#community-summary-state').textContent = summary ? `Gemeinschaftskarte: ${summary.state === 'sent' ? 'veröffentlicht' : summary.state}${summary.error ? ` · ${summary.error}` : ''}` : '';
  } catch (error) { if (own === generation) $('#community-discord-state').textContent = error.message; }
}
export async function openCommunityPublishing(event) {
  selected = event; snapshot = null; $('#moment-detail').close(); $('#community-discord').open = true;
  $('#publish-community-media').checked = false; $('#replace-community-post').checked = false;
  $('#publish-community-event').disabled = true; await loadCommunityDiscord(); $('#community-discord').scrollIntoView({block:'start',behavior:'smooth'});
}
async function publish(summary) {
  if (busy || !snapshot?.configured || !summary && !selected) return;
  const id = summary ? 'community-summary' : selected.id, row = snapshot.publications.find(item=>item.event_id === id);
  const replace = $('#replace-community-post').checked;
  const media = !summary && !$('#publish-community-media').disabled && $('#publish-community-media').checked;
  if (!confirm(`${summary ? 'Eine Gemeinschaftskarte mit den letzten fünf Momenten' : selected.title}${media ? ' samt Screenshot' : ''} im eingerichteten Discord-Kanal veröffentlichen?${replace ? ' Es wird ausdrücklich eine neue Nachricht angelegt; eine bisherige Nachricht bleibt erhalten. Prüfe bei unklarem Versand zuerst den Kanal.' : ''}`)) return;
  busy = true;
  try {
    const {job} = await api('/api/community/discord/publish',{kind:summary ? 'summary' : 'event',event_id:id,revision:summary ? 0 : selected.revision,
      media,replace,destination_revision:snapshot.destination_revision,generation:row?.generation || 'new',request_id:requestId()});
    accepted(job); notice('Gemeinschaftsveröffentlichung als Auftrag angenommen.'); await loadCommunityDiscord();
  } catch (error) { notice(error.message,true); } finally { busy = false; await loadCommunityDiscord(); }
}
export function bindCommunityDiscord(onAccepted) {
  accepted = onAccepted;
  $('#community-discord').addEventListener('toggle',()=>{if ($('#community-discord').open) loadCommunityDiscord();});
  $('#community-discord-form').addEventListener('submit',async event=>{
    event.preventDefault(); if (busy) return; busy = true;
    try {
      const result = await api('/api/community/discord/settings',{webhook_url:$('#community-webhook').value,clear_webhook:$('#clear-community-webhook').checked,allow_media:$('#community-discord-media').checked});
      $('#community-webhook').value = ''; $('#clear-community-webhook').checked = false; notice(result.message);
    } catch (error) { notice(error.message,true); } finally { busy = false; await loadCommunityDiscord(); }
  });
  $('#publish-community-event').addEventListener('click',()=>publish(false));
  $('#community-summary').addEventListener('click',()=>publish(true));
}
