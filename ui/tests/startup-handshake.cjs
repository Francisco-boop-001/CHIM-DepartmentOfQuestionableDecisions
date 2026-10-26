'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightRoot = process.env.PLAYWRIGHT_MODULE_ROOT;
if (!playwrightRoot) throw new Error('Set PLAYWRIGHT_MODULE_ROOT to the existing local Node modules directory.');
const { chromium } = require(require.resolve('playwright', { paths: [playwrightRoot] }));
const browserPath = process.env.PRISMA_TEST_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const screenshotDirectory = process.env.UI_STARTUP_SCREENSHOT_DIR;
const pagePath = path.resolve(__dirname, '..', 'index.html');
const results = [];
let tokenCounter = 1;

function uuid() {
  return `00000000-0000-4000-8000-${String(tokenCounter++).padStart(12, '0')}`;
}

async function test(name, run) {
  try {
    await run();
    results.push({ name, status: 'passed' });
  } catch (error) {
    results.push({ name, status: 'failed', error: String(error.message || error) });
  }
}

async function openPage(browser, watchdog = 'normal') {
  const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });
  page.setDefaultTimeout(4000);
  const externalRequests = [];
  page.on('request', (request) => {
    if (!request.url().startsWith('file://')) externalRequests.push(request.url());
  });
  await page.addInitScript(({ watchdog }) => {
    const nativeSetTimeout = window.setTimeout.bind(window);
    const nativeClearTimeout = window.clearTimeout.bind(window);
    window.__bridgeRequests = [];
    window.__startupWatchdogCallback = null;
    window.__startupWatchdogId = null;
    window.__startupWatchdogCleared = false;
    window.setTimeout = (callback, delay, ...args) => {
      let effectiveDelay = delay;
      if (delay === 20000 && window.__startupWatchdogCallback === null) {
        window.__startupWatchdogCallback = () => callback(...args);
        if (watchdog === 'fire') effectiveDelay = 0;
        if (watchdog === 'hold') effectiveDelay = 2147483647;
        const id = nativeSetTimeout(callback, effectiveDelay, ...args);
        window.__startupWatchdogId = id;
        return id;
      }
      return nativeSetTimeout(callback, effectiveDelay, ...args);
    };
    window.clearTimeout = (id) => {
      if (id === window.__startupWatchdogId) window.__startupWatchdogCleared = true;
      return nativeClearTimeout(id);
    };
    window.PRISMA = Object.freeze({ testSentinel: true });
    window.departmentRequest = (raw) => window.__bridgeRequests.push(JSON.parse(raw));
  }, { watchdog });
  await page.goto(pathToFileURL(pagePath).href);
  page.__externalRequests = externalRequests;
  return page;
}

async function operation(page, name, count = 1) {
  await page.waitForFunction(({ name, count }) => window.__bridgeRequests.filter((item) => item.operation === name).length >= count,
    { name, count }, { timeout: 1500 });
  return page.evaluate(({ name, count }) => window.__bridgeRequests.filter((item) => item.operation === name)[count - 1], { name, count });
}

async function operationCount(page, name) {
  return page.evaluate((name) => window.__bridgeRequests.filter((item) => item.operation === name).length, name);
}

async function initialize(page, viewToken = uuid(), nativeGeneration = '1', count = 1) {
  const init = { version: 1, viewToken, nativeGeneration, serverContext: null };
  const accepted = await page.evaluate((init) => window.departmentInitialize(JSON.stringify(init)), init);
  assert.equal(accepted, true, 'native initialization is accepted');
  const bootstrap = await operation(page, 'bootstrap', count);
  return { ...init, bootstrap };
}

async function receive(page, request, data) {
  await page.evaluate(({ request, data }) => window.departmentReceive(JSON.stringify({
    version: request.version, requestId: request.requestId, operation: request.operation,
    context: request.context, ok: true, data,
  })), { request, data });
}

async function receiveError(page, request, error) {
  return page.evaluate(({ request, error }) => window.departmentReceive(JSON.stringify({
    version: request.version, requestId: request.requestId, operation: request.operation,
    context: request.context, ok: false, error,
  })), { request, error });
}

function authenticatedData(serverContext = 'startup-retained-scope') {
  return {
    authenticated: true, authRequired: false, serverContext, scopeToken: 'A'.repeat(43),
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
    access: { pcv: true, inspector: true, compose: true, authorDetail: true, brainwash: true },
    perspectives: { mp: { choices: [], limited: false }, sas: { choices: [], limited: false } },
  };
}

async function verifyLocalPage(page) {
  assert.deepEqual(page.__externalRequests, [], 'the interface makes no browser requests');
  assert.equal(await page.evaluate(() => window.PRISMA.testSentinel), true, 'the page preserves the PRISMA framework object');
}

async function captureScreenshot(page, name) {
  if (!screenshotDirectory) return;
  fs.mkdirSync(screenshotDirectory, { recursive: true });
  await page.screenshot({ path: path.join(screenshotDirectory, name), fullPage: true });
}

async function assertPcvControlsGated(page, reason) {
  for (const id of ['#pcv-refresh', '#pcv-end', '#pcv-text', '#send-target', '#pcv-send', '#pcv-refresh-send']) {
    assert.equal(await page.locator(id).isDisabled(), true, `${id} stays gated ${reason}`);
  }
  assert.equal(await page.locator('#pcv-config-fields').evaluate((fieldset) => fieldset.matches(':disabled')), true,
    `scene settings stay gated ${reason}`);
  assert.equal(await page.locator('#pcv-arm').isDisabled(), true, `#pcv-arm stays gated ${reason}`);
}

async function main() {
  const browser = await chromium.launch({ headless: true, executablePath: browserPath });
  await test('shows local startup state before the native bridge initializes', async () => {
    const page = await openPage(browser);
    assert.equal(await page.locator('#connection-status').innerText(), 'Starting game interface');
    assert.match(await page.locator('#permission-status').innerText(), /local companion bridge/i);
    for (const id of ['pcv-state', 'mp-state', 'sas-state', 'mail-state']) {
      assert.match(await page.locator(`#${id}`).innerText(), /game interface is starting/i, `${id} reports local startup before authentication`);
    }
    assert.equal(await operationCount(page, 'bootstrap'), 0);
    await verifyLocalPage(page);
    await page.close();
  });

  await test('a missing bridge reports bounded local startup guidance', async () => {
    const page = await openPage(browser, 'fire');
    await page.waitForFunction(() => document.getElementById('connection-status').textContent === 'Companion bridge did not start', null, { timeout: 1500 });
    const guidance = await page.locator('#permission-status').innerText();
    assert.match(guidance, /reopen the game interface/i);
    assert.match(guidance, /WorldOfDramaLlama\.log/);
    assert.doesNotMatch(`${await page.locator('#connection-status').innerText()} ${guidance}`, /website|browser|approval|provision/i);
    for (const id of ['pcv-state', 'mp-state', 'sas-state', 'mail-state']) {
      assert.match(await page.locator(`#${id}`).innerText(), /Companion bridge did not start/i, `${id} reports local startup failure`);
      assert.match(await page.locator(`#${id}`).innerText(), /WorldOfDramaLlama\.log/);
    }
    assert.equal(await operationCount(page, 'bootstrap'), 0);
    await verifyLocalPage(page);
    await page.close();
  });

  await test('a native bootstrap invalid_response gives safe component-mismatch guidance', async () => {
    const page = await openPage(browser, 'hold');
    const current = await initialize(page);
    const accepted = await receiveError(page, current.bootstrap, {
      code: 'invalid_response', message: 'The Department server returned invalid access information.',
    });
    assert.equal(accepted, true, 'the native error envelope is accepted for the pending bootstrap');
    await page.locator('#connection-status').getByText(/game mod and server package do not match/i).waitFor();
    const status = await page.locator('#connection-status').innerText();
    assert.match(status, /reinstall the Department game mod and included server package from the same release/i);
    assert.match(status, /restart Skyrim and CHIM/i);
    assert.doesNotMatch(status, /invalid access information/i, 'the native message and server detail stay hidden');
    assert.match(await page.locator('#permission-status').innerText(), /Retry connection/i);
    await verifyLocalPage(page);
    await page.close();
  });

  await test('granted PCV access with an unready dependency gets separate guidance and stays gated', async () => {
    const page = await openPage(browser, 'hold');
    const current = await initialize(page);
    const data = authenticatedData();
    data.capabilities.pcv = false;
    await receive(page, current.bootstrap, data);
    const status = await page.locator('#pcv-state').innerText();
    assert.match(status, /supported CHIM or Private Conversation \(PCV\) dependency is unavailable/i);
    assert.match(status, /check that both CHIM and Private Conversation are supported for this Department release/i);
    assert.match(status, /install compatible supported versions as needed/i);
    assert.doesNotMatch(status, /browser|manual.?token/i);
    await assertPcvControlsGated(page, 'until PCV is ready');
    await captureScreenshot(page, 'pcv-unready-dependency.png');
    await verifyLocalPage(page);
    await page.close();
  });

  await test('PCV permission denial stays distinct from dependency readiness and remains gated', async () => {
    for (const capabilityPcv of [true, false]) {
      const page = await openPage(browser, 'hold');
      const current = await initialize(page);
      const data = authenticatedData();
      data.capabilities.pcv = capabilityPcv;
      data.access.pcv = false;
      await receive(page, current.bootstrap, data);
      const status = await page.locator('#pcv-state').innerText();
      assert.match(status, /session.*(?:does not have|lacks).*Private Conversation permission/i);
      assert.doesNotMatch(status, /dependency|install/i);
      await assertPcvControlsGated(page, 'without PCV permission');
      if (!capabilityPcv) await captureScreenshot(page, 'pcv-permission-denied.png');
      await verifyLocalPage(page);
      await page.close();
    }
  });

  await test('unauthenticated PCV remains gated while CHIM setup is pending', async () => {
    const page = await openPage(browser, 'hold');
    const current = await initialize(page);
    await receive(page, current.bootstrap, {
      authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
      capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
      access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
    });
    await operation(page, 'connection.begin');
    assert.match(await page.locator('#pcv-state').innerText(), /Private Conversation becomes available after CHIM connects/i);
    await assertPcvControlsGated(page, 'before CHIM authentication');
    await verifyLocalPage(page);
    await page.close();
  });

  const protocolErrors = [
    ['invalid_request', /values are invalid/i],
    ['unsupported_operation', /Department operation is not available/i],
    ['unauthorized', /CHIM connection is not authorized/i],
    ['forbidden', /session does not have permission/i],
    ['stale_context', /game context changed/i],
    ['stale_revision', /saved state changed/i],
    ['conflict', /conflicts with current CHIM state/i],
    ['not_found', /requested item could not be found/i],
    ['unavailable', /feature is unavailable right now/i],
    ['unsupported_media_type', /CHIM rejected the request format/i],
    ['method_not_allowed', /CHIM rejected the request method/i],
    ['payload_too_large', /request exceeded the server.*size limit/i],
    ['actor_unavailable', /actor is no longer eligible/i],
    ['invalid_input', /values are invalid/i],
    ['busy', /previous operation is still active/i],
    ['rate_limited', /too many requests/i],
    ['too_large', /exceeded the safe display limit/i],
    ['invalid_response', /game mod and server package do not match/i],
    ['connection_setup_required', /server address could not be determined/i],
    ['connection_secure_storage_failed', /could not save this game.*connection/i],
  ];
  for (const [code, expectedCopy] of protocolErrors) {
    await test(`maps ${code} without exposing server text or resending bootstrap`, async () => {
      const page = await openPage(browser, 'hold');
      const current = await initialize(page);
      assert.equal(await receiveError(page, current.bootstrap, { code, message: 'UNTRUSTED_SERVER_DETAIL' }), true);
      const status = await page.locator('#connection-status').innerText();
      assert.match(status, expectedCopy, `wire error ${code} gets its intended local copy`);
      assert.doesNotMatch(status, /UNTRUSTED_SERVER_DETAIL/);
      assert.equal(await operationCount(page, 'bootstrap'), 1, `${code} does not automatically retry bootstrap`);
      assert.equal(await operationCount(page, 'connection.begin'), 0, `${code} does not automatically start connection setup`);
      await verifyLocalPage(page);
      await page.close();
    });
  }

  await test('retained authentication, a pending mutation, and drafts survive duplicate initialization and reopen', async () => {
    const page = await openPage(browser, 'hold');
    const current = await initialize(page);
    assert.equal(await page.evaluate(() => window.__startupWatchdogCleared), true, 'native initialization clears the startup watchdog');
    await receive(page, current.bootstrap, authenticatedData());
    await page.getByRole('tab', { name: 'Sealed Fates' }).click();
    await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
    const catalog = await operation(page, 'correspondence.catalog.read');
    const inventory = await operation(page, 'correspondence.inventory.read');
    await receive(page, catalog, { revision: 'a'.repeat(64), actors: [
      { actorKey: 'npc:1', label: 'Writer', canWrite: true, canReceive: true, canSignAs: true },
      { actorKey: 'npc:2', label: 'Recipient', canWrite: false, canReceive: true, canSignAs: false },
    ], limited: false, nextCursor: null });
    await receive(page, inventory, { inventoryRevision: 'startup-inventory', items: [], gold: { available: 10 } });
    await page.getByLabel('Writer').selectOption('npc:1');
    await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
    await page.getByLabel('Letter body').fill('Retain this pending draft.');
    await page.getByRole('button', { name: /review letter/i }).click();
    await page.getByRole('button', { name: /send sealed letter/i }).click();
    await operation(page, 'correspondence.compose');

    const duplicate = await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
      version: 1, viewToken, nativeGeneration, serverContext: null,
    })), current);
    assert.equal(duplicate, true, 'the current active view is acknowledged idempotently');
    assert.equal(await page.locator('#connection-status').innerText(), 'Connected to CHIM', 'authentication remains active');
    assert.equal(await page.getByLabel('Letter body').inputValue(), 'Retain this pending draft.');
    assert.equal(await operationCount(page, 'bootstrap'), 1, 'duplicate initialization starts no bootstrap');
    assert.equal(await operationCount(page, 'correspondence.compose'), 1, 'duplicate initialization does not replay the pending mutation');
    assert.equal(await operationCount(page, 'connection.begin'), 0, 'retained credentials do not start new automatic setup');

    await page.evaluate(() => window.__startupWatchdogCallback());
    assert.equal(await page.locator('#connection-status').innerText(), 'Connected to CHIM', 'a queued startup timeout cannot change the initialized view');
    await page.evaluate(({ viewToken, nativeGeneration }) => {
      const sendRequest = window.departmentRequest;
      const duplicate = JSON.stringify({ version: 1, viewToken, nativeGeneration, serverContext: null });
      window.departmentRequest = (raw) => {
        sendRequest(raw);
        if (JSON.parse(raw).operation === 'view.close') window.__initializeWhileClosing = window.departmentInitialize(duplicate);
      };
    }, current);
    await page.getByRole('button', { name: 'Close' }).click();
    await operation(page, 'view.close');
    assert.equal(await page.evaluate(() => window.__initializeWhileClosing), false, 'closing views refuse duplicate initialization');
    const closedReplay = await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
      version: 1, viewToken, nativeGeneration, serverContext: null,
    })), current);
    assert.equal(closedReplay, false, 'a previously closed token remains refused');

    const reopened = await initialize(page, uuid(), '2', 2);
    await page.evaluate(() => window.__startupWatchdogCallback());
    assert.equal(await page.locator('#connection-status').innerText(), 'Connecting to CHIM', 'a stale watchdog cannot replace the reopened view status');
    const oldTokenReplay = await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
      version: 1, viewToken, nativeGeneration, serverContext: null,
    })), current);
    assert.equal(oldTokenReplay, false, 'a replayed token remains refused after reopen');
    assert.equal(await operationCount(page, 'bootstrap'), 2);
    const reopenedDuplicate = await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
      version: 1, viewToken, nativeGeneration, serverContext: null,
    })), reopened);
    assert.equal(reopenedDuplicate, true, 'the reopened active view is also idempotent');
    assert.equal(await operationCount(page, 'bootstrap'), 2);
    await verifyLocalPage(page);
    await page.close();
  });

  await test('new setup uses the automatic connection route once and keeps the same view', async () => {
    const page = await openPage(browser, 'hold');
    const current = await initialize(page);
    await receive(page, current.bootstrap, {
      authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
      capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
      access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
    });
    const begin = await operation(page, 'connection.begin');
    assert.match(await page.locator('#mp-state').innerText(), /Authentication is required/i,
      'tab status switches to the confirmed unauthenticated state after bootstrap');
    const duplicate = await page.evaluate(({ viewToken, nativeGeneration }) => window.departmentInitialize(JSON.stringify({
      version: 1, viewToken, nativeGeneration, serverContext: null,
    })), current);
    assert.equal(duplicate, true);
    assert.equal(await operationCount(page, 'connection.begin'), 1, 'duplicate initialization does not restart new setup');
    await receive(page, begin, { state: 'approved' });
    const refreshed = await operation(page, 'bootstrap', 2);
    assert.equal(refreshed.context.viewToken, current.viewToken);
    assert.equal(refreshed.context.nativeGeneration, current.nativeGeneration);
    await receive(page, refreshed, authenticatedData('startup-new-scope'));
    assert.equal(await page.locator('#connection-status').innerText(), 'Connected to CHIM');
    assert.equal(await operationCount(page, 'connection.begin'), 1);
    await verifyLocalPage(page);
    await page.close();
  });

  await browser.close();
  const failed = results.filter((item) => item.status === 'failed');
  process.stdout.write(`${JSON.stringify({ total: results.length, passed: results.length - failed.length, failed: failed.length, tests: results }, null, 2)}\n`);
  if (failed.length) process.exitCode = 1;
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
