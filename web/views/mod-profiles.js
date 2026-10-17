import {api} from '../api.js';
import {element, notice} from '../dom.js';
import {state} from '../state.js';

export async function profilesSection(game, catalog) {
  const root = element('section', undefined, 'mod-profiles');
  const base = `/api/mods/${encodeURIComponent(game.id)}/profiles`;
  async function load() {
    const {profiles} = await api(base);
    root.replaceChildren(element('h3', 'Gemeinsame Modprofile'));
    root.append(element('p', 'Feste Revisionen zum eigenen Abgleich. Manuelle Rückmeldung ist kein automatisch verifizierter Dateistand. Nach 24 Stunden wird sie als älter angezeigt.', 'muted'));
    for (let i = 0; i < profiles.length; i++) {
      const profile = profiles[i], m = profile.manifest, row = element('article'); row.dataset.modProfile = m.id;
      row.append(element('h4', `${m.name} · Revision ${m.revision}${profile.withdrawn_at ? ' · zurückgezogen' : ''}`));
      row.append(element('p', `${m.game_version} · ${m.loader} · ${m.items.length} Mods · ${profile.manual_confirmations} aktuelle manuelle Bestätigungen`));
      row.append(element('small', `Profilkennung: ${m.id} · Inhaltskennung: ${profile.sha256}`));
      const entries = element('ul');
      for (const item of m.items) {
        const li = element('li'), link = element('a', item.name); link.href = item.url; link.target = '_blank'; link.rel = 'noopener noreferrer';
        li.append(link, document.createTextNode(` · ${item.version} · ${item.file_id} · ${item.target} · Ladefolge ${item.order}`));
        li.append(element('small', `SHA-256: ${item.sha256} · ${item.loader} · Abhängigkeiten: ${item.dependencies.join(', ') || 'keine angegeben'}`));
        if (item.notes) li.append(element('p', item.notes)); entries.append(li);
      }
      row.append(entries);
      const receipt = profile.receipt;
      row.append(element('p', receipt ? `Deine Rückmeldung: ${receipt.status === 'confirmed' ? 'manuell abgeglichen' : 'abweichend'}${receipt.fresh ? '' : ' · älter als 24 Stunden'}` : 'Dein lokaler Stand: unbekannt'));
      if (!profile.withdrawn_at) {
        const download = element('a', 'Manifest herunterladen', 'button secondary'); download.href = `${base}/${m.id}/export`; row.append(download);
        for (const [status, text] of [['confirmed','Eigene Versionen und Hashes geprüft'],['different','Bei mir abweichend']]) {
          const button = element('button', text, 'button secondary'); button.type = 'button'; row.append(button);
          button.addEventListener('click', () => action(button, `${base}/${m.id}`, {action:'confirm',status,sha256:profile.sha256}));
        }
        if (state.principal?.permissions.includes('catalog.write')) {
          const button = element('button', 'Revision zurückziehen', 'button secondary'); button.type = 'button'; row.append(button);
          button.addEventListener('click', () => { if (confirm('Diese Revision zurückziehen? Bestehende Installationen bleiben erhalten.')) action(button, `${base}/${m.id}`, {action:'withdraw'}); });
        }
      }
      if (profiles[i + 1]) {
        const button = element('button', 'Mit vorheriger Revision vergleichen', 'button secondary'), result = element('div'); button.type = 'button'; row.append(button, result);
        button.addEventListener('click', async () => {
          try {
            const delta = await api(`${base}/${m.id}/compare/${profiles[i + 1].manifest.id}`);
            result.replaceChildren(element('p', `Neu: ${delta.added.length} · Entfernt: ${delta.removed.length} · Geändert: ${delta.changed.length} · Spielstand: ${delta.game_version_changed ? 'geändert' : 'gleich'} · Loader: ${delta.loader_changed ? 'geändert' : 'gleich'}`));
            for (const [key,label] of [['added','Neu'],['removed','Entfernt'],['changed','Geändert']]) for (const item of delta[key]) result.append(element('p', `${label}: ${item.name} · ${item.version} · ${item.target}`));
          } catch (error) { notice(error.message, true); }
        });
      }
      root.append(row);
    }
    if (!state.principal?.permissions.includes('catalog.write')) return;
    const form = element('form', undefined, 'settings-grid'); form.dataset.modPublish = game.id;
    for (const [name,label] of [['name','Profilname'],['game_version','Exakter Spielbuild / Version'],['loader','Loader und Version des Profils']]) {
      const wrap = element('label', label), field = element('input'); field.name = name; field.required = true; wrap.append(field); form.append(wrap);
    }
    for (const item of Object.values(catalog.items)) {
      const label = element('label'), check = element('input'); check.type = 'checkbox'; check.name = 'items'; check.value = item.id;
      label.append(check, document.createTextNode(`${item.name} · ${item.version || 'Version fehlt'} · ${item.file_id || 'Dateikennung fehlt'}`)); form.append(label);
    }
    const button = element('button', 'Neue unveränderliche Revision freigeben', 'button secondary'); form.append(button); root.append(form);
    form.addEventListener('submit', event => { event.preventDefault(); const data = new FormData(form); action(button, base, {name:data.get('name'),game_version:data.get('game_version'),loader:data.get('loader'),items:data.getAll('items')}); });
  }
  async function action(button, path, body) {
    button.disabled = true;
    try { const result = await api(path, body); notice(result.message); await load(); }
    catch (error) { notice(error.message, true); } finally { button.disabled = false; }
  }
  await load(); return root;
}
