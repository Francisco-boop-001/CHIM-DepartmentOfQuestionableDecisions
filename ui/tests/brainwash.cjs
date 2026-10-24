'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightRoot = process.env.PLAYWRIGHT_MODULE_ROOT;
if (!playwrightRoot) throw new Error('Set PLAYWRIGHT_MODULE_ROOT to the existing local Node modules directory.');
const { chromium } = require(require.resolve('playwright', { paths: [playwrightRoot] }));
const browserPath = process.env.PRISMA_TEST_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const uiRoot = path.resolve(__dirname, '..');
const htmlPath = path.join(uiRoot, 'index.html');
let tokenCounter = 1;

function newUuid() {
  return `123e4567-e89b-12d3-a456-${String(tokenCounter++).padStart(12, '0')}`;
}

function testDocument() {
  const html = fs.readFileSync(htmlPath, 'utf8');
  const css = fs.readFileSync(path.join(uiRoot, 'app.css'), 'utf8');
  const app = fs.readFileSync(path.join(uiRoot, 'app.js'), 'utf8');
  assert.ok(!app.includes('</script'), 'test app source must not terminate its inline script');
  const setup = `<script>
    window.PRISMA = Object.freeze({ testSentinel: true });
    window.__bridgeRequests = [];
    window.departmentRequest = (raw) => window.__bridgeRequests.push(JSON.parse(raw));
    window.__brainwashEditorSpy = { mounts: 0, resets: 0, available: false, request: null };
    window.BrainwashEditor = { mount(root, options) {
      const spy = window.__brainwashEditorSpy;
      spy.mounts += 1; spy.request = options.request;
      const status = document.createElement('p'); status.setAttribute('role', 'status'); status.textContent = 'Editor mounted';
      const refresh = document.createElement('button'); refresh.type = 'button'; refresh.textContent = 'Refresh Malpractice Makes Perfect actors'; refresh.disabled = true;
      refresh.addEventListener('click', () => options.request('brainwash.catalog.read', { query: 'Lydia' }).catch(() => {}));
      root.replaceChildren(status, refresh);
      return {
        reset() { spy.resets += 1; refresh.disabled = true; status.textContent = 'Editor reset'; },
        setAvailable(value) { spy.available = value; refresh.disabled = !value; },
      };
    } };
  </script>`;
  const withoutOwnerScript = html.replace('<script src="./brainwash-editor.js" defer></script>', '');
  const withoutOwnerStyles = withoutOwnerScript.replace('<link rel="stylesheet" href="./brainwash-editor.css">', '');
  const withoutAppScript = withoutOwnerStyles.replace('<script src="./app.js" defer></script>', '');
  assert.ok(withoutAppScript.includes('<link rel="stylesheet" href="./app.css">'), 'entrypoint stylesheet marker exists');
  assert.ok(withoutAppScript.includes('</body>'), 'entrypoint body marker exists');
  return withoutAppScript
    .replace('<link rel="stylesheet" href="./app.css">', `<style>${css}</style>`)
    .replace('</body>', `${setup}<script>${app}</script></body>`);
}

async function latestRequest(page, operation) {
  return page.evaluate((name) => window.__bridgeRequests.filter((item) => item.operation === name).at(-1) || null, operation);
}

async function waitForRequest(page, operation, previousCount = 0) {
  await page.waitForFunction(({ operation, previousCount }) => window.__bridgeRequests.filter((item) => item.operation === operation).length > previousCount,
    { operation, previousCount });
  return latestRequest(page, operation);
}

async function respond(page, request, data, error = null) {
  await page.evaluate(({ request, data, error }) => window.departmentReceive(JSON.stringify({
    version: 1, requestId: request.requestId, operation: request.operation, context: request.context,
    ...(error ? { ok: false, error } : { ok: true, data }),
  })), { request, data, error });
}

function bootstrapData(authenticated, scopeToken = 'A'.repeat(43)) {
  return authenticated ? {
    authenticated: true, authRequired: false, serverContext: 'brainwash-test-context', scopeToken,
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true, brainwash: true },
    access: { pcv: true, inspector: true, compose: true, authorDetail: false, brainwash: true },
    perspectives: { mp: { choices: [], limited: false }, sas: { choices: [], limited: false } },
  } : {
    authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
    capabilities: { pcv: false, mp: false, sas: false, correspondence: false, brainwash: false },
    access: { pcv: false, inspector: false, compose: false, authorDetail: false, brainwash: false },
  };
}

async function main() {
  if (!fs.existsSync(browserPath)) throw new Error(`Existing local browser not found: ${browserPath}`);
  const browser = await chromium.launch({ headless: true, executablePath: browserPath });
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 900 } });
    const externalRequests = [];
    page.on('request', (request) => { if (!request.url().startsWith('file://') && !request.url().startsWith('about:')) externalRequests.push(request.url()); });
    await page.setContent(testDocument(), { waitUntil: 'domcontentloaded' });
    await page.evaluate((viewToken) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', serverContext: null })), newUuid());
    const firstBootstrap = await waitForRequest(page, 'bootstrap');
    await respond(page, firstBootstrap, bootstrapData(false));
    assert.equal(await page.getByRole('tab', { name: 'Malpractice Makes Perfect' }).count(), 1, 'Malpractice Makes Perfect is the fifth accessible plugin tab');
    await page.getByRole('tab', { name: 'Malpractice Makes Perfect' }).click();
    assert.equal(await page.getByRole('tabpanel', { name: /Malpractice Makes Perfect/i }).isVisible(), true);
    assert.equal(await page.locator('#panel-brainwash').getByText('Unlicensed character development', { exact: true }).isVisible(), true);
    assert.equal(await page.evaluate(() => window.__brainwashEditorSpy.mounts), 1, 'shared editor mount API is called once');
    assert.equal(await page.evaluate(() => window.__brainwashEditorSpy.available), false, 'unpaired bootstrap keeps editor unavailable');

    const nextView = newUuid();
    const priorBootstrapCount = await page.evaluate(() => window.__bridgeRequests.filter((item) => item.operation === 'bootstrap').length);
    await page.evaluate((viewToken) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '2', serverContext: null })), nextView);
    const secondBootstrap = await waitForRequest(page, 'bootstrap', priorBootstrapCount);
    await respond(page, secondBootstrap, bootstrapData(true));
    assert.equal(await page.evaluate(() => window.__brainwashEditorSpy.available), true, 'fresh Malpractice Makes Perfect capability and scope enable the shared editor');
    await page.getByRole('tab', { name: 'Malpractice Makes Perfect' }).click();
    await page.getByRole('button', { name: 'Refresh Malpractice Makes Perfect actors' }).click();
    const catalogRequest = await waitForRequest(page, 'brainwash.catalog.read');
    assert.deepEqual(catalogRequest.payload, { query: 'Lydia' }, 'editor uses the existing native request transport with its catalog query');

    const longLabelCount = await page.evaluate(() => window.__bridgeRequests.filter((item) => item.operation === 'brainwash.catalog.read').length);
    await page.evaluate(() => {
      window.__brainwashLongLabelPromise = window.__brainwashEditorSpy.request('brainwash.catalog.read', {});
    });
    const longLabelRequest = await waitForRequest(page, 'brainwash.catalog.read', longLabelCount);
    await respond(page, longLabelRequest, { actors: [{ actorKey: 'npc:42', label: 'L'.repeat(512) }], limited: false });
    const longLabelResult = await page.evaluate(async () => {
      try { return (await window.__brainwashLongLabelPromise).actors[0].label.length; }
      catch (error) { return error.code; }
    });
    assert.equal(longLabelResult, 512, 'owner-compatible 512-byte catalog labels pass the response validator');

    await page.evaluate(() => {
      window.__brainwashBeliefOnlyPromise = window.__brainwashEditorSpy.request('brainwash.draft', {
        actorKey: 'npc:42', expectedRevision: 'a'.repeat(64), operationId: '123e4567-e89b-12d3-a456-426614174002',
        mode: 'manual', changes: {}, instruction: '', beliefs: 'A revised fictional belief.',
      });
    });
    const beliefDraftRequest = await waitForRequest(page, 'brainwash.draft');
    await respond(page, beliefDraftRequest, {
      actorKey: 'npc:42', scope: 'profile:1|restore:2', revision: 'a'.repeat(64), draftId: '123e4567-e89b-12d3-a456-426614174003',
      origin: 'manual', before: {}, changes: {}, beliefsBefore: 'A past belief.', beliefs: 'A revised fictional belief.',
    });
    const beliefDraftResult = await page.evaluate(async () => {
      try { const data = await window.__brainwashBeliefOnlyPromise; return data.beliefs; }
      catch (error) { return error.code; }
    });
    assert.equal(beliefDraftResult, 'A revised fictional belief.', 'belief-only draft DTO uses empty object field maps');

    const invalidCatalogRequests = await page.evaluate(async () => {
      const before = window.__bridgeRequests.length;
      const results = [];
      for (const payload of [{ query: 42 }, { query: 'é'.repeat(65) }]) {
        try { await window.__brainwashEditorSpy.request('brainwash.catalog.read', payload); results.push('accepted'); }
        catch (error) { results.push(error.code); }
      }
      return { results, dispatched: window.__bridgeRequests.length - before };
    });
    assert.deepEqual(invalidCatalogRequests, { results: ['INVALID_INPUT', 'INVALID_INPUT'], dispatched: 0 },
      'invalid catalog query types and UTF-8 byte overflow fail before native dispatch');

    const beforeDeniedRead = await page.evaluate(() => window.__bridgeRequests.length);
    const deniedAttempt = await page.evaluate(async (before) => {
      let rejection = null;
      window.__brainwashEditorSpy.request('brainwash.card.read', { actorKey: 'npc:42' })
        .catch((error) => { rejection = { code: error.code, message: error.message }; });
      await new Promise((resolve) => window.setTimeout(resolve, 0));
      return { requests: window.__bridgeRequests.slice(before), rejection };
    }, beforeDeniedRead);
    assert.equal(deniedAttempt.requests.length, 1, `card read should enter the bridge transport; rejection=${JSON.stringify(deniedAttempt.rejection)}`);
    const deniedRead = deniedAttempt.requests[0];
    await respond(page, deniedRead, null, { code: 'forbidden', message: 'Authorization does not permit this operation.' });
    await page.waitForFunction(() => window.__brainwashEditorSpy.available === false && window.__brainwashEditorSpy.resets > 0);
    assert.equal(await page.getByRole('button', { name: 'Refresh Malpractice Makes Perfect actors' }).isDisabled(), true, 'authorization loss locks editor mutations and reads');

    const resetsAfterAuthLoss = await page.evaluate(() => window.__brainwashEditorSpy.resets);
    const priorCount = await page.evaluate(() => window.__bridgeRequests.filter((item) => item.operation === 'bootstrap').length);
    await page.evaluate((viewToken) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '3', serverContext: null })), newUuid());
    const thirdBootstrap = await waitForRequest(page, 'bootstrap', priorCount);
    const resetsAfterNativeReset = await page.evaluate(() => window.__brainwashEditorSpy.resets);
    assert.ok(resetsAfterNativeReset > resetsAfterAuthLoss, 'native view invalidation clears stale Malpractice Makes Perfect state');
    await respond(page, thirdBootstrap, bootstrapData(true, 'B'.repeat(43)));
    await page.waitForFunction(() => window.__brainwashEditorSpy.available === true);
    assert.ok(await page.evaluate(() => window.__brainwashEditorSpy.resets) > resetsAfterNativeReset, 'a changed authenticated scope clears stale Malpractice Makes Perfect state');
    const unknownMutation = await page.evaluate(async () => {
      let sends = 0;
      window.departmentRequest = () => { sends += 1; throw new Error('simulated bridge uncertainty'); };
      try {
        await window.__brainwashEditorSpy.request('brainwash.apply', {
          actorKey: 'npc:42', draftId: '123e4567-e89b-12d3-a456-426614174000',
          operationId: '123e4567-e89b-12d3-a456-426614174001', acceptedFields: ['personality'], acceptBeliefs: false,
        });
        return { code: null, dispatched: null, sends };
      } catch (error) {
        return { code: error.code, dispatched: error.dispatched, sends };
      }
    });
    assert.deepEqual(unknownMutation, { code: 'UNAVAILABLE', dispatched: true, sends: 1 },
      'uncertain Malpractice Makes Perfect mutation is marked dispatched and is never automatically resent');
    assert.deepEqual(externalRequests, [], 'the editor and Department shell make no browser network requests');
    await page.close();
    process.stdout.write('Malpractice Makes Perfect Department browser contract passed.\n');
  } finally {
    await browser.close();
  }
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
