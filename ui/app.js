(() => {
  'use strict';

  const MAX_ENVELOPE_BYTES = 65536;
  const MAX_STRING_BYTES = 8192;
  const MAX_ROWS = 100;
  const MAX_CATALOG_ACTORS = 10000;
  const MAX_UNRESOLVED_LETTERS = 3;
  const CONNECTION_STATES = new Set(['pending', 'approved', 'expired']);
  const CONNECTION_SETUP_COPY = 'Setting up the connection with CHIM.';
  const CONNECTION_FAILURE_COPY = 'CHIM connection could not be confirmed. Retry connection to try again.';
  const CONNECTION_TIMEOUT_COPY = 'CHIM connection setup timed out. Retry connection to try again.';
  const CONNECTION_SLOW_COPY = 'Connection is taking longer than expected.';
  const CONNECTION_EXPIRED_COPY = 'The CHIM connection request expired. Retry connection to try again.';
  const CONNECTION_UNAUTHENTICATED_COPY = 'CHIM did not confirm the connection. Retry connection to try again.';
  const CONNECTION_MAX_POLLS = 12;
  const CONNECTION_POLL_INTERVAL_MS = 1000;
  const OPS = new Set([
    'bootstrap', 'view.close', 'connection.begin', 'connection.status', 'pcv.roster.read', 'pcv.status.read', 'pcv.arm', 'pcv.end', 'pcv.send', 'pcv.send.status',
    'mp.relationships.read', 'sas.issues.read', 'correspondence.catalog.read', 'correspondence.inventory.read',
    'correspondence.mailbox.read', 'correspondence.detail.read', 'correspondence.compose', 'correspondence.reconcile',
  ]);
  const CAPABILITY = {
    'pcv.roster.read': 'pcv', 'pcv.status.read': 'pcv', 'pcv.arm': 'pcv', 'pcv.end': 'pcv', 'pcv.send': 'pcv', 'pcv.send.status': 'pcv',
    'mp.relationships.read': 'mp', 'sas.issues.read': 'sas',
    'correspondence.catalog.read': 'correspondence', 'correspondence.inventory.read': 'correspondence',
    'correspondence.mailbox.read': 'correspondence', 'correspondence.detail.read': 'correspondence',
    'correspondence.compose': 'correspondence', 'correspondence.reconcile': 'correspondence',
  };
  const PERMISSION = {
    'pcv.roster.read': 'pcv', 'pcv.status.read': 'pcv', 'pcv.arm': 'pcv', 'pcv.end': 'pcv', 'pcv.send': 'pcv', 'pcv.send.status': 'pcv',
    'mp.relationships.read': 'inspector', 'sas.issues.read': 'inspector',
    'correspondence.inventory.read': 'compose',
    'correspondence.mailbox.read': 'inspector', 'correspondence.detail.read': 'inspector',
    'correspondence.compose': 'compose', 'correspondence.reconcile': 'compose',
  };
  const ERROR_COPY = {
    AUTH_REQUIRED: 'This CHIM connection is not authorized.',
    FORBIDDEN: 'This session does not have permission for this action.',
    CONTEXT_STALE: 'The game context changed. Refresh this view before continuing.',
    STALE_REVISION: 'The saved state changed. Refresh and review it before trying again.',
    ACTOR_UNAVAILABLE: 'That actor is no longer eligible. Refresh the cast and choose again.',
    INVALID_INPUT: 'Some values are invalid. Review the fields and try again.',
    BUSY: 'A previous operation is still active. Check its status before continuing.',
    UNAVAILABLE: 'This feature is unavailable right now. Refresh its status later.',
    RATE_LIMITED: 'Too many requests. Wait briefly before refreshing.',
    TOO_LARGE: 'The response exceeded the safe display limit and was discarded.',
    CONNECTION_SETUP_REQUIRED: 'CHIM’s server address could not be determined. Check that CHIM is connected, then restart Skyrim.',
    CONNECTION_SECURE_STORAGE_FAILED: 'Windows could not save this game’s connection. Connection was not started.',
  };
  const BOOTSTRAP_TIMEOUT_COPY = 'The bootstrap request timed out. No automatic retry was made.';
  const BOOTSTRAP_FAILURE_NEXT_STEP = 'Select Retry connection to try again.';
  const BOUNDARY_ERROR = {
    unauthorized: 'AUTH_REQUIRED', forbidden: 'FORBIDDEN', stale_context: 'CONTEXT_STALE',
    stale_revision: 'STALE_REVISION', actor_unavailable: 'ACTOR_UNAVAILABLE', invalid_input: 'INVALID_INPUT',
    busy: 'BUSY', unavailable: 'UNAVAILABLE', rate_limited: 'RATE_LIMITED', too_large: 'TOO_LARGE',
    connection_setup_required: 'CONNECTION_SETUP_REQUIRED', connection_secure_storage_failed: 'CONNECTION_SECURE_STORAGE_FAILED',
  };

  function createConnectionState() {
    return {
      busy: false, pending: false, pollTimer: null, pollCount: 0, autoStarted: false,
      retryAvailable: false, awaitingBootstrap: false, retryBootstrap: false,
      failureCode: null, message: null,
    };
  }

  const $ = (id) => document.getElementById(id);
  const text = (id, value) => { const node = $(id); if (node) node.textContent = value == null ? '' : String(value); };
  const state = {
    initialized: false, closed: true, closing: false, bootstrapping: false, bootstrapFailure: null,
    viewToken: null, nativeGeneration: null, serverContext: null, lastAuthenticatedScopeToken: null, seenViewTokens: [], idCounter: 0,
    authenticated: false, authRequired: true,
    connection: createConnectionState(),
    capabilities: { pcv: false, mp: false, sas: false, correspondence: false },
    access: { pcv: false, inspector: false, compose: false, authorDetail: false },
    perspectives: { mp: [], sas: [] }, perspectiveLimited: { mp: false, sas: false },
    mp: { records: [], nextCursor: null, limited: false }, sas: { issues: [], nextCursor: null, limited: false },
    pending: new Map(), inFlight: new Set(),
    pcv: { roster: null, status: null, mutationPending: false, mutationUnknown: false, mutationOperationId: null, sendPending: false, sendStatusPending: false, sendOperationId: null, sendUnknownId: null, sendSubmitted: null, sendDraftDiscardedId: null, draftText: '', lastFocusedControl: null },
    mail: { actors: [], catalogLimited: false, catalogRevision: null, catalogNextCursor: null, catalogError: null, inventory: null, inventoryLoaded: false, items: [], letters: [], selectedLetter: null, mailboxNextCursor: null,
      operations: [], currentOperationId: null, operationDraft: null, recoveryNotice: '', composeLocked: false, mailboxLimited: false, reviewed: false },
  };

  function bytes(value) { return new TextEncoder().encode(value).length; }
  function isPlainObject(value) { return value !== null && typeof value === 'object' && Object.getPrototypeOf(value) === Object.prototype; }
  function boundedTree(value, depth = 0) {
    if (depth > 8) return false;
    if (typeof value === 'string') return bytes(value) <= MAX_STRING_BYTES;
    if (typeof value === 'number') return Number.isFinite(value);
    if (value === null || typeof value === 'boolean') return true;
    if (Array.isArray(value)) return value.length <= 128 && value.every((item) => boundedTree(item, depth + 1));
    if (!isPlainObject(value)) return false;
    const entries = Object.entries(value);
    return entries.length <= 64 && entries.every(([key, item]) => bytes(key) <= MAX_STRING_BYTES && boundedTree(item, depth + 1));
  }
  function validUuid(value) { return typeof value === 'string' && /^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value); }
  function letterIntentFingerprint(payload) {
    if (!isPlainObject(payload) || !boundedString(payload.writerActorKey, 128) || !boundedString(payload.recipientActorKey, 128) || !boundedString(payload.body, 8192)) return '';
    const body = payload.body.replace(/\r\n?/g, '\n').trim().replace(/\s+/g, ' ');
    if (!body) return '';
    const value = JSON.stringify([payload.writerActorKey, payload.recipientActorKey, body]);
    let first = 0x811c9dc5;
    let second = 0x9e3779b9;
    for (let index = 0; index < value.length; index += 1) {
      const code = value.charCodeAt(index);
      first = Math.imul(first ^ code, 0x01000193);
      second = Math.imul(second ^ (code + index), 0x85ebca6b);
    }
    // shortcut: whitespace-only edits and rare 64-bit collisions can refuse a distinct draft; relax only if that becomes a product requirement.
    return `${value.length}:${first >>> 0}:${second >>> 0}`;
  }
  function validGeneration(value) { return typeof value === 'string' && /^\d{1,20}$/.test(value); }
  function validServerContext(value) { return value === null || (typeof value === 'string' && value.length > 0 && bytes(value) <= 128); }
  function boundedString(value, maxBytes = MAX_STRING_BYTES) { return typeof value === 'string' && bytes(value) <= maxBytes; }
  function validBoundaryError(error) {
    if (!isPlainObject(error) || !Object.prototype.hasOwnProperty.call(error, 'code') || !Object.prototype.hasOwnProperty.call(error, 'message')) return false;
    const keys = Object.keys(error);
    return (keys.length === 2 || keys.length === 3) && keys.every((key) => ['code', 'message', 'dispatched'].includes(key))
      && boundedString(error.code, 128) && boundedString(error.message, 256)
      && (!Object.prototype.hasOwnProperty.call(error, 'dispatched') || typeof error.dispatched === 'boolean');
  }
  function boundedLabel(value) { return typeof value === 'string' && value.length > 0 && value.length <= 2000 && bytes(value) <= MAX_STRING_BYTES; }
  function asSafeNumber(value) { return typeof value === 'number' && Number.isFinite(value) ? value : null; }
  function newUuid() {
    if (!validUuid(state.viewToken) || state.idCounter >= 1000000) throw new Error('The native view identifier is unavailable.');
    const groups = state.viewToken.split('-');
    const seed = Number.parseInt(groups[4], 16);
    const suffix = seed + state.idCounter + 1;
    if (!Number.isSafeInteger(seed) || suffix > 0xffffffffffff) throw new Error('The native view identifier counter is exhausted.');
    state.idCounter += 1;
    groups[4] = suffix.toString(16).padStart(12, '0');
    return groups.join('-');
  }
  function setStatus(nodeId, message, kind = '') {
    const node = $(nodeId);
    if (!node) return;
    node.textContent = message;
    node.dataset.state = kind;
  }
  function localError(error) {
    const code = error && typeof error.code === 'string' ? error.code : '';
    return ERROR_COPY[code] || 'The operation could not be confirmed. Refresh the current status before continuing.';
  }
  function failLocal(code, message) {
    const error = new Error(message || ERROR_COPY[code] || 'Operation unavailable.');
    error.code = code;
    error.dispatched = false;
    return error;
  }
  function isStale(error) { return Boolean(error && ['CONTEXT_STALE', 'VIEW_INVALIDATED'].includes(error.code)); }
  function makeContext() { return { viewToken: state.viewToken, nativeGeneration: state.nativeGeneration, serverContext: state.serverContext }; }
  function isCurrentContext(context) { return Boolean(state.initialized && !state.closed && !state.closing && exactContext(context, makeContext())); }
  function isCurrentView(viewToken, nativeGeneration) { return Boolean(state.initialized && !state.closed && !state.closing && state.viewToken === viewToken && state.nativeGeneration === nativeGeneration); }
  function exactContext(a, b) {
    return Boolean(a && b && a.viewToken === b.viewToken && a.nativeGeneration === b.nativeGeneration && a.serverContext === b.serverContext);
  }
  function validOptionalKey(value, maxBytes = 128) { return value === null || (boundedString(value, maxBytes) && value.length > 0); }
  function validConfigIdentity(value) { return value === null || validUuid(value); }
  function validPcvConfiguration(config) {
    if (!isPlainObject(config)) return false;
    const fields = new Set(['enabled', 'scene_mode', 'actor_a', 'actor_b', 'actor_ids', 'opener', 'free', 'free_cap', 'exclude_player', 'bystander_mode', 'card', 'pace', 'actions']);
    if (Object.keys(config).some((key) => !fields.has(key)) || config.enabled !== true || !['pair', 'solo'].includes(config.scene_mode)
      || typeof config.exclude_player !== 'boolean' || !['exclude', 'silent'].includes(config.bystander_mode)) return false;
    if (config.actor_a !== undefined && !validOptionalKey(config.actor_a)) return false;
    if (config.actor_b !== undefined && !validOptionalKey(config.actor_b)) return false;
    if (config.actor_ids !== undefined && (!Array.isArray(config.actor_ids) || config.actor_ids.length < 2 || config.actor_ids.length > 4
      || config.actor_ids.some((key) => !boundedString(key, 128) || !key) || new Set(config.actor_ids).size !== config.actor_ids.length)) return false;
    if (config.opener !== undefined && !(config.opener === 'auto' || (boundedString(config.opener, 128) && config.opener))) return false;
    if (config.free !== undefined && config.free !== true) return false;
    if (config.free_cap !== undefined && (!Number.isInteger(config.free_cap) || config.free_cap < 2 || config.free_cap > 6)) return false;
    if (config.free === true && (config.scene_mode !== 'pair' || config.free_cap === undefined || config.actor_ids !== undefined || config.actor_a !== undefined || config.actor_b !== undefined || config.exclude_player !== true)) return false;
    if (config.free !== true && config.free_cap !== undefined) return false;
    if (config.actor_ids !== undefined && (config.scene_mode !== 'pair' || config.actor_a !== undefined || config.actor_b !== undefined)) return false;
    if (config.scene_mode === 'solo' && (!boundedString(config.actor_a, 128) || !config.actor_a || (config.actor_b !== undefined && config.actor_b !== null) || config.actor_ids !== undefined)) return false;
    if (config.scene_mode === 'pair' && config.free !== true && config.actor_ids === undefined
      && (!boundedString(config.actor_a, 128) || !config.actor_a || !boundedString(config.actor_b, 128) || !config.actor_b || config.actor_a === config.actor_b)) return false;
    if (config.card !== undefined && (!boundedString(config.card, 1200) || Array.from(config.card).length > 300 || /[\r\n]/.test(config.card))) return false;
    if (config.pace !== undefined && !['short', 'normal', 'long'].includes(config.pace)) return false;
    if (config.actions !== undefined && (!Array.isArray(config.actions) || config.actions.length > 3
      || config.actions.some((action) => !['personal', 'physical', 'intimate'].includes(action)) || new Set(config.actions).size !== config.actions.length)) return false;
    return true;
  }
  function payloadFieldsValid(operation, payload) {
    const fields = {
      bootstrap: [], 'view.close': [], 'connection.begin': [], 'connection.status': [], 'pcv.roster.read': [], 'pcv.status.read': [],
      'pcv.arm': ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId', 'configuration'],
      'pcv.end': ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId'],
      'pcv.send': ['operationId', 'expectedActiveConfigId', 'expectedPendingConfigId', 'actorKey', 'text'],
      'pcv.send.status': ['operationId'],
      'mp.relationships.read': ['perspectiveActorKey', 'targetActorKey', 'limit', 'cursor'],
      'sas.issues.read': ['perspectiveActorKey', 'limit', 'cursor'], 'correspondence.catalog.read': ['limit', 'cursor'], 'correspondence.inventory.read': [],
      'correspondence.mailbox.read': ['recipientActorKey', 'limit', 'cursor'], 'correspondence.detail.read': ['recipientActorKey', 'letterId'],
      'correspondence.compose': ['operationId', 'writerActorKey', 'recipientActorKey', 'signatureActorKey', 'body', 'inventoryRevision', 'attachments', 'goldAmount'],
      'correspondence.reconcile': ['operationId'],
    }[operation];
    if (!Array.isArray(fields)) return false;
    if (!isPlainObject(payload)) return false;
    if (Object.keys(payload).some((key) => !fields.includes(key))) return false;
    if (operation === 'pcv.arm') {
      if (!validUuid(payload.operationId) || !validConfigIdentity(payload.expectedActiveConfigId) || !validConfigIdentity(payload.expectedPendingConfigId) || !validPcvConfiguration(payload.configuration)) return false;
    }
    if (operation === 'pcv.end' && (!validUuid(payload.operationId) || !validConfigIdentity(payload.expectedActiveConfigId) || !validConfigIdentity(payload.expectedPendingConfigId))) return false;
    if (operation === 'pcv.send' && (!validUuid(payload.operationId) || !validConfigIdentity(payload.expectedActiveConfigId) || !validConfigIdentity(payload.expectedPendingConfigId)
      || (payload.expectedActiveConfigId === null && payload.expectedPendingConfigId === null)
      || !boundedString(payload.actorKey, 128) || !payload.actorKey || !boundedString(payload.text, 8000) || Array.from(payload.text).length > 2000)) return false;
    if (operation === 'pcv.send.status' && !validUuid(payload.operationId)) return false;
    if (operation === 'correspondence.catalog.read' && ((payload.limit !== undefined && (!Number.isInteger(payload.limit) || payload.limit < 1 || payload.limit > 100))
      || (payload.cursor !== undefined && payload.cursor !== null && (!boundedString(payload.cursor, 512) || payload.cursor.length === 0)))) return false;
    if (operation === 'correspondence.compose') {
      if (!validUuid(payload.operationId) || !Array.isArray(payload.attachments) || payload.attachments.length > 8) return false;
      if (!boundedString(payload.body, 8192) || Array.from(payload.body).length > 2000) return false;
      if (!boundedString(payload.writerActorKey, 128) || !payload.writerActorKey || !boundedString(payload.recipientActorKey, 128) || !payload.recipientActorKey
        || (payload.signatureActorKey !== undefined && (!boundedString(payload.signatureActorKey, 128) || !payload.signatureActorKey))
        || !boundedString(payload.inventoryRevision, 128) || !payload.inventoryRevision
        || !Number.isSafeInteger(payload.goldAmount) || payload.goldAmount < 0
        || payload.attachments.length + (payload.goldAmount > 0 ? 1 : 0) > 8) return false;
      if (payload.attachments.some((item) => !isPlainObject(item) || Object.keys(item).some((key) => !['itemKey', 'quantity'].includes(key)) || !boundedString(item.itemKey, 96) || !Number.isInteger(item.quantity) || item.quantity < 1)) return false;
      if (new Set(payload.attachments.map((item) => item.itemKey)).size !== payload.attachments.length) return false;
    }
    if (operation === 'correspondence.reconcile' && !validUuid(payload.operationId)) return false;
    if (['mp.relationships.read', 'sas.issues.read', 'correspondence.mailbox.read'].includes(operation)) {
      if (!Number.isInteger(payload.limit) || payload.limit < 1 || payload.limit > 100) return false;
      if (payload.cursor !== undefined && !boundedString(payload.cursor, 512)) return false;
      if (operation === 'mp.relationships.read' && (!boundedString(payload.perspectiveActorKey, 128) || !payload.perspectiveActorKey
        || (payload.targetActorKey !== undefined && (!boundedString(payload.targetActorKey, 128) || !payload.targetActorKey)))) return false;
      if (operation === 'sas.issues.read' && (!boundedString(payload.perspectiveActorKey, 128) || !payload.perspectiveActorKey)) return false;
      if (operation === 'correspondence.mailbox.read' && (!boundedString(payload.recipientActorKey, 128) || !payload.recipientActorKey)) return false;
    }
    if (operation === 'correspondence.detail.read' && (!boundedString(payload.recipientActorKey, 128) || !payload.recipientActorKey || !boundedString(payload.letterId, 128) || !payload.letterId)) return false;
    return boundedTree(payload);
  }
  function validConnectionReply(operation, data) {
    if (!isPlainObject(data) || !CONNECTION_STATES.has(data.state)) return false;
    return Object.keys(data).length === 1 && Object.keys(data)[0] === 'state'
      && ['connection.begin', 'connection.status'].includes(operation);
  }
  function roleAllows(operation) {
    if (operation === 'bootstrap' || operation === 'view.close') return true;
    if (operation === 'connection.begin' || operation === 'connection.status') return !state.authenticated;
    const capability = CAPABILITY[operation];
    const permission = PERMISSION[operation];
    if (operation === 'correspondence.catalog.read') return state.authenticated && Boolean(capability && state.capabilities[capability]) && (state.access.compose || state.access.inspector);
    return state.authenticated && Boolean(capability && state.capabilities[capability]) && Boolean(permission && state.access[permission]);
  }
  function request(operation, payload = {}) {
    if (!OPS.has(operation)) return Promise.reject(failLocal('INVALID_INPUT', 'This operation is not available.'));
    if (!state.initialized || state.closed || state.closing) return Promise.reject(failLocal('CONTEXT_STALE'));
    if (operation !== 'bootstrap' && !roleAllows(operation)) return Promise.reject(failLocal(state.authenticated ? 'FORBIDDEN' : 'AUTH_REQUIRED'));
    if (!payloadFieldsValid(operation, payload)) return Promise.reject(failLocal('INVALID_INPUT', 'The request fields did not pass local validation.'));
    const context = makeContext();
    if (!['bootstrap', 'view.close', 'connection.begin', 'connection.status'].includes(operation)
      && (!validServerContext(context.serverContext) || context.serverContext === null)) return Promise.reject(failLocal('CONTEXT_STALE'));
    const envelope = { version: 1, requestId: newUuid(), operation, context, payload };
    if (!validUuid(envelope.requestId) || !validUuid(context.viewToken) || !validGeneration(context.nativeGeneration) || !validServerContext(context.serverContext) || !boundedTree(envelope)) {
      return Promise.reject(failLocal('INVALID_INPUT', 'The request exceeded a safe transport limit.'));
    }
    let serialized;
    try { serialized = JSON.stringify(envelope); } catch { return Promise.reject(failLocal('INVALID_INPUT')); }
    if (bytes(serialized) > MAX_ENVELOPE_BYTES || typeof window.departmentRequest !== 'function') return Promise.reject(failLocal('UNAVAILABLE'));
    return new Promise((resolve, reject) => {
      const timer = window.setTimeout(() => {
        state.pending.delete(envelope.requestId);
        const error = failLocal('UNAVAILABLE', operation === 'bootstrap'
          ? BOOTSTRAP_TIMEOUT_COPY : 'The request timed out. No automatic retry was made.');
        if (operation === 'bootstrap') error.bootstrapTimeout = true;
        error.timedOut = true;
        error.dispatched = true;
        reject(error);
      }, 20000);
      state.pending.set(envelope.requestId, { envelope, context, resolve, reject, timer });
      try { window.departmentRequest(serialized); }
      catch {
        window.clearTimeout(timer);
        state.pending.delete(envelope.requestId);
        const error = failLocal('UNAVAILABLE');
        // Once the native call starts, a throw cannot prove the command was not queued.
        // Mutations stay unknown and locked; reads can be safely refreshed by the user.
        error.dispatched = true;
        reject(error);
      }
    });
  }
  function clearPending(reason = 'The game view changed.') {
    if (state.connection.pollTimer !== null) {
      window.clearTimeout(state.connection.pollTimer);
      state.connection.pollTimer = null;
    }
    for (const [id, item] of state.pending) {
      window.clearTimeout(item.timer);
      item.reject(failLocal('VIEW_INVALIDATED', reason));
      state.pending.delete(id);
    }
    state.inFlight.clear();
  }
  function validateBootstrapData(data, responseContext) {
    const allowed = ['authenticated', 'authRequired', 'serverContext', 'scopeToken', 'capabilities', 'access', 'perspectives'];
    if (!isPlainObject(data) || Object.keys(data).some((key) => !allowed.includes(key)) || typeof data.authenticated !== 'boolean'
      || typeof data.authRequired !== 'boolean' || responseContext.serverContext !== null) return null;
    if (data.authenticated) {
      if (data.authRequired || !boundedString(data.serverContext, 128) || !data.serverContext
        || typeof data.scopeToken !== 'string' || !/^[A-Za-z0-9_-]{43}$/.test(data.scopeToken)) return null;
    } else if (!data.authRequired || data.serverContext !== null || data.scopeToken !== null) return null;
    if (!isPlainObject(data.capabilities) || !isPlainObject(data.access)) return null;
    const capKeys = ['pcv', 'mp', 'sas', 'correspondence'];
    const accessKeys = ['pcv', 'inspector', 'compose', 'authorDetail'];
    if (Object.keys(data.capabilities).length !== capKeys.length || Object.keys(data.capabilities).some((key) => !capKeys.includes(key))
      || Object.keys(data.access).length !== accessKeys.length || Object.keys(data.access).some((key) => !accessKeys.includes(key))
      || capKeys.some((key) => typeof data.capabilities[key] !== 'boolean') || accessKeys.some((key) => typeof data.access[key] !== 'boolean')) return null;
    if (!data.authenticated && accessKeys.some((key) => data.access[key])) return null;
    if (!data.authenticated && Object.prototype.hasOwnProperty.call(data, 'perspectives')) return null;
    if (data.access.authorDetail && !data.access.inspector) return null;
    if (data.access.inspector && (!isPlainObject(data.perspectives))) return null;
    const perspectives = isPlainObject(data.perspectives) ? data.perspectives : {};
    if (data.perspectives && (!isPlainObject(data.perspectives) || Object.keys(data.perspectives).some((key) => !['mp', 'sas'].includes(key)))) return null;
    const result = { authenticated: data.authenticated, authRequired: data.authRequired, serverContext: data.serverContext, scopeToken: data.scopeToken,
      capabilities: { pcv: data.capabilities.pcv, mp: data.capabilities.mp, sas: data.capabilities.sas, correspondence: data.capabilities.correspondence },
      access: { pcv: data.access.pcv, inspector: data.access.inspector, compose: data.access.compose, authorDetail: data.access.authorDetail }, perspectives: {}, perspectiveLimited: {} };
    for (const key of ['mp', 'sas']) {
      const item = perspectives[key];
      if (result.access.inspector) {
        if (!isPlainObject(item) || Object.keys(item).length !== 2 || Object.keys(item).some((field) => !['choices', 'limited'].includes(field))
          || !Array.isArray(item.choices) || item.choices.length > 64 || typeof item.limited !== 'boolean') return null;
      }
      const choices = item && Array.isArray(item.choices) ? item.choices : [];
      const seen = new Set();
      const clean = [];
      for (const choice of choices) {
        if (!isPlainObject(choice) || Object.keys(choice).length !== 2 || Object.keys(choice).some((field) => !['actorKey', 'label'].includes(field))
          || !boundedString(choice.actorKey, 128) || choice.actorKey.length === 0 || !boundedLabel(choice.label) || seen.has(choice.actorKey)) return null;
        seen.add(choice.actorKey);
        clean.push({ actorKey: choice.actorKey, label: choice.label });
      }
      result.perspectives[key] = clean;
      result.perspectiveLimited[key] = item && item.limited === true;
    }
    if (!result.access.inspector && (result.perspectives.mp.length || result.perspectives.sas.length)) return null;
    return result;
  }
  function receive(raw) {
    if (typeof raw !== 'string' || bytes(raw) > MAX_ENVELOPE_BYTES) return false;
    let response;
    try { response = JSON.parse(raw); } catch { return false; }
    if (!boundedTree(response) || !isPlainObject(response) || response.version !== 1 || !validUuid(response.requestId) || !OPS.has(response.operation) || typeof response.ok !== 'boolean') return false;
    const responseKeys = response.ok ? ['version', 'requestId', 'operation', 'context', 'ok', 'data'] : ['version', 'requestId', 'operation', 'context', 'ok', 'error'];
    if (Object.keys(response).length !== responseKeys.length || Object.keys(response).some((key) => !responseKeys.includes(key))) return false;
    const context = response.context;
    if (!isPlainObject(context) || Object.keys(context).length !== 3 || !['viewToken', 'nativeGeneration', 'serverContext'].every((key) => Object.prototype.hasOwnProperty.call(context, key))
      || !validUuid(context.viewToken) || !validGeneration(context.nativeGeneration) || !validServerContext(context.serverContext)) return false;
    const pending = state.pending.get(response.requestId);
    if (!pending || pending.envelope.operation !== response.operation || !exactContext(context, pending.context)) return false;
    if (state.closed || !state.initialized || context.viewToken !== state.viewToken || context.nativeGeneration !== state.nativeGeneration) return false;
    if (response.operation === 'bootstrap') {
      if (response.context.serverContext !== null) return false;
      if (response.ok === false && validBoundaryError(response.error)) {
        state.pending.delete(response.requestId);
        window.clearTimeout(pending.timer);
        const code = BOUNDARY_ERROR[response.error.code] || 'UNAVAILABLE';
        pending.reject(failLocal(code));
        state.bootstrapping = false;
        state.bootstrapFailure = { message: localError({ code }) };
        if (state.connection.awaitingBootstrap || state.connection.retryBootstrap) {
          state.connection.awaitingBootstrap = false;
          state.connection.retryBootstrap = false;
          state.connection.retryAvailable = true;
          state.connection.failureCode = 'UNCONFIRMED';
          state.connection.message = CONNECTION_FAILURE_COPY;
        }
        clearAuthorization(code);
        renderBootstrap();
        return true;
      }
      const bootstrap = validateBootstrapData(response.data, context);
      if (response.ok !== true || !bootstrap || state.bootstrapping !== true || state.serverContext !== null) return false;
      const wasApprovedBootstrap = state.connection.awaitingBootstrap;
      const wasRetryBootstrap = state.connection.retryBootstrap;
      state.pending.delete(response.requestId);
      window.clearTimeout(pending.timer);
      if (state.lastAuthenticatedScopeToken && bootstrap.authenticated && state.lastAuthenticatedScopeToken !== bootstrap.scopeToken) {
        clearScopeBoundOperations();
      }
      pending.resolve(bootstrap);
      state.serverContext = bootstrap.serverContext;
      state.authenticated = bootstrap.authenticated;
      state.authRequired = bootstrap.authRequired;
      state.capabilities = bootstrap.capabilities;
      state.access = bootstrap.access;
      state.perspectives = bootstrap.perspectives;
      state.perspectiveLimited = bootstrap.perspectiveLimited;
      if (bootstrap.authenticated) state.lastAuthenticatedScopeToken = bootstrap.scopeToken;
      state.bootstrapping = false;
      state.bootstrapFailure = null;
      if (!bootstrap.authenticated) clearAuthorization('AUTH_REQUIRED');
      if (state.authenticated && state.pcv.draftText) {
        $('pcv-text').value = state.pcv.draftText;
        updatePcvCount();
      }
      if (state.authenticated && state.mail.operationDraft && state.mail.currentOperationId && state.mail.composeLocked) {
        $('letter-body').value = state.mail.operationDraft.body;
        updateLetterCount();
      }
      if (state.authenticated) {
        state.connection = createConnectionState();
        renderBootstrap();
      } else if (wasApprovedBootstrap) {
        stopConnectionFailure(CONNECTION_UNAUTHENTICATED_COPY, 'UNCONFIRMED');
      } else if (wasRetryBootstrap) {
        state.connection.retryBootstrap = false;
        startConnectionBegin();
      } else if (!state.connection.autoStarted) {
        startConnectionBegin();
      } else {
        renderBootstrap();
      }
      if (state.authenticated && state.pcv.sendUnknownId) requestPcvSendStatus(state.pcv.sendUnknownId);
      return true;
    }
    if (!exactContext(context, pending.context) || !exactContext(context, makeContext())) return false;
    if (response.ok) {
      if (!Object.prototype.hasOwnProperty.call(response, 'data') || Object.prototype.hasOwnProperty.call(response, 'error')) return false;
    } else if (!validBoundaryError(response.error)) return false;
    state.pending.delete(response.requestId);
    window.clearTimeout(pending.timer);
    if (response.ok) pending.resolve(response.data);
    else {
      const code = BOUNDARY_ERROR[response.error.code] || 'UNAVAILABLE';
      const error = failLocal(code);
      error.boundaryCode = response.error.code;
      error.dispatched = Object.prototype.hasOwnProperty.call(BOUNDARY_ERROR, response.error.code)
        && Object.prototype.hasOwnProperty.call(response.error, 'dispatched') ? response.error.dispatched : true;
      if (['AUTH_REQUIRED', 'FORBIDDEN', 'CONTEXT_STALE'].includes(code)) {
        clearAuthorization(code);
        if (error.dispatched === false && response.operation === 'correspondence.compose'
          && releaseComposeOperation(pending.envelope.payload.operationId)) {
          setStatus('letter-receipt', `${localError(error)} The request was not sent; review current access and details before sending again.`, 'error');
          renderMailAccess();
        }
      }
      pending.reject(error);
    }
    return true;
  }
  function clearAuthorization(code = 'AUTH_REQUIRED') {
    const wasAuthenticated = state.authenticated;
    if (wasAuthenticated && ['AUTH_REQUIRED', 'FORBIDDEN', 'CONTEXT_STALE'].includes(code)) {
      state.connection.retryAvailable = true;
      state.connection.retryBootstrap = true;
      state.connection.failureCode = 'UNCONFIRMED';
      state.connection.message = 'CHIM connection must be refreshed. Retry connection to continue.';
    }
    state.authenticated = false;
    state.authRequired = true;
    state.serverContext = null;
    state.pcv.sendPending = false;
    state.pcv.sendStatusPending = false;
    state.pcv.sendOperationId = null;
    state.pcv.draftText = '';
    if (state.pcv.sendSubmitted) state.pcv.sendSubmitted.text = null;
    state.mail.operationDraft = null;
    state.mail.recoveryNotice = '';
    state.mail.reviewed = false;
    $('pcv-text').value = '';
    $('letter-body').value = '';
    $('letter-review-summary').replaceChildren();
    $('letter-review-summary').hidden = true;
    updatePcvCount();
    updateLetterCount();
    state.capabilities = { pcv: false, mp: false, sas: false, correspondence: false };
    state.access = { pcv: false, inspector: false, compose: false, authorDetail: false };
    state.perspectives = { mp: [], sas: [] };
    state.perspectiveLimited = { mp: false, sas: false };
    state.inFlight.clear();
    clearPrivateViews();
    for (const [id, pending] of state.pending) {
      if (pending.envelope.operation !== 'bootstrap') {
        window.clearTimeout(pending.timer);
        const error = failLocal(code);
        error.dispatched = true;
        pending.reject(error);
        state.pending.delete(id);
      }
    }
    renderBootstrap();
  }

  function clearScopeBoundOperations() {
    state.pcv.mutationPending = false;
    state.pcv.mutationUnknown = false;
    state.pcv.mutationOperationId = null;
    state.pcv.sendPending = false;
    state.pcv.sendStatusPending = false;
    state.pcv.sendOperationId = null;
    state.pcv.sendUnknownId = null;
    state.pcv.sendSubmitted = null;
    state.pcv.sendDraftDiscardedId = null;
    state.pcv.draftText = '';
    state.mail.operations = [];
    state.mail.currentOperationId = null;
    state.mail.operationDraft = null;
    state.mail.recoveryNotice = '';
    state.mail.composeLocked = false;
    state.mail.reviewed = false;
    $('pcv-text').value = '';
    $('letter-body').value = '';
    $('letter-review-summary').replaceChildren();
    $('letter-review-summary').hidden = true;
    updatePcvCount();
    updateLetterCount();
  }


  function clearPrivateViews() {
    state.pcv.roster = null; state.pcv.status = null;
    state.mp = { records: [], nextCursor: null, limited: false }; state.sas = { issues: [], nextCursor: null, limited: false };
    state.mail.actors = []; state.mail.catalogLimited = false; state.mail.catalogRevision = null; state.mail.catalogNextCursor = null; state.mail.catalogError = null; state.mail.inventory = null; state.mail.inventoryLoaded = false; state.mail.items = [];
    state.mail.letters = []; state.mail.selectedLetter = null; state.mail.mailboxNextCursor = null; state.mail.mailboxLimited = false; state.mail.reviewed = false;
    $('mp-rows').replaceChildren();
    const mpEmpty = document.createElement('tr'); const mpCell = document.createElement('td'); mpCell.colSpan = 5; mpCell.className = 'empty-row'; mpCell.textContent = 'No authorized history is loaded.'; mpEmpty.append(mpCell); $('mp-rows').append(mpEmpty);
    for (const id of ['sas-grievances', 'sas-promises', 'mailbox-list-secondary']) {
      const node = $(id); if (node) { node.replaceChildren(); const empty = document.createElement('p'); empty.className = 'empty-row'; empty.textContent = 'No authorized records are loaded.'; node.append(empty); }
    }
    $('letter-detail-secondary').hidden = true;
    text('detail-signature-secondary', ''); text('detail-author-secondary', ''); text('detail-body-secondary', ''); $('detail-attachments-secondary').replaceChildren();
    text('mp-limited', ''); text('sas-limited', ''); text('mail-limited', '');
    renderPcvStatus(); renderRoster(); renderInventory(); clearSelectsForMail();
  }
  function resetTransientControls() {
    $('conversation-mode').value = 'pair';
    for (const id of ['actor-a', 'actor-b', 'actor-c', 'actor-d', 'send-target']) $(id).value = '';
    $('opener').value = 'auto'; $('bystander-mode').value = 'exclude'; $('pace').value = 'normal'; $('free-cap').value = '6'; $('exclude-player').checked = true;
    $('scene-card').value = ''; $('pcv-text').value = ''; $('pcv-count').textContent = '0 / 2,000';
    document.querySelectorAll('input[name="actions"]').forEach((input) => { input.checked = false; });
    $('mp-target').value = ''; $('mp-perspective').value = ''; $('sas-perspective').value = '';
    $('letter-body').value = ''; $('gold-amount').value = '0'; $('sign-as-other').checked = false; $('letter-review-summary').hidden = true;
    $('letter-writer').value = ''; $('letter-recipient').value = ''; $('letter-signature').value = '';
    $('signature-wrap').hidden = true; $('letter-detail-secondary').hidden = true;
    setMailView('compose');
    renderPcvStatus(); updatePcvMode(); updateLetterCount();
  }

  function startBootstrap() {
    const viewToken = state.viewToken;
    const nativeGeneration = state.nativeGeneration;
    state.serverContext = null;
    state.bootstrapping = true;
    state.bootstrapFailure = null;
    renderBootstrap();
    request('bootstrap', {}).catch((error) => {
      if (isStale(error) || !isCurrentView(viewToken, nativeGeneration)) return;
      const connectionBootstrap = state.connection.awaitingBootstrap || state.connection.retryBootstrap;
      state.bootstrapping = false;
      state.bootstrapFailure = { message: error.bootstrapTimeout ? BOOTSTRAP_TIMEOUT_COPY : localError(error) };
      if (connectionBootstrap) {
        state.connection.awaitingBootstrap = false;
        state.connection.retryBootstrap = false;
        state.connection.retryAvailable = true;
        state.connection.failureCode = 'UNCONFIRMED';
        state.connection.message = error.bootstrapTimeout ? CONNECTION_TIMEOUT_COPY : CONNECTION_FAILURE_COPY;
      }
      renderBootstrap();
    });
  }

  function clearConnectionPoll() {
    if (state.connection.pollTimer !== null) {
      window.clearTimeout(state.connection.pollTimer);
      state.connection.pollTimer = null;
    }
  }

  function stopConnectionFailure(message, code = 'UNCONFIRMED') {
    clearConnectionPoll();
    state.connection.busy = false;
    state.connection.pending = false;
    state.connection.awaitingBootstrap = false;
    state.connection.retryBootstrap = false;
    state.connection.retryAvailable = true;
    state.connection.failureCode = code;
    state.connection.message = message;
    renderBootstrap();
  }

  function scheduleConnectionPoll() {
    clearConnectionPoll();
    if (!state.connection.pending || state.connection.pollCount >= CONNECTION_MAX_POLLS) {
      stopConnectionFailure(CONNECTION_SLOW_COPY, 'SLOW');
      return;
    }
    state.connection.pollTimer = window.setTimeout(() => {
      state.connection.pollTimer = null;
      if (!state.connection.pending || !state.initialized || state.closed || state.closing || state.authenticated) return;
      connectionOperation('connection.status');
    }, CONNECTION_POLL_INTERVAL_MS);
  }

  function connectionApproved() {
    clearConnectionPoll();
    state.connection.busy = false;
    state.connection.pending = false;
    state.connection.awaitingBootstrap = true;
    state.connection.retryAvailable = false;
    state.connection.failureCode = null;
    state.connection.message = CONNECTION_SETUP_COPY;
    state.bootstrapFailure = null;
    startBootstrap();
  }

  function connectionErrorMessage(error) {
    if (error && ['CONNECTION_SETUP_REQUIRED', 'CONNECTION_SECURE_STORAGE_FAILED'].includes(error.code)) return localError(error);
    if (error && error.timedOut) return CONNECTION_TIMEOUT_COPY;
    return CONNECTION_FAILURE_COPY;
  }

  function connectionOperation(operation) {
    if (state.connection.busy || !state.initialized || state.closed || state.closing || state.authenticated) return;
    const viewToken = state.viewToken;
    const nativeGeneration = state.nativeGeneration;
    state.connection.busy = true;
    state.connection.pending = operation === 'connection.status';
    state.connection.message = CONNECTION_SETUP_COPY;
    state.connection.failureCode = null;
    renderBootstrap();
    request(operation, {}).then((data) => {
      if (!isCurrentView(viewToken, nativeGeneration)) return;
      state.connection.busy = false;
      if (!validConnectionReply(operation, data)) {
        stopConnectionFailure(CONNECTION_FAILURE_COPY);
        return;
      }
      if (data.state === 'approved') {
        connectionApproved();
        return;
      }
      if (data.state === 'expired') {
        stopConnectionFailure(CONNECTION_EXPIRED_COPY, 'EXPIRED');
        return;
      }
      state.connection.pending = true;
      if (operation === 'connection.status') state.connection.pollCount += 1;
      state.connection.message = CONNECTION_SETUP_COPY;
      if (state.connection.pollCount >= CONNECTION_MAX_POLLS) {
        stopConnectionFailure(CONNECTION_SLOW_COPY, 'SLOW');
      } else {
        renderBootstrap();
        scheduleConnectionPoll();
      }
    }).catch((error) => {
      if (isStale(error) || !isCurrentView(viewToken, nativeGeneration)) return;
      if (operation === 'connection.begin' && error && error.dispatched === true
        && !['CONNECTION_SETUP_REQUIRED', 'CONNECTION_SECURE_STORAGE_FAILED'].includes(error.code)) {
        state.connection.busy = false;
        state.connection.pending = true;
        state.connection.pollCount = 0;
        state.connection.message = CONNECTION_SETUP_COPY;
        renderBootstrap();
        scheduleConnectionPoll();
        return;
      }
      stopConnectionFailure(connectionErrorMessage(error), error && error.code
        && ['CONNECTION_SETUP_REQUIRED', 'CONNECTION_SECURE_STORAGE_FAILED'].includes(error.code) ? error.code : 'UNCONFIRMED');
    });
  }

  function startConnectionBegin() {
    if (!state.initialized || state.closed || state.closing || state.authenticated || state.bootstrapping || state.connection.busy) return;
    clearConnectionPoll();
    state.connection.autoStarted = true;
    state.connection.retryAvailable = false;
    state.connection.retryBootstrap = false;
    state.connection.awaitingBootstrap = false;
    state.connection.pollCount = 0;
    connectionOperation('connection.begin');
  }

  function retryConnection() {
    if (!state.initialized || state.closed || state.closing || state.authenticated || state.bootstrapping || state.connection.busy) return;
    if (state.bootstrapFailure || state.connection.retryBootstrap) {
      state.connection.retryBootstrap = true;
      state.connection.retryAvailable = false;
      state.bootstrapFailure = null;
      startBootstrap();
      return;
    }
    startConnectionBegin();
  }

  window.departmentInitialize = (raw) => {
    if (typeof raw !== 'string' || bytes(raw) > 4096) return false;
    let init;
    try { init = JSON.parse(raw); } catch { return false; }
    if (!isPlainObject(init) || init.version !== 1 || !validUuid(init.viewToken) || !validGeneration(init.nativeGeneration) || init.serverContext !== null) return false;
    if (state.seenViewTokens.includes(init.viewToken)) return false;
    if (state.initialized && !state.closed && init.viewToken === state.viewToken && init.nativeGeneration === state.nativeGeneration) return false;
    const pendingSend = [...state.pending.values()].find((item) => item.envelope.operation === 'pcv.send');
    if (pendingSend) {
      state.pcv.sendUnknownId = pendingSend.envelope.payload.operationId;
      state.pcv.draftText = pendingSend.envelope.payload.text;
    } else if (state.pcv.sendPending && state.pcv.sendOperationId) {
      state.pcv.sendUnknownId = state.pcv.sendOperationId;
    }
    if (state.pcv.mutationPending) state.pcv.mutationUnknown = true;
    if (state.mail.currentOperationId && state.mail.composeLocked && !state.mail.operationDraft) state.mail.operationDraft = { body: $('letter-body').value };
    clearPending('The game view reopened or its context changed.');
    state.seenViewTokens.push(init.viewToken);
    state.initialized = true;
    state.closed = false;
    state.closing = false;
    state.bootstrapping = true;
    state.bootstrapFailure = null;
    state.connection = createConnectionState();
    state.viewToken = init.viewToken;
    state.nativeGeneration = init.nativeGeneration;
    state.serverContext = null;
    state.idCounter = 0;
    state.authenticated = false;
    state.authRequired = true;
    state.capabilities = { pcv: false, mp: false, sas: false, correspondence: false };
    state.access = { pcv: false, inspector: false, compose: false, authorDetail: false };
    state.perspectives = { mp: [], sas: [] };
    state.perspectiveLimited = { mp: false, sas: false };
    state.pcv = {
      roster: null, status: null, mutationPending: false,
      mutationUnknown: state.pcv.mutationUnknown,
      mutationOperationId: state.pcv.mutationOperationId,
      sendPending: false, sendStatusPending: false, sendOperationId: null,
      sendUnknownId: state.pcv.sendUnknownId,
      sendSubmitted: state.pcv.sendSubmitted,
      sendDraftDiscardedId: state.pcv.sendDraftDiscardedId,
      draftText: state.pcv.draftText,
    };
    state.mail.actors = [];
    state.mail.inventory = null;
    state.mail.inventoryLoaded = false;
    state.mail.items = [];
    state.mail.letters = [];
    state.mail.selectedLetter = null;
    clearPrivateViews();
    resetTransientControls();
    startBootstrap();
    return true;
  };
  window.departmentReceive = receive;
  window.departmentNavigate = (raw) => {
    if (typeof raw !== 'string' || bytes(raw) > 4096 || !state.initialized || state.closed || state.closing) return false;
    let event;
    try { event = JSON.parse(raw); } catch { return false; }
    if (!isPlainObject(event) || event.version !== 1 || event.viewToken !== state.viewToken || event.nativeGeneration !== state.nativeGeneration
      || !['up', 'down', 'left', 'right', 'next', 'previous', 'activate', 'back'].includes(event.command)) return false;
    if (event.command === 'back') { closeView(); return true; }
    navigateControls(event.command);
    return true;
  };

  function renderConnectionControls(connected) {
    const available = connected && !state.bootstrapping && !state.authenticated
      && (state.connection.retryAvailable || Boolean(state.bootstrapFailure));
    $('connection-actions').hidden = !available;
    $('retry-connection').disabled = !available || state.connection.busy;
  }

  function renderBootstrap() {
    const connected = state.initialized && !state.closed;
    $('close-view').disabled = !connected;
    if (!connected) {
      setStatus('connection-status', 'Waiting for CHIM connection', 'waiting');
      setStatus('permission-status', 'This game connects automatically when CHIM is available.');
    } else if (state.bootstrapping) {
      setStatus('connection-status', 'Connecting to CHIM', 'waiting');
      setStatus('permission-status', 'Private data loads after CHIM confirms the connection.');
    } else if (state.bootstrapFailure) {
      setStatus('connection-status', state.bootstrapFailure.message, 'error');
      setStatus('permission-status', BOOTSTRAP_FAILURE_NEXT_STEP, 'error');
    } else if (!state.authenticated && state.connection.pending) {
      setStatus('connection-status', 'Connecting to CHIM', 'waiting');
      setStatus('permission-status', CONNECTION_SETUP_COPY, 'waiting');
    } else if (!state.authenticated && state.connection.failureCode === 'CONNECTION_SETUP_REQUIRED') {
      setStatus('connection-status', 'Connection could not be started', 'error');
      setStatus('permission-status', state.connection.message, 'error');
    } else if (!state.authenticated && state.connection.failureCode === 'CONNECTION_SECURE_STORAGE_FAILED') {
      setStatus('connection-status', 'Connection could not be started', 'error');
      setStatus('permission-status', state.connection.message, 'error');
    } else if (!state.authenticated && state.connection.failureCode === 'EXPIRED') {
      setStatus('connection-status', 'Connection request expired', 'error');
      setStatus('permission-status', state.connection.message, 'error');
    } else if (!state.authenticated && state.connection.failureCode === 'SLOW') {
      setStatus('connection-status', CONNECTION_SLOW_COPY, 'error');
      setStatus('permission-status', CONNECTION_FAILURE_COPY, 'error');
    } else if (!state.authenticated && state.connection.failureCode) {
      setStatus('connection-status', 'Connection could not be confirmed', 'error');
      setStatus('permission-status', state.connection.message, 'error');
    } else if (!state.authenticated && state.connection.busy) {
      setStatus('connection-status', 'Connecting to CHIM', 'waiting');
      setStatus('permission-status', CONNECTION_SETUP_COPY, 'waiting');
    } else if (!state.authenticated) {
      setStatus('connection-status', 'Not connected to CHIM', 'waiting');
      setStatus('permission-status', 'CHIM connection starts automatically.');
    } else {
      setStatus('connection-status', 'Connected to CHIM', 'ready');
      setStatus('permission-status', state.access.inspector ? 'Out-of-character Inspector access is authorized.' : 'Inspector access is unavailable for this session.');
    }
    renderConnectionControls(connected);
    text('scope-footer', state.authenticated ? 'Connected to CHIM; private data stays out of dialogue.' : 'Not connected to CHIM.');
    renderPcvAccess();
    renderMpChoices();
    renderSasChoices();
    renderMailAccess();
  }

  function requireCapability(operation) {
    if (!roleAllows(operation)) throw failLocal(state.authenticated ? 'FORBIDDEN' : 'AUTH_REQUIRED');
  }
  function withBusy(operation, button, run) {
    if (state.inFlight.has(operation)) return Promise.resolve(null);
    const context = makeContext();
    state.inFlight.add(operation);
    renderPcvAccess(); renderMpChoices(); renderSasChoices(); renderMailAccess();
    return Promise.resolve().then(run).finally(() => {
      if (!isCurrentContext(context)) return;
      state.inFlight.delete(operation);
      renderPcvAccess();
      renderMpChoices(); renderSasChoices();
      renderMailAccess();
    });
  }
  function runRead(operation, payload, button, onData, statusId, label, isRelevant = () => true) {
    if (state.inFlight.has(operation)) return Promise.resolve();
    const context = makeContext();
    return withBusy(operation, button, async () => {
      try {
        requireCapability(operation);
        if (statusId) setStatus(statusId, label || 'Reading current state…');
        const data = await request(operation, payload);
        if (!isCurrentContext(context) || !isRelevant()) return;
        onData(data);
      } catch (error) {
        if (!isCurrentContext(context) || !isRelevant()) return;
        if (statusId) setStatus(statusId, localError(error), 'error');
        else setStatus('mail-state', localError(error), 'error');
      }
    });
  }
  function boundedRows(value, max = MAX_ROWS) { return Array.isArray(value) ? value.slice(0, max) : []; }
  function appendOption(select, value, label, disabled = false) {
    const option = document.createElement('option');
    option.value = value;
    option.textContent = label;
    option.disabled = disabled;
    select.append(option);
  }
  function fillActorSelect(id, placeholder, choices, selected = '') {
    const select = $(id);
    const previous = selected || select.value;
    select.replaceChildren();
    appendOption(select, '', placeholder);
    for (const actor of choices) appendOption(select, actor.actorKey, actor.label, actor.eligibility !== 'eligible');
    if (previous && choices.some((actor) => actor.actorKey === previous && actor.eligibility === 'eligible')) select.value = previous;
    else select.value = '';
  }
  function actorLabel(actorKey) {
    if (!boundedString(actorKey, 128)) return 'Unavailable actor';
    const actor = state.pcv.roster && state.pcv.roster.actors.find((item) => item.actorKey === actorKey);
    return actor ? actor.label : 'Unavailable actor';
  }
  function validRoster(data) {
    if (!isPlainObject(data) || Object.keys(data).length !== 4 || Object.keys(data).some((key) => !['revision', 'state', 'actors', 'limited'].includes(key))
      || !boundedString(data.revision, 128) || !boundedString(data.state, 64) || typeof data.limited !== 'boolean'
      || !Array.isArray(data.actors) || data.actors.length > 128) return null;
    const keys = new Set();
    const actors = [];
    for (const actor of data.actors) {
      if (!isPlainObject(actor) || Object.keys(actor).length !== 4 || Object.keys(actor).some((key) => !['actorKey', 'label', 'eligibility', 'reason'].includes(key))
        || !boundedString(actor.actorKey, 128) || actor.actorKey.length === 0 || !boundedLabel(actor.label)
        || !['eligible', 'ineligible'].includes(actor.eligibility) || !(actor.reason === null || boundedLabel(actor.reason)) || keys.has(actor.actorKey)) return null;
      keys.add(actor.actorKey);
      actors.push({ actorKey: actor.actorKey, label: actor.label, eligibility: actor.eligibility, reason: actor.reason });
    }
    return { revision: data.revision, state: data.state, actors, limited: data.limited };
  }
  function validPcvConfig(config) {
    const allowed = ['enabled', 'scene_mode', 'actor_a', 'actor_b', 'actor_ids', 'opener', 'free', 'free_cap', 'exclude_player', 'bystander_mode', 'card', 'pace', 'actions'];
    if (!isPlainObject(config) || Object.keys(config).some((key) => !allowed.includes(key)) || !validPcvConfiguration(config)) return null;
    return Object.fromEntries(Object.entries(config).map(([key, value]) => [key, Array.isArray(value) ? value.slice() : value]));
  }
  function validPcvStatus(data) {
    const fields = ['state', 'active', 'pending', 'activeConfigId', 'pendingConfigId'];
    if (!isPlainObject(data) || Object.keys(data).some((key) => !fields.includes(key)) || !['active', 'pending', 'off', 'unavailable'].includes(data.state)
      || !['active', 'pending', 'activeConfigId', 'pendingConfigId'].every((key) => Object.prototype.hasOwnProperty.call(data, key))) return null;
    const active = data.active === null ? null : validPcvConfig(data.active);
    const pending = data.pending === null ? null : validPcvConfig(data.pending);
    if ((data.active !== null && !active) || (data.pending !== null && !pending)) return null;
    if (!validConfigIdentity(data.activeConfigId) || !validConfigIdentity(data.pendingConfigId)) return null;
    if ((data.activeConfigId !== null) !== Boolean(active) || (data.pendingConfigId !== null) !== Boolean(pending)) return null;
    if (data.state === 'off' && (active || pending)) return null;
    if (data.state === 'active' && !active) return null;
    if (data.state === 'pending' && !pending) return null;
    return { state: data.state, active, pending, activeConfigId: data.activeConfigId, pendingConfigId: data.pendingConfigId };
  }
  function validPcvSendStart(data, operationId) {
    const required = ['operationId', 'state'];
    const allowed = [...required, 'phase'];
    if (!isPlainObject(data) || data.operationId !== operationId || !['prepared', 'consumed', 'rejected', 'cancelled', 'expired', 'unknown'].includes(data.state)
      || required.some((field) => !Object.prototype.hasOwnProperty.call(data, field))
      || Object.keys(data).some((field) => !allowed.includes(field))
      || (Object.prototype.hasOwnProperty.call(data, 'phase') && (!boundedString(data.phase, 64) || !data.phase))) return null;
    return { operationId, state: data.state, ...(data.phase ? { phase: data.phase } : {}) };
  }
  function validPcvSendStatus(data, operationId) {
    const required = ['operationId', 'state', 'expectedActiveConfigId', 'expectedPendingConfigId'];
    const allowed = [...required, 'phase'];
    if (!isPlainObject(data) || data.operationId !== operationId || !['prepared', 'consumed', 'rejected', 'cancelled', 'expired', 'unknown'].includes(data.state)
      || required.some((field) => !Object.prototype.hasOwnProperty.call(data, field))
      || Object.keys(data).some((field) => !allowed.includes(field))
      || !validConfigIdentity(data.expectedActiveConfigId) || !validConfigIdentity(data.expectedPendingConfigId)
      || (Object.prototype.hasOwnProperty.call(data, 'phase') && (!boundedString(data.phase, 64) || !data.phase))) return null;
    return { operationId, state: data.state, expectedActiveConfigId: data.expectedActiveConfigId,
      expectedPendingConfigId: data.expectedPendingConfigId, ...(data.phase ? { phase: data.phase } : {}) };
  }
  function validPcvMutationResult(data, operationId, operation) {
    if (!isPlainObject(data) || data.operationId !== operationId || !['staged', 'ended', 'unknown', 'rejected'].includes(data.state)) return null;
    if (data.state === 'unknown' || data.state === 'rejected') {
      return Object.keys(data).length === 2 ? { operationId, state: data.state } : null;
    }
    const fields = ['operationId', 'state', 'activeConfigId', 'pendingConfigId', 'active', 'pending'];
    if (Object.keys(data).length !== fields.length || Object.keys(data).some((key) => !fields.includes(key))
      || !['activeConfigId', 'pendingConfigId', 'active', 'pending'].every((key) => Object.prototype.hasOwnProperty.call(data, key))) return null;
    if (operation === 'pcv.arm' && data.state === 'staged') {
      const active = data.active === null ? null : validPcvConfig(data.active);
      const pending = data.pending === null ? null : validPcvConfig(data.pending);
      if ((data.active !== null && !active) || !pending || !validConfigIdentity(data.activeConfigId) || !validUuid(data.pendingConfigId)) return null;
      return { operationId, state: 'staged', activeConfigId: data.activeConfigId, pendingConfigId: data.pendingConfigId, active, pending };
    }
    if (operation === 'pcv.end' && data.state === 'ended' && data.activeConfigId === null && data.pendingConfigId === null
      && data.active === null && data.pending === null) return { operationId, state: 'ended', activeConfigId: null, pendingConfigId: null, active: null, pending: null };
    return null;
  }
  function renderRoster() {
    const roster = state.pcv.roster;
    const actors = roster ? roster.actors : [];
    const selected = {
      'actor-a': $('actor-a').value, 'actor-b': $('actor-b').value, 'actor-c': $('actor-c').value, 'actor-d': $('actor-d').value,
    };
    for (const [id, label] of [['actor-a', 'Choose an eligible NPC'], ['actor-b', 'Choose an eligible NPC'], ['actor-c', 'No additional NPC'], ['actor-d', 'No additional NPC']]) fillActorSelect(id, label, actors, selected[id]);
    const select = $('send-target');
    const prev = select.value;
    select.replaceChildren();
    appendOption(select, '', roster ? 'Choose an exact eligible target' : 'Refresh the eligible cast first');
    for (const actor of actors) if (actor.eligibility === 'eligible') appendOption(select, actor.actorKey, actor.label);
    if (prev && actors.some((actor) => actor.actorKey === prev && actor.eligibility === 'eligible')) select.value = prev;
    updateOpenerOptions();
    updatePcvMode();
    renderPcvAccess();
  }
  function setPcvSettingsExpanded(expanded) {
    const fieldset = $('pcv-config-fields');
    const toggle = $('pcv-settings-toggle');
    if (!fieldset || !toggle) return;
    const current = toggle.getAttribute('aria-expanded') === 'true';
    if (current === expanded) return;
    const focusWasLostByDisable = document.activeElement === document.body && state.pcv.lastFocusedControl
      && state.pcv.lastFocusedControl.isConnected && fieldset.contains(state.pcv.lastFocusedControl);
    if (!expanded && (fieldset.contains(document.activeElement) || focusWasLostByDisable)) toggle.focus();
    fieldset.hidden = !expanded;
    toggle.setAttribute('aria-expanded', String(expanded));
    toggle.textContent = expanded ? 'Hide scene settings' : 'Edit scene settings';
  }
  function renderPcvStatus() {
    const status = state.pcv.status;
    if (!status) {
      text('pcv-current', 'Unknown');
      text('pcv-current-detail', 'Refresh to read PCV owner state.');
      text('pcv-pending', 'Unknown');
      text('pcv-pending-detail', 'Queued settings are separate from the active scene.');
      text('pcv-send-state', 'Not armed');
      return;
    }
    const active = status.active;
    const pending = status.pending;
    if (pending || active) setPcvSettingsExpanded(false);
    else if (status.state === 'off') setPcvSettingsExpanded(true);
    if (active) {
      text('pcv-current', 'Active');
      const actors = Array.isArray(active.actor_ids) ? active.actor_ids : [active.actor_a, active.actor_b].filter((item) => boundedString(item, 128));
      text('pcv-current-detail', actors.length ? actors.map(actorLabel).join(' · ') : (active.free === true ? 'Free scene cast is fixed at activation.' : 'Current scene confirmed by PCV.'));
    } else if (status.state === 'off' || status.state === 'pending') {
      text('pcv-current', 'Off');
      text('pcv-current-detail', 'No active scene is confirmed.');
    } else {
      text('pcv-current', 'Unavailable');
      text('pcv-current-detail', 'The owner state could not be confirmed.');
    }
    if (pending) {
      text('pcv-pending', 'Armed · pending');
      const actors = Array.isArray(pending.actor_ids) ? pending.actor_ids : [pending.actor_a, pending.actor_b].filter((item) => boundedString(item, 128));
      text('pcv-pending-detail', pending.free === true ? 'The nearest eligible cast will be fixed at the next eligible Standard input.' : actors.length ? `Next eligible Standard input · ${actors.map(actorLabel).join(' · ')}` : 'Settings are staged for the next eligible Standard input.');
      text('pcv-send-state', 'Armed · pending');
    } else if (active) {
      text('pcv-pending', 'No queued edit');
      text('pcv-pending-detail', 'The confirmed active cast remains current.');
      text('pcv-send-state', 'Active');
    } else {
      text('pcv-pending', 'No pending settings');
      text('pcv-pending-detail', status.state === 'off' ? 'PCV confirms there is no armed scene.' : 'No pending settings are confirmed.');
      text('pcv-send-state', 'Not armed');
    }
    renderPcvAccess();
  }
  function applyPcvSendStatus(result) {
    if (state.pcv.sendUnknownId !== result.operationId) return;
    if (result.state === 'unknown' || result.state === 'prepared') {
      setStatus('pcv-send-note', result.state === 'prepared'
        ? 'Native still reports this operation as prepared. It remains locked; refresh status later and do not resend.'
        : 'Native cannot currently resolve this operation. It remains locked; do not resend.', 'error');
      return;
    }
    const submitted = state.pcv.sendSubmitted;
    const matchesSubmitted = Boolean(submitted && submitted.operationId === result.operationId
      && submitted.scopeToken === state.lastAuthenticatedScopeToken
      && submitted.expectedActiveConfigId === result.expectedActiveConfigId
      && submitted.expectedPendingConfigId === result.expectedPendingConfigId);
    if (!matchesSubmitted) {
      setStatus('pcv-send-note', 'Status does not match the original operation scope and scene. The handle remains locked; do not resend.', 'error');
      return;
    }
    const discarded = state.pcv.sendDraftDiscardedId === result.operationId;
    if (result.state === 'consumed') {
      if (submitted.text !== null && !discarded && $('pcv-text').value === submitted.text) {
        $('pcv-text').value = '';
        state.pcv.draftText = '';
        updatePcvCount();
      }
      setStatus('pcv-send-note', 'PCV accepted the player input. NPC response and audio delivery are not confirmed.', 'success');
    } else {
      if (submitted.text !== null && !discarded && !$('pcv-text').value) {
        $('pcv-text').value = submitted.text;
        state.pcv.draftText = submitted.text;
        updatePcvCount();
      }
      setStatus('pcv-send-note', `Native resolved this operation as ${result.state}. Review the draft and scene before another deliberate send.`, 'error');
    }
    state.pcv.sendUnknownId = null;
    state.pcv.sendSubmitted = null;
    state.pcv.sendDraftDiscardedId = null;
  }
  async function requestPcvSendStatus(operationId = state.pcv.sendUnknownId) {
    if (!operationId || operationId !== state.pcv.sendUnknownId || state.pcv.sendStatusPending || !state.authenticated || state.closed) return;
    const context = makeContext();
    state.pcv.sendStatusPending = true;
    renderPcvAccess();
    try {
      requireCapability('pcv.send.status');
      const data = await request('pcv.send.status', { operationId });
      if (!isCurrentContext(context)) return;
      const result = validPcvSendStatus(data, operationId);
      if (!result) throw failLocal('UNAVAILABLE');
      applyPcvSendStatus(result);
    } catch (error) {
      if (!isCurrentContext(context)) return;
      setStatus('pcv-send-note', `${localError(error)} The existing operation remains locked; do not resend it.`, 'error');
    } finally {
      if (isCurrentView(context.viewToken, context.nativeGeneration)) {
        state.pcv.sendStatusPending = false;
        renderPcvAccess();
      }
    }
  }
  function renderPcvAccess() {
    const authorized = state.authenticated && state.capabilities.pcv && state.access.pcv && !state.closed;
    const rosterReady = state.pcv.roster && state.pcv.roster.state === 'ready' && state.pcv.roster.actors.some((actor) => actor.eligibility === 'eligible');
    const statusReady = Boolean(state.pcv.status && state.pcv.status.state !== 'unavailable');
    $('pcv-refresh').disabled = !authorized || state.pcv.mutationPending || state.inFlight.has('pcv.roster.read') || state.inFlight.has('pcv.status.read');
    $('pcv-config-fields').disabled = !authorized || !rosterReady || !statusReady || state.pcv.mutationPending || state.pcv.mutationUnknown;
    $('pcv-end').disabled = !authorized || !statusReady || state.pcv.mutationPending || state.pcv.mutationUnknown;
    const status = state.pcv.status;
    const confirmedArmed = Boolean(status && (status.pending || status.active));
    const target = $('send-target').value;
    const exactTarget = rosterReady && state.pcv.roster.actors.some((actor) => actor.actorKey === target && actor.eligibility === 'eligible');
    const body = $('pcv-text').value;
    const validBody = body.trim().length > 0 && Array.from(body).length <= 2000 && bytes(body) <= 8000;
    const unknownBlocked = Boolean(state.pcv.sendUnknownId);
    const sendBusy = state.pcv.sendPending || state.pcv.sendStatusPending;
    const allowedSend = authorized && confirmedArmed && exactTarget && validBody && !sendBusy && !unknownBlocked && !state.pcv.mutationPending;
    $('pcv-text').disabled = !authorized || !confirmedArmed || unknownBlocked || state.pcv.mutationPending || state.pcv.sendPending;
    $('send-target').disabled = !authorized || !confirmedArmed || !rosterReady || unknownBlocked;
    $('pcv-send').disabled = !allowedSend;
    $('pcv-refresh-send').disabled = !authorized || sendBusy || state.inFlight.has('pcv.status.read');
    $('pcv-discard-unknown').hidden = !unknownBlocked;
    $('pcv-discard-unknown').disabled = !unknownBlocked;
    const mode = $('conversation-mode').value;
    const values = [ $('actor-a').value, $('actor-b').value ];
    if (mode === 'group') values.push($('actor-c').value, $('actor-d').value);
    const distinct = values.filter(Boolean).length === new Set(values.filter(Boolean)).size;
    const canPick = mode === 'free' || (mode === 'solo' ? Boolean($('actor-a').value) : Boolean($('actor-a').value && $('actor-b').value));
    $('pcv-arm').disabled = !authorized || !rosterReady || !statusReady || !distinct || !canPick || state.pcv.mutationPending || state.pcv.mutationUnknown;
    if (!state.authenticated) {
      setStatus('pcv-state', 'Private Conversation becomes available after CHIM connects.', 'waiting');
    } else if (!authorized) {
      setStatus('pcv-state', 'An authenticated PCV permission is required for this control.', 'error');
    } else if (!rosterReady) {
      const rosterState = state.pcv.roster && state.pcv.roster.state;
      const message = rosterState === 'missing'
        ? 'CHIM has not supplied a nearby-actor report yet. It updates automatically; you do not need to message CHIM first. Wait briefly, then refresh.'
        : rosterState === 'stale'
          ? 'CHIM has not supplied a recent nearby-actor report. It updates automatically; you do not need to message CHIM first. Wait briefly, then refresh.'
          : rosterState === 'unavailable'
            ? 'The nearby-actor report is unavailable. Wait briefly, then refresh.'
            : rosterState === 'ready' || rosterState === 'empty'
              ? 'The latest CHIM report has no eligible nearby NPCs.'
              : 'Refresh the nearby-actor report and PCV status.';
      setStatus('pcv-state', message);
    } else if (state.pcv.mutationUnknown) {
      setStatus('pcv-state', statusReady
        ? 'The last Arm or End result is uncertain. Refresh state before another change.'
        : 'The last Arm or End result is uncertain and PCV status is unavailable. Refresh before another change.', 'error');
    } else if (!statusReady) {
      setStatus('pcv-state', 'Private Conversation status is unavailable. The nearby cast can be read, but Arm and Send stay disabled.', 'error');
    } else {
      setStatus('pcv-state', 'Nearby NPCs and Private Conversation status are current. Arm prepares settings for the next eligible CHIM turn.', 'success');
    }
    if (!confirmedArmed) {
      $('pcv-text').disabled = true;
      $('send-target').disabled = true;
      $('pcv-send').disabled = true;
    }
  }
  function updateOpenerOptions() {
    const select = $('opener');
    const previous = select.value;
    select.replaceChildren();
    appendOption(select, 'auto', 'Auto');
    for (const id of ['actor-a', 'actor-b', 'actor-c', 'actor-d'].map((name) => $(name).value).filter(Boolean)) appendOption(select, id, actorLabel(id));
    if ([...select.options].some((option) => option.value === previous)) select.value = previous;
    else select.value = 'auto';
  }
  function updatePcvMode() {
    const mode = $('conversation-mode').value;
    document.querySelectorAll('.group-only').forEach((node) => { node.hidden = mode !== 'group'; });
    document.querySelectorAll('.free-only').forEach((node) => { node.hidden = mode !== 'free'; });
    document.querySelectorAll('.pair-only').forEach((node) => { node.hidden = mode !== 'pair' && mode !== 'group'; });
    $('actor-b-wrap').hidden = mode === 'free';
    $('actor-a-wrap').hidden = mode === 'free';
    $('actor-b').disabled = mode === 'solo' || mode === 'free';
    $('exclude-player').disabled = mode === 'solo' || mode === 'free';
    if (mode === 'solo' || mode === 'free') $('exclude-player').checked = true;
    $('pace').disabled = mode === 'solo' || mode === 'free';
    $('action-physical').disabled = mode === 'solo';
    if (mode === 'solo') $('action-physical').checked = false;
    updateOpenerOptions();
    renderPcvAccess();
  }
  function readPcv() {
    if (state.inFlight.has('pcv.roster.read') || state.inFlight.has('pcv.status.read')) return;
    requireCapability('pcv.roster.read');
    const context = makeContext();
    let resolvedMutationId = null;
    setStatus('pcv-state', 'Reading the nearby managed cast and current PCV owner state…');
    const jobs = [
      request('pcv.roster.read', {}).then((data) => {
        if (!isCurrentContext(context)) return;
        const roster = validRoster(data);
        if (!roster) throw failLocal('UNAVAILABLE');
        state.pcv.roster = roster;
        renderRoster();
      }).catch((error) => { if (isCurrentContext(context)) setStatus('pcv-state', localError(error), 'error'); }),
      request('pcv.status.read', {}).then((data) => {
        if (!isCurrentContext(context)) return;
        const status = validPcvStatus(data);
        if (!status) throw failLocal('UNAVAILABLE');
        state.pcv.status = status;
        if (status.state !== 'unavailable') {
          resolvedMutationId = state.pcv.mutationOperationId;
          state.pcv.mutationUnknown = false;
          state.pcv.mutationOperationId = null;
        }
        renderPcvStatus();
      }).catch((error) => { if (isCurrentContext(context)) setStatus('pcv-state', localError(error), 'error'); }),
    ];
    state.inFlight.add('pcv.roster.read'); state.inFlight.add('pcv.status.read');
    $('pcv-refresh').disabled = true;
    Promise.allSettled(jobs).finally(() => {
      if (!isCurrentView(context.viewToken, context.nativeGeneration)) return;
      state.inFlight.delete('pcv.roster.read'); state.inFlight.delete('pcv.status.read');
      renderPcvAccess();
      if (resolvedMutationId) setStatus('pcv-state', `Current owner status refreshed after uncertain operation ${resolvedMutationId}; this status does not identify which operation caused it.`, 'success');
    });
  }
  function makePcvConfiguration() {
    const mode = $('conversation-mode').value;
    const configuration = {
      enabled: true,
      scene_mode: mode === 'solo' ? 'solo' : 'pair',
      bystander_mode: $('bystander-mode').value,
      exclude_player: mode === 'solo' || mode === 'free' ? true : $('exclude-player').checked,
    };
    if (mode === 'pair') {
      configuration.actor_a = $('actor-a').value;
      configuration.actor_b = $('actor-b').value;
    } else if (mode === 'group') {
      configuration.actor_ids = ['actor-a', 'actor-b', 'actor-c', 'actor-d'].map((id) => $(id).value).filter(Boolean);
      configuration.opener = $('opener').value;
    } else if (mode === 'solo') {
      configuration.actor_a = $('actor-a').value;
      configuration.actor_b = null;
    } else {
      configuration.free = true;
      configuration.free_cap = Number($('free-cap').value);
    }
    const rawCard = $('scene-card').value.trim();
    if (Array.from(rawCard).length > 300) return null;
    const card = rawCard;
    if (card) configuration.card = card;
    if (mode === 'pair' || mode === 'group') configuration.pace = $('pace').value;
    const actions = [...document.querySelectorAll('input[name="actions"]:checked')].map((input) => input.value);
    if (actions.length) configuration.actions = actions;
    return configuration;
  }
  async function armPcv() {
    if (state.pcv.mutationPending || state.pcv.mutationUnknown) return;
    const status = state.pcv.status;
    if (!status || status.state === 'unavailable') return;
    const configuration = makePcvConfiguration();
    const operationId = newUuid();
    state.pcv.mutationOperationId = operationId;
    if (!configuration || !payloadFieldsValid('pcv.arm', { operationId, expectedActiveConfigId: status.activeConfigId, expectedPendingConfigId: status.pendingConfigId, configuration })) {
      setStatus('pcv-state', 'Review the scene settings before Arm.', 'error'); return;
    }
    const payload = { operationId, expectedActiveConfigId: status.activeConfigId, expectedPendingConfigId: status.pendingConfigId, configuration };
    const context = makeContext();
    state.pcv.mutationPending = true;
    renderPcvAccess();
    let mutationMayHaveApplied = false;
    try {
      const data = await request('pcv.arm', payload);
      if (!isCurrentContext(context)) return;
      mutationMayHaveApplied = true;
      const result = validPcvMutationResult(data, operationId, 'pcv.arm');
      if (!result) throw failLocal('UNAVAILABLE');
      if (result.state !== 'staged') {
        state.pcv.mutationUnknown = true;
        state.pcv.mutationOperationId = result.state === 'unknown' ? operationId : null;
        setStatus('pcv-state', result.state === 'unknown' ? 'Arm outcome is unknown. Refresh owner status before another change.' : 'PCV rejected this Arm request. Refresh to confirm the current owner status before another change.', 'error');
        return;
      }
      const readback = await request('pcv.status.read', {});
      if (!isCurrentContext(context)) return;
      const refreshed = validPcvStatus(readback);
      if (!refreshed) throw failLocal('UNAVAILABLE');
      state.pcv.status = refreshed;
      if (refreshed.state === 'unavailable') {
        state.pcv.mutationUnknown = true;
        state.pcv.mutationOperationId = operationId;
        setStatus('pcv-state', 'Arm was accepted, but PCV status is unavailable. Its outcome remains uncertain; refresh before another change.', 'error');
        renderPcvStatus();
        return;
      }
      state.pcv.mutationUnknown = false;
      const stagedPending = refreshed.pendingConfigId === result.pendingConfigId;
      const activated = refreshed.activeConfigId === result.pendingConfigId;
      const unchangedActive = refreshed.activeConfigId === result.activeConfigId;
      state.pcv.mutationUnknown = !(stagedPending && unchangedActive) && !activated;
      state.pcv.mutationOperationId = state.pcv.mutationUnknown ? operationId : null;
      const message = stagedPending && unchangedActive
        ? 'Arm was staged and read back as Pending. It activates on the first accepted eligible CHIM turn; Active still requires a status confirmation.'
        : activated
          ? 'The staged configuration is now Active, confirmed by PCV status.'
          : 'PCV accepted Arm, but the current status no longer matches that operation. Review the refreshed owner state before another change.';
      setStatus('pcv-state', message, stagedPending || activated ? 'success' : 'error');
      renderPcvStatus();
    } catch (error) {
      if (!isCurrentContext(context)) return;
      state.pcv.mutationUnknown = mutationMayHaveApplied || error.dispatched === true;
      state.pcv.mutationOperationId = state.pcv.mutationUnknown ? operationId : null;
      setStatus('pcv-state', state.pcv.mutationUnknown ? `${localError(error)} Refresh status before another mutation.` : `${localError(error)} No Arm request was sent; review and try again.`, 'error');
    } finally {
      if (isCurrentView(context.viewToken, context.nativeGeneration)) {
        state.pcv.mutationPending = false;
        renderPcvAccess();
      }
    }
  }
  async function endPcv() {
    if (state.pcv.mutationPending || state.pcv.mutationUnknown) return;
    const status = state.pcv.status;
    if (!status) { setStatus('pcv-state', 'Refresh owner state before End now.', 'error'); return; }
    const context = makeContext();
    state.pcv.mutationPending = true;
    renderPcvAccess();
    const payload = { operationId: newUuid(), expectedActiveConfigId: status.activeConfigId, expectedPendingConfigId: status.pendingConfigId };
    state.pcv.mutationOperationId = payload.operationId;
    let mutationMayHaveApplied = false;
    try {
      const data = await request('pcv.end', payload);
      if (!isCurrentContext(context)) return;
      mutationMayHaveApplied = true;
      const result = validPcvMutationResult(data, payload.operationId, 'pcv.end');
      if (!result) throw failLocal('UNAVAILABLE');
      if (result.state !== 'ended') {
        state.pcv.mutationUnknown = true;
        state.pcv.mutationOperationId = result.state === 'unknown' ? payload.operationId : null;
        setStatus('pcv-state', result.state === 'unknown' ? 'End outcome is unknown. Refresh owner status before another change.' : 'PCV rejected End. Refresh to confirm the current owner status before another change.', 'error');
        return;
      }
      const readback = await request('pcv.status.read', {});
      if (!isCurrentContext(context)) return;
      const refreshed = validPcvStatus(readback);
      if (!refreshed) throw failLocal('UNAVAILABLE');
      state.pcv.status = refreshed;
      if (refreshed.state === 'unavailable') {
        state.pcv.mutationUnknown = true;
        state.pcv.mutationOperationId = payload.operationId;
        setStatus('pcv-state', 'End was accepted, but PCV status is unavailable. Off is not confirmed; refresh before another change.', 'error');
        renderPcvStatus();
        return;
      }
      state.pcv.mutationUnknown = false;
      const off = refreshed.state === 'off' && !refreshed.active && !refreshed.pending;
      state.pcv.mutationOperationId = null;
      setStatus('pcv-state', off ? 'PCV confirms the scene is Off. Any audio already queued may continue.' : 'End was accepted, but a current Active or Pending configuration is now present; review the refreshed status.', off ? 'success' : 'error');
      renderPcvStatus();
    } catch (error) {
      if (!isCurrentContext(context)) return;
      state.pcv.mutationUnknown = mutationMayHaveApplied || error.dispatched === true;
      state.pcv.mutationOperationId = state.pcv.mutationUnknown ? payload.operationId : null;
      setStatus('pcv-state', state.pcv.mutationUnknown ? `${localError(error)} Off is not confirmed. Refresh before another change.` : `${localError(error)} No End request was sent.`, 'error');
    } finally {
      if (isCurrentView(context.viewToken, context.nativeGeneration)) {
        state.pcv.mutationPending = false;
        renderPcvAccess();
      }
    }
  }
  async function sendPcv() {
    if ($('pcv-send').disabled || state.pcv.sendPending || state.pcv.sendUnknownId) return;
    const status = state.pcv.status;
    const expectedActiveConfigId = status && status.activeConfigId;
    const expectedPendingConfigId = status && status.pendingConfigId;
    const operationId = newUuid();
    const payload = { operationId, actorKey: $('send-target').value, text: $('pcv-text').value, expectedActiveConfigId, expectedPendingConfigId };
    if ((!expectedActiveConfigId && !expectedPendingConfigId) || !payloadFieldsValid('pcv.send', payload)) {
      setStatus('pcv-send-note', 'A current confirmed PCV configuration, exact target, and valid message are required.', 'error'); return;
    }
    const context = makeContext();
    state.pcv.sendPending = true;
    state.pcv.sendOperationId = operationId;
    state.pcv.sendUnknownId = operationId;
    state.pcv.draftText = payload.text;
    state.pcv.sendSubmitted = {
      operationId, text: payload.text, expectedActiveConfigId, expectedPendingConfigId,
      scopeToken: state.lastAuthenticatedScopeToken,
    };
    state.pcv.sendDraftDiscardedId = null;
    renderPcvAccess();
    let dispatched = false;
    try {
      const data = await request('pcv.send', payload);
      if (!isCurrentContext(context)) return;
      dispatched = true;
      if (!validPcvSendStart(data, operationId)) throw failLocal('UNAVAILABLE');
      setStatus('pcv-send-note', 'Checking recorded send status; do not resend.', 'waiting');
      await requestPcvSendStatus(operationId);
    } catch (error) {
      if (!isCurrentContext(context)) return;
      if (dispatched || error.dispatched !== false) {
        state.pcv.sendUnknownId = operationId;
        setStatus('pcv-send-note', `${localError(error)} The operation may have been accepted; keep it locked and do not resend.`, 'error');
        await requestPcvSendStatus(operationId);
      } else {
        state.pcv.sendUnknownId = null;
        state.pcv.sendSubmitted = null;
        state.pcv.sendDraftDiscardedId = null;
        setStatus('pcv-send-note', `${localError(error)} The request was not sent; your draft remains here.`, 'error');
      }
    } finally {
      if (isCurrentView(context.viewToken, context.nativeGeneration)) {
        state.pcv.sendPending = false;
        state.pcv.sendOperationId = null;
        renderPcvAccess();
      }
    }
  }
  function updatePcvCount() {
    const value = $('pcv-text').value;
    state.pcv.draftText = value;
    text('pcv-count', `${Array.from(value).length.toLocaleString()} / 2,000`);
    renderPcvAccess();
  }

  function renderPerspectiveSelect(id, choices, limitedId, isLimited) {
    const select = $(id);
    const old = select.value;
    select.replaceChildren();
    appendOption(select, '', choices.length ? 'Choose a perspective' : 'No authorized perspectives');
    for (const choice of choices) appendOption(select, choice.actorKey, choice.label);
    if (choices.some((choice) => choice.actorKey === old)) select.value = old;
    select.disabled = !state.authenticated || !state.access.inspector || choices.length === 0;
    text(limitedId, isLimited ? 'Perspective choices are bounded; the list may be incomplete.' : choices.length ? `${choices.length} authorized perspectives.` : 'No authorized perspectives are available.');
  }
  function renderMpChoices() {
    renderPerspectiveSelect('mp-perspective', state.perspectives.mp, 'mp-perspective-limit', state.perspectiveLimited.mp);
    const target = $('mp-target');
    const targetActorKey = target.value;
    target.replaceChildren();
    appendOption(target, '', 'All targets');
    for (const choice of state.perspectives.mp) appendOption(target, choice.actorKey, choice.label);
    if (state.perspectives.mp.some((choice) => choice.actorKey === targetActorKey)) target.value = targetActorKey;
    target.disabled = !state.authenticated || !state.access.inspector || state.perspectives.mp.length === 0;
    const enabled = state.authenticated && state.access.inspector && state.capabilities.mp && state.perspectives.mp.length > 0;
    $('mp-refresh').disabled = !enabled || state.inFlight.has('mp.relationships.read');
    $('mp-more').disabled = !enabled || !state.mp.nextCursor || state.inFlight.has('mp.relationships.read');
    if (!enabled) {
      setStatus('mp-state', state.authenticated ? (state.capabilities.mp ? 'Inspector permission or an authorized perspective is unavailable.' : 'Mind Poisoning relationship history is unavailable in this installation.') : 'Authentication is required to inspect relationship history.', 'error');
      $('mp-rows').replaceChildren();
      const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 5; cell.className = 'empty-row'; cell.textContent = 'No authorized history is available.'; row.append(cell); $('mp-rows').append(row);
    }
  }
  function renderSasChoices() {
    renderPerspectiveSelect('sas-perspective', state.perspectives.sas, 'sas-perspective-limit', state.perspectiveLimited.sas);
    const enabled = state.authenticated && state.access.inspector && state.capabilities.sas && state.perspectives.sas.length > 0;
    $('sas-refresh').disabled = !enabled || state.inFlight.has('sas.issues.read');
    $('sas-more').disabled = !enabled || !state.sas.nextCursor || state.inFlight.has('sas.issues.read');
    if (!enabled) {
      setStatus('sas-state', state.authenticated ? (state.capabilities.sas ? 'Inspector permission or an authorized perspective is unavailable.' : 'Sworn & Scorned issue history is unavailable in this installation.') : 'Authentication is required to inspect private issues.', 'error');
      $('sas-grievances').replaceChildren(); $('sas-promises').replaceChildren();
      const empty1 = document.createElement('p'); empty1.className = 'empty-row'; empty1.textContent = 'No authorized grievances are available.'; $('sas-grievances').append(empty1);
      const empty2 = document.createElement('p'); empty2.className = 'empty-row'; empty2.textContent = 'No authorized promises are available.'; $('sas-promises').append(empty2);
    }
  }
  function cellWithText(primary, secondary = '') {
    const cell = document.createElement('td');
    const strong = document.createElement('strong'); strong.textContent = primary;
    cell.append(strong);
    if (secondary) { const small = document.createElement('small'); small.textContent = secondary; cell.append(small); }
    return cell;
  }
  function displayValue(value) {
    return value === null || value === undefined ? 'Unknown (not recorded)' : String(value);
  }
  function validMpResponse(data, perspectiveActorKey) {
    if (!isPlainObject(data) || Object.keys(data).length !== 4 || Object.keys(data).some((key) => !['perspectiveActorKey', 'records', 'limited', 'nextCursor'].includes(key))
      || data.perspectiveActorKey !== perspectiveActorKey || !Array.isArray(data.records) || data.records.length > MAX_ROWS || typeof data.limited !== 'boolean'
      || !(data.nextCursor === null || boundedString(data.nextCursor, 512))) return null;
    const records = [];
    const seen = new Set();
    const typedValue = (value) => value === null || asSafeNumber(value) !== null || boundedString(value, 256);
    const fields = ['id', 'owner', 'target', 'at', 'proposedDelta', 'appliedDelta', 'historicalBefore', 'historicalAfter', 'currentValue', 'currentAsOf', 'sourceKind', 'fixedReason', 'kind', 'confirmation'];
    for (const row of data.records) {
      if (!isPlainObject(row) || Object.keys(row).length !== fields.length || Object.keys(row).some((key) => !fields.includes(key))
        || fields.some((key) => !Object.prototype.hasOwnProperty.call(row, key)) || !boundedString(row.id, 128) || !row.id || seen.has(row.id)
        || !boundedLabel(row.owner) || !boundedLabel(row.target) || !(row.at === null || boundedString(row.at, 256))
        || !(row.sourceKind === null || boundedString(row.sourceKind, 128)) || !(row.fixedReason === null || boundedString(row.fixedReason, 256))
        || !(row.kind === null || boundedString(row.kind, 128)) || !(row.confirmation === null || boundedString(row.confirmation, 128))
        || !['proposedDelta', 'appliedDelta'].every((key) => row[key] === null || asSafeNumber(row[key]) !== null)
        || !['historicalBefore', 'historicalAfter', 'currentValue'].every((key) => typedValue(row[key]))
        || !(row.currentAsOf === null || boundedString(row.currentAsOf, 256))) return null;
      seen.add(row.id);
      records.push({ id: row.id, owner: row.owner, target: row.target, at: row.at || '',
        proposedDelta: row.proposedDelta, appliedDelta: row.appliedDelta,
        historicalBefore: row.historicalBefore, historicalAfter: row.historicalAfter, currentValue: row.currentValue,
        currentAsOf: row.currentAsOf || '', sourceKind: row.sourceKind || '', fixedReason: row.fixedReason || '', kind: row.kind || '', confirmation: row.confirmation || '' });
    }
    return { records, limited: data.limited, nextCursor: data.nextCursor || null };
  }
  function formatDelta(value) {
    if (value === null) return 'Not recorded';
    return value > 0 ? `+${value}` : String(value);
  }
  function renderMpRecords(result) {
    const body = $('mp-rows');
    body.replaceChildren();
    if (!result.records.length) {
      const row = document.createElement('tr'); const cell = document.createElement('td'); cell.colSpan = 5; cell.className = 'empty-row'; cell.textContent = result.limited ? 'No matching rows in the bounded sample; older records may exist.' : 'No retained relationship changes for this authorized perspective.'; row.append(cell); body.append(row);
    }
    for (const record of result.records) {
      const row = document.createElement('tr');
      row.append(cellWithText(`${record.owner} → ${record.target}`, `${record.at || 'Time not recorded'} · ${record.confirmation || 'certainty unknown'}`));
      row.append(cellWithText(`${formatDelta(record.proposedDelta)} / ${formatDelta(record.appliedDelta)}`, 'Proposed / confirmed applied'));
      row.append(cellWithText(`${displayValue(record.historicalBefore)} → ${displayValue(record.historicalAfter)}`, 'Historical before / after'));
      row.append(cellWithText(displayValue(record.currentValue), record.currentAsOf ? `Read ${record.currentAsOf}` : 'Current query value'));
      row.append(cellWithText(`${record.kind || 'Type not recorded'} · ${record.sourceKind || 'Source unknown'}`, record.fixedReason || 'No fixed reason supplied'));
      body.append(row);
    }
    text('mp-limited', result.limited || result.nextCursor ? 'Showing a bounded page. Load older to continue; older matching history may remain.' : '');
    $('mp-more').disabled = !state.authenticated || !state.access.inspector || !state.mp.nextCursor || state.inFlight.has('mp.relationships.read');
  }
  async function refreshMp(loadOlder = false) {
    const perspectiveActorKey = $('mp-perspective').value;
    if (!perspectiveActorKey) { setStatus('mp-state', 'Choose an authorized perspective first.', 'error'); return; }
    const targetActorKey = $('mp-target').value;
    const payload = { perspectiveActorKey, limit: 50 };
    if (targetActorKey) payload.targetActorKey = targetActorKey;
    const currentState = state.mp;
    const requestedCursor = loadOlder ? currentState.nextCursor : null;
    if (loadOlder) {
      if (!currentState.nextCursor) return;
      payload.cursor = currentState.nextCursor;
    } else state.mp = { records: [], nextCursor: null, limited: false };
    const button = loadOlder ? $('mp-more') : $('mp-refresh');
    await runRead('mp.relationships.read', payload, button, (data) => {
      const result = validMpResponse(data, perspectiveActorKey);
      if (!result) throw failLocal('UNAVAILABLE');
      state.mp.records = [...state.mp.records, ...result.records];
      state.mp.nextCursor = result.nextCursor;
      state.mp.limited = result.limited;
      renderMpRecords(state.mp);
      setStatus('mp-state', result.limited || result.nextCursor ? `Bounded relationship page loaded for ${$('mp-perspective').selectedOptions[0].textContent}.` : `Relationship history loaded for ${$('mp-perspective').selectedOptions[0].textContent}.`, 'success');
    }, 'mp-state', 'Reading relationship history…', () => $('mp-perspective').value === perspectiveActorKey
      && $('mp-target').value === targetActorKey
      && (loadOlder ? state.mp.nextCursor === requestedCursor : state.mp.nextCursor === null));
  }
  function validSasResponse(data, perspectiveActorKey) {
    const topFields = ['perspectiveActorKey', 'issues', 'limited', 'nextCursor'];
    if (!isPlainObject(data) || Object.keys(data).length !== topFields.length || Object.keys(data).some((key) => !topFields.includes(key))
      || data.perspectiveActorKey !== perspectiveActorKey || !Array.isArray(data.issues) || data.issues.length > MAX_ROWS
      || typeof data.limited !== 'boolean' || !(data.nextCursor === null || boundedString(data.nextCursor, 512))) return null;
    const fields = ['id', 'kind', 'owner', 'counterpart', 'direction', 'matter', 'nature', 'status', 'settlement'];
    const seen = new Set();
    const issues = [];
    for (const row of data.issues) {
      if (!isPlainObject(row) || Object.keys(row).length !== fields.length || Object.keys(row).some((key) => !fields.includes(key))
        || fields.some((key) => !Object.prototype.hasOwnProperty.call(row, key)) || !['grievance', 'promise'].includes(row.kind)
        || !boundedString(row.id, 128) || !row.id || seen.has(row.id) || !boundedLabel(row.owner) || !boundedLabel(row.counterpart)
        || !(row.direction === null || boundedString(row.direction, 256)) || !(row.matter === null || boundedString(row.matter, 2000))
        || !(row.nature === null || boundedString(row.nature, 512)) || !(row.status === null || boundedString(row.status, 256))
        || !(row.settlement === null || boundedString(row.settlement, 256))) return null;
      seen.add(row.id);
      issues.push({ id: row.id, kind: row.kind, owner: row.owner, counterpart: row.counterpart,
        direction: row.direction || 'Direction not recorded', matter: row.matter || 'Matter not recorded', nature: row.nature || 'Nature not recorded',
        status: row.status || 'Status not recorded', settlement: row.settlement || 'Not recorded' });
    }
    return { issues, limited: data.limited, nextCursor: data.nextCursor };
  }
  function renderIssueList(node, issues, emptyText) {
    node.replaceChildren();
    if (!issues.length) { const empty = document.createElement('p'); empty.className = 'empty-row'; empty.textContent = emptyText; node.append(empty); return; }
    for (const issue of issues) {
      const row = document.createElement('div'); row.className = 'issue-row';
      for (const [primary, secondary] of [[`${issue.owner} → ${issue.counterpart}`, issue.direction], [issue.matter, issue.nature], [issue.status, `Settlement: ${issue.settlement}`]]) {
        const cell = document.createElement('div');
        const strong = document.createElement('strong'); strong.textContent = primary;
        const small = document.createElement('small'); small.textContent = secondary;
        cell.append(strong, small); row.append(cell);
      }
      node.append(row);
    }
  }
  async function refreshSas(loadOlder = false) {
    const perspectiveActorKey = $('sas-perspective').value;
    if (!perspectiveActorKey) { setStatus('sas-state', 'Choose an authorized perspective first.', 'error'); return; }
    const payload = { perspectiveActorKey, limit: 50 };
    const requestedCursor = loadOlder ? state.sas.nextCursor : null;
    if (loadOlder) {
      if (!state.sas.nextCursor) return;
      payload.cursor = state.sas.nextCursor;
    } else state.sas = { issues: [], nextCursor: null, limited: false };
    const button = loadOlder ? $('sas-more') : $('sas-refresh');
    await runRead('sas.issues.read', payload, button, (data) => {
      const result = validSasResponse(data, perspectiveActorKey);
      if (!result) throw failLocal('UNAVAILABLE');
      state.sas.issues = [...state.sas.issues, ...result.issues];
      state.sas.nextCursor = result.nextCursor;
      state.sas.limited = result.limited;
      renderIssueList($('sas-grievances'), state.sas.issues.filter((issue) => issue.kind === 'grievance'), 'No retained grievances for this perspective.');
      renderIssueList($('sas-promises'), state.sas.issues.filter((issue) => issue.kind === 'promise'), 'No retained promises for this perspective.');
      text('sas-limited', result.limited || result.nextCursor ? 'Showing a bounded page; use Load older to continue.' : '');
      $('sas-more').disabled = !state.authenticated || !state.access.inspector || !state.sas.nextCursor || state.inFlight.has('sas.issues.read');
      setStatus('sas-state', result.limited || result.nextCursor ? 'Bounded issue page loaded.' : 'Issue history loaded for the selected perspective.', 'success');
    }, 'sas-state', 'Reading grievances and promises…', () => $('sas-perspective').value === perspectiveActorKey
      && (loadOlder ? state.sas.nextCursor === requestedCursor : state.sas.nextCursor === null));
  }

  function validCatalog(data) {
    if (!isPlainObject(data) || Object.keys(data).length !== 4 || Object.keys(data).some((key) => !['actors', 'limited', 'revision', 'nextCursor'].includes(key))
      || !Array.isArray(data.actors) || data.actors.length > MAX_ROWS || typeof data.limited !== 'boolean'
      || typeof data.revision !== 'string' || !/^[a-f0-9]{64}$/.test(data.revision)
      || (data.nextCursor !== null && (!boundedString(data.nextCursor, 512) || data.nextCursor.length === 0))) return null;
    const seen = new Set();
    const actors = [];
    for (const actor of data.actors) {
      if (!isPlainObject(actor) || Object.keys(actor).length !== 5 || Object.keys(actor).some((key) => !['actorKey', 'label', 'canWrite', 'canReceive', 'canSignAs'].includes(key))
        || typeof actor.actorKey !== 'string' || !/^(?:player|npc:[1-9][0-9]{0,18})$/.test(actor.actorKey) || !boundedLabel(actor.label)
        || typeof actor.canWrite !== 'boolean' || typeof actor.canReceive !== 'boolean' || typeof actor.canSignAs !== 'boolean' || seen.has(actor.actorKey)) return null;
      seen.add(actor.actorKey);
      actors.push({ actorKey: actor.actorKey, label: actor.label, canWrite: actor.canWrite, canReceive: actor.canReceive, canSignAs: actor.canSignAs });
    }
    return { actors, limited: data.limited, revision: data.revision, nextCursor: data.nextCursor };
  }
  function validInventory(data) {
    if (!isPlainObject(data) || Object.keys(data).length !== 3 || Object.keys(data).some((key) => !['inventoryRevision', 'items', 'gold'].includes(key))
      || !boundedString(data.inventoryRevision, 128) || !Array.isArray(data.items) || data.items.length > 128 || !isPlainObject(data.gold)
      || Object.keys(data.gold).length !== 1 || !Object.prototype.hasOwnProperty.call(data.gold, 'available')) return null;
    const availableGold = Number.isSafeInteger(data.gold.available) && data.gold.available >= 0 ? data.gold.available : null;
    if (availableGold === null) return null;
    const seen = new Set();
    const items = [];
    for (const item of data.items) {
      if (!isPlainObject(item) || !boundedString(item.itemKey, 96) || item.itemKey.length === 0 || !boundedLabel(item.label)
        || !Number.isSafeInteger(item.availableQuantity) || item.availableQuantity < 0 || seen.has(item.itemKey)) return null;
      seen.add(item.itemKey);
      if (item.availableQuantity > 0) items.push({ itemKey: item.itemKey, label: item.label, availableQuantity: item.availableQuantity });
    }
    return { inventoryRevision: data.inventoryRevision, items, goldAvailable: availableGold };
  }
  function renderCatalogSelect(id, placeholder, actors, predicate, preserve = '') {
    const select = $(id);
    const old = preserve || select.value;
    select.replaceChildren();
    appendOption(select, '', placeholder);
    for (const actor of actors.filter(predicate)) appendOption(select, actor.actorKey, actor.label);
    select.value = actors.some((actor) => actor.actorKey === old && predicate(actor)) ? old : '';
  }
  function syncMailboxRecipients() {
    // The authorized catalog bounds mailbox choices; compose eligibility is unrelated to archive access.
    renderCatalogSelect('mailbox-recipient-secondary', 'Choose a recipient mailbox', state.mail.actors, () => true);
    const selected = $('mailbox-recipient-secondary').value;
    if (selected) $('mailbox-recipient-secondary').value = selected;
  }
  function renderInventory() {
    const container = $('inventory-items');
    container.replaceChildren();
    if (!state.mail.inventoryLoaded || !state.mail.inventory) {
      const empty = document.createElement('p'); empty.className = 'empty-row'; empty.textContent = 'Refresh inventory to see supported items.'; container.append(empty);
      $('gold-amount').value = '0';
      text('inventory-status', 'Inventory has not been read.');
      text('attachment-count', '0 / 8 unique attachment types · Gold counts as one.');
      return;
    }
    for (const [index, item] of state.mail.inventory.items.entries()) {
      const row = document.createElement('label'); row.className = 'inventory-row';
      const check = document.createElement('input'); check.type = 'checkbox'; check.className = 'inventory-check'; check.setAttribute('aria-label', `Attach ${item.label}`); check.addEventListener('change', () => { state.mail.reviewed = false; updateAttachmentCount(); renderMailAccess(); });
      const name = document.createElement('span'); name.textContent = item.label;
      const available = document.createElement('small'); available.textContent = `Available ${item.availableQuantity}`;
      const quantity = document.createElement('input'); quantity.type = 'number'; quantity.className = 'inventory-quantity'; quantity.min = '1'; quantity.max = String(item.availableQuantity); quantity.step = '1'; quantity.value = '1'; quantity.disabled = true; quantity.setAttribute('aria-label', `${item.label} quantity`);
      check.addEventListener('change', () => { quantity.disabled = !check.checked; if (check.checked) quantity.value = '1'; state.mail.reviewed = false; updateAttachmentCount(); renderMailAccess(); });
      quantity.addEventListener('input', () => { state.mail.reviewed = false; updateAttachmentCount(); renderMailAccess(); });
      row.append(check, name, available, quantity);
      row.dataset.inventoryIndex = String(index);
      container.append(row);
    }
    $('gold-amount').max = String(state.mail.inventory.goldAvailable);
    text('inventory-status', 'Inventory refreshed.');
    updateAttachmentCount();
  }
  function selectedAttachments() {
    const attachments = [];
    for (const row of $('inventory-items').querySelectorAll('.inventory-row')) {
      const index = Number(row.dataset.inventoryIndex);
      const item = state.mail.inventory && state.mail.inventory.items[index];
      const check = row.querySelector('.inventory-check');
      const quantity = row.querySelector('.inventory-quantity');
      if (!item || !check || !quantity || !check.checked) continue;
      const count = quantity.valueAsNumber;
      if (!Number.isSafeInteger(count) || count < 1 || count > item.availableQuantity) return null;
      attachments.push({ itemKey: item.itemKey, quantity: count });
    }
    return attachments;
  }
  function goldAmount() {
    const value = $('gold-amount').valueAsNumber;
    if (!Number.isSafeInteger(value) || value < 0 || !state.mail.inventory || value > state.mail.inventory.goldAvailable) return null;
    return value;
  }
  function updateAttachmentCount() {
    const selected = selectedAttachments();
    const gold = goldAmount();
    const count = (selected ? selected.length : 0) + (gold > 0 ? 1 : 0);
    text('attachment-count', `${count} / 8 unique attachment types · Gold counts as one${count > 8 ? ' · Too many attachments' : ''}.`);
  }
  function operationFor(operationId) {
    return state.mail.operations.find((operation) => operation.operationId === operationId) || null;
  }
  function selectedUnresolvedOperation() {
    const selectedId = $('letter-operation').value;
    return operationFor(selectedId) || operationFor(state.mail.currentOperationId) || state.mail.operations[0] || null;
  }
  function renderUnresolvedOperations() {
    const select = $('letter-operation');
    const canManage = state.authenticated && !state.closed && state.access.compose && state.capabilities.correspondence;
    if (!canManage) {
      const placeholder = document.createElement('option');
      placeholder.value = '';
      placeholder.textContent = 'Available after authorization';
      select.replaceChildren(placeholder);
      select.disabled = true;
      $('letter-recovery').hidden = true;
      $('letter-reconcile').hidden = true;
      $('letter-reconcile').disabled = true;
      $('letter-new').hidden = true;
      $('letter-new').disabled = true;
      text('letter-recovery-status', '');
      text('letter-recovery-result', '');
      return { fingerprint: '', transferBlocked: false, duplicateBlocked: false };
    }
    $('letter-recovery').hidden = false;
    const currentSelection = select.value;
    const selectedId = operationFor(currentSelection) ? currentSelection
      : operationFor(state.mail.currentOperationId) ? state.mail.currentOperationId
        : state.mail.operations[0]?.operationId || '';
    const options = state.mail.operations.map((operation) => {
      const option = document.createElement('option');
      option.value = operation.operationId;
      option.textContent = `${operation.operationId} · ${operation.state}`;
      return option;
    });
    if (!options.length) {
      const option = document.createElement('option');
      option.value = '';
      option.textContent = 'No unresolved letters';
      options.push(option);
    }
    select.replaceChildren(...options);
    select.value = selectedId;
    select.disabled = state.mail.operations.length === 0;
    const selected = selectedUnresolvedOperation();
    const payload = composePayload();
    const fingerprint = payload ? letterIntentFingerprint(payload) : '';
    const transferBlocked = Boolean(payload && (payload.attachments.length > 0 || payload.goldAmount > 0)
      && state.mail.operations.some((operation) => operation.hasTransfer));
    const duplicateBlocked = Boolean(fingerprint && state.mail.operations.some((operation) => operation.intentFingerprint === fingerprint));
    const messages = [];
    if (state.mail.operations.length >= MAX_UNRESOLVED_LETTERS) {
      messages.push('Three unresolved operations are retained. Reconcile one before sending another letter; none will be dropped.');
    } else if (transferBlocked) {
      messages.push('A transfer-bearing operation remains unresolved. Reconcile it before sending another transfer-bearing letter; text-only letters remain available.');
    } else if (payload && !fingerprint) {
      messages.push('This draft cannot be compared safely with unresolved operations, so it cannot be sent.');
    } else if (duplicateBlocked) {
      messages.push('An unresolved operation has the same writer, recipient, and normalized body. Reconcile that operation before sending this draft.');
    }
    if (selected && selected.message) messages.push(selected.message);
    else if (selected) messages.push(`${selected.operationId} remains unresolved (${selected.state}). Reconcile this same operation; absence alone does not prove it was not sent.`);
    text('letter-recovery-status', messages.join(' '));
    text('letter-recovery-result', state.mail.recoveryNotice);
    $('letter-reconcile').hidden = !selected;
    $('letter-reconcile').disabled = !selected || state.inFlight.has('correspondence.reconcile');
    $('letter-new').hidden = false;
    $('letter-new').disabled = false;
    return { fingerprint, transferBlocked, duplicateBlocked };
  }
  function renderMailAccess() {
    const authenticated = state.authenticated && !state.closed;
    const correspondenceReady = authenticated && state.capabilities.correspondence;
    const canCompose = correspondenceReady && state.access.compose;
    const canInspect = correspondenceReady && state.access.inspector;
    const canCatalog = canCompose || canInspect;
    $('mail-refresh').disabled = !canCatalog || state.inFlight.has('correspondence.catalog.read') || (!canCompose && state.inFlight.has('correspondence.inventory.read'));
    $('mail-catalog-more').disabled = !canCatalog || !state.mail.catalogNextCursor || !state.mail.catalogRevision || state.inFlight.has('correspondence.catalog.read');
    $('mailbox-recipient-secondary').disabled = !canInspect || state.mail.actors.length === 0;
    $('mailbox-refresh-secondary').disabled = !canInspect || !state.mail.actors.some((actor) => actor.actorKey === $('mailbox-recipient-secondary').value) || state.inFlight.has('correspondence.mailbox.read');
    $('mailbox-more-secondary').disabled = !canInspect || !state.mail.mailboxNextCursor || state.inFlight.has('correspondence.mailbox.read');
    const composerReady = canCompose && state.mail.inventoryLoaded && state.mail.actors.some((actor) => actor.canWrite) && state.mail.actors.some((actor) => actor.canReceive) && !state.mail.composeLocked;
    $('letter-writer').disabled = !composerReady;
    $('letter-recipient').disabled = !composerReady;
    $('sign-as-other').disabled = !composerReady;
    $('letter-signature').disabled = !composerReady || !$('sign-as-other').checked;
    $('letter-body').disabled = !composerReady;
    $('gold-amount').disabled = !composerReady;
    $('inventory-items').querySelectorAll('.inventory-row').forEach((row) => {
      row.querySelector('.inventory-check').disabled = !composerReady;
      if (composerReady) row.querySelector('.inventory-quantity').disabled = !row.querySelector('.inventory-check').checked;
      else row.querySelector('.inventory-quantity').disabled = true;
    });
    const payload = composePayload();
    const formValid = composerReady && payload !== null;
    const recovery = renderUnresolvedOperations();
    $('letter-review').disabled = !formValid;
    $('letter-send').disabled = !formValid || !state.mail.reviewed || state.mail.operations.length >= MAX_UNRESOLVED_LETTERS
      || recovery.transferBlocked || recovery.duplicateBlocked || !recovery.fingerprint;
    if (!correspondenceReady) {
      setStatus('mail-state', authenticated ? 'Sealed Fates is unavailable in this installation.' : 'Authentication is required to use Sealed Fates.', 'error');
    } else if (!canCatalog) {
      setStatus('mail-state', 'Sealed Fates permission is unavailable for this session.', 'error');
    } else if (state.mail.catalogError) {
      setStatus('mail-state', state.mail.catalogError, 'error');
    } else if (!canCompose && canInspect) {
      setStatus('mail-state', 'Mailboxes are available to this Inspector. Composition needs a separate Compose permission.', 'success');
    } else {
      setStatus('mail-state', 'Out-of-character access is authorized. Composition and receipt stages remain separate from NPC knowledge.', 'success');
    }
  }
  function clearSelectsForMail() {
    renderCatalogSelect('letter-writer', 'No authorized writers', state.mail.actors, (actor) => actor.canWrite);
    renderCatalogSelect('letter-recipient', 'No authorized recipients', state.mail.actors, (actor) => actor.canReceive);
    renderCatalogSelect('letter-signature', 'Choose an authorized signature', state.mail.actors, (actor) => actor.canSignAs);
    syncMailboxRecipients();
  }
  function clearCatalogPage() {
    state.mail.actors = [];
    state.mail.catalogLimited = false;
    state.mail.catalogRevision = null;
    state.mail.catalogNextCursor = null;
    state.mail.catalogError = null;
    clearSelectsForMail();
    renderMailAccess();
  }
  async function refreshCatalog(loadMore = false) {
    const context = makeContext();
    const requestedCursor = loadMore ? state.mail.catalogNextCursor : null;
    const requestedRevision = loadMore ? state.mail.catalogRevision : null;
    try {
      requireCapability('correspondence.catalog.read');
      if (loadMore && (!requestedCursor || !requestedRevision)) return;
      const payload = { limit: 100 };
      if (loadMore) payload.cursor = requestedCursor;
      const data = await request('correspondence.catalog.read', payload);
      if (!isCurrentContext(context)) return;
      const catalog = validCatalog(data);
      if (!catalog) throw failLocal('UNAVAILABLE');
      if (loadMore && catalog.revision !== requestedRevision) throw failLocal('STALE_REVISION');
      const existing = loadMore ? state.mail.actors : [];
      const existingKeys = new Set(existing.map((actor) => actor.actorKey));
      if (catalog.actors.some((actor) => existingKeys.has(actor.actorKey)) || existing.length + catalog.actors.length > MAX_CATALOG_ACTORS) {
        throw failLocal('UNAVAILABLE');
      }
      state.mail.actors = [...existing, ...catalog.actors];
      state.mail.catalogLimited = catalog.limited;
      state.mail.catalogRevision = catalog.revision;
      state.mail.catalogNextCursor = catalog.nextCursor;
      state.mail.catalogError = null;
      clearSelectsForMail();
      renderMailAccess();
      const notices = [];
      if (catalog.nextCursor) notices.push('More authorized actors are available; use Load more actors to continue.');
      if (catalog.limited) notices.push('The source catalog is capped; some source entries may be unavailable.');
      text('mail-limited', notices.join(' '));
    } catch (error) {
      if (!isCurrentContext(context)) return;
      if (loadMore && error && error.code === 'STALE_REVISION') {
        clearCatalogPage();
        state.mail.catalogError = 'The authorized actor list changed. Refresh the catalog before continuing.';
        text('mail-limited', 'The catalog changed while paging; its old choices and cursor were cleared.');
        return;
      }
      state.mail.catalogError = localError(error);
      setStatus('mail-state', state.mail.catalogError, 'error');
    }
  }
  async function refreshInventory() {
    if (!state.access.compose) return;
    const context = makeContext();
    try {
      requireCapability('correspondence.inventory.read');
      const data = await request('correspondence.inventory.read', {});
      if (!isCurrentContext(context)) return;
      const inventory = validInventory(data);
      if (!inventory) throw failLocal('UNAVAILABLE');
      state.mail.inventory = inventory;
      state.mail.inventoryLoaded = true;
      state.mail.reviewed = false;
      renderInventory();
      renderMailAccess();
    } catch (error) {
      if (!isCurrentContext(context)) return;
      state.mail.inventory = null; state.mail.inventoryLoaded = false; renderInventory();
      setStatus('inventory-status', localError(error), 'error'); renderMailAccess();
    }
  }
  async function refreshCatalogAndInventory() {
    if (state.inFlight.has('correspondence.catalog.read') || state.inFlight.has('correspondence.inventory.read')) return;
    const context = makeContext();
    state.inFlight.add('correspondence.catalog.read');
    const catalogJob = refreshCatalog().finally(() => { if (isCurrentView(context.viewToken, context.nativeGeneration)) state.inFlight.delete('correspondence.catalog.read'); });
    if (state.access.compose) {
      state.inFlight.add('correspondence.inventory.read');
      await Promise.allSettled([catalogJob, refreshInventory().finally(() => { if (isCurrentView(context.viewToken, context.nativeGeneration)) state.inFlight.delete('correspondence.inventory.read'); })]);
    } else await catalogJob;
    if (isCurrentView(context.viewToken, context.nativeGeneration)) renderMailAccess();
  }
  async function loadMoreCatalog() {
    if (state.inFlight.has('correspondence.catalog.read') || !state.mail.catalogNextCursor) return;
    const context = makeContext();
    state.inFlight.add('correspondence.catalog.read');
    renderMailAccess();
    try { await refreshCatalog(true); }
    finally {
      if (isCurrentView(context.viewToken, context.nativeGeneration)) {
        state.inFlight.delete('correspondence.catalog.read');
        renderMailAccess();
      }
    }
  }
  function composePayload() {
    if (!state.mail.inventory || !state.mail.inventoryLoaded || !state.access.compose) return null;
    const writerActorKey = $('letter-writer').value;
    const recipientActorKey = $('letter-recipient').value;
    const body = $('letter-body').value;
    const chars = Array.from(body).length;
    const attachments = selectedAttachments();
    const gold = goldAmount();
    const signatureActorKey = $('sign-as-other').checked ? $('letter-signature').value : '';
    if (!writerActorKey || !recipientActorKey || !body.trim() || chars > 2000 || bytes(body) > 8192 || !attachments || gold === null || attachments.length + (gold > 0 ? 1 : 0) > 8) return null;
    if (!state.mail.actors.some((actor) => actor.actorKey === writerActorKey && actor.canWrite) || !state.mail.actors.some((actor) => actor.actorKey === recipientActorKey && actor.canReceive)) return null;
    if ($('sign-as-other').checked && !state.mail.actors.some((actor) => actor.actorKey === signatureActorKey && actor.canSignAs)) return null;
    if (new Set(attachments.map((item) => item.itemKey)).size !== attachments.length) return null;
    return { writerActorKey, recipientActorKey, ...(signatureActorKey ? { signatureActorKey } : {}), body, inventoryRevision: state.mail.inventory.inventoryRevision, attachments, goldAmount: gold };
  }
  function markComposeChanged() {
    state.mail.reviewed = false;
    $('letter-review-summary').hidden = true;
    renderMailAccess();
  }
  function startNewLetter() {
    if ($('letter-new').disabled) return;
    const retained = state.mail.operations.length > 0;
    state.mail.currentOperationId = null;
    state.mail.operationDraft = null;
    state.mail.composeLocked = false;
    state.mail.reviewed = false;
    $('letter-writer').value = '';
    $('letter-recipient').value = '';
    $('sign-as-other').checked = false;
    $('letter-signature').value = '';
    $('signature-wrap').hidden = true;
    $('letter-body').value = '';
    $('gold-amount').value = '0';
    $('inventory-items').querySelectorAll('.inventory-row').forEach((row) => {
      row.querySelector('.inventory-check').checked = false;
      row.querySelector('.inventory-quantity').value = '1';
    });
    $('letter-review-summary').replaceChildren();
    $('letter-review-summary').hidden = true;
    updateLetterCount();
    updateAttachmentCount();
    setReceiptText(retained
      ? 'A new draft is ready. Earlier unresolved operations remain listed by ID.'
      : 'The draft was cleared. Enter a new letter.');
    renderMailAccess();
  }
  function reviewLetter() {
    const payload = composePayload();
    if (!payload) { setStatus('letter-receipt', 'Choose authorized writer and recipient, a valid body, and supported quantities within the player inventory.', 'error'); return; }
    state.mail.reviewed = true;
    const summary = $('letter-review-summary');
    summary.replaceChildren();
    const heading = document.createElement('strong'); heading.textContent = 'Review before sending'; summary.append(heading);
    const writer = state.mail.actors.find((actor) => actor.actorKey === payload.writerActorKey);
    const recipient = state.mail.actors.find((actor) => actor.actorKey === payload.recipientActorKey);
    const signature = payload.signatureActorKey ? state.mail.actors.find((actor) => actor.actorKey === payload.signatureActorKey).label : 'None';
    const details = document.createElement('p'); details.textContent = `Writer: ${writer.label} · Recipient: ${recipient.label} · Payer: Player · Claimed signature: ${signature} · ${payload.goldAmount} gold · ${payload.attachments.length + (payload.goldAmount > 0 ? 1 : 0)} attachment types.`; summary.append(details);
    const items = document.createElement('p');
    const itemLabels = payload.attachments.map((attachment) => {
      const item = state.mail.inventory.items.find((entry) => entry.itemKey === attachment.itemKey);
      return item ? `${item.label} × ${attachment.quantity}` : 'Unavailable item';
    });
    items.textContent = `Items: ${itemLabels.length ? itemLabels.join(', ') : 'None'}`;
    summary.append(items);
    const body = document.createElement('p'); body.textContent = payload.body; summary.append(body);
    summary.hidden = false;
    setStatus('letter-receipt', 'Review the writer, recipient, body, and player-paid attachments. Send creates one operation; uncertain results must be reconciled.', 'success');
    renderMailAccess();
  }
  function setReceiptText(message, kind = '') { setStatus('letter-receipt', message, kind); }
  function applyComposeResult(data, expectedOperationId) {
    if (!isPlainObject(data) || data.operationId !== expectedOperationId || !['stored', 'queued', 'dispatched', 'delivered', 'unknown', 'partial', 'rejected'].includes(data.state)) throw failLocal('UNAVAILABLE');
    const operation = operationFor(expectedOperationId);
    if (!operation) return false;
    const receipt = isPlainObject(data.receipt) ? {
      deliveryConfirmed: data.receipt.deliveryConfirmed === true,
      receivedAt: boundedString(data.receipt.receivedAt, 256) ? data.receipt.receivedAt : '',
    } : null;
    const resolvedState = data.state === 'delivered' && (!receipt || receipt.deliveryConfirmed !== true) ? 'unknown' : data.state;
    const terminal = resolvedState === 'delivered' || resolvedState === 'rejected';
    const isCurrent = state.mail.currentOperationId === expectedOperationId;
    if (terminal) {
      state.mail.operations = state.mail.operations.filter((item) => item.operationId !== expectedOperationId);
      if (!isCurrent) {
        state.mail.recoveryNotice = resolvedState === 'delivered'
          ? `Operation ${expectedOperationId} resolved: delivery receipt confirmed.`
          : `Operation ${expectedOperationId} resolved: rejected without a delivery receipt.`;
      }
      if (isCurrent) {
        state.mail.currentOperationId = null;
        state.mail.composeLocked = false;
        state.mail.reviewed = false;
        $('letter-review-summary').hidden = true;
        state.mail.operationDraft = null;
        setReceiptText(resolvedState === 'delivered'
          ? `Delivery receipt confirmed for operation ${expectedOperationId}${boundedString(receipt.receivedAt, 256) ? ` · Received ${receipt.receivedAt}` : ''}.`
          : `Operation ${expectedOperationId} was rejected; no delivery receipt was recorded.`, resolvedState === 'delivered' ? 'success' : 'error');
      }
    } else {
      operation.state = resolvedState;
      operation.message = '';
      if (isCurrent) {
        state.mail.composeLocked = true;
        const stage = {
          stored: 'Stored; delivery is not confirmed.', queued: 'Queued; delivery is not confirmed.', dispatched: 'Dispatched; delivery is not confirmed.',
          unknown: 'Outcome unknown; do not send again.', partial: 'Partial outcome; do not send again.',
        }[resolvedState] || 'Receipt stage unavailable.';
        setReceiptText(`${stage} Reconcile this operation before retrying.`, ['unknown', 'partial'].includes(resolvedState) ? 'error' : '');
      }
    }
    renderMailAccess();
    return true;
  }
  function releaseComposeOperation(operationId) {
    const operation = operationFor(operationId);
    if (!operation) return false;
    const isCurrent = state.mail.currentOperationId === operationId;
    state.mail.operations = state.mail.operations.filter((item) => item.operationId !== operationId);
    if (isCurrent) {
      state.mail.currentOperationId = null;
      state.mail.operationDraft = null;
      state.mail.composeLocked = false;
      state.mail.reviewed = false;
      $('letter-review-summary').hidden = true;
    } else state.mail.recoveryNotice = `Operation ${operationId} was confirmed not sent and released.`;
    renderMailAccess();
    return true;
  }
  async function sendLetter() {
    if ($('letter-send').disabled || state.mail.composeLocked || !state.mail.reviewed) return;
    const base = composePayload();
    if (!base) return;
    const intentFingerprint = letterIntentFingerprint(base);
    const hasTransfer = base.attachments.length > 0 || base.goldAmount > 0;
    if (!intentFingerprint || state.mail.operations.length >= MAX_UNRESOLVED_LETTERS
      || state.mail.operations.some((operation) => operation.intentFingerprint === intentFingerprint)
      || (hasTransfer && state.mail.operations.some((operation) => operation.hasTransfer))) {
      renderMailAccess();
      return;
    }
    const operationId = newUuid();
    const payload = { operationId, ...base };
    state.mail.operations.push({ operationId, state: 'unknown', intentFingerprint, hasTransfer, message: '' });
    state.mail.currentOperationId = operationId;
    state.mail.operationDraft = { body: base.body };
    state.mail.composeLocked = true;
    renderMailAccess();
    setReceiptText('Submitting once. If the result is uncertain, reconcile this same operation; do not send a second copy.');
    const context = makeContext();
    let dispatched = false;
    try {
      const data = await request('correspondence.compose', payload);
      if (!isCurrentContext(context)) return;
      dispatched = true;
      applyComposeResult(data, operationId);
    } catch (error) {
      if (!isCurrentContext(context)) return;
      if (!dispatched && error.dispatched !== true) {
        const wasCurrent = state.mail.currentOperationId === operationId;
        if (!releaseComposeOperation(operationId)) return;
        if (error.code === 'STALE_REVISION' && wasCurrent) {
          state.mail.inventory = null;
          state.mail.inventoryLoaded = false;
          renderInventory();
        }
        if (wasCurrent) setStatus('letter-receipt', `${localError(error)} The request was not sent; review and try again.`, 'error');
        renderMailAccess();
        return;
      }
      const operation = operationFor(operationId);
      if (!operation) return;
      operation.state = 'unknown';
      operation.message = '';
      if (state.mail.currentOperationId === operationId) {
        state.mail.composeLocked = true;
        setStatus('letter-receipt', `${localError(error)} Reconcile this operation; do not send a second copy.`, 'error');
      }
      renderMailAccess();
    }
  }
  async function reconcileLetter() {
    const selected = selectedUnresolvedOperation();
    if (!selected || state.inFlight.has('correspondence.reconcile')) return;
    const operationId = selected.operationId;
    const context = makeContext();
    await withBusy('correspondence.reconcile', $('letter-reconcile'), async () => {
      try {
        requireCapability('correspondence.reconcile');
        const data = await request('correspondence.reconcile', { operationId });
        if (!isCurrentContext(context)) return;
        applyComposeResult(data, operationId);
      } catch (error) {
        if (!isCurrentContext(context)) return;
        const operation = operationFor(operationId);
        if (operation) {
          operation.state = 'unknown';
          operation.message = error.boundaryCode === 'not_found'
            ? 'Not found yet; this operation remains unresolved. Reconcile the same ID later; absence does not prove no write.'
            : 'This operation remains unresolved. Reconcile the same ID later.';
        }
        if (state.mail.currentOperationId === operationId) {
          state.mail.composeLocked = true;
          setStatus('letter-receipt', error.boundaryCode === 'not_found'
            ? 'Not found yet; this operation remains unresolved. Do not send its payload again.'
            : `${localError(error)} The existing operation remains unresolved.`, 'error');
        }
        renderMailAccess();
      }
    });
  }
  function validMailbox(data, recipientActorKey) {
    const topFields = ['recipientActorKey', 'letters', 'limited', 'nextCursor'];
    const rowFields = ['letterId', 'claimedSignature', 'receivedAt', 'bodyPreview', 'attachments', 'deliveryConfirmed'];
    if (!isPlainObject(data) || Object.keys(data).length !== topFields.length || Object.keys(data).some((key) => !topFields.includes(key))
      || data.recipientActorKey !== recipientActorKey || !Array.isArray(data.letters) || data.letters.length > MAX_ROWS
      || typeof data.limited !== 'boolean' || !(data.nextCursor === null || boundedString(data.nextCursor, 512))) return null;
    const letters = [];
    const seen = new Set();
    for (const row of data.letters) {
      if (!isPlainObject(row) || Object.keys(row).length !== rowFields.length || Object.keys(row).some((key) => !rowFields.includes(key))
        || !boundedString(row.letterId, 128) || !row.letterId || seen.has(row.letterId) || !boundedString(row.claimedSignature, 256)
        || !boundedString(row.receivedAt, 256) || !boundedString(row.bodyPreview, 128) || row.deliveryConfirmed !== true
        || !Array.isArray(row.attachments) || row.attachments.length > 8) return null;
      seen.add(row.letterId);
      const attachments = [];
      for (const item of row.attachments) {
        if (!isPlainObject(item) || Object.keys(item).length !== 2 || Object.keys(item).some((key) => !['label', 'quantity'].includes(key))
          || !boundedLabel(item.label) || !Number.isSafeInteger(item.quantity) || item.quantity < 1) return null;
        attachments.push({ label: item.label, quantity: item.quantity });
      }
      letters.push({ letterId: row.letterId, claimedSignature: row.claimedSignature, receivedAt: row.receivedAt, bodyPreview: row.bodyPreview, deliveryConfirmed: true, attachments });
    }
    return { letters, limited: data.limited, nextCursor: data.nextCursor };
  }
  function renderMailbox(data, append = false) {
    const combined = append ? [...state.mail.letters] : [];
    const seen = new Set(combined.map((letter) => letter.letterId));
    for (const letter of data.letters) if (!seen.has(letter.letterId)) { combined.push(letter); seen.add(letter.letterId); }
    state.mail.letters = combined;
    state.mail.mailboxNextCursor = data.nextCursor;
    state.mail.mailboxLimited = data.limited;
    const list = $('mailbox-list-secondary');
    if (!append) list.replaceChildren();
    if (!state.mail.letters.length) {
      const empty = document.createElement('p'); empty.className = 'empty-row'; empty.textContent = data.limited ? 'No delivered letters in the bounded sample; older records may exist.' : 'No receipt-confirmed delivered letters are recorded for this recipient.'; list.append(empty);
    }
    for (const letter of data.letters) {
      if (append && [...list.querySelectorAll('.mail-item')].some((item) => item.dataset.letterId === letter.letterId)) continue;
      const button = document.createElement('button'); button.type = 'button'; button.className = 'mail-item'; button.setAttribute('aria-pressed', 'false');
      button.dataset.letterId = letter.letterId;
      const title = document.createElement('strong'); title.textContent = `Letter signed as ${letter.claimedSignature || 'unknown'}`;
      const stage = document.createElement('span'); stage.className = 'stage'; stage.textContent = 'Delivered · receipt confirmed';
      const meta = document.createElement('small'); meta.textContent = `${letter.receivedAt} · OOC recipient view · ${letter.attachments.length} attachment types`;
      const action = document.createElement('small'); action.textContent = 'Open detail';
      const preview = document.createElement('span'); preview.className = 'body-preview'; preview.textContent = letter.bodyPreview;
      button.append(title, stage, preview, meta, action);
      button.addEventListener('click', () => { state.mail.selectedLetter = letter.letterId; list.querySelectorAll('.mail-item').forEach((item) => item.setAttribute('aria-pressed', String(item === button))); readLetterDetail(letter); });
      list.append(button);
    }
    text('mail-limited', data.limited || data.nextCursor ? 'Showing a bounded page of receipt-confirmed deliveries; load older to continue.' : '');
    $('mailbox-more-secondary').disabled = !state.authenticated || !state.access.inspector || !state.mail.mailboxNextCursor || state.inFlight.has('correspondence.mailbox.read');
  }
  async function refreshMailbox(loadOlder = false) {
    const recipientActorKey = $('mailbox-recipient-secondary').value;
    if (!recipientActorKey) { setStatus('mail-state', 'Choose a recipient mailbox first.', 'error'); return; }
    const payload = { recipientActorKey, limit: 50 };
    const requestedCursor = loadOlder ? state.mail.mailboxNextCursor : null;
    if (loadOlder) {
      if (!state.mail.mailboxNextCursor) return;
      payload.cursor = state.mail.mailboxNextCursor;
    } else {
      state.mail.letters = [];
      state.mail.mailboxNextCursor = null;
      state.mail.mailboxLimited = false;
    }
    const button = loadOlder ? $('mailbox-more-secondary') : $('mailbox-refresh-secondary');
    await runRead('correspondence.mailbox.read', payload, button, (data) => {
      const result = validMailbox(data, recipientActorKey);
      if (!result) throw failLocal('UNAVAILABLE');
      renderMailbox(result, loadOlder);
      setStatus('mail-state', result.limited || result.nextCursor ? 'Bounded receipt-confirmed mailbox page loaded.' : 'Mailbox loaded. Opening a record does not tell an NPC anything.', 'success');
    }, 'mail-state', 'Reading the selected mailbox…', () => $('mailbox-recipient-secondary').value === recipientActorKey
      && (loadOlder ? state.mail.mailboxNextCursor === requestedCursor : state.mail.mailboxNextCursor === null));
  }
  function validLetterDetail(data, expectedId) {
    const allowedFields = ['letterId', 'claimedSignature', 'body', 'receivedAt', 'deliveryConfirmed', 'attachments', 'recordedAuthor'];
    if (!isPlainObject(data) || Object.keys(data).some((key) => !allowedFields.includes(key))
      || !['letterId', 'claimedSignature', 'body', 'receivedAt', 'deliveryConfirmed', 'attachments'].every((key) => Object.prototype.hasOwnProperty.call(data, key))
      || (Object.prototype.hasOwnProperty.call(data, 'recordedAuthor') && !state.access.authorDetail)
      || data.letterId !== expectedId || data.deliveryConfirmed !== true || !boundedString(data.claimedSignature, 256)
      || !boundedString(data.receivedAt, 256) || !boundedString(data.body, 8192) || Array.from(data.body).length > 2000 || !Array.isArray(data.attachments) || data.attachments.length > 8) return null;
    const attachments = [];
    for (const item of data.attachments) {
      if (!isPlainObject(item) || Object.keys(item).length !== 2 || Object.keys(item).some((key) => !['label', 'quantity'].includes(key))
        || !boundedLabel(item.label) || !Number.isSafeInteger(item.quantity) || item.quantity < 1) return null;
      attachments.push({ label: item.label, quantity: item.quantity });
    }
    return { letterId: data.letterId, claimedSignature: data.claimedSignature, receivedAt: data.receivedAt, body: data.body, attachments,
      recordedAuthor: state.access.authorDetail && boundedString(data.recordedAuthor, 256) ? data.recordedAuthor : '' };
  }
  async function readLetterDetail(letter) {
    if (!letter || !state.mail.letters.some((row) => row.letterId === letter.letterId && row.deliveryConfirmed !== false)) return;
    const context = makeContext();
    const recipientActorKey = $('mailbox-recipient-secondary').value;
    const letterId = letter.letterId;
    const isRelevant = () => $('mailbox-recipient-secondary').value === recipientActorKey
      && state.mail.selectedLetter === letterId
      && state.mail.letters.some((row) => row.letterId === letterId && row.deliveryConfirmed !== false);
    await withBusy('correspondence.detail.read', null, async () => {
      try {
        requireCapability('correspondence.detail.read');
        const data = await request('correspondence.detail.read', { recipientActorKey, letterId });
        if (!isCurrentContext(context) || !isRelevant()) return;
        const detail = validLetterDetail(data, letterId);
        if (!detail) throw failLocal('UNAVAILABLE');
        $('letter-detail-secondary').hidden = false;
        text('detail-heading-secondary', `Letter received ${detail.receivedAt}`);
        text('detail-signature-secondary', `Signed as ${detail.claimedSignature} · signature is a claim`);
        $('detail-author-secondary').hidden = !detail.recordedAuthor;
        text('detail-author-secondary', detail.recordedAuthor ? `Recorded author · director-only: ${detail.recordedAuthor}` : '');
        text('detail-body-secondary', detail.body);
        const attachments = $('detail-attachments-secondary'); attachments.replaceChildren();
        for (const item of detail.attachments) { const li = document.createElement('li'); li.textContent = `${item.label} · ${item.quantity}`; attachments.append(li); }
        setStatus('mail-state', 'Receipt-confirmed letter opened in this OOC view. NPC exposure and read receipt were not created.', 'success');
      } catch (error) { if (isCurrentContext(context) && isRelevant()) setStatus('mail-state', localError(error), 'error'); }
    });
  }

  function updateLetterCount() {
    const value = $('letter-body').value;
    text('letter-count', `${Array.from(value).length.toLocaleString()} / 2,000`);
    renderMailAccess();
  }
  function setMailView(view, focus = false) {
    const mailbox = view === 'mailbox';
    $('mail-compose-tab').setAttribute('aria-selected', String(!mailbox));
    $('mailbox-tab').setAttribute('aria-selected', String(mailbox));
    $('mail-compose-view').hidden = mailbox;
    $('mailbox-view').hidden = !mailbox;
    if (focus) focusAndReveal($(mailbox ? 'mailbox-tab' : 'mail-compose-tab'));
  }
  function focusAndReveal(node) {
    node.focus();
    node.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
  function selectMainTab(tab, focus = false) {
    const tabs = [...document.querySelectorAll('.tabs [role="tab"]')];
    const index = typeof tab === 'number' ? tab : tabs.indexOf(tab);
    if (index < 0 || index >= tabs.length) return;
    tabs.forEach((item, position) => {
      const selected = position === index;
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
      const panel = $(item.getAttribute('aria-controls'));
      if (panel) panel.hidden = !selected;
    });
    if (focus) focusAndReveal(tabs[index]);
  }
  function visibleControls() {
    return [...document.querySelectorAll('button, input:not([type="hidden"]), select, textarea, [tabindex]:not([tabindex="-1"])')]
      .filter((node) => !node.disabled && !node.hidden && !node.closest('[hidden]') && node.getAttribute('aria-hidden') !== 'true'
        && node.getClientRects().length > 0);
  }
  function focusStep(delta) {
    const items = visibleControls();
    if (!items.length) return;
    const current = items.indexOf(document.activeElement);
    const next = current < 0 ? (delta > 0 ? 0 : items.length - 1) : (current + delta + items.length) % items.length;
    focusAndReveal(items[next]);
  }
  function cycleSelect(select, delta) {
    const options = [...select.options];
    let index = select.selectedIndex;
    for (let attempts = 0; attempts < options.length; attempts += 1) {
      index += delta;
      if (index < 0 || index >= options.length) return;
      if (options[index].disabled || options[index].value === '') continue;
      select.selectedIndex = index;
      select.dispatchEvent(new Event('input', { bubbles: true }));
      select.dispatchEvent(new Event('change', { bubbles: true }));
      return;
    }
  }
  function spatialFocus(command) {
    const current = document.activeElement;
    const currentRect = current && current.getBoundingClientRect();
    if (!currentRect) return;
    const cx = currentRect.left + currentRect.width / 2;
    const cy = currentRect.top + currentRect.height / 2;
    const horizontal = command === 'left' || command === 'right';
    const sign = command === 'left' || command === 'up' ? -1 : 1;
    const candidates = visibleControls().filter((node) => node !== current).map((node) => {
      const rect = node.getBoundingClientRect();
      const dx = rect.left + rect.width / 2 - cx;
      const dy = rect.top + rect.height / 2 - cy;
      const primary = horizontal ? dx : dy;
      const cross = horizontal ? dy : dx;
      return { node, primary, cross, score: Math.abs(primary) + Math.abs(cross) * 1.8 };
    }).filter((item) => item.primary * sign > 1).sort((a, b) => a.score - b.score);
    if (candidates.length) focusAndReveal(candidates[0].node);
  }
  function navigateControls(command) {
    const active = document.activeElement;
    if (command === 'next' || command === 'previous') { focusStep(command === 'next' ? 1 : -1); return; }
    const tabs = [...document.querySelectorAll('.tabs [role="tab"]')];
    const tabIndex = tabs.indexOf(active);
    if (tabIndex >= 0 && (command === 'left' || command === 'right')) {
      selectMainTab((tabIndex + (command === 'right' ? 1 : tabs.length - 1)) % tabs.length, true); return;
    }
    const subtabs = [...document.querySelectorAll('#panel-mail .subtabs .subtab')];
    const subtabIndex = subtabs.indexOf(active);
    if (subtabIndex >= 0 && (command === 'left' || command === 'right')) {
      const next = (subtabIndex + (command === 'right' ? 1 : subtabs.length - 1)) % subtabs.length;
      setMailView(next === 0 ? 'compose' : 'mailbox', true); return;
    }
    if (active instanceof HTMLSelectElement && ['up', 'down', 'left', 'right'].includes(command)) {
      cycleSelect(active, command === 'up' || command === 'left' ? -1 : 1); return;
    }
    if (command === 'activate') {
      if (active instanceof HTMLButtonElement) active.click();
      else if (active instanceof HTMLInputElement && active.type === 'checkbox') active.click();
      return;
    }
    spatialFocus(command);
  }
  function preserveOutstandingOperations() {
    const pendingSend = [...state.pending.values()].find((item) => item.envelope.operation === 'pcv.send');
    if (pendingSend) {
      state.pcv.sendUnknownId = pendingSend.envelope.payload.operationId;
      state.pcv.draftText = pendingSend.envelope.payload.text;
    } else if (state.pcv.sendPending && state.pcv.sendOperationId) state.pcv.sendUnknownId = state.pcv.sendOperationId;
    if (state.pcv.mutationPending) state.pcv.mutationUnknown = true;
    if (state.mail.currentOperationId && state.mail.composeLocked && !state.mail.operationDraft) state.mail.operationDraft = { body: $('letter-body').value };
  }
  function closeView() {
    if (!state.initialized || state.closed || state.closing) return false;
    preserveOutstandingOperations();
    const envelope = { version: 1, requestId: newUuid(), operation: 'view.close', context: makeContext(), payload: {} };
    let serialized = '';
    try { serialized = JSON.stringify(envelope); } catch { /* Local close still releases this view. */ }
    state.closing = true;
    if (serialized && bytes(serialized) <= MAX_ENVELOPE_BYTES && typeof window.departmentRequest === 'function') {
      try { window.departmentRequest(serialized); } catch { /* Native close is best-effort; local controls still close. */ }
    }
    clearPending('This view closed. Any already accepted native operation continues independently.');
    state.closed = true;
    state.closing = false;
    state.serverContext = null;
    state.authenticated = false;
    state.authRequired = true;
    state.capabilities = { pcv: false, mp: false, sas: false, correspondence: false };
    state.access = { pcv: false, inspector: false, compose: false, authorDetail: false };
    state.perspectives = { mp: [], sas: [] };
    state.perspectiveLimited = { mp: false, sas: false };
    clearPrivateViews();
    if (document.activeElement instanceof HTMLElement) document.activeElement.blur();
    renderBootstrap();
    return true;
  }
  function handleTabKeydown(event, items, activeIndex, activate) {
    let next = null;
    if (event.key === 'ArrowRight' || event.key === 'ArrowDown') next = (activeIndex + 1) % items.length;
    else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') next = (activeIndex + items.length - 1) % items.length;
    else if (event.key === 'Home') next = 0;
    else if (event.key === 'End') next = items.length - 1;
    if (next === null) return;
    event.preventDefault();
    activate(next);
  }
  function invoke(action, statusId) {
    try {
      const result = action();
      if (result && typeof result.catch === 'function') result.catch((error) => { if (!isStale(error)) setStatus(statusId, localError(error), 'error'); });
    } catch (error) { if (!isStale(error)) setStatus(statusId, localError(error), 'error'); }
  }
  function wireEvents() {
    const mainTabs = [...document.querySelectorAll('.tabs [role="tab"]')];
    mainTabs.forEach((tab, index) => {
      tab.addEventListener('click', () => selectMainTab(index));
      tab.addEventListener('keydown', (event) => handleTabKeydown(event, mainTabs, index, (next) => selectMainTab(next, true)));
    });
    $('mail-compose-tab').addEventListener('click', () => setMailView('compose'));
    $('mailbox-tab').addEventListener('click', () => setMailView('mailbox'));
    const mailTabs = [$('mail-compose-tab'), $('mailbox-tab')];
    mailTabs.forEach((tab, index) => tab.addEventListener('keydown', (event) => handleTabKeydown(event, mailTabs, index, (next) => setMailView(next === 0 ? 'compose' : 'mailbox', true))));
    $('close-view').addEventListener('click', closeView);
    $('retry-connection').addEventListener('click', retryConnection);
    $('pcv-refresh').addEventListener('click', () => invoke(readPcv, 'pcv-state'));
    $('pcv-refresh-send').addEventListener('click', () => invoke(
      state.pcv.sendUnknownId ? () => requestPcvSendStatus(state.pcv.sendUnknownId) : readPcv,
      'pcv-send-note',
    ));
    document.addEventListener('focusin', (event) => {
      if ($('pcv-config-fields').contains(event.target)) state.pcv.lastFocusedControl = event.target;
      else if (event.target !== document.body) state.pcv.lastFocusedControl = null;
    });
    $('pcv-settings-toggle').addEventListener('click', () => setPcvSettingsExpanded($('pcv-settings-toggle').getAttribute('aria-expanded') !== 'true'));
    $('pcv-arm').addEventListener('click', () => invoke(armPcv, 'pcv-state'));
    $('pcv-end').addEventListener('click', () => invoke(endPcv, 'pcv-state'));
    $('pcv-send').addEventListener('click', () => invoke(sendPcv, 'pcv-send-note'));
    $('pcv-text').addEventListener('input', updatePcvCount);
    $('send-target').addEventListener('change', renderPcvAccess);
    $('pcv-discard-unknown').addEventListener('click', () => {
      if (!state.pcv.sendUnknownId) return;
      if (state.pcv.sendSubmitted && state.pcv.sendSubmitted.operationId === state.pcv.sendUnknownId) state.pcv.sendDraftDiscardedId = state.pcv.sendUnknownId;
      state.pcv.draftText = ''; $('pcv-text').value = ''; updatePcvCount();
      setStatus('pcv-send-note', `The retained text was discarded. Uncertain native operation ${state.pcv.sendUnknownId} remains locked; refresh status before another send.`, 'error');
      renderPcvAccess();
    });
    $('conversation-mode').addEventListener('change', updatePcvMode);
    for (const id of ['actor-a', 'actor-b', 'actor-c', 'actor-d']) $(id).addEventListener('change', () => { updateOpenerOptions(); renderPcvAccess(); });
    for (const id of ['opener', 'free-cap', 'bystander-mode', 'pace', 'scene-card', 'exclude-player', 'action-personal', 'action-physical', 'action-intimate']) {
      $(id).addEventListener('input', renderPcvAccess);
      $(id).addEventListener('change', renderPcvAccess);
    }
    $('mp-refresh').addEventListener('click', () => invoke(() => refreshMp(false), 'mp-state'));
    $('mp-more').addEventListener('click', () => invoke(() => refreshMp(true), 'mp-state'));
    $('mp-perspective').addEventListener('change', () => { state.mp = { records: [], nextCursor: null, limited: false }; renderMpRecords(state.mp); renderMpChoices(); });
    $('mp-target').addEventListener('change', () => { state.mp = { records: [], nextCursor: null, limited: false }; renderMpRecords(state.mp); renderMpChoices(); });
    $('sas-refresh').addEventListener('click', () => invoke(() => refreshSas(false), 'sas-state'));
    $('sas-more').addEventListener('click', () => invoke(() => refreshSas(true), 'sas-state'));
    $('sas-perspective').addEventListener('change', () => { state.sas = { issues: [], nextCursor: null, limited: false }; renderIssueList($('sas-grievances'), [], 'No retained grievances for this perspective.'); renderIssueList($('sas-promises'), [], 'No retained promises for this perspective.'); renderSasChoices(); });
    $('mail-refresh').addEventListener('click', () => invoke(refreshCatalogAndInventory, 'mail-state'));
    $('mail-catalog-more').addEventListener('click', () => invoke(loadMoreCatalog, 'mail-state'));
    $('mailbox-refresh-secondary').addEventListener('click', () => invoke(() => refreshMailbox(false), 'mail-state'));
    $('mailbox-more-secondary').addEventListener('click', () => invoke(() => refreshMailbox(true), 'mail-state'));
    $('mailbox-recipient-secondary').addEventListener('change', () => {
      state.mail.letters = []; state.mail.mailboxNextCursor = null; state.mail.mailboxLimited = false; state.mail.selectedLetter = null;
      $('mailbox-list-secondary').replaceChildren(); $('letter-detail-secondary').hidden = true;
      const empty = document.createElement('p'); empty.className = 'empty-row'; empty.textContent = 'Select a recipient and refresh.'; $('mailbox-list-secondary').append(empty);
      renderMailAccess();
    });
    for (const id of ['letter-writer', 'letter-recipient', 'letter-signature']) $(id).addEventListener('change', markComposeChanged);
    $('sign-as-other').addEventListener('change', () => { $('signature-wrap').hidden = !$('sign-as-other').checked; markComposeChanged(); });
    $('letter-body').addEventListener('input', () => { updateLetterCount(); markComposeChanged(); });
    $('gold-amount').addEventListener('input', () => { updateAttachmentCount(); markComposeChanged(); });
    $('letter-review').addEventListener('click', reviewLetter);
    $('letter-send').addEventListener('click', () => invoke(sendLetter, 'letter-receipt'));
    $('letter-reconcile').addEventListener('click', () => invoke(reconcileLetter, 'letter-receipt'));
    $('letter-operation').addEventListener('change', renderMailAccess);
    $('letter-new').addEventListener('click', startNewLetter);
  }

  wireEvents();
  renderBootstrap();
  renderPcvStatus();
  renderRoster();
  renderMpChoices();
  renderSasChoices();
  renderInventory();
  clearSelectsForMail();
  renderMailAccess();
  updatePcvMode();
  updateLetterCount();
})();
