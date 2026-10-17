import {api} from '../api.js';
import {element, notice} from '../dom.js';
import {state} from '../state.js';

const input = (form, name, label, value = '') => {
  const wrap = element('label', label), field = element('input'); field.name = name; field.value = value;
  wrap.append(field); form.append(wrap); return field;
};
const select = (form, name, label, values) => {
  const wrap = element('label', label), field = element('select'); field.name = name;
  for (const [id, text] of values) { const option = element('option', text); option.value = id; field.append(option); }
  wrap.append(field); form.append(wrap); return field;
};
export function modSection(game) {
  const root = element('details', undefined, 'mod-catalog'), title = element('summary', 'Modquellen und feste Modstände'); root.append(title);
  root.addEventListener('toggle', () => { if (root.open && !root.dataset.loaded) { root.dataset.loaded = '1'; load(); } });
  async function load() {
    try {
      const data = await api(`/api/mods/${encodeURIComponent(game.id)}`);
      if (!root.isConnected || state.view !== 'games') return;
      root.replaceChildren(title);
      let edit;
      root.append(element('p', 'Original-Downloads bleiben beim jeweiligen Spieler. Hier werden Quellen und Versionen manuell gepflegt; keine Installation.', 'muted'));
      for (const source of data.sources) {
        const line = element('p', `${source.label}: ${data.bindings[source.id] || 'Noch nicht zugeordnet'}`);
        line.append(element('small', source.capabilities.install.reason)); root.append(line);
      }
      for (const item of Object.values(data.items)) {
        const row = element('p'), link = element('a', item.name); link.href = item.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
        row.append(link, document.createTextNode(` · ${item.source} · ${item.version || 'Version offen'} · ${item.target} · ${item.loader || 'Loader offen'}`));
        row.append(element('small', `Datei: ${item.file_id || 'offen'} · SHA-256: ${item.sha256 || 'offen'} · Abrufbarkeit ungeprüft · ${item.id}`)); root.append(row);
        row.append(element('small', `Ladefolge: ${item.order} · Abhängigkeiten: ${item.dependencies.join(', ') || 'Keine angegeben'}`));
        if (item.notes) row.append(element('small', item.notes));
        if (state.principal?.permissions.includes('catalog.write')) {
          const button = element('button', 'Bearbeiten', 'button secondary'); button.type = 'button'; row.append(button);
          button.addEventListener('click', () => edit(item));
        }
      }
      if (!state.principal?.permissions.includes('catalog.write')) return;
      const sourceForm = element('form', undefined, 'settings-grid'); sourceForm.dataset.modSource = game.id;
      const source = select(sourceForm, 'source', 'Quelle', data.sources.map(row => [row.id, row.label]));
      const key = input(sourceForm, 'game_key', 'Spielkennung beim Anbieter (Steam: Spiel-App-ID, Nexus: Spielkürzel, Raft: raft)'); key.required = true;
      source.addEventListener('change', () => { key.value = data.bindings[source.value] || ''; }); source.dispatchEvent(new Event('change'));
      const saveSource = element('button', 'Quelle zuordnen', 'button secondary'); sourceForm.append(saveSource); root.append(sourceForm);
      sourceForm.addEventListener('submit', event => { event.preventDefault(); save(sourceForm, {action:'source'}); });
      if (!Object.keys(data.bindings).length) return;
      const form = element('form', undefined, 'settings-grid'); form.dataset.modItem = game.id;
      select(form, 'source', 'Modquelle', data.sources.filter(row => data.bindings[row.id]).map(row => [row.id,row.label]));
      input(form, 'url', 'Ursprüngliche Modseite (keine persönlichen Downloadlinks)').required = true;
      input(form, 'name', 'Modname').required = true;
      input(form, 'version', 'Exakte Modversion'); input(form, 'file_id', 'Datei-/Revisionskennung'); input(form, 'sha256', 'SHA-256 der eigenen Datei');
      input(form, 'loader', 'Benötigter Loader und Version');
      select(form, 'target', 'Benötigt auf', [['both','Server und Clients'],['client','Clients'],['server','Server'],['optional-client','Optionaler Client-Mod']]);
      input(form, 'order', 'Ladefolge', '0').type = 'number'; input(form, 'dependencies', 'Abhängigkeiten: Modkennungen mit Komma trennen');
      input(form, 'notes', 'Gemeinsame Hinweise (keine Passwörter oder persönlichen Einstellungen)');
      form.append(element('button', 'Modstand speichern', 'button secondary')); root.append(form);
      form.addEventListener('submit', event => { event.preventDefault(); save(form, {action:'item'}); });
      edit = item => {
        for (const name of ['source','url','name','version','file_id','sha256','loader','target','order','notes']) form.elements.namedItem(name).value = item[name] ?? '';
        form.elements.namedItem('dependencies').value = item.dependencies.join(', '); form.scrollIntoView({block:'nearest'});
      };
    } catch (error) { notice(error.message, true); delete root.dataset.loaded; }
  }
  async function save(form, fields) {
    const body = {...Object.fromEntries(new FormData(form)), ...fields};
    if (body.action === 'item') { body.order = Number(body.order); body.dependencies = body.dependencies.split(',').map(x => x.trim()).filter(Boolean); }
    const button = form.querySelector('button'); button.disabled = true;
    try { const result = await api(`/api/mods/${encodeURIComponent(game.id)}`, body); notice(result.message); await load(); }
    catch (error) { notice(error.message, true); } finally { button.disabled = false; }
  }
  return root;
}
