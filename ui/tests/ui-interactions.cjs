'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightRoot = process.env.PLAYWRIGHT_MODULE_ROOT;
if (!playwrightRoot) throw new Error('Set PLAYWRIGHT_MODULE_ROOT to the existing local Node modules directory.');
const { chromium } = require(require.resolve('playwright', { paths: [playwrightRoot] }));
const browserPath = process.env.PRISMA_TEST_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const pagePath = path.resolve(__dirname, '..', 'index.html');
const results = [];

async function test(name, run) {
  try {
    await run();
    results.push({ name, status: 'passed' });
  } catch (error) {
    results.push({ name, status: 'failed', error: String(error.message || error) });
  }
}

async function openPage(browser, native = true) {
  const page = await browser.newPage({ viewport: { width: 1600, height: 900 } });
  const externalRequests = [];
  page.on('request', (request) => {
    if (!request.url().startsWith('file://')) externalRequests.push(request.url());
  });
  await page.addInitScript(({ native }) => {
    window.__networkAttempts = [];
    const rejectNetwork = (kind) => (...args) => {
      window.__networkAttempts.push({ kind, target: String(args[0] ?? '') });
      throw new Error('Browser network is disabled in this test.');
    };
    window.fetch = rejectNetwork('fetch');
    window.XMLHttpRequest = class { open(...args) { rejectNetwork('xhr')(...args); } send(...args) { rejectNetwork('xhr')(...args); } };
    window.WebSocket = class { constructor(...args) { rejectNetwork('websocket')(...args); } };
    window.EventSource = class { constructor(...args) { rejectNetwork('eventsource')(...args); } };
    if (navigator.sendBeacon) navigator.sendBeacon = rejectNetwork('sendBeacon');
    window.PRISMA = Object.freeze({ testSentinel: true });
    if (!native) return;
    window.__bridgeRequests = [];
    window.departmentRequest = (raw) => {
      try {
        window.__bridgeRequests.push(JSON.parse(raw));
      } catch {
        window.__bridgeRequests.push({ malformed: true });
      }
    };
  }, { native });
  await page.goto(pathToFileURL(pagePath).href);
  page.__externalRequests = externalRequests;
  return page;
}

async function main() {
  if (!fs.existsSync(pagePath)) {
    results.push({ name: 'renders the approved title, subtitle, and five tabs', status: 'failed', error: 'UI entrypoint is missing.' });
  } else {
    if (!fs.existsSync(browserPath)) throw new Error(`Existing local browser not found: ${browserPath}`);
    const browser = await chromium.launch({ headless: true, executablePath: browserPath });
    await test('renders the approved title, subtitle, and five tabs', async () => {
      const page = await openPage(browser, false);
      assert.equal(await page.title(), 'World of Drama Llama: The Department of Questionable Decisions');
      assert.equal(await page.getByRole('heading', { level: 1 }).textContent(), 'The Department of Questionable Decisions');
      assert.equal(await page.getByText('World of Drama Llama', { exact: true }).count(), 1);
      assert.equal(await page.getByText('Conversations, relationships, grudges, and suspicious mail.', { exact: true }).count(), 1);
      for (const name of ['Private Conversation', 'Mind Poisoning', 'Sworn & Scorned', 'Sealed Fates', 'Malpractice Makes Perfect']) {
        assert.equal(await page.getByRole('tab', { name }).count(), 1, `Missing tab ${name}`);
      }
      assert.equal(await page.evaluate(() => window.PRISMA.testSentinel), true, 'The UI replaced the framework global.');
      assert.equal(await page.evaluate(() => window.__networkAttempts.length), 0, 'The browser attempted network access.');
      assert.deepEqual(page.__externalRequests, [], 'The UI loaded a non-local resource.');
      assert.equal(await page.evaluate(() => typeof window.departmentInitialize), 'function', 'Native initialization callback is missing.');
      await page.close();
    });
    await browser.close();
  }

  const failed = results.filter((item) => item.status === 'failed');
  process.stdout.write(`${JSON.stringify({ total: results.length, passed: results.length - failed.length, failed: failed.length, tests: results }, null, 2)}\n`);
  if (failed.length) process.exitCode = 1;
}

main().catch((error) => {
  process.stderr.write(`${error.stack || error}\n`);
  process.exitCode = 1;
});
