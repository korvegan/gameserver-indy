// One source of truth for selection, drafts, permissions and pending operations.
let selectedServer = '';
try { selectedServer = localStorage.getItem('gameserver-indy-server') || ''; } catch { /* Optional preference. */ }

export const state = {
  selectedServer, selectionEpoch: 0, navigationEpoch: 0, view: 'overview', snapshot: null,
  catalog: {servers: [], games: []}, principal: null,
  config: null, schema: null, dirty: false, rawDirty: false, discordDirty: false, messageDirty: false, profileDirty: false,
  discordRevision: 0, messageRevision: 0,
  communityDirty: false,
  connectionDirty: false,
  whatsappDirty: false,
  accessDirty: false,
  available: false, catalogAvailable: false, submitting: false, refreshing: false, configLoading: false,
  logSource: 'server', pending: new Map(), latestJobs: [],
};

export const can = permission => Boolean(state.principal?.permissions.includes(permission));

export function rememberServer() {
  try { localStorage.setItem('gameserver-indy-server', state.selectedServer); } catch { /* Optional preference. */ }
}

export function replaceSelection(serverId) {
  state.selectedServer = serverId;
  state.selectionEpoch++;
  Object.assign(state, {snapshot: null, config: null, schema: null, dirty: false, rawDirty: false,
    available: false, configLoading: false, latestJobs: [], logSource: 'server'});
  rememberServer();
}
