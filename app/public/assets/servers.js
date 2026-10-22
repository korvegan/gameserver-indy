'use strict';
const token = document.querySelector('meta[name="indy-csrf"]').content;
const notice = document.querySelector('[data-notice]');
const dialog = document.querySelector('dialog');
const busy = new Set();
let selectedStop;
let lastRefresh = 0;
let active = false;
let reading = false;
let connectionLost = false;
const say = (message) => { notice.textContent = message; };

async function request(path, data) {
  const response = await fetch(path, { method: data ? 'POST' : 'GET', cache: 'no-store', signal: AbortSignal.timeout(4000),
    ...(data ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams({ csrf: token, ...data }) } : {}) });
  if (response.status === 403) {
    location.replace(location.pathname + '?action=denied');
    throw new Error('Der Zugang wird erneut geprüft.');
  }
  let value;
  try { value = await response.json(); }
  catch { throw new Error('Die Antwort konnte nicht bestätigt werden. Bitte prüfe den aktuellen Status.'); }
  if (!response.ok) throw new Error(value.message || 'Die Anfrage konnte nicht bestätigt werden.');
  return value;
}

function render(servers) {
  active = servers.some((s) => s.operation && ['pending','running','unknown'].includes(s.operation.state));
  for (const server of servers) {
    const card = document.querySelector(`[data-game="${server.id}"]`);
    if (!card) continue;
    const status = card.querySelector('[data-status]');
    if (status.textContent !== server.status) status.textContent = server.status;
    status.className = `state-title ${server.tone}`;
    const detail = card.querySelector('[data-detail]');
    if (detail.textContent !== server.detail) detail.textContent = server.detail;
    card.querySelector('[data-action="start"]').disabled = !server.can_start || busy.has(server.id);
    card.querySelector('[data-action="stop"]').disabled = !server.can_stop || busy.has(server.id);
    card.querySelector('[data-refresh]').hidden = !['unknown','error'].includes(server.tone);
    const connection = card.querySelector('[data-connection]');
    if (connection) {
      const value = server.connection ?? 'Noch nicht verfügbar';
      if (connection.value !== value) connection.value = value;
      card.querySelector('[data-copy]').disabled = server.connection === null;
    }
  }
}

async function read() {
  if (reading) return;
  reading = true;
  try {
    render((await request('/api/status')).servers);
    if (connectionLost && notice.textContent === 'Verbindung unterbrochen. Bitte prüfe den aktuellen Status.') say('');
    connectionLost = false;
  }
  catch (error) {
    for (const button of document.querySelectorAll('[data-action],[data-copy]')) button.disabled = true;
    say('Verbindung unterbrochen. Bitte prüfe den aktuellen Status.');
    connectionLost = true;
  } finally { reading = false; }
}

async function refresh() {
  if (Date.now() - lastRefresh < 4500) return;
  lastRefresh = Date.now();
  try { await request('/api/refresh', {}); }
  catch (error) { say(error.message); }
}

async function action(server, action) {
  if (busy.has(server)) return;
  busy.add(server);
  for (const button of document.querySelectorAll(`[data-server="${server}"]`)) button.disabled = true;
  try {
    await request('/api/actions', { server, action, ...(action === 'stop' ? { confirmed: server } : {}) });
    say('');
  } catch (error) {
    say(error.name === 'TimeoutError' || error instanceof TypeError
      ? 'Antwort fehlt. Bitte prüfe den aktuellen Status. Die Aktion wird nicht wiederholt.' : error.message);
  } finally {
    busy.delete(server);
    await read();
  }
}

for (const button of document.querySelectorAll('[data-action]')) {
  button.addEventListener('click', () => {
    if (button.dataset.action === 'stop') {
      selectedStop = button.dataset.server;
      document.querySelector('#stop-title').textContent = `${selectedStop === 'aska' ? 'ASKA' : 'Enshrouded'} stoppen?`;
      dialog.showModal();
      dialog.querySelector('[data-cancel]').focus();
    } else { action(button.dataset.server, 'start'); }
  });
}
dialog.querySelector('[data-cancel]').addEventListener('click', () => dialog.close());
dialog.querySelector('[data-confirm]').addEventListener('click', () => {
  const server = selectedStop;
  dialog.close();
  action(server, 'stop');
});
dialog.addEventListener('keydown', (event) => {
  if (event.key !== 'Tab') return;
  const first = dialog.querySelector('[data-cancel]');
  const last = dialog.querySelector('[data-confirm]');
  if (event.shiftKey && document.activeElement === first) { last.focus(); event.preventDefault(); }
  else if (!event.shiftKey && document.activeElement === last) { first.focus(); event.preventDefault(); }
});
for (const button of document.querySelectorAll('[data-refresh]')) button.addEventListener('click', async () => { await refresh(); await read(); });
for (const button of document.querySelectorAll('[data-copy]')) button.addEventListener('click', async () => {
  const input = button.closest('[data-game]').querySelector('[data-connection]');
  try { await navigator.clipboard.writeText(input.value); say('Kopiert'); }
  catch { input.focus(); input.select(); say('Kopieren nicht möglich'); }
});
async function poll() {
  if (!document.hidden) { await refresh(); await read(); }
  setTimeout(poll, active ? 750 : 2500);
}
poll();
