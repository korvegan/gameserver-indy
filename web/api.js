// Explicit request targets: selection changes cannot retarget an in-flight action.
import {state} from './state.js';
let token = '';
let recoverSession;
export function setToken(value) { token = value; }
export function onSessionExpired(callback) { recoverSession = callback; }
export const requestId = () => [...crypto.getRandomValues(new Uint8Array(16))].map(value => value.toString(16).padStart(2, '0')).join('');

export async function api(path, body, serverId, signal) {
  const options = {cache: 'no-store', signal};
  if (body !== undefined) {
    let payload = serverId ? {...body, server_id: serverId} : body;
    if (state.apiVersion >= 2) {
      payload = {...payload};
      if (path === '/api/servers') delete payload.server_id;
      if (['/api/jobs', '/api/config', '/api/setup', '/api/updates/check', '/api/connections/refresh', '/api/discord/send'].includes(path)) {
        payload.request_id ??= requestId();
      }
      if (path === '/api/config') payload.revision ??= state.configRevision;
      if (path === '/api/jobs') {
        const {server_id, request_id, operation, ...values} = payload;
        const allowed = {backup: [], backup_verify: ['backup_id'], backup_pin: ['backup_id', 'pinned'], backup_settings: ['automatic_after_stop'],
          backup_restore: ['backup_id', 'token', 'confirmation'], backup_recover: ['backup_id', 'token', 'confirmation']}[operation];
        const fields = allowed ?? Object.keys(values);
        const data = Object.fromEntries(fields.filter(key => Object.hasOwn(values, key)).map(key => [key, values[key]]));
        payload = {server_id, request_id, operation, ...(Object.keys(data).length ? {payload: data} : {})};
      }
    }
    Object.assign(options, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-Manager-Token': token},
      body: JSON.stringify(payload)});
  } else if (serverId) {
    path += `${path.includes('?') ? '&' : '?'}server=${encodeURIComponent(serverId)}`;
  }
  let response;
  try { response = await fetch(path, options); }
  catch (error) {
    if (error.name === 'AbortError') throw error;
    throw new Error('Die lokale Verwaltung ist nicht erreichbar. Bitte das Startfenster prüfen.');
  }
  let data;
  try { data = await response.json(); }
  catch { throw new Error('Die Verwaltung hat keine gültige Antwort geliefert. Bitte erneut aktualisieren.'); }
  if (!response.ok) {
    const error = new Error(data.error || 'Die Anfrage ist fehlgeschlagen.');
    error.code = data.code; error.status = response.status;
    if (body !== undefined && error.code === 'session_expired' && recoverSession) {
      try {
        await recoverSession();
        error.message = 'Sitzung aktualisiert. Die Aktion wurde nicht ausgeführt; bitte erneut beauftragen.';
      } catch {
        error.message = 'Zugriff konnte nicht aktualisiert werden. Bitte die Seite neu laden.';
      }
    }
    throw error;
  }
  return data;
}
