'use strict';

const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightRoot = process.env.PLAYWRIGHT_MODULE_ROOT;
if (!playwrightRoot) throw new Error('Set PLAYWRIGHT_MODULE_ROOT to the existing local Node modules directory.');
const { chromium } = require(require.resolve('playwright', { paths: [playwrightRoot] }));
const browserPath = process.env.PRISMA_TEST_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const pagePath = path.resolve(__dirname, '..', 'index.html');
const fifthTabFixturePath = path.join(__dirname, 'fixtures', 'fifth-tab.html');
const resultPath = process.env.UI_TEST_RESULTS;
const screenshotPath = process.env.UI_TEST_SCREENSHOT;
const connectionPendingScreenshotPath = process.env.UI_CONNECTION_PENDING_SCREENSHOT;
const connectionSetupErrorScreenshotPath = process.env.UI_CONNECTION_SETUP_ERROR_SCREENSHOT;
const nativeRefusalScreenshotPath = process.env.UI_NATIVE_REFUSAL_SCREENSHOT;
const nativeRecoveryScreenshotPath = process.env.UI_NATIVE_RECOVERY_SCREENSHOT;
const letterRecoveryScreenshotPath = process.env.UI_LETTER_RECOVERY_SCREENSHOT;
const results = [];
let uuidCounter = 1;

async function test(name, run) {
  try {
    await run();
    results.push({ name, status: 'passed' });
  } catch (error) {
    results.push({ name, status: 'failed', error: String(error.message || error) });
  }
}

function fifthTabDocument() {
  const html = fs.readFileSync(pagePath, 'utf8');
  const fixture = fs.readFileSync(fifthTabFixturePath, 'utf8');
  const css = fs.readFileSync(path.resolve(__dirname, '..', 'app.css'), 'utf8');
  const js = fs.readFileSync(path.resolve(__dirname, '..', 'app.js'), 'utf8');
  assert.ok(!js.includes('</script'), 'inline fixture must not terminate its script element');
  assert.ok(html.includes('<link rel="stylesheet" href="./app.css">'), 'entrypoint stylesheet marker exists');
  assert.ok(html.includes('<script src="./app.js" defer></script>'), 'entrypoint script marker exists');
  assert.ok(html.includes('</nav>'), 'entrypoint tab navigation exists');
  assert.ok(html.includes('<section id="panel-pcv"'), 'entrypoint panel insertion point exists');
  const buttonEnd = fixture.indexOf('</button>') + '</button>'.length;
  const panelStart = fixture.indexOf('<section id="panel-fixture"');
  const panelEnd = fixture.lastIndexOf('</section>') + '</section>'.length;
  assert.ok(buttonEnd > '</button>'.length && panelStart >= 0 && panelEnd > panelStart, 'fixture contains a tab and matching panel');
  const fixtureTab = fixture.slice(0, buttonEnd);
  const fixturePanel = fixture.slice(panelStart, panelEnd);
  return html
    .replace('<link rel="stylesheet" href="./app.css">', `<style>${css}</style>`)
    .replace('<script src="./app.js" defer></script>', '')
    .replace('</nav>', `${fixtureTab}\n    </nav>`)
    .replace('<section id="panel-pcv"', `${fixturePanel}\n\n    <section id="panel-pcv"`)
    .replace('</body>', `<script>${js}</script>\n</body>`);
}

async function openPage(browser, inlineDocument = null, bootstrapTimeoutMs = null, fastConnectionPolling = false) {
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 } });
  const externalRequests = [];
  page.on('request', (request) => {
    if (!request.url().startsWith('file://')) externalRequests.push(request.url());
  });
  await page.addInitScript(({ testBootstrapTimeoutMs, testFastConnectionPolling }) => {
    const nativeSetTimeout = window.setTimeout.bind(window);
    window.__fastConnectionTimeout = false;
    window.setTimeout = (callback, delay, ...args) => {
      let effectiveDelay = delay;
      if (delay === 20000 && window.__fastConnectionTimeout) {
        effectiveDelay = 0;
        window.__fastConnectionTimeout = false;
      }
      else if (delay === 20000 && Number.isInteger(testBootstrapTimeoutMs) && testBootstrapTimeoutMs >= 0) effectiveDelay = testBootstrapTimeoutMs;
      else if (delay === 1000 && testFastConnectionPolling) effectiveDelay = 0;
      return nativeSetTimeout(callback, effectiveDelay, ...args);
    };
    window.__networkAttempts = [];
    window.__bridgeRequests = [];
    window.__throwAfterRecord = null;
    const rejectNetwork = (kind) => (...args) => {
      window.__networkAttempts.push({ kind, target: String(args[0] ?? '') });
      throw new Error('Browser network is disabled in this test.');
    };
    window.fetch = rejectNetwork('fetch');
    window.XMLHttpRequest = class { open(...args) { rejectNetwork('xhr')(...args); } send(...args) { rejectNetwork('xhr')(...args); } };
    window.WebSocket = class { constructor(...args) { rejectNetwork('websocket')(...args); } };
    window.EventSource = class { constructor(...args) { rejectNetwork('eventsource')(...args); } };
    try { Object.defineProperty(navigator, 'sendBeacon', { configurable: true, value: rejectNetwork('sendBeacon') }); } catch {}
    window.PRISMA = Object.freeze({ testSentinel: true });
    window.departmentRequest = (raw) => {
      let request;
      try { request = JSON.parse(raw); window.__bridgeRequests.push(request); }
      catch { window.__bridgeRequests.push({ malformed: true }); }
      if (request && request.operation === window.__throwAfterRecord) throw new Error('Synthetic throw after native dispatch began.');
    };
  }, { testBootstrapTimeoutMs: bootstrapTimeoutMs, testFastConnectionPolling: fastConnectionPolling });
  if (inlineDocument) await page.setContent(inlineDocument);
  else await page.goto(pathToFileURL(pagePath).href);
  page.__externalRequests = externalRequests;
  return page;
}

function newUuid() {
  const tail = String(uuidCounter++).padStart(12, '0');
  return `00000000-0000-4000-8000-${tail}`;
}

async function initialize(page, viewToken = newUuid(), nativeGeneration = '1') {
  const previous = await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length);
  await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
    version: 1, viewToken, nativeGeneration, serverContext: null,
  })), { viewToken, nativeGeneration });
  await page.waitForFunction((previous) => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length > previous, previous);
  return latestRequest(page, 'bootstrap');
}

async function latestRequest(page, operation) {
  return page.evaluate((operation) => {
    const matches = window.__bridgeRequests.filter((request) => request.operation === operation);
    return matches.at(-1) || null;
  }, operation);
}

async function waitForOperationCount(page, operation, count, timeout = 1500) {
  await page.waitForFunction(({ operation, count }) => window.__bridgeRequests.filter((request) => request.operation === operation).length === count,
    { operation, count }, { timeout });
}

async function respond(page, request, data, overrides = {}) {
  const context = overrides.context ?? request.context;
  const response = {
    version: overrides.version ?? request.version,
    requestId: overrides.requestId ?? request.requestId,
    operation: overrides.operation ?? request.operation,
    context: overrides.context ?? context,
    ok: overrides.ok ?? true,
  };
  if (response.ok) response.data = data;
  else response.error = overrides.error ?? { code: 'unauthorized', message: 'Authentication required.' };
  return page.evaluate((json) => window.departmentReceive(JSON.stringify(json)), response);
}

async function saveScreenshot(page, target) {
  if (!target) return;
  fs.mkdirSync(path.dirname(target), { recursive: true });
  await page.screenshot({ path: target, fullPage: true });
}

function catalogPage(actors, { revision = 'a'.repeat(64), nextCursor = null, limited = false } = {}) {
  return { actors, limited, revision, nextCursor };
}

function authenticatedData(serverContext = 'scope-test-1', scopeToken = 'A'.repeat(43), overrides = {}) {
  return {
    authenticated: true,
    authRequired: false,
    serverContext,
    scopeToken,
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true, ...(overrides.capabilities || {}) },
    access: { pcv: true, inspector: true, compose: true, authorDetail: false, brainwash: true, ...(overrides.access || {}) },
    perspectives: overrides.perspectives || {
      mp: { choices: [{ actorKey: 'actor:mp-owner', label: '<img src=x onerror=window.__xss=1>' }, { actorKey: 'npc:captain', label: 'Captain Snide' }], limited: false },
      sas: { choices: [{ actorKey: 'actor:sas-owner', label: 'Captain Snide' }], limited: false },
    },
  };
}

function unauthenticatedData() {
  return {
    authenticated: false,
    authRequired: true,
    serverContext: null,
    scopeToken: null,
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
    access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
  };
}

async function authenticatedPage(browser, serverContext = 'scope-test-1', scopeToken = 'A'.repeat(43), overrides = {}) {
  const page = await openPage(browser);
  const request = await initialize(page);
  assert.match(request.requestId, /^[0-9a-f-]{36}$/i, 'requestId must be a UUID');
  assert.equal(request.context.serverContext, null, 'bootstrap must start without a server context');
  await respond(page, request, authenticatedData(serverContext, scopeToken, overrides));
  return page;
}

async function prepareComposer(page, body, options = {}) {
  await page.getByRole('tab', { name: 'Sealed Fates' }).click();
  await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
  const catalog = await latestRequest(page, 'correspondence.catalog.read');
  const inventory = await latestRequest(page, 'correspondence.inventory.read');
  await respond(page, catalog, catalogPage(options.actors || [
    { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
    { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
  ]));
  await respond(page, inventory, options.inventory || { inventoryRevision: 'inventory-1', items: [], gold: { available: 10 } });
  await page.getByLabel('Writer').selectOption('npc:1');
  await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
  await page.getByLabel('Letter body').fill(body);
  await page.getByRole('button', { name: /review letter/i }).click();
}

async function prepareActivePcv(page, revision = 'roster-pcv-unavailable') {
  await page.getByRole('tab', { name: 'Private Conversation' }).click();
  await page.getByRole('button', { name: /refresh cast and status/i }).click();
  const roster = await latestRequest(page, 'pcv.roster.read');
  const readStatus = await latestRequest(page, 'pcv.status.read');
  const scene = { enabled: true, scene_mode: 'pair', actor_a: 'npc:one', actor_b: 'npc:two', exclude_player: true, bystander_mode: 'exclude', pace: 'normal' };
  const activeId = newUuid();
  await respond(page, roster, { revision, state: 'ready', actors: [
    { actorKey: 'npc:one', label: 'Countess One', eligibility: 'eligible', reason: null },
    { actorKey: 'npc:two', label: 'Countess Two', eligibility: 'eligible', reason: null },
  ], limited: false });
  await respond(page, readStatus, { state: 'active', activeConfigId: activeId, pendingConfigId: null, active: scene, pending: null });
  await page.waitForFunction(() => !document.getElementById('pcv-refresh').disabled);
  return { scene, activeId };
}

async function refreshPcv(page, status) {
  await page.getByRole('button', { name: /refresh cast and status/i }).click();
  const roster = await latestRequest(page, 'pcv.roster.read');
  const readStatus = await latestRequest(page, 'pcv.status.read');
  await respond(page, roster, { revision: 'roster-pcv-unavailable-refresh', state: 'ready', actors: [
    { actorKey: 'npc:one', label: 'Countess One', eligibility: 'eligible', reason: null },
    { actorKey: 'npc:two', label: 'Countess Two', eligibility: 'eligible', reason: null },
  ], limited: false });
  await respond(page, readStatus, status);
  await page.waitForFunction(() => !document.getElementById('pcv-refresh').disabled);
}

async function main() {
  if (!fs.existsSync(pagePath)) {
    results.push({ name: 'renders the approved shell', status: 'failed', error: 'UI entrypoint is missing.' });
  } else {
    if (!fs.existsSync(browserPath)) throw new Error(`Existing local browser not found: ${browserPath}`);
    const browser = await chromium.launch({ headless: true, executablePath: browserPath });

    await test('renders the approved title and tabs without network or framework-global replacement', async () => {
      const page = await openPage(browser);
      assert.equal(await page.title(), 'World of Drama Llama: The Department of Questionable Decisions');
      assert.equal(await page.getByRole('heading', { level: 1 }).textContent(), 'The Department of Questionable Decisions');
      assert.equal(await page.getByText('World of Drama Llama', { exact: true }).count(), 1, 'franchise branding appears once in the visible shell');
      assert.equal(await page.getByText('Conversations, relationships, grudges, and suspicious mail.', { exact: true }).count(), 1);
      for (const name of ['Private Conversation', 'Mind Poisoning', 'Sworn & Scorned', 'Sealed Fates']) assert.equal(await page.getByRole('tab', { name }).count(), 1);
      assert.equal(await page.evaluate(() => window.PRISMA.testSentinel), true);
      assert.equal(await page.evaluate(() => typeof window.departmentInitialize), 'function');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.length), 0, 'request before native initialize');
      await page.evaluate(() => {
        try { window.fetch('https://blocked.invalid/'); } catch {}
        try { new XMLHttpRequest().open('GET', 'https://blocked.invalid/'); } catch {}
        try { new WebSocket('wss://blocked.invalid/'); } catch {}
        try { new EventSource('https://blocked.invalid/'); } catch {}
        try { navigator.sendBeacon('https://blocked.invalid/', 'x'); } catch {}
      });
      assert.deepEqual((await page.evaluate(() => window.__networkAttempts.map((item) => item.kind))).sort(), ['eventsource', 'fetch', 'sendBeacon', 'websocket', 'xhr']);
      assert.deepEqual(page.__externalRequests, [], 'all page resources must be local');
      await page.close();
    });

    await test('uses one fresh bootstrap per native open and denies private operations without auth', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length), 1);
      await respond(page, bootstrap, {
        authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
        capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
        access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
      });
      await page.locator('#connection-status').getByText('Connecting to CHIM').waitFor();
      assert.equal(await page.locator('#permission-status').innerText(), 'Setting up the connection with CHIM.');
      assert.equal(await page.locator('#pcv-state').innerText(), 'Private Conversation becomes available after CHIM connects.');
      for (const tabName of ['Mind Poisoning', 'Sworn & Scorned', 'Sealed Fates', 'Private Conversation']) await page.getByRole('tab', { name: tabName }).click();
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => !['bootstrap', 'connection.begin'].includes(request.operation)).length), 0,
        'automatic connection does not enable private operations');
      await page.close();
    });

    await test('keeps the missing PCV permission message for an authenticated session', async () => {
      const page = await authenticatedPage(browser, 'no-pcv-access', 'C'.repeat(43), {
        capabilities: { pcv: true },
        access: { pcv: false },
      });
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      assert.equal(await page.locator('#pcv-state').innerText(), 'This CHIM session does not have Private Conversation permission.');
      await page.close();
    });

    await test('keeps a bootstrap unavailable error visible with a safe next step and private controls disabled', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, null, { ok: false, error: { code: 'unavailable', message: 'UNTRUSTED_BACKEND_DETAILS' } });
      await page.waitForFunction(() => document.getElementById('connection-status').textContent !== 'Connecting to CHIM', null, { timeout: 1000 });
      assert.match(await page.locator('#connection-status').innerText(), /feature is unavailable/i);
      assert.equal(await page.locator('#permission-status').innerText(), 'Select Retry connection to try again.');
      assert.equal(await page.locator('#connection-status').innerText().then((text) => text.includes('UNTRUSTED_BACKEND_DETAILS')), false,
        'native error prose is never rendered');
      for (const id of ['pcv-refresh', 'mp-refresh', 'sas-refresh', 'mail-refresh', 'letter-review']) {
        assert.equal(await page.locator(`#${id}`).isDisabled(), true, `${id} stays disabled until bootstrap authenticates`);
      }
      await page.close();
    });

    await test('keeps bootstrap timeout copy visible instead of replacing it with authentication required', async () => {
      const page = await openPage(browser, null, 1);
      await initialize(page);
      await page.waitForFunction(() => document.getElementById('connection-status').textContent !== 'Connecting to CHIM', null, { timeout: 1000 });
      assert.match(await page.locator('#connection-status').innerText(), /bootstrap request timed out/i);
      assert.equal(await page.locator('#permission-status').innerText(), 'Select Retry connection to try again.');
      assert.equal(await page.locator('#pcv-refresh').isDisabled(), true);
      await page.close();
    });

    await test('a stale bootstrap failure cannot replace the reopened view state', async () => {
      const page = await openPage(browser);
      const oldRequest = await initialize(page);
      const newView = newUuid();
      await page.evaluate(({ viewToken }) => window.departmentInitialize(JSON.stringify({
        version: 1, viewToken, nativeGeneration: '2', serverContext: null,
      })), { viewToken: newView });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      const accepted = await page.evaluate((request) => window.departmentReceive(JSON.stringify({
        version: request.version, requestId: request.requestId, operation: request.operation, context: request.context,
        ok: false, error: { code: 'unavailable', message: 'STALE_BACKEND_DETAILS' },
      })), oldRequest);
      assert.equal(accepted, false, 'the old bootstrap response is rejected after the view reopens');
      assert.equal(await page.locator('#connection-status').innerText(), 'Connecting to CHIM');
      const current = await latestRequest(page, 'bootstrap');
      await respond(page, current, authenticatedData('reopened-scope', 'B'.repeat(43)));
      await page.locator('#connection-status').getByText('Connected to CHIM').waitFor();
      assert.equal(await page.locator('body').innerText().then((text) => text.includes('STALE_BACKEND_DETAILS')), false);
      await page.close();
    });

    await test('unauthenticated bootstrap automatically connects once and approved status bootstraps the same view', async () => {
      const page = await openPage(browser);
      const firstBootstrap = await initialize(page);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 0,
        'setup does not begin before bootstrap identifies an unauthenticated session');
      await respond(page, firstBootstrap, unauthenticatedData());
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length === 1,
        null, { timeout: 1500 });
      const begin = await latestRequest(page, 'connection.begin');
      assert.deepEqual(begin.payload, {});
      assert.equal(await page.locator('#retry-connection').isHidden(), true, 'ordinary first-run setup has no manual action');
      const visibleText = await page.locator('body').innerText();
      assert.doesNotMatch(visibleText, /Connect to CHIM|Check connection|approval page|approve this game/i);
      const duplicateInit = await page.evaluate((request) => window.departmentInitialize(JSON.stringify({
        version: 1, viewToken: request.context.viewToken, nativeGeneration: request.context.nativeGeneration, serverContext: null,
      })), firstBootstrap);
      assert.equal(duplicateInit, true, 'the same live view acknowledges duplicate initialization without starting another setup orchestration');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);

      await respond(page, begin, { state: 'pending' });
      assert.equal(await page.locator('#connection-status').innerText(), 'Connecting to CHIM');
      assert.equal(await page.locator('#retry-connection').isHidden(), true, 'retry stays hidden while setup is progressing');
      await saveScreenshot(page, connectionPendingScreenshotPath);
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.status').length === 1,
        null, { timeout: 2500 });
      const status = await latestRequest(page, 'connection.status');
      assert.deepEqual(status.payload, {});
      await respond(page, status, { state: 'approved' });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      const refreshedBootstrap = await latestRequest(page, 'bootstrap');
      assert.equal(refreshedBootstrap.context.viewToken, firstBootstrap.context.viewToken);
      assert.equal(refreshedBootstrap.context.nativeGeneration, firstBootstrap.context.nativeGeneration);
      assert.equal(refreshedBootstrap.context.serverContext, null);
      await respond(page, refreshedBootstrap, authenticatedData('connection-approved-scope', 'C'.repeat(43)));
      await page.getByText('Connected to CHIM', { exact: true }).waitFor();
      assert.equal(await page.locator('#connection-actions').isHidden(), true);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);
      assert.equal(await page.evaluate(() => window.__networkAttempts.length), 0, 'connection setup stays on the native bridge');
      await page.close();
    });

    await test('authorization loss exposes Retry connection and refreshes bootstrap without replaying the read', async () => {
      const page = await authenticatedPage(browser, 'revoked-scope', 'R'.repeat(43));
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      const roster = await latestRequest(page, 'pcv.roster.read');
      await respond(page, roster, null, { ok: false, error: { code: 'stale_context', message: 'UNTRUSTED_SCOPE_DETAILS' } });
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);

      await page.getByRole('button', { name: 'Retry connection' }).click();
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      const bootstrap = await latestRequest(page, 'bootstrap');
      await respond(page, bootstrap, authenticatedData('revoked-scope', 'R'.repeat(43)));
      await page.getByText('Connected to CHIM', { exact: true }).waitFor();
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'pcv.roster.read').length), 1,
        'the old read is not automatically replayed');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 0);
      assert.equal(await page.locator('body').innerText().then((value) => value.includes('UNTRUSTED_SCOPE_DETAILS')), false);
      await page.close();
    });

    await test('connection setup failures expose Retry connection without an automatic retry loop', async () => {
      const page = await openPage(browser, null, null, true);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      const begin = await latestRequest(page, 'connection.begin');
      await respond(page, begin, null, { ok: false, error: { code: 'connection_setup_required', message: 'UNTRUSTED_SETUP_DETAILS' } });
      assert.equal(await page.locator('#connection-status').innerText(), 'Connection could not be started');
      assert.equal(await page.locator('#permission-status').innerText(),
        'CHIM’s server address could not be determined. Check that CHIM is connected, then restart Skyrim.');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      await saveScreenshot(page, connectionSetupErrorScreenshotPath);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);
      assert.equal(await page.locator('body').innerText().then((text) => text.includes('UNTRUSTED_SETUP_DETAILS')), false);

      await page.getByRole('button', { name: 'Retry connection' }).click();
      await waitForOperationCount(page, 'connection.begin', 2);
      const retry = await latestRequest(page, 'connection.begin');
      assert.deepEqual(retry.payload, {});
      await respond(page, retry, { state: 'expired' });
      assert.equal(await page.locator('#connection-status').innerText(), 'Connection request expired');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      await page.waitForTimeout(25);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 2,
        'failure and expiration do not trigger a hidden begin loop');
      await page.close();
    });

    await test('connection setup stops after twelve pending polls and retry does not loop after an unauthenticated rebootstrap', async () => {
      const page = await openPage(browser, null, null, true);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      await respond(page, await latestRequest(page, 'connection.begin'), { state: 'pending' });

      for (let attempt = 1; attempt <= 12; attempt += 1) {
        await waitForOperationCount(page, 'connection.status', attempt, 2000);
        const status = await latestRequest(page, 'connection.status');
        assert.deepEqual(status.payload, {});
        await respond(page, status, { state: 'pending' });
      }
      assert.equal(await page.locator('#connection-status').innerText(), 'Connection is taking longer than expected.');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.status').length), 12);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);

      await page.getByRole('button', { name: 'Retry connection' }).click();
      await waitForOperationCount(page, 'connection.begin', 2);
      const retry = await latestRequest(page, 'connection.begin');
      await respond(page, retry, { state: 'approved' });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      const refreshedBootstrap = await latestRequest(page, 'bootstrap');
      assert.equal(refreshedBootstrap.context.serverContext, null);
      await respond(page, refreshedBootstrap, unauthenticatedData());
      await page.waitForTimeout(25);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 2,
        'one unsuccessful fresh bootstrap does not auto-start another begin');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      await page.close();
    });

    await test('a timed out begin queries native status instead of issuing another begin', async () => {
      const page = await openPage(browser, null, null, true);
      const bootstrap = await initialize(page);
      await page.evaluate(() => { window.__fastConnectionTimeout = true; });
      await respond(page, bootstrap, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      await waitForOperationCount(page, 'connection.status', 1);
      const status = await latestRequest(page, 'connection.status');
      assert.equal(await respond(page, status, { state: 'approved' }), true);
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.begin').length), 1);
      assert.deepEqual(status.payload, {});
      await page.close();
    });

    await test('closing cancels polling and late setup replies cannot change the reopened view', async () => {
      const page = await openPage(browser);
      const initial = await initialize(page);
      await respond(page, initial, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      const oldBegin = await latestRequest(page, 'connection.begin');
      await respond(page, oldBegin, { state: 'pending' });
      await page.getByRole('button', { name: 'Close' }).click();

      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 2);
      const currentBegin = await latestRequest(page, 'connection.begin');
      await respond(page, currentBegin, { state: 'expired' });
      const oldAccepted = await page.evaluate((request) => window.departmentReceive(JSON.stringify({
        version: request.version, requestId: request.requestId, operation: request.operation, context: request.context,
        ok: true, data: { state: 'approved' },
      })), oldBegin);
      assert.equal(oldAccepted, false, 'the old connection response is rejected after reopen');
      await page.waitForTimeout(1100);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.status').length), 0,
        'the old view’s scheduled poll is cancelled');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length), 2,
        'a stale approval cannot trigger another bootstrap');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      await page.close();
    });

    await test('rejects obsolete browser approval fields in connection replies', async () => {
      const page = await openPage(browser, null, null, true);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      const begin = await latestRequest(page, 'connection.begin');
      await respond(page, begin, { state: 'pending', approvalOpened: true, claimId: 'PRIVATE_CLAIM_ID' });
      assert.equal(await page.locator('#connection-status').innerText(), 'Connection could not be confirmed');
      assert.equal(await page.getByRole('button', { name: 'Retry connection' }).isVisible(), true);
      assert.doesNotMatch(await page.locator('body').innerText(), /PRIVATE_CLAIM_ID|approval page|approve this game/i);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'connection.status').length), 0);
      await page.close();
    });

    await test('ignores old view and generation responses and never reuses context on reopen', async () => {
      const page = await openPage(browser);
      const oldRequest = await initialize(page, newUuid(), '1');
      const newView = newUuid();
      await page.evaluate(({ viewToken }) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '2', serverContext: null })), { viewToken: newView });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 2);
      const bootstrapRequests = await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap'));
      await respond(page, oldRequest, { authenticated: true, authRequired: false, serverContext: 'stale-scope', scopeToken: 'A'.repeat(43), capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true }, access: { pcv: true, inspector: true, compose: true, authorDetail: false, brainwash: true }, perspectives: { mp: { choices: [], limited: false }, sas: { choices: [], limited: false } } });
      assert.equal(bootstrapRequests[1].context.viewToken, newView);
      assert.equal(bootstrapRequests[1].context.nativeGeneration, '2');
      assert.equal(bootstrapRequests[1].context.serverContext, null);
      const thirdView = newUuid();
      await page.evaluate(({ viewToken }) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '2', serverContext: null })), { viewToken: thirdView });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 3);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length), 3);
      assert.equal(await page.evaluate(() => document.body.innerText.includes('stale-scope')), false);
      await page.close();
    });

    await test('renders backend labels as text, never markup', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      const perspective = page.getByLabel('Perspective');
      assert.equal(await perspective.locator('option').filter({ hasText: '<img src=x onerror=window.__xss=1>' }).count(), 1);
      assert.equal(await page.evaluate(() => window.__xss || false), false);
      assert.equal(await page.locator('img[src="x"]').count(), 0);
      await page.close();
    });

    await test('keeps permission scopes separate from plugin readiness and uses the labelled target selector', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, authenticatedData('scope-ready', 'B'.repeat(43), { capabilities: { correspondence: false } }));
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      const target = page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel(/Target filter/);
      assert.equal(await target.evaluate((node) => node.tagName), 'SELECT');
      assert.equal(await target.locator('option').filter({ hasText: 'Captain Snide' }).count(), 1);
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      assert.equal(await page.locator('#mail-refresh').isDisabled(), true, 'permission does not enable an unavailable plugin');
      assert.equal(await page.locator('#mail-state').textContent().then((text) => /unavailable/i.test(text)), true);
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      assert.equal(await page.locator('#pcv-refresh').isDisabled(), false, 'correspondence readiness must not disable a separate available plugin');
      await page.close();
    });

    await test('PCV send carries both scene IDs and status recovery never resends', async () => {
      const scope = 'A'.repeat(43);
      const page = await authenticatedPage(browser, 'scope-pcv', scope);
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      const roster = await latestRequest(page, 'pcv.roster.read');
      const readStatus = await latestRequest(page, 'pcv.status.read');
      const scene = { enabled: true, scene_mode: 'pair', actor_a: 'npc:one', actor_b: 'npc:two', exclude_player: true, bystander_mode: 'exclude', pace: 'normal' };
      const activeId = newUuid();
      const pendingId = newUuid();
      await respond(page, roster, { revision: 'roster-pcv', state: 'ready', actors: [
        { actorKey: 'npc:one', label: 'Countess One', eligibility: 'eligible', reason: null },
        { actorKey: 'npc:two', label: 'Countess Two', eligibility: 'eligible', reason: null },
      ], limited: false });
      await respond(page, readStatus, { state: 'pending', activeConfigId: activeId, pendingConfigId: pendingId, active: scene, pending: scene });
      await page.getByLabel('Conversation target').selectOption('npc:one');
      await page.getByLabel('Your message').fill('One staged message.');
      await page.getByRole('button', { name: /^send message$/i }).click();
      const send = await latestRequest(page, 'pcv.send');
      assert.ok(send, 'the first Armed/Pending turn can send');
      assert.deepEqual(Object.keys(send.payload).sort(), ['actorKey', 'expectedActiveConfigId', 'expectedPendingConfigId', 'operationId', 'text']);
      assert.equal(send.payload.expectedActiveConfigId, activeId);
      assert.equal(send.payload.expectedPendingConfigId, pendingId);
      await respond(page, send, { operationId: send.payload.operationId, state: 'prepared', phase: 'vm_dispatched' });
      let status = await latestRequest(page, 'pcv.send.status');
      assert.ok(status, 'prepared sends trigger an explicit status poll');
      assert.deepEqual(status.payload, { operationId: send.payload.operationId });
      await respond(page, status, { operationId: send.payload.operationId, state: 'unknown', expectedActiveConfigId: null, expectedPendingConfigId: null });
      assert.equal(await page.locator('#pcv-send').isDisabled(), true, 'unknown operation remains locked even when IDs are unavailable');
      assert.equal(await page.getByLabel('Your message').inputValue(), 'One staged message.');

      await page.getByRole('button', { name: 'Close' }).click();
      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, unauthenticatedData());
      await waitForOperationCount(page, 'connection.begin', 1);
      const begin = await latestRequest(page, 'connection.begin');
      assert.deepEqual(begin.payload, {}, 'automatic reconnect does not alter the retained send operation');
      await respond(page, begin, { state: 'approved' });
      await page.waitForFunction(() => window.__bridgeRequests.filter((request) => request.operation === 'bootstrap').length === 3);
      const refreshed = await latestRequest(page, 'bootstrap');
      assert.equal(refreshed.context.serverContext, null);
      await respond(page, refreshed, authenticatedData('scope-pcv-reopen', scope));
      status = await latestRequest(page, 'pcv.send.status');
      assert.ok(status, 'automatic reconnect recovers the retained handle');
      assert.equal(status.payload.operationId, send.payload.operationId);
      await respond(page, status, { operationId: send.payload.operationId, state: 'consumed', expectedActiveConfigId: activeId, expectedPendingConfigId: pendingId });
      assert.equal(await page.getByLabel('Your message').inputValue(), '', 'PCV acceptance clears the submitted text');
      assert.match(await page.locator('#pcv-send-note').innerText(), /PCV accepted/i);
      assert.match(await page.locator('#pcv-send-note').innerText(), /NPC response and audio delivery are not confirmed/i);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'pcv.send').length), 1,
        'status recovery does not replay the send');
      await page.close();
    });

    await test('initial unknown PCV send response is status-polled without acceptance language and terminal cancellation unlocks', async () => {
      const scope = 'C'.repeat(43);
      const page = await authenticatedPage(browser, 'scope-pcv-initial-unknown', scope);
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      const roster = await latestRequest(page, 'pcv.roster.read');
      const readStatus = await latestRequest(page, 'pcv.status.read');
      const scene = { enabled: true, scene_mode: 'pair', actor_a: 'npc:one', actor_b: 'npc:two', exclude_player: true, bystander_mode: 'exclude', pace: 'normal' };
      const activeId = newUuid();
      const pendingId = newUuid();
      await respond(page, roster, { revision: 'roster-pcv-initial-unknown', state: 'ready', actors: [
        { actorKey: 'npc:one', label: 'Countess One', eligibility: 'eligible', reason: null },
        { actorKey: 'npc:two', label: 'Countess Two', eligibility: 'eligible', reason: null },
      ], limited: false });
      await respond(page, readStatus, { state: 'pending', activeConfigId: activeId, pendingConfigId: pendingId, active: scene, pending: scene });
      await page.getByLabel('Conversation target').selectOption('npc:one');
      await page.getByLabel('Your message').fill('One staged message.');
      await page.getByRole('button', { name: /^send message$/i }).click();
      const send = await latestRequest(page, 'pcv.send');
      await respond(page, send, { operationId: send.payload.operationId, state: 'unknown', phase: 'stage_unknown' });
      const status = await latestRequest(page, 'pcv.send.status');
      assert.ok(status, 'an initial unknown response triggers status recovery');
      assert.equal(await page.locator('#pcv-send').isDisabled(), true, 'unknown remains locked pending the authoritative status');
      assert.match(await page.locator('#pcv-send-note').innerText(), /Checking recorded send status; do not resend\./i);
      assert.doesNotMatch(await page.locator('#pcv-send-note').innerText(), /Native accepted/i);
      await respond(page, status, { operationId: send.payload.operationId, state: 'cancelled', expectedActiveConfigId: activeId, expectedPendingConfigId: pendingId });
      assert.match(await page.locator('#pcv-send-note').innerText(), /resolved this operation as cancelled/i);
      assert.equal(await page.locator('#pcv-send').isDisabled(), false, 'a matching terminal cancellation releases the lock');
      assert.equal(await page.getByLabel('Your message').inputValue(), 'One staged message.', 'cancellation retains the draft for review');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'pcv.send').length), 1,
        'status resolution never resends the operation');
      await page.close();
    });

    await test('unknown End survives unavailable refresh until definitive Off readback', async () => {
      const page = await authenticatedPage(browser, 'scope-pcv-unknown-refresh');
      await prepareActivePcv(page);
      await page.getByRole('button', { name: 'End now' }).click();
      const end = await latestRequest(page, 'pcv.end');
      await respond(page, end, { operationId: end.payload.operationId, state: 'unknown' });
      assert.equal(await page.locator('#pcv-end').isDisabled(), true, 'a second mutation stays blocked while End is unresolved');

      await refreshPcv(page, { state: 'unavailable', activeConfigId: null, pendingConfigId: null, active: null, pending: null });
      assert.equal(await page.locator('#pcv-arm').isDisabled(), true, 'unavailable readback does not release the mutation lock');
      assert.equal(await page.locator('#pcv-end').isDisabled(), true, 'End remains blocked while its result is unresolved');
      assert.match(await page.locator('#pcv-state').innerText(), /uncertain.*refresh/i, 'the unavailable readback keeps the uncertainty visible');
      if (screenshotPath) await page.screenshot({ path: screenshotPath, fullPage: true });

      await refreshPcv(page, { state: 'off', activeConfigId: null, pendingConfigId: null, active: null, pending: null });
      assert.equal(await page.locator('#pcv-end').isDisabled(), false, 'a definitive Off status resolves the uncertain mutation lock');
      assert.match(await page.locator('#pcv-state').innerText(), new RegExp(`uncertain operation ${end.payload.operationId}`), 'the original operation handle remains through unavailable status until a definitive readback');
      await page.close();
    });

    await test('accepted End followed by unavailable status remains unresolved and does not claim Active or Pending', async () => {
      const page = await authenticatedPage(browser, 'scope-pcv-end-unavailable');
      await prepareActivePcv(page, 'roster-pcv-end-unavailable');
      await page.getByRole('button', { name: 'End now' }).click();
      const end = await latestRequest(page, 'pcv.end');
      await respond(page, end, { operationId: end.payload.operationId, state: 'ended', activeConfigId: null, pendingConfigId: null, active: null, pending: null });
      const readStatus = await latestRequest(page, 'pcv.status.read');
      await respond(page, readStatus, { state: 'unavailable', activeConfigId: null, pendingConfigId: null, active: null, pending: null });
      assert.equal(await page.locator('#pcv-arm').isDisabled(), true);
      assert.equal(await page.locator('#pcv-end').isDisabled(), true);
      assert.match(await page.locator('#pcv-state').innerText(), /uncertain.*refresh/i);
      assert.doesNotMatch(await page.locator('#pcv-state').innerText(), /current Active or Pending configuration is now present/i);
      await page.close();
    });

    await test('accepted Arm followed by unavailable status remains unresolved', async () => {
      const page = await authenticatedPage(browser, 'scope-pcv-arm-unavailable');
      const { activeId } = await prepareActivePcv(page, 'roster-pcv-arm-unavailable');
      await page.getByRole('button', { name: /edit scene settings/i }).click();
      await page.getByLabel('Speaker').selectOption('npc:one');
      await page.getByLabel('Second NPC').selectOption('npc:two');
      await page.getByRole('button', { name: /arm conversation/i }).click();
      const arm = await latestRequest(page, 'pcv.arm');
      const pendingId = newUuid();
      await respond(page, arm, { operationId: arm.payload.operationId, state: 'staged', activeConfigId: activeId, pendingConfigId: pendingId,
        active: arm.payload.configuration, pending: arm.payload.configuration });
      const readStatus = await latestRequest(page, 'pcv.status.read');
      await respond(page, readStatus, { state: 'unavailable', activeConfigId: null, pendingConfigId: null, active: null, pending: null });
      assert.equal(await page.locator('#pcv-arm').isDisabled(), true);
      assert.equal(await page.locator('#pcv-end').isDisabled(), true);
      assert.match(await page.locator('#pcv-state').innerText(), /uncertain.*refresh/i);
      await page.close();
    });

    await test('explicit native dispatched false releases a local compose refusal', async () => {
      const page = await authenticatedPage(browser);
      await prepareComposer(page, 'A locally refused draft.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, null, { ok: false, error: { code: 'stale_revision', message: 'Fixed local refusal.', dispatched: false } });
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true, 'stale inventory disables composition until refreshed');
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'A locally refused draft.', 'a local refusal keeps the draft available for fresh review');
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'a known local refusal must not create a reconcile handle');
      assert.match(await page.locator('#letter-receipt').innerText(), /request was not sent/i);
      assert.equal(await page.locator('#letter-send').isDisabled(), true, 'the old review cannot send against a stale inventory revision');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), true, 'the stale review summary is discarded');
      assert.equal(await page.locator('#letter-review').isDisabled(), true, 'stale inventory must be refreshed before another review');
      const beforeRefresh = await page.evaluate(() => ({
        catalog: window.__bridgeRequests.filter((request) => request.operation === 'correspondence.catalog.read').length,
        inventory: window.__bridgeRequests.filter((request) => request.operation === 'correspondence.inventory.read').length,
      }));
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      await page.waitForFunction((before) =>
        window.__bridgeRequests.filter((request) => request.operation === 'correspondence.catalog.read').length > before.catalog
        && window.__bridgeRequests.filter((request) => request.operation === 'correspondence.inventory.read').length > before.inventory, beforeRefresh);
      const refreshedCatalog = await latestRequest(page, 'correspondence.catalog.read');
      const refreshedInventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, refreshedCatalog, catalogPage([
        { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
        { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
      ], { revision: 'b'.repeat(64) }));
      await respond(page, refreshedInventory, { inventoryRevision: 'inventory-2', items: [], gold: { available: 10 } });
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), false, 'a fresh review enables a deliberate retry');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'refresh and review do not resubmit automatically');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const retry = await latestRequest(page, 'correspondence.compose');
      assert.notEqual(retry.payload.operationId, compose.payload.operationId, 'a new send uses a new operation after explicit review');
      await page.close();
    });

    await test('invalid input and unavailable no-dispatch errors require a new explicit review', async () => {
      for (const code of ['invalid_input', 'unavailable']) {
        const page = await authenticatedPage(browser, `scope-${code}`, 'F'.repeat(43));
        await prepareComposer(page, `${code} refusal draft.`);
        await page.getByRole('button', { name: /send sealed letter/i }).click();
        const compose = await latestRequest(page, 'correspondence.compose');
        await respond(page, compose, null, { ok: false, error: { code, message: 'Fixed pre-dispatch refusal.', dispatched: false } });
        assert.equal(await page.locator('#letter-reconcile').isHidden(), true, `${code} refusal has no operation to reconcile`);
        assert.equal(await page.locator('#letter-send').isDisabled(), true, `${code} refusal consumes the prior review`);
        assert.equal(await page.locator('#letter-review-summary').isHidden(), true, `${code} refusal hides the old review summary`);
        await page.getByRole('button', { name: /review letter/i }).click();
        assert.equal(await page.locator('#letter-send').isDisabled(), false, `${code} refusal needs a fresh review before retry`);
        assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1);
        await page.close();
      }
    });

    await test('consumes the C++ MakeError refusal fixture and requires a new review after auth cleanup', async () => {
      const fixturePath = process.env.NATIVE_REFUSAL_FIXTURE;
      assert.ok(fixturePath, 'Set NATIVE_REFUSAL_FIXTURE to the native producer output path.');
      const fixtureText = fs.readFileSync(fixturePath, 'utf8');
      assert.equal(crypto.createHash('sha256').update(fixtureText).digest('hex').toUpperCase(),
        'AFF16DB303852CBD36F17E151F571BC28194422DDE35EB16CBB3D5DABF420342',
        'the fixture is the hash-checked output from the native producer');
      const producerResponse = JSON.parse(fixtureText);
      const body = 'FORMATTER_FIXTURE_PRIVATE_DRAFT_SENTINEL';
      assert.equal(producerResponse.version, 1);
      assert.equal(producerResponse.operation, 'correspondence.compose');
      assert.equal(producerResponse.ok, false);
      assert.deepEqual(producerResponse.error, {
        code: 'stale_context',
        dispatched: false,
        message: 'The Department authorization context is no longer current.',
      }, 'consume the exact error object emitted by the production C++ formatter');
      assert.equal(fixtureText.includes(body), false, 'the producer fixture contains no private compose payload');

      const scopeToken = 'L'.repeat(43);
      const page = await authenticatedPage(browser, 'scope-native-formatter-refusal', scopeToken);
      await prepareComposer(page, body);
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      // The native formatter fixture carries its own synthetic correlation. Rebind only these three envelope fields.
      const response = {
        ...producerResponse,
        requestId: compose.requestId,
        operation: compose.operation,
        context: compose.context,
      };
      assert.deepEqual(response.error, producerResponse.error, 'the native-produced error object remains byte-shape equivalent');
      assert.equal(JSON.stringify(response).includes(body), false, 'the rebound response contains no private compose payload');
      assert.equal(await page.evaluate((value) => window.departmentReceive(JSON.stringify(value)), response), true,
        'the exact active compose request accepts its matching production-format refusal');
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'stale authorization erases the private draft');
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'known non-dispatch leaves no unresolved operation handle');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), true, 'stale authorization clears the prior review');
      if (nativeRefusalScreenshotPath) {
        fs.mkdirSync(path.dirname(nativeRefusalScreenshotPath), { recursive: true });
        await page.screenshot({ path: nativeRefusalScreenshotPath, fullPage: true });
      }

      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, authenticatedData('scope-native-formatter-reopen', scopeToken));
      assert.equal(await page.getByRole('button', { name: /refresh catalog and inventory/i }).isVisible(), true);
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'same-scope reauthorization does not restore erased text');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'reauthorization does not automatically resend');

      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const refreshedCatalog = await latestRequest(page, 'correspondence.catalog.read');
      const refreshedInventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, refreshedCatalog, catalogPage([
        { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
        { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
      ], { revision: 'c'.repeat(64) }));
      await respond(page, refreshedInventory, { inventoryRevision: 'inventory-2', items: [], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('A newly entered draft after authorization refresh.');
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), false, 'a new explicit review enables the next send');
      if (nativeRecoveryScreenshotPath) {
        fs.mkdirSync(path.dirname(nativeRecoveryScreenshotPath), { recursive: true });
        await page.screenshot({ path: nativeRecoveryScreenshotPath, fullPage: true });
      }
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'refreshing and reviewing do not resubmit automatically');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const replacement = await latestRequest(page, 'correspondence.compose');
      assert.notEqual(replacement.payload.operationId, compose.payload.operationId, 'an explicit later send receives a new operation ID');
      await page.close();
    });

    await test('forbidden dispatched false clears the exact operation without restoring its erased draft', async () => {
      const scopeToken = 'G'.repeat(43);
      const page = await authenticatedPage(browser, 'scope-forbidden-no-dispatch', scopeToken);
      await prepareComposer(page, 'FORBIDDEN_PRIVATE_DRAFT_SENTINEL');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, null, { ok: false, error: { code: 'forbidden', message: 'Fixed denial.', dispatched: false } });
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'authorization cleanup erases the private draft');
      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, authenticatedData('scope-forbidden-reopen', scopeToken));
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'known not-dispatched operation is not restored after reauthorization');
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'reauthorization does not restore erased private text');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'reauthorization does not retry the refused operation');
      await page.close();
    });

    await test('late compose refusals from another operation or scope are ignored and dispatched uncertainty stays locked', async () => {
      const page = await authenticatedPage(browser, 'scope-late-compose', 'H'.repeat(43));
      await prepareComposer(page, 'A possibly dispatched draft.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      const wrongOperation = { version: 1, requestId: compose.requestId, operation: 'correspondence.reconcile', context: compose.context,
        ok: false, error: { code: 'stale_revision', message: 'Wrong operation.', dispatched: false } };
      assert.equal(await page.evaluate((response) => window.departmentReceive(JSON.stringify(response)), wrongOperation), false);
      const wrongScope = { ...wrongOperation, operation: compose.operation, context: { ...compose.context, serverContext: 'another-scope' } };
      assert.equal(await page.evaluate((response) => window.departmentReceive(JSON.stringify(response)), wrongScope), false);
      await respond(page, compose, null, { ok: false, error: { code: 'unavailable', message: 'May have dispatched.', dispatched: true } });
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true, 'an explicit dispatched marker preserves the operation lock');
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false, 'the same operation remains available for reconciliation');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'rejected late messages do not trigger a retry');
      await page.close();
    });

    await test('generic not_found with a false marker cannot release a compose operation', async () => {
      const page = await authenticatedPage(browser, 'scope-compose-not-found', 'K'.repeat(43));
      await prepareComposer(page, 'Generic absence is uncertain.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, null, { ok: false, error: { code: 'not_found', message: 'No receipt exists.', dispatched: false } });
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true, 'generic not_found does not prove the compose was not dispatched');
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false, 'the original operation remains available for reconciliation');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1);
      await page.close();
    });

    await test('ordinary view close and reinitialize retain the locked draft cache and unresolved operation ID', async () => {
      const scope = 'Q'.repeat(43);
      const page = await authenticatedPage(browser, 'scope-letter-ordinary-reopen', scope);
      await prepareComposer(page, 'Retained only in the current UI lifetime.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, { operationId: compose.payload.operationId, state: 'queued' });
      assert.match(await page.locator('#letter-review-summary').innerText(), /Retained only in the current UI lifetime\./,
        'the review summary contains the private body before auth-loss cleanup');
      await page.getByRole('button', { name: 'Close' }).click();

      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, authenticatedData('scope-letter-ordinary-reopen', scope));
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'Retained only in the current UI lifetime.');
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId);
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false);
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      const reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, compose.payload.operationId);
      await respond(page, reconcile, null, { ok: false, error: { code: 'not_found', message: 'No receipt yet.' } });
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId, 'not_found keeps the same ID through ordinary reopen');

      await page.getByRole('button', { name: 'Close' }).click();
      const unauthenticated = await initialize(page, newUuid(), '3');
      await respond(page, unauthenticated, {
        authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
        capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
        access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
      });
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'an unauthenticated bootstrap erases the cached private body');
      assert.equal(await page.locator('#letter-review-summary').innerText(), '', 'an unauthenticated bootstrap erases review-summary text');
      assert.equal(await page.locator('#letter-review-summary').locator('> *').count(), 0, 'an unauthenticated bootstrap removes private review-summary children');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), true);
      assert.equal(await page.locator('#letter-operation').isHidden(), true, 'unresolved IDs are hidden while authorization is absent');

      await page.getByRole('button', { name: 'Close' }).click();
      const reauthenticated = await initialize(page, newUuid(), '4');
      await respond(page, reauthenticated, authenticatedData('scope-letter-ordinary-reopen', scope));
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'same-scope reauthentication does not restore erased private text');
      assert.equal(await page.locator('#letter-review-summary').innerText(), '', 'erased review text stays absent after reauthentication');
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId, 'the unresolved operation ID survives same-scope auth loss');
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false, 'same-scope reauthentication restores ID-only reconciliation');
      await page.close();
    });

    await test('starts a new draft beside unresolved IDs and isolates delayed compose and reconcile responses', async () => {
      const page = await authenticatedPage(browser, 'scope-letter-recovery-multiple', 'M'.repeat(43));
      await prepareComposer(page, 'First operation private body.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const first = await latestRequest(page, 'correspondence.compose');

      await page.getByRole('button', { name: /new letter/i }).click();
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'New letter explicitly clears the prior editor body');
      assert.equal(await page.locator('#letter-operation').inputValue(), first.payload.operationId, 'the unresolved ID remains available');
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('Second operation private body.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const second = await latestRequest(page, 'correspondence.compose');
      assert.notEqual(second.payload.operationId, first.payload.operationId);

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('Current editor stays on this newer draft.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await respond(page, first, { operationId: first.payload.operationId, state: 'queued' });
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'Current editor stays on this newer draft.');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), false, 'a delayed old result cannot relock the current editor');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), false, 'an old result cannot clear the newer review');
      const listedIds = await page.locator('#letter-operation option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean));
      assert.deepEqual(new Set(listedIds), new Set([first.payload.operationId, second.payload.operationId]));
      assert.doesNotMatch(await page.locator('#letter-operation').innerText(), /First operation private body|Second operation private body/,
        'the unresolved list contains IDs and statuses, never prior letter text');

      await page.locator('#letter-operation').selectOption(first.payload.operationId);
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      const reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, first.payload.operationId, 'reconciliation uses the selected old operation ID');
      await respond(page, reconcile, null, { ok: false, error: { code: 'not_found', message: 'No current receipt.' } });
      assert.match(await page.locator('#letter-recovery-status').innerText(), /not found yet.*remains unresolved/i);
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'Current editor stays on this newer draft.');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), false, 'not_found for an old ID cannot lock the newer editor');
      assert.equal(await page.locator('#letter-send').isDisabled(), false, 'the reviewed newer draft remains sendable');
      if (letterRecoveryScreenshotPath) {
        fs.mkdirSync(path.dirname(letterRecoveryScreenshotPath), { recursive: true });
        await page.screenshot({ path: letterRecoveryScreenshotPath, fullPage: true });
      }
      const currentReceipt = await page.locator('#letter-receipt').innerText();
      await respond(page, second, null, { ok: false, error: { code: 'stale_revision', message: 'Detached operation was not sent.', dispatched: false } });
      await page.waitForFunction((id) => {
        const ids = [...document.querySelectorAll('#letter-operation option')].map((option) => option.value).filter(Boolean);
        return ids.length === 1 && !ids.includes(id);
      }, second.payload.operationId);
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'Current editor stays on this newer draft.');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), false, 'a delayed proven refusal for a detached ID cannot relock the newer draft');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), false, 'a detached refusal cannot clear the newer review');
      assert.equal(await page.locator('#letter-receipt').innerText(), currentReceipt, 'a detached refusal cannot overwrite the newer editor status');
      assert.equal(await page.locator('#letter-send').isDisabled(), false);

      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const third = await latestRequest(page, 'correspondence.compose');
      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('A final draft survives detached delivery confirmation.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await respond(page, third, { operationId: third.payload.operationId, state: 'queued' });
      await page.locator('#letter-operation').selectOption(third.payload.operationId);
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      const terminalReconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(terminalReconcile.payload.operationId, third.payload.operationId);
      await respond(page, terminalReconcile, { operationId: third.payload.operationId, state: 'delivered', receipt: { deliveryConfirmed: true, receivedAt: 'now' } });
      assert.match(await page.locator('#letter-recovery-result').innerText(), new RegExp(`${third.payload.operationId} resolved: delivery receipt confirmed`),
        'a definitive old-ID result is reported in recovery status without replacing the editor receipt');
      assert.equal(await page.getByLabel('Letter body').inputValue(), 'A final draft survives detached delivery confirmation.');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), false, 'an old terminal reconciliation cannot relock the current editor');
      assert.equal(await page.locator('#letter-review-summary').isHidden(), false, 'an old terminal reconciliation cannot clear the newer review');
      assert.match(await page.locator('#letter-recovery-status').innerText(), /not found yet.*remains unresolved/i,
        'the earlier not_found operation remains visible after another ID resolves');
      assert.equal(await page.locator('#letter-send').isDisabled(), false);
      await page.close();
    });

    await test('blocks unresolved semantic duplicates, permits changed intent, and refuses cap overflow without eviction', async () => {
      const actors = [
        { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
        { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
        { actorKey: 'npc:3', label: 'Alternate recipient', canWrite: false, canReceive: true, canSignAs: true },
      ];
      const page = await authenticatedPage(browser, 'scope-letter-intent-guard', 'N'.repeat(43));
      await page.evaluate(() => Object.defineProperty(window, 'crypto', { configurable: true, value: undefined }));
      const originalBody = 'Same   semantic\nbody ';
      await prepareComposer(page, originalBody, { actors, inventory: {
        inventoryRevision: 'inventory-before', items: [{ itemKey: 'item:before', label: 'Potion', availableQuantity: 2 }], gold: { available: 10 },
      } });
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const first = await latestRequest(page, 'correspondence.compose');
      assert.equal(first.payload.body, originalBody, 'fingerprint normalization leaves the transmitted body unchanged');
      await respond(page, first, { operationId: first.payload.operationId, state: 'queued' });

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const refreshedCatalog = await latestRequest(page, 'correspondence.catalog.read');
      const refreshedInventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, refreshedCatalog, catalogPage(actors, { revision: 'd'.repeat(64) }));
      await respond(page, refreshedInventory, { inventoryRevision: 'inventory-after', items: [{ itemKey: 'item:after', label: 'Potion', availableQuantity: 2 }], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Sign as someone else').check();
      await page.getByLabel('Signature shown on the letter').selectOption('npc:3');
      await page.getByLabel('Letter body').fill('  Same   semantic\n body  ');
      await page.getByLabel('Attach Potion').check();
      await page.getByLabel('Gold').fill('1');
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), true,
        'changed inventory revision, item key, signature, attachments, gold, and whitespace cannot bypass an unresolved semantic duplicate');
      assert.match(await page.locator('#letter-recovery-status').innerText(), /same writer, recipient, and normalized body/i);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'the duplicate refusal sends no second request');

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('Changed body is a distinct intent.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const second = await latestRequest(page, 'correspondence.compose');
      await respond(page, second, { operationId: second.payload.operationId, state: 'queued' });

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:3');
      await page.getByLabel('Letter body').fill('Same body, different recipient.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const third = await latestRequest(page, 'correspondence.compose');
      await respond(page, third, { operationId: third.payload.operationId, state: 'queued' });
      assert.equal(await page.locator('#letter-operation option').count(), 3, 'the bounded unresolved list retains all three operation IDs');

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('Fourth unique letter.');
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), true, 'the fourth unresolved send is explicitly refused at the cap');
      assert.match(await page.locator('#letter-recovery-status').innerText(), /three unresolved operations.*none will be dropped/i);
      assert.deepEqual(await page.locator('#letter-operation option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean)),
        [first.payload.operationId, second.payload.operationId, third.payload.operationId], 'cap exhaustion never silently evicts an unresolved ID');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 3);
      await page.locator('#letter-operation').selectOption(first.payload.operationId);
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      const reconcile = await latestRequest(page, 'correspondence.reconcile');
      await respond(page, reconcile, null, { ok: false, error: { code: 'not_found', message: 'No receipt yet.' } });
      assert.match(await page.locator('#letter-recovery-status').innerText(), /three unresolved operations.*not found yet.*remains unresolved/i,
        'at capacity, reconciliation still reports that the selected not_found operation remains unresolved');
      assert.deepEqual(await page.locator('#letter-operation option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean)),
        [first.payload.operationId, second.payload.operationId, third.payload.operationId], 'not_found at capacity preserves all three IDs');
      await page.close();
    });

    await test('reserves transfer-bearing sends across unresolved IDs while allowing text-only letters', async () => {
      const page = await authenticatedPage(browser, 'scope-letter-transfer-reservation', 'P'.repeat(43));
      const actors = [
        { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
        { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
      ];
      await prepareComposer(page, 'First transfer operation.', { actors, inventory: {
        inventoryRevision: 'transfer-inventory-1', items: [{ itemKey: 'item:old', label: 'Potion', availableQuantity: 2 }], gold: { available: 10 },
      } });
      await page.getByLabel('Attach Potion').check();
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const transfer = await latestRequest(page, 'correspondence.compose');
      await respond(page, transfer, { operationId: transfer.payload.operationId, state: 'queued' });

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('A distinct text-only letter can proceed.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const textOnly = await latestRequest(page, 'correspondence.compose');
      await respond(page, textOnly, { operationId: textOnly.payload.operationId, state: 'queued' });
      assert.deepEqual(textOnly.payload.attachments, [], 'a pending gift does not block a distinct text-only letter');

      await page.getByRole('button', { name: /new letter/i }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage(actors, { revision: 'e'.repeat(64) }));
      await respond(page, inventory, { inventoryRevision: 'transfer-inventory-2', items: [{ itemKey: 'item:new', label: 'Potion', availableQuantity: 2 }], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('A second transfer must wait.');
      await page.getByLabel('Attach Potion').check();
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), true, 'a second transfer stays blocked after inventory refresh');
      assert.match(await page.locator('#letter-recovery-status').innerText(), /transfer-bearing operation remains unresolved/i);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 2);

      await page.locator('#letter-operation').selectOption(transfer.payload.operationId);
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      let reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, transfer.payload.operationId);
      await respond(page, reconcile, null, { ok: false, error: { code: 'not_found', message: 'No receipt yet.' } });
      assert.equal(await page.locator('#letter-send').isDisabled(), true, 'not_found does not release a transfer reservation');

      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, transfer.payload.operationId, 'the same ID remains available for later reconciliation');
      await respond(page, reconcile, { operationId: transfer.payload.operationId, state: 'delivered', receipt: { deliveryConfirmed: true, receivedAt: 'now' } });
      assert.equal(await page.locator('#letter-send').isDisabled(), false, 'a matching definitive receipt frees the transfer reservation');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const third = await latestRequest(page, 'correspondence.compose');
      assert.notEqual(third.payload.operationId, transfer.payload.operationId);
      assert.deepEqual(third.payload.attachments, [{ itemKey: 'item:new', quantity: 1 }]);
      await page.close();
    });

    await test('unmarked auth/stale errors retain unresolved operations and not_found does not prove non-dispatch', async () => {
      const page = await authenticatedPage(browser, 'scope-auth-recovery', 'B'.repeat(43));
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([{ actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true }, { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false }]));
      await respond(page, inventory, { inventoryRevision: 'inventory-1', items: [], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('One retained operation.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, null, { ok: false, error: { code: 'stale_context', message: 'Fixed stale response.' } });
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'reconciliation stays unavailable while authorization is stale');
      assert.equal(await page.locator('#letter-operation option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean)).then((ids) => ids.length), 0,
        'unresolved IDs stay out of the recovery controls while authorization is lost');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'authorization errors clear private compose text');
      await page.getByRole('button', { name: 'Close' }).click();
      const reopened = await initialize(page, newUuid(), '2');
      await respond(page, reopened, authenticatedData('scope-auth-recovery-reopen', 'B'.repeat(43)));
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false, 'same confirmed scope restores the original operation handle');
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId, 'same-scope reopen restores the exact unresolved ID');
      assert.equal(await page.getByLabel('Letter body').inputValue(), '', 'same-scope recovery does not restore unauthorized compose text');
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      const reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, compose.payload.operationId);
      await respond(page, reconcile, null, { ok: false, error: { code: 'not_found', message: 'No current receipt.' } });
      assert.equal(await page.locator('#letter-reconcile').isHidden(), false, 'generic not_found never unlocks an uncertain compose');
      assert.match(await page.locator('#letter-recovery-status').innerText(), /not found yet.*remains unresolved/i);
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);

      const newScope = await initialize(page, newUuid(), '3');
      await respond(page, newScope, authenticatedData('scope-auth-recovery-new', 'C'.repeat(43)));
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'an authenticated server-scope change clears the old operation handles');
      assert.deepEqual(await page.locator('#letter-operation option').evaluateAll((options) => options.map((option) => option.value).filter(Boolean)), []);
      await page.close();
    });

    await test('malformed dispatched markers are rejected conservatively', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([{ actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true }, { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false }]));
      await respond(page, inventory, { inventoryRevision: 'inventory-1', items: [], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('Malformed marker draft.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      const malformed = { version: compose.version, requestId: compose.requestId, operation: compose.operation, context: compose.context,
        ok: false, error: { code: 'stale_revision', message: 'Malformed marker.', dispatched: 'false' } };
      const accepted = await page.evaluate((response) => window.departmentReceive(JSON.stringify(response)), malformed);
      assert.equal(accepted, false, 'a non-boolean marker is not accepted by the boundary');
      await page.getByRole('button', { name: 'Close' }).click();
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true, 'invalid response does not unlock the in-flight operation');
      await page.close();
    });

    await test('terminal compose results require a fresh review before another send', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([{ actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true }, { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false }]));
      await respond(page, inventory, { inventoryRevision: 'inventory-1', items: [], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('One reviewed letter.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, { operationId: compose.payload.operationId, state: 'rejected' });
      assert.equal(await page.locator('#letter-send').isDisabled(), true, 'terminal compose clears the prior review');
      assert.equal(await page.locator('#letter-review').isDisabled(), false);
      await page.getByRole('button', { name: /review letter/i }).click();
      assert.equal(await page.locator('#letter-send').isDisabled(), false, 'a new deliberate review re-enables sending');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1);
      await page.close();
    });

    await test('late inspector responses cannot mutate a changed perspective, query, or mailbox', async () => {
      const perspectives = {
        mp: { choices: [{ actorKey: 'actor:mp-owner', label: 'Countess Example' }, { actorKey: 'npc:mp-other', label: 'Other Owner' }, { actorKey: 'npc:captain', label: 'Captain Snide' }], limited: false },
        sas: { choices: [{ actorKey: 'actor:sas-owner', label: 'Captain Snide' }, { actorKey: 'npc:sas-other', label: 'Other Captain' }], limited: false },
      };
      const page = await authenticatedPage(browser, 'scope-inspectors', 'C'.repeat(43), { perspectives });
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('actor:mp-owner');
      await page.getByLabel(/Target filter/).selectOption('npc:captain');
      await page.getByRole('button', { name: /refresh relationship history/i }).click();
      let request = await latestRequest(page, 'mp.relationships.read');
      await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('npc:mp-other');
      await page.getByLabel(/Target filter/).selectOption('');
      const record = { id: 'stale-mp', owner: 'Countess Example', target: 'Captain Snide', at: 'now', proposedDelta: 0, appliedDelta: 0,
        historicalBefore: 0, historicalAfter: 0, currentValue: 0, currentAsOf: 'now', sourceKind: 'review', fixedReason: 'STALE_MP_SENTINEL', kind: 'relationship', confirmation: 'confirmed' };
      await respond(page, request, { perspectiveActorKey: 'actor:mp-owner', records: [record], limited: false, nextCursor: null });
      assert.equal(await page.getByText('STALE_MP_SENTINEL', { exact: true }).count(), 0, 'perspective and target changes reject a late page');

      await page.getByRole('tab', { name: 'Sworn & Scorned' }).click();
      await page.getByRole('tabpanel', { name: 'Sworn & Scorned' }).getByLabel('Perspective').selectOption('actor:sas-owner');
      await page.getByRole('button', { name: /refresh grievances and promises/i }).click();
      request = await latestRequest(page, 'sas.issues.read');
      await page.getByRole('tabpanel', { name: 'Sworn & Scorned' }).getByLabel('Perspective').selectOption('npc:sas-other');
      const issue = { id: 'stale-sas', kind: 'grievance', owner: 'Captain Snide', counterpart: 'Baron Wit', direction: 'owed by counterpart',
        matter: 'STALE_SAS_SENTINEL', nature: 'public insult', status: 'open', settlement: 'unknown' };
      await respond(page, request, { perspectiveActorKey: 'actor:sas-owner', issues: [issue], limited: false, nextCursor: null });
      assert.equal(await page.getByText('STALE_SAS_SENTINEL', { exact: true }).count(), 0, 'S&S perspective changes reject a late page');

      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([
        { actorKey: 'npc:101', label: 'Archive One', canWrite: false, canReceive: true, canSignAs: false },
        { actorKey: 'npc:102', label: 'Archive Two', canWrite: false, canReceive: true, canSignAs: false },
      ]));
      await respond(page, inventory, { inventoryRevision: 'inventory-1', items: [], gold: { available: 0 } });
      await page.getByRole('button', { name: 'Mailboxes' }).click();
      const recipient = page.getByLabel('Recipient mailbox');
      await recipient.selectOption('npc:101');
      await page.getByRole('button', { name: 'Refresh mailbox' }).click();
      request = await latestRequest(page, 'correspondence.mailbox.read');
      await recipient.selectOption('npc:102');
      await respond(page, request, { recipientActorKey: 'npc:101', letters: [{ letterId: 'old-mail', claimedSignature: 'Old', receivedAt: 'today',
        bodyPreview: 'STALE_MAILBOX_SENTINEL', attachments: [], deliveryConfirmed: true }], limited: false, nextCursor: null });
      assert.equal(await page.getByText('STALE_MAILBOX_SENTINEL', { exact: true }).count(), 0, 'recipient changes reject a late mailbox response');
      await page.close();
    });

    await test('rejects response correlation mismatches and oversized JSON', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, { authenticated: true, authRequired: false, serverContext: 'scope', scopeToken: 'A'.repeat(43), capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true }, access: { pcv: true, inspector: true, compose: true, authorDetail: false, brainwash: true }, perspectives: { mp: { choices: [], limited: false }, sas: { choices: [], limited: false } } }, { requestId: newUuid() });
      const receive = await page.evaluate(() => typeof window.departmentReceive);
      assert.equal(receive, 'function');
      const before = await page.getByRole('tab', { name: 'Mind Poisoning' }).getAttribute('aria-disabled');
      await page.evaluate(() => window.departmentReceive('x'.repeat(70000)));
      assert.equal(await page.getByRole('tab', { name: 'Mind Poisoning' }).getAttribute('aria-disabled'), before, 'mismatched or oversized response mutated authorization state');
      assert.deepEqual(await page.evaluate(() => window.__networkAttempts), []);
      await page.close();
    });

    await test('generates distinct UUID correlation IDs without Web Crypto', async () => {
      const page = await openPage(browser);
      await page.evaluate(() => Object.defineProperty(window, 'crypto', { configurable: true, value: undefined }));
      assert.equal(await page.evaluate(() => window.crypto), undefined);
      const bootstrap = await initialize(page);
      assert.match(bootstrap.requestId, /^[0-9a-f-]{36}$/i);
      await respond(page, bootstrap, authenticatedData());
      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      await page.waitForFunction(() => window.__bridgeRequests.some((request) => request.operation === 'pcv.roster.read'));
      const ids = await page.evaluate(() => window.__bridgeRequests.map((request) => request.requestId));
      assert.equal(new Set(ids).size, ids.length, 'counter-derived IDs stay unique within one native view');
      assert.ok(ids.every((id) => /^[0-9a-f-]{36}$/i.test(id)));
      await page.close();
    });

    await test('lowercase authorization revocation clears private rows and ignores raw server prose', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('actor:mp-owner');
      await page.getByRole('button', { name: /refresh relationship history/i }).click();
      let request = await latestRequest(page, 'mp.relationships.read');
      await respond(page, request, { perspectiveActorKey: 'actor:mp-owner', records: [{ id: 'private-1', owner: 'Countess Example', target: 'Captain Snide', at: 'today', proposedDelta: 1, appliedDelta: 1, historicalBefore: 0, historicalAfter: 1, currentValue: 1, currentAsOf: 'now', sourceKind: 'review', fixedReason: 'PRIVATE_ROW_SENTINEL', kind: 'relationship', confirmation: 'confirmed' }], limited: false, nextCursor: null });
      await page.getByText('PRIVATE_ROW_SENTINEL', { exact: true }).waitFor();
      await page.getByRole('button', { name: /refresh relationship history/i }).click();
      request = await latestRequest(page, 'mp.relationships.read');
      await respond(page, request, null, { ok: false, error: { code: 'unauthorized', message: 'RAW_PRIVATE_BACKEND_PROSE_SENTINEL' } });
      await page.locator('#mp-state').getByText(/authentication is required/i).waitFor();
      assert.equal(await page.getByText('PRIVATE_ROW_SENTINEL', { exact: true }).count(), 0);
      assert.equal(await page.locator('body').innerText().then((value) => value.includes('RAW_PRIVATE_BACKEND_PROSE_SENTINEL')), false);
      await page.close();
    });

    await test('drops a just-resolved read when a fresh view initializes before Promise consumers run', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('actor:mp-owner');
      await page.getByRole('button', { name: /refresh relationship history/i }).click();
      const request = await latestRequest(page, 'mp.relationships.read');
      const row = { id: 'stale-row', owner: 'Countess Example', target: 'Captain Snide', at: 'now', proposedDelta: 0, appliedDelta: 0, historicalBefore: 0, historicalAfter: 0, currentValue: 0, currentAsOf: 'now', sourceKind: 'review', fixedReason: 'STALE_PRIVATE_SENTINEL', kind: 'relationship', confirmation: 'confirmed' };
      const response = { version: 1, requestId: request.requestId, operation: request.operation, context: request.context, ok: true,
        data: { perspectiveActorKey: 'actor:mp-owner', records: [row], limited: false, nextCursor: null } };
      const nextToken = newUuid();
      await page.evaluate(({ response, viewToken }) => {
        window.departmentReceive(JSON.stringify(response));
        window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', serverContext: null }));
      }, { response, viewToken: nextToken });
      await page.waitForFunction(() => window.__bridgeRequests.filter((item) => item.operation === 'bootstrap').length === 2);
      await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 0)));
      assert.equal(await page.locator('body').innerText().then((value) => value.includes('STALE_PRIVATE_SENTINEL')), false);
      await page.close();
    });

    await test('MP keeps zero deltas and historical values separate from current; S&S separates types', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
      await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('actor:mp-owner');
      const targetFilter = page.getByLabel(/Target filter/);
      assert.equal(await targetFilter.evaluate((node) => node.tagName), 'SELECT', 'users choose a label; no raw actor-key text field is exposed');
      assert.equal(await targetFilter.locator('option').filter({ hasText: 'Captain Snide' }).count(), 1);
      await targetFilter.selectOption('npc:captain');
      await page.getByRole('button', { name: /refresh relationship history/i }).click();
      const request = await latestRequest(page, 'mp.relationships.read');
      assert.equal(request.payload.perspectiveActorKey, 'actor:mp-owner');
      assert.equal(request.payload.targetActorKey, 'npc:captain', 'the visible target label should submit only its internal bounded key');
      const mpRecord = { id: 'record-1', owner: 'Countess Example', target: 'Captain Snide', at: 'day 4', proposedDelta: 0, appliedDelta: 0, historicalBefore: 0, historicalAfter: 0, currentValue: -2, currentAsOf: 'day 9', sourceKind: 'review', fixedReason: 'Zero change', kind: 'arrangement', confirmation: 'confirmed' };
      await respond(page, request, { perspectiveActorKey: 'actor:mp-owner', records: [mpRecord, { ...mpRecord, id: 'record-2', proposedDelta: null, appliedDelta: null, historicalBefore: 'Acquaintance', historicalAfter: 'Friend', currentValue: 'Friend', kind: 'relationship' }, { ...mpRecord, id: 'record-3', historicalBefore: 'none', historicalAfter: 'active', currentValue: null }, { ...mpRecord, id: 'record-4', historicalBefore: 'active', historicalAfter: 'ended', currentValue: 'ended' }, { ...mpRecord, id: 'record-5', historicalBefore: 'active', historicalAfter: 'reaffirmed', currentValue: 'reaffirmed' }, { ...mpRecord, id: 'record-6', historicalBefore: null, historicalAfter: 'none', currentValue: 'none' }], limited: false, nextCursor: null });
      await page.locator('#mp-rows tr').first().getByText('Zero change', { exact: true }).waitFor();
      const mpRows = await page.locator('#mp-rows').innerText();
      assert.equal(await page.locator('#mp-rows tr').count(), 6, 'zero-delta and typed arrangement rows stay in the bounded result');
      assert.match(mpRows, /0 \/ 0/);
      assert.ok(mpRows.includes('Acquaintance → Friend'));
      assert.ok(mpRows.includes('Unknown (not recorded) → none'), 'unknown must stay distinct from the explicit none state');
      assert.ok(mpRows.includes('active → ended'));
      assert.ok(mpRows.includes('active → reaffirmed'));
      await page.getByRole('tab', { name: 'Sworn & Scorned' }).click();
      await page.getByRole('tabpanel', { name: 'Sworn & Scorned' }).getByLabel('Perspective').selectOption('actor:sas-owner');
      await page.getByRole('button', { name: /refresh grievances and promises/i }).click();
      const sas = await latestRequest(page, 'sas.issues.read');
      await respond(page, sas, { perspectiveActorKey: 'actor:sas-owner', issues: [{ id: 'issue-1', kind: 'grievance', owner: 'Captain Snide', counterpart: 'Baron Wit', direction: 'owed by counterpart', matter: 'Insult at court', nature: 'public insult', status: 'open', settlement: 'unknown' }, { id: 'issue-2', kind: 'promise', owner: 'Captain Snide', counterpart: 'Baron Wit', direction: 'owed by owner', matter: 'Escort promised', nature: 'service', status: 'open', settlement: 'not settled' }], limited: false, nextCursor: null });
      await page.getByText('Insult at court', { exact: true }).waitFor();
      await page.getByText('Escort promised', { exact: true }).waitFor();
      assert.equal(await page.getByRole('heading', { name: 'Grievances', exact: true }).count(), 1);
      assert.equal(await page.getByRole('heading', { name: 'Promises', exact: true }).count(), 1);
      await page.close();
    });

    await test('Sealed Fates uses authorized writer/recipient and does not equate queued with delivered', async () => {
      const page = await authenticatedPage(browser);
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([{ actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true }, { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false }]));
      await respond(page, inventory, { inventoryRevision: 'inventory-1', items: [{ itemKey: 'form:plain', label: 'Plain parchment', availableQuantity: 1 }], gold: { available: 10 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Letter body').fill('A short letter.');
      await page.getByRole('button', { name: /review letter/i }).click();
      await page.getByText(/Payer: Player/i).waitFor();
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      assert.equal(compose.payload.writerActorKey, 'npc:1');
      assert.equal(compose.payload.recipientActorKey, 'npc:2');
      assert.ok(compose.payload.operationId);
      await respond(page, compose, { operationId: compose.payload.operationId, state: 'queued', receipt: { id: 'receipt-1', state: 'queued' } });
      await page.locator('#letter-receipt').getByText(/queued/i).waitFor();
      assert.equal(await page.getByText(/delivered/i).count(), 0);
      await page.close();
    });

    await test('scheduler-owned and operator-closed compose uncertainty stays locked until a receipt confirms delivery', async () => {
      const page = await authenticatedPage(browser, 'scope-letter-scheduler-owned', 'T'.repeat(43));
      await prepareComposer(page, 'A private scheduler-owned letter.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const compose = await latestRequest(page, 'correspondence.compose');
      await respond(page, compose, { operationId: compose.payload.operationId, state: 'queued', companionSchedulerOwned: true });

      let recovery = await page.locator('#letter-recovery-status').innerText();
      assert.match(recovery, /queued/i);
      assert.match(recovery, /Sealed Fates handles this letter automatically.*Do not send it again/i);
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId);
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);
      assert.equal(await page.locator('#letter-send').isDisabled(), true);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'scheduler ownership never sends a second compose request');

      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      let reconcile = await latestRequest(page, 'correspondence.reconcile');
      assert.equal(reconcile.payload.operationId, compose.payload.operationId);
      await respond(page, reconcile, { operationId: compose.payload.operationId, state: 'unknown', operatorClosed: true });
      recovery = await page.locator('#letter-recovery-status').innerText();
      assert.match(recovery, /Sealed Fates handles this letter automatically.*Do not send it again/i, 'an omitted optional field preserves prior trusted metadata');
      assert.match(recovery, /closed without a delivery confirmation.*check whether it arrived/i);
      assert.doesNotMatch(recovery, /scheduler|operator/i, 'internal ownership details are not exposed');
      assert.doesNotMatch(await page.locator('#letter-operation option:checked').innerText(), /closed/i,
        'operator closure is metadata, not a replacement operation state');
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);
      assert.equal(await page.locator('#letter-send').isDisabled(), true);
      await saveScreenshot(page, letterRecoveryScreenshotPath);

      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      reconcile = await latestRequest(page, 'correspondence.reconcile');
      await respond(page, reconcile, {
        operationId: compose.payload.operationId, state: 'unknown', companionSchedulerOwned: false, operatorClosed: 'closed',
      });
      recovery = await page.locator('#letter-recovery-status').innerText();
      assert.match(recovery, /Sealed Fates handles this letter automatically.*Do not send it again/i, 'invalid optional metadata cannot erase earlier valid metadata');
      assert.match(recovery, /closed without a delivery confirmation.*check whether it arrived/i);
      assert.equal(await page.locator('#letter-operation').inputValue(), compose.payload.operationId);
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);
      assert.equal(await page.locator('#letter-send').isDisabled(), true);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1,
        'unknown and malformed results never trigger a retry');

      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      reconcile = await latestRequest(page, 'correspondence.reconcile');
      await respond(page, reconcile, { operationId: compose.payload.operationId, state: 'delivered', receipt: { deliveryConfirmed: true, receivedAt: 'day 20' } });
      assert.match(await page.locator('#letter-receipt').innerText(), /delivery receipt confirmed.*received day 20/i);
      assert.equal(await page.locator('#letter-reconcile').isHidden(), true, 'a valid late receipt resolves the original operation');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 1);

      await prepareComposer(page, 'A separate operation without scheduler ownership.');
      await page.getByRole('button', { name: /send sealed letter/i }).click();
      const nextCompose = await latestRequest(page, 'correspondence.compose');
      await respond(page, nextCompose, { operationId: nextCompose.payload.operationId, state: 'queued' });
      recovery = await page.locator('#letter-recovery-status').innerText();
      assert.doesNotMatch(recovery, /Sealed Fates handles this letter automatically|closed without a delivery confirmation/i, 'metadata does not carry into a new operation');
      await page.getByRole('button', { name: /reconcile same operation/i }).click();
      reconcile = await latestRequest(page, 'correspondence.reconcile');
      await respond(page, reconcile, {
        operationId: compose.payload.operationId, state: 'unknown', companionSchedulerOwned: true, operatorClosed: true,
      });
      recovery = await page.locator('#letter-recovery-status').innerText();
      assert.doesNotMatch(recovery, /Sealed Fates handles this letter automatically|closed without a delivery confirmation/i, 'a stale result cannot attach metadata to another operation');
      assert.equal(await page.locator('#letter-operation').inputValue(), nextCompose.payload.operationId);
      assert.equal(await page.getByLabel('Letter body').isDisabled(), true);
      assert.equal(await page.locator('#letter-send').isDisabled(), true);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'correspondence.compose').length), 2,
        'a stale result never replays either payload');
      await page.close();
    });

    await test('nearby-cast guidance explains missing and stale CHIM reports without requiring a first message', async () => {
      const page = await authenticatedPage(browser);
      assert.equal(await page.locator('#connection-status').innerText(), 'Connected to CHIM');
      assert.match(await page.locator('#scope-footer').innerText(), /Connected to CHIM/);
      await page.getByRole('tab', { name: 'Private Conversation' }).click();
      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      let roster = await latestRequest(page, 'pcv.roster.read');
      let status = await latestRequest(page, 'pcv.status.read');
      await respond(page, roster, { revision: 'missing-cast', state: 'missing', actors: [], limited: false });
      await respond(page, status, { state: 'unavailable', activeConfigId: null, pendingConfigId: null, pending: null, active: null });
      const missing = await page.locator('#pcv-state').innerText();
      assert.match(missing, /CHIM has not supplied a nearby-actor report yet/i);
      assert.match(missing, /updates automatically/i);
      assert.match(missing, /do not need to message CHIM first/i);

      await page.getByRole('button', { name: /refresh cast and status/i }).click();
      roster = await latestRequest(page, 'pcv.roster.read');
      status = await latestRequest(page, 'pcv.status.read');
      await respond(page, roster, { revision: 'stale-cast', state: 'stale', actors: [], limited: false });
      await respond(page, status, { state: 'unavailable', activeConfigId: null, pendingConfigId: null, pending: null, active: null });
      const stale = await page.locator('#pcv-state').innerText();
      assert.match(stale, /CHIM has not supplied a recent nearby-actor report/i);
      assert.match(stale, /updates automatically/i);
      assert.match(stale, /do not need to message CHIM first/i);
      await page.close();
    });

    await test('full 128-item inventory scrolls independently, controller reveals focus, and review remains reachable', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, authenticatedData('inventory-layout-scope', 'A'.repeat(43)));
      await page.setViewportSize({ width: 1280, height: 720 });
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      await respond(page, catalog, catalogPage([
        { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: false, canSignAs: false },
        { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
        { actorKey: 'npc:3', label: 'Baron Example', canWrite: false, canReceive: false, canSignAs: true },
      ]));
      const items = Array.from({ length: 128 }, (_, index) => ({
        itemKey: `form:fixture-${String(index + 1).padStart(3, '0')}`,
        label: `Bounded Item ${String(index + 1).padStart(3, '0')}`,
        availableQuantity: 3,
      }));
      await respond(page, inventory, { inventoryRevision: 'inventory-layout-128', items, gold: { available: 500 } });
      assert.equal(await page.locator('#inventory-status').innerText(), 'Inventory refreshed.');
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
      await page.getByLabel('Sign as someone else').check();
      await page.getByLabel('Signature shown on the letter').selectOption('npc:3');
      await page.getByLabel('Letter body').fill('A bounded fixture letter for review.');
      await page.getByLabel('Attach Bounded Item 001').check();
      await page.getByLabel('Gold').fill('12');

      const list = await page.locator('#inventory-items').evaluate((node) => ({
        rowCount: node.querySelectorAll('.inventory-row').length,
        maxHeight: getComputedStyle(node).maxHeight,
        overflowY: getComputedStyle(node).overflowY,
        clientHeight: node.clientHeight,
        scrollHeight: node.scrollHeight,
      }));
      assert.equal(list.rowCount, 128, 'the complete accepted inventory count is rendered');
      assert.equal(list.maxHeight, '220px');
      assert.equal(list.overflowY, 'auto');
      assert.ok(list.scrollHeight > list.clientHeight, `inventory has an independent vertical scroll area: ${JSON.stringify(list)}`);

      await page.evaluate(() => {
        const items = [...document.querySelectorAll('.inventory-check')];
        const list = document.getElementById('inventory-items');
        window.__focusRevealTargets = [];
        const original = Element.prototype.scrollIntoView;
        Element.prototype.scrollIntoView = function scrollIntoView(options) {
          window.__focusRevealTargets.push(this);
          return original.call(this, options);
        };
        items.at(-2).focus({ preventScroll: true });
        list.scrollTop = 0;
      });
      await page.evaluate(({ viewToken }) => window.departmentNavigate(JSON.stringify({
        version: 1, viewToken, nativeGeneration: '1', command: 'next',
      })), { viewToken: bootstrap.context.viewToken });
      const controllerFocus = await page.evaluate(() => {
        const items = [...document.querySelectorAll('.inventory-check')];
        const list = document.getElementById('inventory-items');
        const last = items.at(-1);
        const rect = last.getBoundingClientRect();
        const clip = list.getBoundingClientRect();
        return {
          focusedLastItem: document.activeElement === last,
          revealedByController: window.__focusRevealTargets.includes(last),
          listScrollTop: list.scrollTop,
          withinListViewport: rect.top >= clip.top && rect.bottom <= clip.bottom,
        };
      });
      assert.equal(controllerFocus.focusedLastItem, true, `controller advances to the final inventory item: ${JSON.stringify(controllerFocus)}`);
      assert.equal(controllerFocus.revealedByController, true, 'controller focus invokes the shared reveal behavior');
      assert.ok(controllerFocus.listScrollTop > 0, `focused inventory row scrolls its own list: ${JSON.stringify(controllerFocus)}`);
      assert.equal(controllerFocus.withinListViewport, true, `focused row is inside the inventory viewport: ${JSON.stringify(controllerFocus)}`);

      const review = page.locator('#letter-review');
      await review.scrollIntoViewIfNeeded();
      const reviewRect = await review.boundingBox();
      assert.ok(reviewRect && reviewRect.y >= 0 && reviewRect.y + reviewRect.height <= 720,
        `Review action is fully reachable at 1280x720: ${JSON.stringify(reviewRect)}`);
      await review.click();
      const summary = await page.locator('#letter-review-summary').innerText();
      assert.match(summary, /Payer: Player/);
      assert.match(summary, /Claimed signature: Baron Example/);
      assert.match(summary, /Items: Bounded Item 001 × 1/);
      assert.match(await page.locator('#signature-wrap').innerText(), /A signature is a claim/i);
      assert.equal(await page.evaluate(() => window.__bridgeRequests.some((item) => item.operation === 'correspondence.compose')), false,
        'reviewing a letter does not submit it');
      assert.equal(await page.evaluate(() => window.__bridgeRequests.some((item) => item.operation === 'correspondence.inventory.read')), true);
      await page.close();
    });

    await test('fifth Malpractice Makes Perfect tab and a sixth extension tab wrap, fit, and participate in keyboard navigation', async () => {
      const page = await openPage(browser, fifthTabDocument());
      await page.setViewportSize({ width: 1280, height: 720 });
      const wide = await page.locator('.tabs [role="tab"]').evaluateAll((nodes) => nodes.map((node) => {
        const rect = node.getBoundingClientRect();
        return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
      }));
      assert.equal(wide.length, 6, 'Malpractice Makes Perfect is the fifth built-in tab before the fixture extension tab');
      assert.equal(new Set(wide.map((rect) => rect.top)).size, 1, 'all six tabs fit on one row at 1280px');
      assert.ok(wide.every((rect) => rect.left >= 0 && rect.right <= 1280 && rect.bottom <= 720), `wide tabs remain in bounds: ${JSON.stringify(wide)}`);

      await page.setViewportSize({ width: 900, height: 720 });
      const compact = await page.locator('.tabs [role="tab"]').evaluateAll((nodes) => nodes.map((node) => {
        const rect = node.getBoundingClientRect();
        return { left: rect.left, right: rect.right, top: rect.top, bottom: rect.bottom };
      }));
      assert.ok(new Set(compact.map((rect) => rect.top)).size >= 2, `six tabs wrap at a narrower width: ${JSON.stringify(compact)}`);
      assert.ok(compact.every((rect) => rect.left >= 0 && rect.right <= 900 && rect.bottom <= 720), `wrapped tabs remain in bounds: ${JSON.stringify(compact)}`);

      await page.getByRole('tab', { name: 'Sealed Fates' }).focus();
      await page.keyboard.press('ArrowRight');
      const brainwashTab = page.getByRole('tab', { name: 'Malpractice Makes Perfect' });
      assert.equal(await brainwashTab.getAttribute('aria-selected'), 'true', 'Malpractice Makes Perfect follows the four existing built-in tabs');
      await page.keyboard.press('ArrowRight');
      const fixtureTab = page.getByRole('tab', { name: 'Field Notes' });
      assert.equal(await fixtureTab.getAttribute('aria-selected'), 'true');
      assert.equal(await page.getByRole('tabpanel', { name: 'Field Notes' }).isVisible(), true);
      await page.keyboard.press('ArrowRight');
      assert.equal(await page.getByRole('tab', { name: 'Private Conversation' }).getAttribute('aria-selected'), 'true', 'arrow navigation wraps around the registered tabs');
      await page.getByRole('tab', { name: 'Private Conversation' }).focus();
      await page.keyboard.press('End');
      assert.equal(await fixtureTab.getAttribute('aria-selected'), 'true', 'End reaches the sixth registered tab');
      await page.keyboard.press('ArrowLeft');
      assert.equal(await brainwashTab.getAttribute('aria-selected'), 'true', 'Malpractice Makes Perfect is immediately before the fixture extension tab');
      await page.close();
    });

    await test('Inspector can read a catalog-authorized inbox without composition eligibility', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, authenticatedData('inspector-only', 'C'.repeat(43), {
        capabilities: { correspondence: true },
        access: { pcv: false, inspector: true, compose: false, authorDetail: false },
      }));
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const catalog = await latestRequest(page, 'correspondence.catalog.read');
      assert.ok(catalog);
      await respond(page, catalog, catalogPage([{ actorKey: 'npc:104', label: 'Retired Envoy', canWrite: false, canReceive: false, canSignAs: false }]));
      await page.getByRole('button', { name: 'Mailboxes' }).click();
      const mailbox = page.getByLabel('Recipient mailbox');
      assert.equal(await mailbox.isDisabled(), false);
      await mailbox.selectOption('npc:104');
      await page.getByRole('button', { name: 'Refresh mailbox' }).click();
      const request = await latestRequest(page, 'correspondence.mailbox.read');
      assert.equal(request.payload.recipientActorKey, 'npc:104');
      await respond(page, request, { recipientActorKey: 'npc:104', letters: [{ letterId: 'letter-archive-1', claimedSignature: 'Retired Envoy', receivedAt: 'day 12', bodyPreview: 'An archived note.', attachments: [], deliveryConfirmed: true }], limited: false, nextCursor: null });
      await page.getByText('An archived note.', { exact: true }).waitFor();
      await page.getByRole('button', { name: /letter signed as retired envoy/i }).click();
      const detail = await latestRequest(page, 'correspondence.detail.read');
      assert.equal(detail.payload.letterId, 'letter-archive-1');
      await respond(page, detail, { letterId: 'letter-archive-1', claimedSignature: 'Retired Envoy', receivedAt: 'day 12', body: 'An archived note with detail.', attachments: [], deliveryConfirmed: true });
      await page.getByText('An archived note with detail.', { exact: true }).waitFor();
      assert.equal(await page.evaluate(() => window.__bridgeRequests.some((item) => item.operation === 'correspondence.compose')), false);
      await page.close();
    });

    await test('catalog loads additional actors by revision-bound pages without replacing existing choices', async () => {
      const page = await authenticatedPage(browser, 'catalog-pagination', 'D'.repeat(43));
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const first = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      assert.ok(inventory, 'compose access requests inventory alongside the actor catalog');
      assert.deepEqual(first.payload, { limit: 100 });
      const firstActors = Array.from({ length: 100 }, (_, index) => ({
        actorKey: `npc:${index + 1}`, label: `Eligible NPC ${String(index + 1).padStart(3, '0')}`,
        canWrite: true, canReceive: true, canSignAs: true,
      }));
      await respond(page, first, catalogPage(firstActors, { nextCursor: 'cursor-page-2' }));
      await respond(page, inventory, { inventoryRevision: 'catalog-pagination-inventory', items: [], gold: { available: 0 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      const more = page.getByRole('button', { name: /load more actors/i });
      await more.waitFor();
      assert.equal(await more.isDisabled(), false);
      await more.click();
      const second = await latestRequest(page, 'correspondence.catalog.read');
      assert.deepEqual(second.payload, { limit: 100, cursor: 'cursor-page-2' });
      const secondActors = Array.from({ length: 3 }, (_, index) => ({
        actorKey: `npc:${index + 101}`, label: `Eligible NPC ${String(index + 101).padStart(3, '0')}`,
        canWrite: true, canReceive: true, canSignAs: true,
      }));
      await respond(page, second, catalogPage(secondActors, { nextCursor: null }));
      assert.equal(await page.locator('#letter-writer option').count(), 104, 'the second page appends all actors after the placeholder');
      assert.equal(await page.getByLabel('Writer').inputValue(), 'npc:1', 'an existing deliberate selection is preserved');
      await page.getByLabel('Writer').selectOption('npc:103');
      assert.equal(await page.getByLabel('Writer').inputValue(), 'npc:103', 'the final actor beyond the first 100 is reachable');
      assert.equal(await more.isDisabled(), true, 'a terminal page disables load-more');
      await page.close();
    });

    await test('catalog revision mismatch clears stale choices and pagination cursor', async () => {
      const page = await authenticatedPage(browser, 'catalog-stale-page', 'E'.repeat(43));
      await page.getByRole('tab', { name: 'Sealed Fates' }).click();
      await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
      const first = await latestRequest(page, 'correspondence.catalog.read');
      const inventory = await latestRequest(page, 'correspondence.inventory.read');
      assert.ok(inventory, 'compose access requests inventory alongside the actor catalog');
      const actors = [{ actorKey: 'npc:1', label: 'Old Actor', canWrite: true, canReceive: true, canSignAs: true }];
      await respond(page, first, catalogPage(actors, { nextCursor: 'cursor-stale' }));
      await respond(page, inventory, { inventoryRevision: 'catalog-stale-inventory', items: [], gold: { available: 0 } });
      await page.getByLabel('Writer').selectOption('npc:1');
      await page.getByRole('button', { name: /load more actors/i }).click();
      const second = await latestRequest(page, 'correspondence.catalog.read');
      await respond(page, second, catalogPage([{ actorKey: 'npc:2', label: 'New Actor', canWrite: true, canReceive: true, canSignAs: true }], {
        revision: 'b'.repeat(64), nextCursor: null,
      }));
      assert.equal(await page.locator('#letter-writer option').count(), 1, 'stale catalog entries are removed');
      assert.equal(await page.getByLabel('Writer').inputValue(), '', 'a stale selection cannot remain eligible');
      assert.equal(await page.getByRole('button', { name: /load more actors/i }).isDisabled(), true, 'stale cursor is discarded');
      assert.match(await page.locator('#mail-state').innerText(), /changed.*refresh/i);
      await page.close();
    });

    await test('supports arrow-key navigation across tab roles', async () => {
      const page = await openPage(browser);
      await page.getByRole('tab', { name: 'Private Conversation' }).focus();
      await page.keyboard.press('ArrowRight');
      assert.equal(await page.getByRole('tab', { name: 'Mind Poisoning' }).getAttribute('aria-selected'), 'true');
      await page.close();
    });

    await test('native navigation accepts only current view commands and close works without an authorized session', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, { authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
        capabilities: { pcv: false, mp: false, sas: false, correspondence: false, brainwash: false },
        access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
      });
      await page.getByRole('button', { name: 'Hide scene settings' }).focus();
      await page.evaluate(({ viewToken }) => window.departmentNavigate(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', command: 'activate' })), { viewToken: bootstrap.context.viewToken });
      assert.equal(await page.locator('#pcv-settings-toggle').getAttribute('aria-expanded'), 'false', 'native Activate toggles the settings while the view is unpaired');
      await page.evaluate(({ viewToken }) => window.departmentNavigate(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', command: 'next' })), { viewToken: bootstrap.context.viewToken });
      assert.equal(await page.evaluate(() => document.getElementById('pcv-config-fields').contains(document.activeElement)), false, 'controller focus skips all hidden scene-setting descendants');
      await page.getByRole('tab', { name: 'Private Conversation' }).focus();
      const token = bootstrap.context.viewToken;
      await page.evaluate(({ viewToken, generation }) => window.departmentNavigate(JSON.stringify({ version: 1, viewToken, nativeGeneration: generation, command: 'right' })), { viewToken: token, generation: '1' });
      assert.equal(await page.getByRole('tab', { name: 'Mind Poisoning' }).getAttribute('aria-selected'), 'true');
      await page.evaluate(({ viewToken }) => window.departmentNavigate(JSON.stringify({ version: 1, viewToken, nativeGeneration: '0', command: 'right' })), { viewToken: token });
      assert.equal(await page.getByRole('tab', { name: 'Mind Poisoning' }).getAttribute('aria-selected'), 'true', 'stale native generation must not navigate');
      await page.getByRole('button', { name: 'Close' }).click();
      const close = await latestRequest(page, 'view.close');
      assert.ok(close, 'close must route through native bridge even when auth is unavailable');
      assert.equal(close.context.serverContext, null);
      await page.evaluate(({ viewToken }) => window.departmentNavigate(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', command: 'back' })), { viewToken: token });
      assert.equal(await page.evaluate(() => window.__bridgeRequests.filter((request) => request.operation === 'view.close').length), 1);
      await page.close();
    });

    await test('close remains local and callable after bootstrap authorization fails', async () => {
      const page = await openPage(browser);
      const bootstrap = await initialize(page);
      await respond(page, bootstrap, null, { ok: false, error: { code: 'unauthorized', message: 'Safe fixed denial.' } });
      await page.locator('#connection-status').getByText(/not authorized/i).waitFor();
      await page.getByRole('button', { name: 'Close' }).click();
      const close = await latestRequest(page, 'view.close');
      assert.ok(close);
      assert.equal(close.context.serverContext, null);
      assert.equal(await page.getByRole('button', { name: 'Close' }).isDisabled(), true);
      await page.close();
    });

    await browser.close();
  }

  const failed = results.filter((result) => result.status === 'failed');
  const summary = { total: results.length, passed: results.length - failed.length, failed: failed.length, tests: results };
  const serialized = `${JSON.stringify(summary, null, 2)}\n`;
  process.stdout.write(serialized);
  if (resultPath) fs.writeFileSync(resultPath, serialized, 'utf8');
  if (failed.length) process.exitCode = 1;
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
