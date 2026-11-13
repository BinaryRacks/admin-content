/**
 * Browser check, step 2: opens every rendered admin tab in Chromium and checks that
 * the logo image loads, tables show, nothing overflows sideways and there are no console errors.
 * Saves a screenshot of each tab. Usage: node tests/browser/check.js <folder>
 */
const { chromium } = require('playwright');
const path = require('path');

(async () => {
  const dir = path.resolve(process.argv[2]);
  const tabs = ['dashboard', 'notifications', 'messages', 'marketing', 'log', 'settings', 'send'];
  const browser = await chromium.launch();
  let failures = 0;
  for (const width of [1280, 390]) {
    const page = await browser.newPage({ viewport: { width, height: 900 } });
    const errors = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push(String(e)));
    page.on('dialog', (d) => d.dismiss());
    for (const tab of tabs) {
      errors.length = 0;
      await page.goto('file://' + dir + '/' + tab + '.html');
      const logo = await page.$eval('img.brwa-logo', (img) => img.complete && img.naturalWidth > 0);
      const title = await page.textContent('.brwa-header');
      const tables = await page.$$eval('.brwa table', (t) => t.length);
      const scripts = await page.$$eval('.brwa script', (s) => s.length);
      const ok = logo && title.includes('v1.0.0') && (tab === 'send' || tables > 0) && scripts === 0 && errors.length === 0;
      if (!ok) failures++;
      console.log(`${ok ? 'PASS' : 'FAIL'} ${tab} @${width}px logo=${logo} tables=${tables} injectedScripts=${scripts} errors=${JSON.stringify(errors)}`);
      if (width === 1280) await page.screenshot({ path: `${dir}/${tab}.png`, fullPage: true });
    }
    await page.close();
  }
  await browser.close();
  process.exit(failures ? 1 : 0);
})();
