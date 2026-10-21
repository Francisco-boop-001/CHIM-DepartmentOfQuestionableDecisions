'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { pathToFileURL } = require('node:url');

const playwrightRoot = process.env.PLAYWRIGHT_MODULE_ROOT;
if (!playwrightRoot) throw new Error('Set PLAYWRIGHT_MODULE_ROOT to the existing local Node modules directory.');
const { chromium } = require(require.resolve('playwright', { paths: [playwrightRoot] }));
const browserPath = process.env.PRISMA_TEST_BROWSER || 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe';
const pagePath = path.resolve(__dirname, '..', 'index.html');
const outputDir = path.resolve(process.env.UI_RENDER_RESULTS_DIR || path.resolve(__dirname, '..', '..', '..', 'evidence', 'implementation'));
const evidence = [];
let tokenCounter = 1;
let browser = null;

function uuid() {
  return `00000000-0000-4000-8000-${String(tokenCounter++).padStart(12, '0')}`;
}

async function request(page, operation, index = 0) {
  await page.waitForFunction(({ operation, index }) => {
    const matches = window.__bridgeRequests.filter((item) => item.operation === operation);
    return matches.length > index;
  }, { operation, index });
  return page.evaluate(({ operation, index }) => window.__bridgeRequests.filter((item) => item.operation === operation)[index], { operation, index });
}

async function respond(page, item, data) {
  await page.evaluate(({ item, data }) => window.departmentReceive(JSON.stringify({
    version: 1, requestId: item.requestId, operation: item.operation, context: item.context, ok: true, data,
  })), { item, data });
  await page.waitForTimeout(20);
}

async function scrollToTop(page) {
  await page.evaluate(() => window.scrollTo(0, 0));
  await page.waitForTimeout(30);
}

async function capture(page, name) {
  await scrollToTop(page);
  await captureCurrent(page, name);
}

async function captureCurrent(page, name) {
  const file = path.join(outputDir, name);
  await page.screenshot({ path: file, animations: 'disabled' });
  evidence.push({
    file, width: page.viewportSize().width, height: page.viewportSize().height,
    scrollY: await page.evaluate(() => window.scrollY), source: 'explicit synthetic test fixtures',
  });
}

async function main() {
  fs.mkdirSync(outputDir, { recursive: true });
  browser = await chromium.launch({ headless: true, executablePath: browserPath });
  const page = await browser.newPage({ viewport: { width: 1600, height: 1000 }, deviceScaleFactor: 1 });
  await page.addInitScript(() => {
    window.__bridgeRequests = [];
    window.PRISMA = Object.freeze({ renderHarness: true });
    window.departmentRequest = (raw) => window.__bridgeRequests.push(JSON.parse(raw));
  });
  await page.goto(pathToFileURL(pagePath).href);
  await page.evaluate((viewToken) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', serverContext: null })), uuid());
  const bootstrap = await request(page, 'bootstrap');
  await respond(page, bootstrap, {
    authenticated: true, authRequired: false, serverContext: 'render-context-1', scopeToken: 'R'.repeat(43),
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true },
    access: { pcv: true, inspector: true, compose: true, authorDetail: true },
    perspectives: {
      mp: { choices: [{ actorKey: 'actor:captain', label: 'Captain Snide' }, { actorKey: 'npc:countess', label: 'Countess Example' }, { actorKey: 'npc:baron', label: 'Baron Example' }], limited: false },
      sas: { choices: [{ actorKey: 'actor:captain', label: 'Captain Snide' }, { actorKey: 'actor:countess', label: 'Countess Example' }], limited: false },
    },
  });

  await page.getByRole('tab', { name: 'Private Conversation' }).click();
  await page.getByRole('button', { name: /refresh cast and status/i }).click();
  const roster = await request(page, 'pcv.roster.read');
  const status = await request(page, 'pcv.status.read');
  const actors = [
    { actorKey: 'npc:countess', label: 'Countess Example', eligibility: 'eligible', reason: null },
    { actorKey: 'npc:baron', label: 'Baron Example', eligibility: 'eligible', reason: null },
    { actorKey: 'npc:captain', label: 'Captain Snide', eligibility: 'eligible', reason: null },
    { actorKey: 'npc:retired', label: 'Retired Envoy', eligibility: 'ineligible', reason: 'Not in current range' },
  ];
  const activeConfig = { enabled: true, scene_mode: 'pair', actor_a: 'npc:countess', actor_b: 'npc:baron', exclude_player: true, bystander_mode: 'exclude', pace: 'normal' };
  const pendingConfig = { enabled: true, scene_mode: 'pair', actor_a: 'npc:captain', actor_b: 'npc:baron', exclude_player: true, bystander_mode: 'silent', pace: 'short' };
  const activeConfigId = uuid();
  const pendingConfigId = uuid();
  await respond(page, roster, { revision: 'render-roster-1', state: 'ready', actors, limited: false });
  await respond(page, status, { state: 'pending', activeConfigId, pendingConfigId, active: activeConfig, pending: pendingConfig });
  await page.getByLabel('Conversation target').selectOption('npc:captain');
  await page.getByLabel('Your message').fill('We should speak quietly before the feast.');
  await capture(page, 'ui-render-private-conversation.png');
  await page.setViewportSize({ width: 1280, height: 720 });
  const compactComposer = await page.evaluate(() => Object.fromEntries(['send-target', 'pcv-text', 'pcv-send'].map((id) => {
    const rect = document.getElementById(id).getBoundingClientRect();
    return [id, rect.height > 0 && rect.top >= 0 && rect.bottom <= window.innerHeight];
  })));
  if (Object.values(compactComposer).some((visible) => !visible)) throw new Error(`Armed composer is not fully visible in the compact PCV render: ${JSON.stringify(compactComposer)}`);
  await capture(page, 'ui-render-compact-pcv.png');
  await page.getByRole('button', { name: /^send message$/i }).click();
  const send = await request(page, 'pcv.send');
  if (send.payload.expectedActiveConfigId !== activeConfigId || send.payload.expectedPendingConfigId !== pendingConfigId) {
    throw new Error('PCV send did not carry both original scene identities.');
  }
  await respond(page, send, { operationId: send.payload.operationId, state: 'prepared', phase: 'vm_dispatched' });
  const sendStatus = await request(page, 'pcv.send.status');
  await respond(page, sendStatus, { operationId: send.payload.operationId, state: 'unknown', expectedActiveConfigId: null, expectedPendingConfigId: null });
  await capture(page, 'ui-render-pcv-send-unknown.png');

  await page.setViewportSize({ width: 1600, height: 1000 });
  await page.getByRole('tab', { name: 'Mind Poisoning' }).click();
  await page.getByRole('tabpanel', { name: 'Mind Poisoning' }).getByLabel('Perspective').selectOption('actor:captain');
  await page.getByLabel(/Target filter/).selectOption('npc:countess');
  await page.getByRole('button', { name: /refresh relationship history/i }).click();
  let mp = await request(page, 'mp.relationships.read');
  await respond(page, mp, { perspectiveActorKey: 'actor:captain', records: [
    { id: 'render-mp-1', owner: 'Captain Snide', target: 'Countess Example', at: 'Frostfall 4', proposedDelta: -2, appliedDelta: -1, historicalBefore: 'Acquaintance', historicalAfter: 'Friend', currentValue: 'Friend', currentAsOf: 'Now', sourceKind: 'Review', fixedReason: 'A public slight was recorded.', kind: 'relationship', confirmation: 'confirmed' },
    { id: 'render-mp-2', owner: 'Captain Snide', target: 'Countess Example', at: 'Frostfall 2', proposedDelta: 0, appliedDelta: 0, historicalBefore: 'none', historicalAfter: 'active', currentValue: 'active', currentAsOf: 'Now', sourceKind: 'Arrangement', fixedReason: 'An obligation changed without affinity.', kind: 'arrangement', confirmation: 'confirmed' },
  ], limited: false, nextCursor: null });
  await capture(page, 'ui-render-mind-poisoning.png');

  await page.getByRole('tab', { name: 'Sworn & Scorned' }).click();
  await page.getByRole('tabpanel', { name: 'Sworn & Scorned' }).getByLabel('Perspective').selectOption('actor:captain');
  await page.getByRole('button', { name: /refresh grievances and promises/i }).click();
  const sas = await request(page, 'sas.issues.read');
  await respond(page, sas, { perspectiveActorKey: 'actor:captain', issues: [
    { id: 'render-sas-1', kind: 'grievance', owner: 'Captain Snide', counterpart: 'Countess Example', direction: 'owed by counterpart', matter: 'A public insult at court', nature: 'Public insult', status: 'open', settlement: 'unknown' },
    { id: 'render-sas-2', kind: 'promise', owner: 'Captain Snide', counterpart: 'Baron Example', direction: 'owed by owner', matter: 'Escort promised before the feast', nature: 'Service', status: 'open', settlement: 'not settled' },
  ], limited: false, nextCursor: null });
  await capture(page, 'ui-render-sworn-and-scorned.png');

  await page.getByRole('tab', { name: 'Sealed Fates' }).click();
  await page.getByRole('button', { name: /refresh catalog and inventory/i }).click();
  const catalog = await request(page, 'correspondence.catalog.read');
  const inventory = await request(page, 'correspondence.inventory.read');
  await respond(page, catalog, { actors: [
    { actorKey: 'npc:1', label: 'Captain Snide', canWrite: true, canReceive: true, canSignAs: true },
    { actorKey: 'npc:2', label: 'Countess Example', canWrite: true, canReceive: true, canSignAs: true },
    { actorKey: 'npc:3', label: 'Baron Example', canWrite: false, canReceive: true, canSignAs: true },
  ], limited: false, revision: 'a'.repeat(64), nextCursor: null });
  const renderItems = Array.from({ length: 128 }, (_, index) => ({
    itemKey: uuid(),
    label: index === 0 ? 'Blue Mountain Flower' : index === 1 ? 'Fine parchment' : `Supported item ${String(index + 1).padStart(3, '0')}`,
    availableQuantity: index === 0 ? 4 : 2,
  }));
  await respond(page, inventory, { inventoryRevision: 'render-inventory-128', items: renderItems, gold: { available: 125 } });
  await page.getByLabel('Writer').selectOption('npc:1');
  await page.getByLabel('Recipient', { exact: true }).selectOption('npc:2');
  await page.getByLabel('Sign as someone else').check();
  await page.getByLabel('Signature shown on the letter').selectOption('npc:3');
  await page.getByLabel('Letter body').fill('The old oath still binds us. Meet me at the west gate.');
  await page.getByLabel('Attach Blue Mountain Flower').check();
  await page.getByLabel('Gold').fill('12');
  await page.getByRole('button', { name: /review letter/i }).click();
  await page.locator('#letter-review-summary').waitFor({ state: 'visible' });
  await capture(page, 'ui-render-sealed-fates.png');

  await page.setViewportSize({ width: 1280, height: 720 });
  await capture(page, 'ui-render-compact-composer-top.png');
  await page.locator('#letter-review').scrollIntoViewIfNeeded();
  await page.locator('#letter-review-summary').scrollIntoViewIfNeeded();
  await captureCurrent(page, 'ui-render-compact-composer-review.png');

  await page.getByRole('button', { name: 'Mailboxes' }).click();
  await page.getByLabel('Recipient mailbox').selectOption('npc:2');
  await page.getByRole('button', { name: 'Refresh mailbox' }).click();
  const mailbox = await request(page, 'correspondence.mailbox.read');
  await respond(page, mailbox, { recipientActorKey: 'npc:2', letters: [
    { letterId: 'render-letter-1', claimedSignature: 'Baron Example', receivedAt: 'Frostfall 3', bodyPreview: 'Our agreement stands. Bring the sealed copy.', attachments: [{ label: 'Fine parchment', quantity: 1 }], deliveryConfirmed: true },
  ], limited: false, nextCursor: null });
  await page.setViewportSize({ width: 1280, height: 720 });
  await capture(page, 'ui-render-compact-mailbox.png');
  await page.setViewportSize({ width: 1600, height: 1000 });
  await capture(page, 'ui-render-sealed-fates-mailbox.png');

  await page.evaluate((viewToken) => window.departmentInitialize(JSON.stringify({ version: 1, viewToken, nativeGeneration: '1', serverContext: null })), uuid());
  const unpairedBootstrap = await request(page, 'bootstrap', 1);
  await respond(page, unpairedBootstrap, {
    authenticated: false, authRequired: true, serverContext: null, scopeToken: null,
    capabilities: { pcv: true, mp: true, sas: true, correspondence: true },
    access: { pcv: false, inspector: false, compose: false, authorDetail: false },
  });
  await capture(page, 'ui-render-auth-required.png');

  fs.writeFileSync(path.join(outputDir, 'ui-render.json'), `${JSON.stringify({ synthetic: true, screenshots: evidence }, null, 2)}\n`, 'utf8');
  await browser.close();
  browser = null;
  process.stdout.write(`${JSON.stringify({ count: evidence.length, files: evidence.map((item) => item.file) }, null, 2)}\n`);
}

main().catch((error) => {
  Promise.resolve(browser ? browser.close() : undefined).finally(() => {
    process.stderr.write(`${error.stack || error}\n`);
    process.exitCode = 1;
  });
});
