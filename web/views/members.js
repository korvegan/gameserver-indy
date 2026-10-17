import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';
let generation = 0, loaded, editing, busy = false, dirty = false;
function form(member) {
  editing = member; dirty = false;
  $('#member-name').value = member?.name || '';
  $('#member-role').value = member?.role || 'writer'; $('#member-enabled').checked = member?.enabled ?? true;
  for (const option of $('#member-accounts').options) option.selected = member?.principals.includes(option.value) || false;
  $('#member-form-title').textContent = member ? 'Mitgliederprofil bearbeiten' : 'Mitglied freigeben';
}
export async function loadMembers() {
  if (state.view !== 'community') return;
  const own = ++generation, epoch = state.navigationEpoch;
  try {
    const data = await api('/api/community/members');
    if (own !== generation || epoch !== state.navigationEpoch || state.view !== 'community') return;
    loaded = data; const list = $('#community-member-list'); list.replaceChildren();
    for (const member of data.members) {
      const row = element('div', undefined, 'profile-entry');
      row.append(element('strong', member.name), element('p', `${member.enabled ? member.role === 'writer' ? 'Momente festhalten' : 'Ansehen' : 'Ausgetreten / deaktiviert'} · ${member.steam_verified ? 'Steam verknüpft' : 'Ohne Steam'} · Spieldaten unbekannt`, 'muted'));
      if (can('access.manage')) {
        const edit = element('button','Bearbeiten','button secondary'); edit.type = 'button'; edit.addEventListener('click',()=>form(member)); row.append(edit);
      }
      list.append(row);
    }
    if (!data.members.length) list.append(element('p','Noch keine Mitgliederprofile freigegeben. Manuelle Namen funktionieren weiterhin.','muted'));
    $('#my-member-form').hidden = !data.own;
    if (data.own) {
      if (document.activeElement !== $('#my-member-name')) $('#my-member-name').value = data.own.name;
      $('#my-steam-state').textContent = data.own.steam_id ? `Verifiziert: ${data.own.steam_id}. Persönliche Spieldaten bisher unbekannt.` : 'Optional: Steam-ID über die offizielle Anmeldung bestätigen. Kein Steam-Passwort im Dashboard.';
      $('#link-steam').hidden = Boolean(data.own.steam_id); $('#unlink-steam').hidden = !data.own.steam_id;
    }
    if (!dirty) {
      const accounts = $('#member-accounts'); accounts.replaceChildren(...data.accounts.map(account=>Object.assign(element('option',account.name),{value:account.id})));
      form(editing);
    }
  } catch (error) { if (own === generation) $('#community-member-list').textContent = error.message; }
}
export function bindMembers() {
  $('#member-form').addEventListener('input',()=>{dirty = true;});
  $('#community-members').addEventListener('toggle',()=>{if ($('#community-members').open) loadMembers();});
  $('#new-member').addEventListener('click',()=>form(null));
  $('#member-form').addEventListener('submit',async event=>{
    event.preventDefault(); if (busy) return;
    const body = {name:$('#member-name').value,role:$('#member-role').value,enabled:$('#member-enabled').checked,
      principals:[...$('#member-accounts').selectedOptions].map(option=>option.value),...(editing ? {id:editing.id} : {})};
    busy = true;
    try { const result = await api('/api/community/members',body); editing = null; dirty = false; notice(result.message); await loadMembers(); }
    catch (error) { notice(error.message,true); } finally { busy = false; }
  });
  $('#my-member-form').addEventListener('submit',async event=>{
    event.preventDefault(); if (busy) return; busy = true;
    try { await api('/api/community/members/me',{name:$('#my-member-name').value}); notice('Anzeigename gespeichert. Frühere Momente behalten ihre Namen.'); await loadMembers(); }
    catch (error) { notice(error.message,true); } finally { busy = false; }
  });
  $('#unlink-steam').addEventListener('click',async()=>{
    if (busy || !confirm('Deine Steam-Verknüpfung trennen? Frühere Nachweise und Momente bleiben erhalten.')) return;
    busy = true;
    try { await api('/api/community/members/me',{disconnect_steam:true}); await loadMembers(); }
    catch (error) { notice(error.message,true); } finally { busy = false; }
  });
  $('#link-steam').addEventListener('click',async()=>{
    if (busy || !loaded?.own) return; busy = true;
    try {
      const {url} = await api('/api/community/steam/begin',{});
      const destination = new URL(url); if (destination.origin !== 'https://steamcommunity.com' || destination.pathname !== '/openid/login') throw new Error('Unerwartetes Anmeldeziel.');
      location.assign(url);
    } catch (error) { notice(error.message,true); } finally { busy = false; }
  });
}
