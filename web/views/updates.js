import {$} from '../dom.js';

export function renderUpdate(update) {
  const data = update || {};
  $('#steam-summary').textContent = data.label || 'Prüfung offen';
  $('#update-tab-hint').hidden = !['available', 'update_available', 'restart_required'].includes(data.state);
  $('#update-state').textContent = data.label || 'Unbekannt';
  $('#update-state').className = 'pill' + (data.state === 'current' ? ' running' : '');
  $('#installed-build').textContent = data.installed_build || 'Unbekannt';
  $('#available-build').textContent = data.available_build || 'Unbekannt';
  $('#steam-branch').textContent = data.branch || 'Unbekannt';
  $('#update-evidence').textContent = `${data.local_source || 'Lokaler Stand unbekannt'} · ${data.remote_source || 'Steam noch nicht geprüft'}${data.app_id ? ` · App ${data.app_id}` : ''}`;
  $('#update-checked').textContent = data.checked_at ? `Letzte erfolgreiche Prüfung: ${new Date(data.checked_at).toLocaleString('de-DE')}` : 'Noch keine erfolgreiche Steam-Prüfung.';
  $('#update-message').textContent = data.message || 'Versionsinformationen werden geladen …';
  $('#update-policy').textContent = data.automatic === undefined ? 'Prüft nur Versionen.'
    : data.automatic ? 'Prüft nur Versionen. Automatisch alle 30 Minuten.' : 'Prüft nur Versionen. Automatische Prüfung deaktiviert.';
  $('#running-build-note').textContent = data.process_message || '';
  $('#game-version').textContent = data.version ? `Dokumentierte Spielversion ${data.version} · ${data.version_source}` : 'Keine verlässlich zugeordnete Spielversionsnummer; Steam-Build wird getrennt geprüft.';
}
