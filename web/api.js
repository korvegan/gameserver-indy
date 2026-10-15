// Explicit request targets: selection changes cannot retarget an in-flight action.
let token = '';
let recoverSession;
export function setToken(value) { token = value; }
export function onSessionExpired(callback) { recoverSession = callback; }

export async function api(path, body, serverId, signal) {
  const options = {cache: 'no-store', signal};
  if (body !== undefined) {
    const payload = serverId ? {...body, server_id: serverId} : body;
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
