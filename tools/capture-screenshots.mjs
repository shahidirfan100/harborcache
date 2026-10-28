/**
 * Captures the HarborCache listing screenshots from a real WordPress install.
 *
 * Environment:
 *   HC_BASE_URL    Site URL, for example http://127.0.0.1:8080
 *   HC_ADMIN_USER  Administrator login
 *   HC_ADMIN_PASS  Administrator password
 *   HC_OUT         Output directory, defaults to media-capture
 */
import { chromium } from 'playwright';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const base = process.env.HC_BASE_URL;
const user = process.env.HC_ADMIN_USER;
const pass = process.env.HC_ADMIN_PASS;
const out = process.env.HC_OUT || 'media-capture';
const warm = process.env.HC_WARM === '1';

if (!base || !user || !pass) {
  throw new Error('HC_BASE_URL, HC_ADMIN_USER and HC_ADMIN_PASS are required.');
}

await mkdir(out, { recursive: true });

const admin = `${base}/wp-admin`;
const pageUrl = (view) => `${admin}/options-general.php?page=harborcache&view=${view}`;

const browser = await chromium.launch();
const failures = [];
const consoleErrors = [];
const pageErrors = [];
const captures = [];

const reducedMotion = `
  *, *::before, *::after { animation: none !important; transition: none !important; caret-color: transparent !important; }
`;

async function newContext(viewport, mobile = false) {
  const context = await browser.newContext({
    viewport,
    deviceScaleFactor: 1,
    isMobile: mobile,
    hasTouch: mobile,
    reducedMotion: 'reduce',
    colorScheme: 'light',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(30000);
  page.on('console', (message) => {
    if (message.type() !== 'error') return;
    const text = message.text();
    if (/Failed to load resource/i.test(text)) return;
    if (/favicon\.ico/i.test(text)) return;
    consoleErrors.push(text);
  });
  page.on('pageerror', (error) => pageErrors.push(error.message));
  await page.goto(`${base}/wp-login.php`, { waitUntil: 'domcontentloaded' });
  await page.fill('#user_login', user);
  await page.fill('#user_pass', pass);
  await page.click('#wp-submit');
  await page.waitForURL(/wp-admin/, { timeout: 30000 });
  return { context, page };
}

async function settle(page) {
  await page.waitForLoadState('networkidle', { timeout: 5000 }).catch(() => {});
  await page.addStyleTag({ content: reducedMotion });
  await page.waitForFunction(
    () => Array.from(document.images).every((image) => image.complete && image.naturalWidth > 0),
    null,
    { timeout: 15000 }
  ).catch(() => {});
  await page.evaluate(() => document.fonts && document.fonts.ready);
  await page.waitForTimeout(600);
}

async function pngSize(file) {
  const bytes = await readFile(file);
  if (bytes.length < 24 || bytes.toString('ascii', 1, 4) !== 'PNG') {
    throw new Error(`${file} is not a PNG.`);
  }
  return { width: bytes.readUInt32BE(16), height: bytes.readUInt32BE(20), bytes: bytes.length };
}

async function capture(page, { file, view, url, selector, minWidth }) {
  const target = selector ? page.locator(selector).first() : page;
  await target.waitFor({ state: 'visible' });
  const destination = path.join(out, file);
  await target.screenshot({ path: destination, animations: 'disabled' });
  const size = await pngSize(destination);
  captures.push({ file, view, url, selector: selector || 'page', minWidth, ...size, viewport: page.viewportSize() });
}

const desktop = await newContext({ width: 1440, height: 1000 });
const { page } = desktop;

if (warm) {
  const views = ['overview', 'analytics', 'connection', 'cache', 'diagnostics'];
  for (let pass = 0; pass < 2; pass++) {
    for (const view of views) {
      await page.goto(pageUrl(view), { waitUntil: 'load' });
      await page.waitForTimeout(150);
    }
    await page.goto(`${admin}/index.php`, { waitUntil: 'load' });
    await page.waitForTimeout(150);
  }
  await desktop.context.close();
  await browser.close();
  console.log('PASS warm pass complete');
  process.exit(0);
}

for (const [file, view] of [
  ['screenshot-1.png', 'overview'],
  ['screenshot-2.png', 'analytics'],
  ['screenshot-3.png', 'connection'],
  ['screenshot-4.png', 'cache'],
  ['screenshot-5.png', 'diagnostics'],
]) {
  await page.goto(pageUrl(view), { waitUntil: 'domcontentloaded' });
  await settle(page);
  await capture(page, { file, view, url: pageUrl(view), selector: '#harborcache', minWidth: 1000 });
}

// The raw report screenshot shows the expanded Report panel from Diagnostics.
await page.goto(pageUrl('diagnostics'), { waitUntil: 'domcontentloaded' });
await settle(page);
const reportPanel = page.locator('section.ch-panel').filter({ has: page.locator('#ch-diagnostics') }).first();
await reportPanel.locator('summary').click();
await page.locator('#ch-diagnostics').waitFor({ state: 'visible' });
await page.addStyleTag({ content: '#ch-diagnostics { max-height: none !important; }' });
await settle(page);
await capture(page, { file: 'screenshot-6.png', view: 'raw report', url: pageUrl('diagnostics'), selector: 'section.ch-panel:has(#ch-diagnostics)', minWidth: 1000 });

// Dashboard widget.
await page.goto(`${admin}/index.php`, { waitUntil: 'domcontentloaded' });
await settle(page);
await page.locator('#harborcache_status').scrollIntoViewIfNeeded();
await settle(page);
await capture(page, { file: 'screenshot-7.png', view: 'dashboard widget', url: `${admin}/index.php`, selector: '#harborcache_status', minWidth: 500 });

await desktop.context.close();

const mobile = await newContext({ width: 375, height: 812 }, true);
const mobilePage = mobile.page;

await mobilePage.goto(pageUrl('connection'), { waitUntil: 'domcontentloaded' });
await settle(mobilePage);
await capture(mobilePage, { file: 'screenshot-mobile.png', view: 'settings', url: pageUrl('connection'), selector: '#harborcache', minWidth: 300 });

await mobilePage.goto(`${admin}/index.php`, { waitUntil: 'domcontentloaded' });
await settle(mobilePage);
await mobilePage.locator('#harborcache_status').scrollIntoViewIfNeeded();
await settle(mobilePage);
await capture(mobilePage, { file: 'screenshot-widget-mobile.png', view: 'dashboard widget', url: `${admin}/index.php`, selector: '#harborcache_status', minWidth: 300 });

await mobile.context.close();
await browser.close();

for (const shot of captures) {
  if (shot.width < shot.minWidth || shot.height < 300) {
    failures.push(`${shot.file} is too small: ${shot.width}x${shot.height}`);
  }
}

const summary = {
  base,
  capturedAt: new Date().toISOString(),
  viewportDesktop: { width: 1440, height: 1000 },
  viewportMobile: { width: 375, height: 812 },
  deviceScaleFactor: 1,
  javascriptErrors: pageErrors,
  consoleErrors,
  failures,
  files: captures,
};

await writeFile(path.join(out, 'capture-report.json'), JSON.stringify(summary, null, 2));

for (const shot of captures) {
  console.log(`${shot.file}: ${shot.width}x${shot.height} ${shot.view}`);
}
if (pageErrors.length || consoleErrors.length) {
  console.log(`console errors: ${consoleErrors.length}, page errors: ${pageErrors.length}`);
}
if (failures.length || pageErrors.length) {
  console.error(failures.join('\n'));
  process.exit(1);
}
console.log('PASS listing screenshots captured');
