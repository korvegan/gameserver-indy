import {api} from '../api.js';
import {$, element, notice} from '../dom.js';
import {can, state} from '../state.js';

let submitting = false, currentConnection, renderRevision = 0;

function addressCard(label, addresses, empty, id) {
  const card = element('div', undefined, 'join-card');
  card.dataset.scope = id;
  card.append(element('h4', label));
  if (id === 'external') card.append(element('p', 'Zugang von außen ungeprüft', 'field-help'));
  if (!addresses.length) card.append(element('p', empty, 'muted'));
  for (const address of addresses) {
    const row = element('div', undefined, 'join-address');
    const value = element('code', address, 'path');
    const button = element('button', 'Kopieren', 'button secondary');
    button.type = 'button'; button.setAttribute('aria-label', `${label}: Adresse kopieren`);
    button.addEventListener('click', async () => {
      try { await navigator.clipboard.writeText(address); notice('Beitrittsadresse kopiert.'); }
      catch {
        const range = document.createRange(); range.selectNodeContents(value);
        const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range);
        notice('Adresse markiert. Mit Strg+C oder dem Kopiermenü kopieren.');
      }
    });
    row.append(value, button); card.append(row);
  }
  return card;
}

export function renderConnection(connection) {
  renderRevision += 1;
  currentConnection = connection;
  const data = connection || {}, container = $('#join-addresses');
  const direct = data.mode === 'ip_query';
  const signature = JSON.stringify(data);
  if (container.dataset.snapshot !== signature) {
    container.dataset.snapshot = signature;
    container.replaceChildren();
    if (direct) container.append(
      addressCard('Dieser PC', data.local ? [data.local] : [], 'Keine lokale Adresse aus der Konfiguration ableitbar.', 'local'),
      addressCard('Im Heimnetz', data.lan || [], 'Keine aktuelle Heimnetzadresse ermittelt oder Serverbindung eingeschränkt.', 'lan'),
      addressCard('Extern · für Mitspieler', data.external ? [data.external] : [], 'Keine aktuelle externe Beitrittsadresse ermittelt.', 'external'));
  }
  container.hidden = !direct;
  $('#join-instructions').textContent = !connection ? 'Beitrittsinformationen werden geladen …'
    : data.instructions || (direct ? 'Im Spiel die Suche nach IP / Domain öffnen und die Adresse samt Port eingeben.'
      : 'Im Spiel die Serverliste öffnen und nach dem oben angezeigten Servernamen suchen. Für dieses Spiel ist hier noch kein direkter IP-Beitritt bestätigt.');
  $('#join-password').textContent = data.password_hint || (connection ? 'Ein benötigtes Serverpasswort separat teilen.' : '');
  $('#join-network-note').textContent = direct ? `Externer Zugang ungeprüft. Router und Windows-Firewall müssen den Spielverkehr erlauben; für die IP-Suche UDP ${data.port || 'Query-Port'} an die Heimnetzadresse dieses PCs weiterleiten. Die Adresse setzt denselben externen Port voraus. Weitere Spiel-/Steam-Ports können nötig sein. Bei CGNAT/DS-Lite ist direkter IPv4-Zugang ggf. nicht möglich. Im eigenen Heimnetz die Heimnetzadresse verwenden.` : '';
  $('#join-checked').textContent = data.checking ? 'Adressen werden im Hintergrund ermittelt …'
    : data.checked_at ? `${data.stale ? 'Stand veraltet · ' : ''}Ermittelt: ${new Date(data.checked_at).toLocaleString('de-DE')} · Windows-Heimnetz / öffentliche IPv4 über ipify. Automatisch alle 15 Minuten.`
      : 'Noch keine Adressermittlung. Der Spielprozess wird dadurch nicht verändert.';
  $('#refresh-connections').disabled = submitting || Boolean(data.checking) || !state.selectedServer || !can('connections.refresh');
}

export function bindConnections() {
  $('#refresh-connections').addEventListener('click', async () => {
    if (submitting) return;
    const target = state.selectedServer, epoch = state.selectionEpoch, revision = renderRevision;
    submitting = true; $('#refresh-connections').disabled = true;
    try {
      const {connection} = await api('/api/connections/refresh', {}, target);
      if (epoch === state.selectionEpoch && revision === renderRevision) renderConnection(connection);
    } catch (error) { notice(error.message, true); }
    finally {
      submitting = false;
      renderConnection(currentConnection);
    }
  });
}
