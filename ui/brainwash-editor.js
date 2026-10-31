(function (global, factory) {
  'use strict';
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  if (global) global.BrainwashEditor = api;
}(typeof window === 'undefined' ? globalThis : window, function () {
  'use strict';

  const FIELDS = ['core', 'npc_static_bio', 'personality', 'appearance', 'occupation', 'skills', 'speechstyle', 'goals'];

  function element(doc, tag, text) {
    const node = doc.createElement(tag);
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function makeId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = new Uint8Array(16);
    if (typeof crypto !== 'undefined' && typeof crypto.getRandomValues === 'function') crypto.getRandomValues(bytes);
    else for (let i = 0; i < bytes.length; i += 1) bytes[i] = Math.floor(Math.random() * 256);
    bytes[6] = (bytes[6] & 15) | 64;
    bytes[8] = (bytes[8] & 63) | 128;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');
    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
  }

  function mount(root, options) {
    if (!root || !root.ownerDocument || !options || typeof options.request !== 'function') throw new TypeError('BrainwashEditor.mount requires a root element and request function.');
    const doc = root.ownerDocument;
    let epoch = 0;
    const state = {
      available: true,
      busy: false,
      actors: [],
      actorKey: '',
      query: '',
      nearby: true,
      conversation: [],
      historyLimit: 50,
      historyEnabled: false,
      card: null,
      scope: null,
      draft: null,
      edits: {},
      selected: {},
      beliefs: '',
      beliefsSelected: false,
      instruction: '',
      accepted: {},
      acceptBeliefs: false,
      uncertain: null,
      status: 'Loading NPCs…',
    };

    function invalidate() {
      epoch += 1;
      state.busy = false;
      return epoch;
    }

    function clearActorData() {
      state.card = null;
      state.scope = null;
      state.draft = null;
      state.edits = {};
      state.selected = {};
      state.beliefs = '';
      state.beliefsSelected = false;
      state.instruction = '';
      state.conversation = [];
      state.historyEnabled = false;
      state.historyLimit = 50;
      state.accepted = {};
      state.acceptBeliefs = false;
      state.uncertain = null;
    }

    function current(token, actorKey) {
      return state.available && token === epoch && (actorKey === undefined || actorKey === state.actorKey);
    }

    function button(parent, label, action, disabled) {
      const node = element(doc, 'button', label);
      node.type = 'button';
      node.disabled = disabled;
      node.addEventListener('click', action);
      parent.appendChild(node);
      return node;
    }

    function labeled(parent, labelText, control) {
      const label = element(doc, 'label', labelText);
      label.appendChild(control);
      parent.appendChild(label);
      return control;
    }

    function setStatus(value) {
      state.status = value;
      render();
    }

    async function startRequest(operation, payload, token, actorKey) {
      if (!state.available || state.busy || (state.uncertain && operation !== 'brainwash.card.read' && operation !== 'brainwash.catalog.read')) return null;
      state.busy = true;
      state.status = operation === 'brainwash.card.read' ? 'Reading NPC…' : 'Working…';
      render();
      try {
        const result = await options.request(operation, payload);
        if (!current(token, actorKey)) return null;
        return result;
      } catch (error) {
        if (current(token, actorKey)) {
          state.status = error && typeof error.message === 'string' ? error.message : 'Malpractice Makes Perfect is unavailable right now.';
        }
        throw error;
      } finally {
        if (current(token, actorKey)) {
          state.busy = false;
          render();
        }
      }
    }

    async function loadCatalog(query, initial) {
      const token = invalidate();
      state.query = query;
      state.status = 'Loading NPCs…';
      try {
        const result = await startRequest('brainwash.catalog.read', query ? { query, nearby: state.nearby } : { nearby: state.nearby }, token);
        if (!result || !current(token)) return;
        state.actors = Array.isArray(result.actors) ? result.actors : [];
        const found = state.actors.some((actor) => actor.actorKey === state.actorKey);
        if (!found) {
          state.actorKey = '';
          clearActorData();
        }
        state.status = state.nearby
          ? `Nearby roster: ${result.state || 'unavailable'}. ${state.actors.length} NPCs resolved.${result.limited ? ' Some names could not be resolved uniquely.' : ''} Use All stored NPCs for explicit fallback.`
          : (result.limited ? 'Stored list limited to 64. Search by name.' : `${state.actors.length} stored NPCs loaded.`);
        render();
        if (initial && !state.actorKey && state.actors.length > 0) {
          state.actorKey = state.actors[0].actorKey;
          await loadCard(state.actorKey);
        }
      } catch (_) {
        if (current(token)) render();
      }
    }

    async function loadCard(actorKey) {
      if (!actorKey) return;
      const token = invalidate();
      state.actorKey = actorKey;
      const savedDraft = state.draft;
      const pendingMutation = state.uncertain;
      const previousScope = state.scope;
      const previousCard = state.card;
      const previousEdits = { ...state.edits };
      const previousSelected = { ...state.selected };
      const previousBeliefs = state.beliefs;
      state.card = null;
      state.status = 'Reading NPC…';
      render();
      try {
        const card = await startRequest('brainwash.card.read', { actorKey }, token, actorKey);
        if (!card || !current(token, actorKey)) return;
        const scopeChanged = previousScope !== null && previousScope !== card.scope;
        if (scopeChanged) clearActorData();
        state.scope = card.scope;
        state.uncertain = scopeChanged ? null : pendingMutation;
        state.draft = !scopeChanged && savedDraft && card.draftId === savedDraft.draftId ? savedDraft : null;
        state.card = card;
        const preserve = !scopeChanged && previousCard && previousCard.actorKey === card.actorKey && previousCard.revision === card.revision;
        state.edits = preserve ? previousEdits : Object.assign({}, card.fields);
        state.selected = preserve ? previousSelected : Object.fromEntries(FIELDS.map((field) => [field, true]));
        state.beliefs = preserve ? previousBeliefs : (card.beliefs || '');
        state.status = scopeChanged
          ? `The timeline changed. Loaded ${card.label}; previous draft and operation state were cleared.`
          : `Loaded ${card.label}.`;
        if (state.uncertain) reconcile(card);
        render();
      } catch (_) {
        if (current(token, actorKey)) {
          state.card = previousCard;
          render();
        }
      }
    }

    function reconcile(card) {
      const unknown = state.uncertain;
      if (!unknown) return;
      const last = card.lastOperation;
      const confirmed = last && last.operationId === unknown.operationId && last.state === unknown.expectedState;
      if (confirmed) {
        state.uncertain = null;
        state.draft = null;
        state.status = `Readback confirmed the ${unknown.kind}.`;
        return;
      }
      if (unknown.kind === 'draft') {
        state.uncertain = null;
        state.draft = null;
        state.status = card.draftId
          ? 'A draft is stored, but its response was lost. Create a new explicit draft to replace it.'
          : 'Readback shows that no draft is stored.';
        return;
      }
      if (unknown.kind === 'undo' && last && last.operationId === unknown.appliedOperationId && last.state === 'applied') {
        state.uncertain = null;
        state.status = 'Readback shows the last apply is still active; undo was not confirmed.';
        return;
      }
      if (unknown.kind === 'apply' && card.draftId && state.draft && card.draftId === state.draft.draftId) {
        state.uncertain = null;
        state.status = 'Readback shows the proposal is still available for review.';
        return;
      }
      state.status = 'Readback did not resolve the previous operation. Mutations remain locked.';
    }

    async function refresh() {
      if (state.actorKey) {
        await loadCard(state.actorKey);
        return;
      }
      await loadCatalog(state.query, false);
    }

    function selectionPayload(mode) {
      const changes = {};
      for (const field of FIELDS) {
        if (state.selected[field]) changes[field] = state.edits[field] || '';
      }
      const payload = {
        actorKey: state.actorKey,
        expectedRevision: state.card.revision,
        operationId: makeId(),
        mode,
        changes,
        instruction: state.instruction,
      };
      if (mode === 'ai') payload.authoring = { workingCard: { ...state.edits }, conversation: state.conversation.map((turn) => ({ ...turn })), historyLimit: state.historyEnabled ? state.historyLimit : 0 };
      if (state.beliefsSelected) payload.beliefs = mode === 'ai' ? '' : state.beliefs;
      return payload;
    }

    async function createDraft(mode) {
      if (!state.card || state.uncertain) return;
      if (!FIELDS.some((field) => state.selected[field]) && !state.beliefsSelected) {
        setStatus('Select at least one field or beliefs to draft.');
        return;
      }
      const payload = selectionPayload(mode);
      const bytes = (text) => new TextEncoder().encode(text).length;
      if (bytes(state.instruction) > 2000 || FIELDS.some((field) => bytes(state.edits[field] || '') > 8192) || FIELDS.reduce((sum, field) => sum + bytes(state.edits[field] || ''), 0) > 20000 || bytes(state.beliefs) > 4000) {
        setStatus('Input exceeds the UTF-8 byte limit. Shorten it explicitly; your text is retained.'); return;
      }
      if (mode === 'ai' && (state.conversation.length > 8 || state.conversation.some((turn) => bytes(turn.content) > 2000) || state.conversation.reduce((sum, turn) => sum + bytes(turn.content), 0) > 12000)) {
        setStatus('Conversation exceeds the 8-turn / 2000-byte per turn / 12000-byte total limit. Edit or clear conversation explicitly.'); return;
      }
      if (bytes(JSON.stringify({ operation: 'brainwash.draft', payload })) > 65536) { setStatus('Request exceeds 65536 UTF-8 bytes. Shorten input explicitly; all text is retained.'); return; }
      const token = epoch;
      const actorKey = state.actorKey;
      try {
        const draft = await startRequest('brainwash.draft', payload, token, actorKey);
        if (!draft || !current(token, actorKey)) return;
        state.draft = draft;
        if (mode === 'ai') {
          for (const field of Object.keys(draft.changes || {})) state.edits[field] = draft.changes[field];
          if (draft.authoring) {
            state.conversation.push({ role: 'user', content: state.instruction }, { role: 'assistant', content: draft.authoring.reply });
          }
        }
        state.accepted = Object.fromEntries(Object.keys(draft.changes || {}).filter((field) => draft.before?.[field] !== draft.changes[field]).map((field) => [field, true]));
        state.acceptBeliefs = Object.prototype.hasOwnProperty.call(draft, 'beliefs') && draft.beliefsBefore !== draft.beliefs;
        state.uncertain = null;
        state.status = 'Draft ready for review. No NPC fields were changed.';
        render();
      } catch (_) {
        if (current(token, actorKey)) {
          state.uncertain = { kind: 'draft', operationId: payload.operationId, scope: state.scope };
          state.status = 'The draft response was uncertain. Refresh and reconcile before another change.';
          render();
        }
      }
    }

    async function applyDraft() {
      if (!state.card || !state.draft || state.uncertain) return;
      const acceptedFields = Object.keys(state.accepted).filter((field) => state.accepted[field]);
      if (acceptedFields.length === 0 && !state.acceptBeliefs) {
        setStatus('Accept at least one proposed field or the beliefs change.');
        return;
      }
      const payload = {
        actorKey: state.actorKey,
        draftId: state.draft.draftId,
        operationId: makeId(),
        acceptedFields,
        acceptBeliefs: state.acceptBeliefs,
      };
      const token = epoch;
      const actorKey = state.actorKey;
      try {
        const result = await startRequest('brainwash.apply', payload, token, actorKey);
        if (!result || !current(token, actorKey)) return;
        state.card = result.card;
        for (const field of acceptedFields) state.edits[field] = result.card.fields[field];
        if (payload.acceptBeliefs) state.beliefs = result.card.beliefs;
        state.draft = null;
        state.uncertain = null;
        state.status = 'Accepted changes were applied. The returned card is current.';
        render();
      } catch (_) {
        if (current(token, actorKey)) {
          state.uncertain = { kind: 'apply', operationId: payload.operationId, expectedState: 'applied', scope: state.scope };
          state.status = 'The apply result is uncertain. Refresh to reconcile; no retry was sent.';
          render();
        }
      }
    }

    async function undo() {
      const applied = state.card && state.card.lastOperation;
      if (!applied || applied.state !== 'applied' || state.uncertain) return;
      const payload = { actorKey: state.actorKey, operationId: makeId(), appliedOperationId: applied.operationId };
      const token = epoch;
      const actorKey = state.actorKey;
      try {
        const result = await startRequest('brainwash.undo', payload, token, actorKey);
        if (!result || !current(token, actorKey)) return;
        state.card = result.card;
        state.edits = Object.assign({}, result.card.fields);
        state.beliefs = result.card.beliefs;
        state.draft = null;
        state.uncertain = null;
        state.status = 'The last Malpractice Makes Perfect change was undone narrowly.';
        render();
      } catch (_) {
        if (current(token, actorKey)) {
          state.uncertain = { kind: 'undo', operationId: payload.operationId, appliedOperationId: payload.appliedOperationId, expectedState: 'undone', scope: state.scope };
          state.status = 'The undo result is uncertain. Refresh to reconcile; no retry was sent.';
          render();
        }
      }
    }

    function render() {
      root.replaceChildren();
      const page = element(doc, 'section');
      page.className = 'brainwash-editor';
      const status = element(doc, 'p', state.status);
      status.className = 'brainwash-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      page.appendChild(status);

      const catalog = element(doc, 'section');
      catalog.className = 'brainwash-panel';
      catalog.appendChild(element(doc, 'h2', state.nearby ? 'Nearby NPCs' : 'All stored NPCs'));
      button(catalog, 'Refresh nearby NPCs', () => { state.nearby = true; return loadCatalog('', false); }, !state.available || state.busy || Boolean(state.uncertain));
      button(catalog, 'All stored NPCs', () => { state.nearby = false; return loadCatalog('', false); }, !state.available || state.busy || Boolean(state.uncertain));
      const searchLabel = element(doc, 'label', 'Search NPC names');
      const search = element(doc, 'input');
      search.type = 'search';
      search.maxLength = 128;
      search.value = state.query;
      search.disabled = !state.available || state.busy || Boolean(state.uncertain);
      search.setAttribute('autocomplete', 'off');
      searchLabel.appendChild(search);
      catalog.appendChild(searchLabel);
      button(catalog, 'Search', () => loadCatalog(search.value, false), !state.available || state.busy || Boolean(state.uncertain));
      const actorLabel = element(doc, 'label', 'Select NPC');
      const actorSelect = element(doc, 'select');
      const placeholder = element(doc, 'option', 'Choose an NPC');
      placeholder.value = '';
      actorSelect.appendChild(placeholder);
      for (const actor of state.actors) {
        const option = element(doc, 'option', actor.label);
        option.value = actor.actorKey;
        option.selected = actor.actorKey === state.actorKey;
        actorSelect.appendChild(option);
      }
      actorSelect.disabled = !state.available || state.busy || Boolean(state.uncertain);
      actorSelect.addEventListener('change', () => {
        invalidate();
        state.actorKey = actorSelect.value;
        clearActorData();
        state.status = state.actorKey ? 'Reading NPC…' : 'Choose an NPC.';
        render();
        if (state.actorKey) loadCard(state.actorKey);
      });
      actorLabel.appendChild(actorSelect);
      catalog.appendChild(actorLabel);
      button(catalog, 'Refresh', refresh, !state.available || state.busy);
      page.appendChild(catalog);

      if (state.card) {
        const workbench = element(doc, 'section');
        workbench.className = 'brainwash-panel';
        workbench.appendChild(element(doc, 'h2', `Develop ${state.card.label}`));
        workbench.appendChild(element(doc, 'p', 'Give creative direction, develop a proposal, then refine it. All unsaved card fields are context. Only selected fields can change.'));
        const historyCheck = element(doc, 'input');
        historyCheck.type = 'checkbox'; historyCheck.checked = state.historyEnabled;
        historyCheck.disabled = state.busy || Boolean(state.uncertain);
        historyCheck.addEventListener('change', () => { state.historyEnabled = historyCheck.checked; });
        labeled(workbench, 'Use recent relevant witnessed interactions (optional)', historyCheck);
        const historySelect = element(doc, 'select');
        historySelect.setAttribute('aria-label', 'Recent interaction count');
        for (const count of [50, 100, 200]) { const option = element(doc, 'option', `Last ${count}`); option.value = String(count); option.selected = count === state.historyLimit; historySelect.appendChild(option); }
        historySelect.disabled = state.busy || Boolean(state.uncertain);
        historySelect.addEventListener('change', () => { state.historyLimit = Number(historySelect.value); });
        workbench.appendChild(historySelect);
        const conversation = element(doc, 'section');
        conversation.appendChild(element(doc, 'h3', 'Current conversation'));
        for (const turn of state.conversation) {
          const text = element(doc, 'textarea'); text.value = turn.content; text.rows = 3;
          text.setAttribute('aria-label', `${turn.role} conversation turn`);
          text.disabled = state.busy || Boolean(state.uncertain);
          text.addEventListener('input', () => { turn.content = text.value; });
          labeled(conversation, turn.role, text);
        }
        button(conversation, 'Clear conversation', () => { state.conversation = []; render(); }, state.busy || Boolean(state.uncertain));
        workbench.appendChild(conversation);
        page.appendChild(workbench);
        const fields = element(doc, 'details');
        fields.open = false;
        fields.className = 'brainwash-panel brainwash-fields';
        fields.appendChild(element(doc, 'summary', 'Optional manual fields and AI field selection'));
        fields.appendChild(element(doc, 'h2', `Current card: ${state.card.label}`));
        fields.appendChild(element(doc, 'p', 'Optional manual fields — edit directly or deselect fields to preserve them during AI development.'));
        for (const field of FIELDS) {
          const group = element(doc, 'fieldset');
          const legend = element(doc, 'legend', field.replaceAll('_', ' '));
          group.appendChild(legend);
          const selectLabel = element(doc, 'label', 'Include in draft');
          const select = element(doc, 'input');
          select.type = 'checkbox';
          select.checked = Boolean(state.selected[field]);
          select.disabled = !state.available || state.busy || Boolean(state.uncertain);
          select.addEventListener('change', () => { state.selected[field] = select.checked; });
          selectLabel.appendChild(select);
          group.appendChild(selectLabel);
          const textarea = element(doc, 'textarea');
          textarea.rows = 4;
          textarea.value = state.edits[field] || '';
          textarea.setAttribute('aria-label', `${field.replaceAll('_', ' ')} text`);
          textarea.disabled = !state.available || state.busy || Boolean(state.uncertain);
          textarea.addEventListener('input', () => { state.edits[field] = textarea.value; });
          group.appendChild(textarea);
          fields.appendChild(group);
        }

        const beliefGroup = element(doc, 'fieldset');
        beliefGroup.appendChild(element(doc, 'legend', 'User-authored fictional belief'));
        const beliefLabel = element(doc, 'label', 'Include beliefs in draft');
        const beliefCheck = element(doc, 'input');
        beliefCheck.type = 'checkbox';
        beliefCheck.checked = state.beliefsSelected;
        beliefCheck.disabled = !state.available || state.busy || Boolean(state.uncertain);
        beliefCheck.addEventListener('change', () => { state.beliefsSelected = beliefCheck.checked; });
        beliefLabel.appendChild(beliefCheck);
        beliefGroup.appendChild(beliefLabel);
        const beliefText = element(doc, 'textarea');
        beliefText.rows = 3;
        beliefText.value = state.beliefs;
        beliefText.setAttribute('aria-label', 'User-authored fictional belief text');
        beliefText.disabled = !state.available || state.busy || Boolean(state.uncertain);
        beliefText.addEventListener('input', () => { state.beliefs = beliefText.value; });
        beliefGroup.appendChild(beliefText);
        fields.appendChild(beliefGroup);

        const instructionLabel = element(doc, 'label', 'Creative direction / refinement instruction');
        const instruction = element(doc, 'textarea');
        instruction.rows = 3;
        instruction.setAttribute('aria-label', 'Creative direction');
        instruction.value = state.instruction;
        instruction.disabled = !state.available || state.busy || Boolean(state.uncertain);
        instruction.addEventListener('input', () => { state.instruction = instruction.value; });
        instructionLabel.appendChild(instruction);
        workbench.appendChild(instructionLabel);
        button(fields, 'Create manual draft', () => createDraft('manual'), !state.available || state.busy || Boolean(state.uncertain));
        button(workbench, state.draft ? 'Refine proposal' : 'Develop card with AI', () => createDraft('ai'), !state.available || state.busy || Boolean(state.uncertain));
        page.appendChild(fields);
      }

      if (state.draft) {
        const review = element(doc, 'section');
        review.className = 'brainwash-panel';
        review.appendChild(element(doc, 'h2', 'Review draft'));
        if (state.draft.authoring) {
          const authoring = state.draft.authoring;
          review.appendChild(element(doc, 'p', authoring.reply));
          review.appendChild(element(doc, 'p', `Recorded history supplied: ${authoring.historyCount} interactions.${authoring.historyTruncated ? ' Some events or excerpts were omitted/truncated by the context bounds.' : ''}`));
          for (const citation of authoring.evidence) {
            review.appendChild(element(doc, 'p', `Event ${citation.eventId}: ${citation.reason}`));
            const excerpt = element(doc, 'blockquote', citation.excerpt || ''); excerpt.className = 'brainwash-review-text'; review.appendChild(excerpt);
          }
        }
        review.appendChild(element(doc, 'p', `Origin: ${state.draft.origin}. Nothing is applied until you accept it.`));
        const changedFields = Object.keys(state.draft.changes || {}).filter((field) => state.draft.before?.[field] !== state.draft.changes[field]);
        const beliefsChanged = Object.prototype.hasOwnProperty.call(state.draft, 'beliefs') && state.draft.beliefsBefore !== state.draft.beliefs;
        if (changedFields.length === 0 && !beliefsChanged) review.appendChild(element(doc, 'p', 'No field changes proposed. Refine the proposal to develop it further.'));
        for (const field of changedFields) {
          const group = element(doc, 'fieldset');
          group.appendChild(element(doc, 'legend', field.replaceAll('_', ' ')));
          const checkLabel = element(doc, 'label', 'Accept this field');
          const check = element(doc, 'input');
          check.type = 'checkbox';
          check.checked = Boolean(state.accepted[field]);
          check.disabled = !state.available || state.busy || Boolean(state.uncertain);
          check.addEventListener('change', () => { state.accepted[field] = check.checked; render(); });
          checkLabel.appendChild(check);
          group.appendChild(checkLabel);
          const before = element(doc, 'p', `Before: ${state.draft.before?.[field] ?? ''}`);
          const after = element(doc, 'p', `Proposed: ${state.draft.changes[field]}`);
          before.className = 'brainwash-review-text';
          after.className = 'brainwash-review-text';
          group.appendChild(before);
          group.appendChild(after);
          review.appendChild(group);
        }
        if (beliefsChanged) {
          const group = element(doc, 'fieldset');
          group.appendChild(element(doc, 'legend', 'Fictional belief'));
          const checkLabel = element(doc, 'label', 'Accept this beliefs change');
          const check = element(doc, 'input');
          check.type = 'checkbox';
          check.checked = state.acceptBeliefs;
          check.disabled = !state.available || state.busy || Boolean(state.uncertain);
          check.addEventListener('change', () => { state.acceptBeliefs = check.checked; render(); });
          checkLabel.appendChild(check);
          group.appendChild(checkLabel);
          group.appendChild(element(doc, 'p', `Before: ${state.draft.beliefsBefore || ''}`));
          group.appendChild(element(doc, 'p', `Proposed: ${state.draft.beliefs}`));
          review.appendChild(group);
        }
        button(review, 'Apply accepted changes', applyDraft, !state.available || state.busy || Boolean(state.uncertain) || (!Object.values(state.accepted).some(Boolean) && !state.acceptBeliefs));
        page.appendChild(review);
      }

      if (state.card && state.card.lastOperation && state.card.lastOperation.state === 'applied') {
        const undoPanel = element(doc, 'section');
        undoPanel.className = 'brainwash-panel';
        undoPanel.appendChild(element(doc, 'h2', 'Undo'));
        undoPanel.appendChild(element(doc, 'p', 'Undo restores only fields and beliefs from the last Malpractice Makes Perfect apply, and refuses overlaps.'));
        button(undoPanel, 'Undo last Malpractice Makes Perfect change', undo, !state.available || state.busy || Boolean(state.uncertain));
        page.appendChild(undoPanel);
      }

      if (state.uncertain) {
        const warning = element(doc, 'p', 'Mutation is locked until an explicit readback resolves the last result.');
        warning.className = 'brainwash-warning';
        page.appendChild(warning);
      }
      root.appendChild(page);
    }

    function reset() {
      invalidate();
      state.actors = [];
      state.actorKey = '';
      state.query = '';
      state.nearby = true;
      clearActorData();
      state.status = 'Editor reset. Refresh to load NPCs.';
      render();
    }

    function setAvailable(available) {
      const nextAvailable = Boolean(available);
      if (state.available === nextAvailable) return;
      invalidate();
      state.available = nextAvailable;
      state.actors = [];
      state.actorKey = '';
      state.query = '';
      state.nearby = true;
      clearActorData();
      state.status = state.available ? 'Loading NPCs…' : 'Malpractice Makes Perfect is unavailable.';
      render();
      if (state.available) loadCatalog('', true);
    }

    render();
    loadCatalog('', true);
    return { reset, setAvailable };
  }

  return { mount, fields: FIELDS.slice() };
}));
