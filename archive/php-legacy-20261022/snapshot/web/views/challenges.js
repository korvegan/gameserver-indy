import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';
let generation = 0, busy = false, afterChange;
const names = {achieved:'Erreicht',not_achieved:'Noch nicht erreicht',unknown:'Nachweis offen',private:'Daten privat',error:'Quelle nicht erreichbar'};
function selectValues(select, rows) {
  const selected = [...select.selectedOptions].map(option=>option.value);
  select.replaceChildren(...rows.map(([value, name])=>Object.assign(element('option',name),{value,selected:selected.includes(value)})));
}
function instances() {
  const game = $('#campaign-game').value;
  selectValues($('#campaign-instance'),[['','Ohne Serverzuordnung'],...state.catalog.servers.filter(server=>server.game===game).map(server=>[server.id,server.label])]);
}
async function send(path, body) {
  if (busy) return; busy = true;
  try { const result = await api(path,body); notice(result.message); await loadChallenges(); await afterChange?.(); }
  catch (error) { notice(error.message,true); } finally { busy = false; }
}
export async function loadChallenges() {
  if (state.view !== 'community' || !$('#community-challenges').open) return;
  const own = ++generation, epoch = state.navigationEpoch;
  try {
    const [data, profiles, options] = await Promise.all([api('/api/community/challenges'),api('/api/community/members'),api('/api/community/options')]);
    if (own !== generation || epoch !== state.navigationEpoch || state.view !== 'community') return;
    const list = $('#challenge-list'); list.replaceChildren();
    if (!data.goals.length) list.append(element('p','Noch keine Sammelziele. Ein Besitzer legt eine Kampagne mit festem Mitgliederkreis an.','muted'));
    for (const goal of data.goals) {
      const card = element('section',undefined,'panel'); card.dataset.goalId = goal.id;
      card.append(element('h3',goal.title),element('p',`${goal.campaign_label} · Regel ${goal.rule_version} · ${goal.withdrawn ? 'Abschluss zurückgezogen' : goal.completed_at ? 'Gemeinsam geschafft' : `${goal.achieved}/${goal.total} erreicht`}`));
      const progress = element('progress'); progress.max = goal.total; progress.value = goal.achieved; progress.setAttribute('aria-label',`${goal.achieved} von ${goal.total} Beteiligten erreicht`); card.append(progress);
      for (const id of goal.member_ids) {
        const proof = goal.evidence[id];
        card.append(element('p',`${goal.member_names[id]} · ${names[proof.state]}${proof.source !== 'none' ? ` · ${proof.source === 'manual' ? 'manuell bestätigt' : proof.source}` : ''}${proof.observed_at ? ` · beobachtet ${new Date(proof.observed_at).toLocaleString('de-DE')}` : ''}`,'muted'));
      }
      if (goal.completed_at) {
        const link = element('a','Gemeinsamen Moment öffnen','button secondary'); link.href = `#moment-challenge-${goal.id}`; card.append(link);
        if (can('access.manage') && !goal.withdrawn) {
          const withdraw = element('button','Abschluss zurückziehen','button secondary'); withdraw.type = 'button';
          withdraw.addEventListener('click',()=>{const reason = prompt('Warum soll dieser Abschluss zurückgezogen werden? Die bisherige Version bleibt erhalten.');if (reason?.trim()) send('/api/community/challenges/withdraw',{goal_id:goal.id,reason});}); card.append(withdraw);
        }
      } else if (can('community.write')) {
        const form = element('form',undefined,'form-grid');
        const member = element('select'), status = element('select'), date = element('input'), note = element('input');
        for (const id of goal.member_ids) member.append(Object.assign(element('option',goal.member_names[id]),{value:id}));
        for (const [value,name] of Object.entries(names)) status.append(Object.assign(element('option',name),{value}));
        status.value = 'unknown'; date.type = 'datetime-local'; note.maxLength = 300;
        for (const [label,input] of [['Mitglied',member],['Manueller Nachweis',status],['Ursprünglicher Zeitpunkt (optional)',date],['Notiz (optional)',note]]) {
          const wrapper = element('label',label); wrapper.append(input); form.append(wrapper);
        }
        const submit = element('button','Nachweis speichern','button secondary'); submit.type = 'submit'; form.append(submit);
        form.addEventListener('submit',event=>{event.preventDefault();send('/api/community/challenges/evidence',{goal_id:goal.id,member_id:member.value,state:status.value,
          occurred_at:date.value ? new Date(date.value).toISOString() : '',note:note.value});}); card.append(form);
      }
      list.append(card);
    }
    selectValues($('#campaign-game'),options.games.map(game=>[game.id,game.label])); instances();
    selectValues($('#campaign-members'),profiles.members.filter(member=>member.enabled).map(member=>[member.id,member.name]));
    selectValues($('#goal-campaign'),data.campaigns.map(campaign=>[campaign.id,`${campaign.name} · ${campaign.member_ids.length} Mitglieder`]));
    $('#goal-create').disabled = !data.campaigns.length;
  } catch (error) { if (own === generation) $('#challenge-list').textContent = error.message; }
}
export function bindChallenges(changed) {
  afterChange = changed;
  $('#community-challenges').addEventListener('toggle',()=>{if ($('#community-challenges').open) loadChallenges();});
  $('#campaign-game').addEventListener('change',instances);
  $('#campaign-form').addEventListener('submit',event=>{event.preventDefault();send('/api/community/campaigns',{name:$('#campaign-name').value,game_id:$('#campaign-game').value,
    instance_id:$('#campaign-instance').value,member_ids:[...$('#campaign-members').selectedOptions].map(option=>option.value)});});
  $('#goal-form').addEventListener('submit',event=>{event.preventDefault();send('/api/community/challenges',{campaign_id:$('#goal-campaign').value,title:$('#goal-title').value,
    badge:$('#goal-badge').value,rule_version:Number($('#goal-version').value)});});
}
