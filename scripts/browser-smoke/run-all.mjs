#!/usr/bin/env node
/**
 * Sequential browser smoke runner for simple-form.
 * Executes scenarios from docs/smoke-tests/plugins/simple-form/scenarios.md
 * plus CP route coverage for Playwright-only Codeception Cests.
 */
import { chromium } from '@playwright/test';
import { execSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dir = dirname(fileURLToPath(import.meta.url));
const PROJECT_ROOT = join(__dir, '../../../../');

const BASE = process.env.SF_SMOKE_BASE ?? 'https://craft-plugin-dev.ddev.site';
const CP = `${BASE}/admin`;
const FRONT = `${BASE}/smoke/simple-form`;
const LOGIN = {
  email: process.env.SF_SMOKE_USER ?? 'admin@10vu10.ch',
  password: process.env.SF_SMOKE_PASS ?? 'nHWoQL2sN-_@V._H*R3xddd',
};

/** @type {{ id: string, status: 'PASS'|'FAIL'|'SKIP', notes: string }[]} */
const results = [];
let formId = process.env.SF_SMOKE_FORM_ID ?? '9145';

function mysql(sql) {
  const escaped = sql.replace(/"/g, '\\"');
  return execSync(`ddev mysql -e "${escaped}"`, {
    cwd: PROJECT_ROOT,
    encoding: 'utf8',
    stdio: ['pipe', 'pipe', 'pipe'],
  }).trim();
}

function queueRun() {
  try {
    execSync('ddev craft queue/run', { cwd: PROJECT_ROOT, encoding: 'utf8', stdio: 'pipe' });
  } catch {
    // queue may be empty
  }
}

async function login(page) {
  await page.goto(`${CP}/login`);
  await page.getByRole('textbox', { name: /Benutzername|E-Mail|Username/i }).fill(LOGIN.email);
  await page.getByRole('textbox', { name: /Passwort|Password/i }).fill(LOGIN.password);
  await page.locator('form button[type="submit"]').first().click();
  await page.waitForURL(/\/admin(?!\/login)/, { timeout: 30000 });
}

async function saveForm(page) {
  await page.getByRole('button', { name: /^Save$|Speichern/i }).click();
  await page.waitForTimeout(1200);
}

async function addFieldType(page, typeLabel) {
  await page.getByRole('button', { name: typeLabel, exact: true }).click();
  await page.waitForTimeout(600);
}

async function run(id, fn, skipReason = null) {
  if (skipReason) {
    results.push({ id, status: 'SKIP', notes: skipReason });
    console.log(`⏭  ${id}: ${skipReason}`);
    return;
  }
  try {
    await fn();
    results.push({ id, status: 'PASS', notes: '' });
    console.log(`✓  ${id}`);
  } catch (e) {
    const msg = e instanceof Error ? e.message : String(e);
    results.push({ id, status: 'FAIL', notes: msg });
    console.log(`✗  ${id}: ${msg}`);
  }
}

async function main() {
  console.log('Simple Form browser smoke — sequential run\n');
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();
  page.setDefaultTimeout(15000);

  await login(page);

  // ── S0: shared smoke form ────────────────────────────────────────────────
  await run('S0', async () => {
    await page.goto(`${CP}/simple-form/forms/edit/${formId}?site=default`);
    await page.getByRole('textbox', { name: /^Name Required|^Name/i }).first().fill('Smoke Form');
    await page.getByRole('textbox', { name: /^Handle Required|^Handle/i }).first().fill('smokeForm');
    await page.getByRole('textbox', { name: /Email To/i }).fill('ops@example.test');
    const hasName = await page.getByRole('button', { name: /— Name|Name —/i }).count();
    if (hasName === 0) {
      await addFieldType(page, 'Text');
      const label = page.locator('#sf-field-inspector input[name="label"], #sf-field-inspector #label').first();
      if (await label.count()) {
        await label.fill('Name');
        await page.locator('#sf-field-inspector input[name="name"]').fill('name');
      }
    }
    await saveForm(page);
    const m = page.url().match(/edit\/(\d+)/);
    if (m) formId = m[1];
    const count = mysql(`SELECT COUNT(*) AS c FROM simpleform_fields WHERE formId=${formId}`);
    if (!count.includes('1') && !count.includes('2') && !count.includes('3')) {
      throw new Error('Form has no fields after S0');
    }
  });

  // ── S1: webhook integration ──────────────────────────────────────────────
  await run('S1', async () => {
    await page.goto(`${CP}/simple-form/settings/integrations/new?site=default`);
    await page.locator('#sf-integration-type').selectOption('webhook');
    await page.waitForSelector('#name', { timeout: 10000 });
    await page.locator('#name').fill('Ops hook');
    await page.locator('#settings-url').fill('https://example.test/hook');
    await page.getByRole('button', { name: /^Save$|Speichern/i }).click();
    await page.waitForURL(/integrations/, { timeout: 15000 });
    const row = mysql(`SELECT name,type,enabled FROM simpleform_integrations WHERE name='Ops hook' ORDER BY id DESC LIMIT 1`);
    if (!row.includes('webhook')) throw new Error(`Expected webhook row, got: ${row}`);
    await page.goto(`${CP}/simple-form/forms/${formId}/integrations?site=default`);
    await page.waitForLoadState('networkidle');
  });

  // ── S2: toggle + delete integration ──────────────────────────────────────
  await run('S2', async () => {
    const lines = mysql(`SELECT id, enabled FROM simpleform_integrations WHERE name='Ops hook' ORDER BY id DESC LIMIT 1`).split('\n');
    const iid = lines[lines.length - 1]?.split('\t')[0]?.trim();
    if (!iid || iid === 'id') throw new Error('No Ops hook integration');
    await page.goto(`${CP}/simple-form/settings/integrations/${iid}?site=default`);
    const enabledCheckbox = page.locator('input[type="checkbox"][name="enabled"]');
    if (await enabledCheckbox.isChecked()) {
      const lightswitch = page.locator('.field-enabled .lightswitch').first();
      if (await lightswitch.count()) {
        await lightswitch.click();
      } else {
        await enabledCheckbox.uncheck();
      }
    }
    await saveForm(page);
    await page.waitForURL(/\/integrations/, { timeout: 15000 });
    const enabled = mysql(`SELECT enabled FROM simpleform_integrations WHERE id=${iid}`);
    if (enabled.includes('1')) throw new Error('Expected disabled after toggle');
    await page.locator(`#sf-integrations button.delete[data-id="${iid}"]`).click();
    await page.locator('.sf-confirm .btn.submit').click();
    await page.waitForTimeout(1000);
    const still = mysql(`SELECT id FROM simpleform_integrations WHERE id=${iid}`);
    if (still.includes(iid)) throw new Error('Integration still exists after delete');
  });

  // ── S5: connector settings forms render ──────────────────────────────────
  const connectors = ['webhook', 'slack', 'discord', 'mailchimp', 'activecampaign', 'hubspot', 'pipedrive', 'google-sheets'];
  for (const type of connectors) {
    await run(`S5-${type}`, async () => {
      await page.goto(`${CP}/simple-form/settings/integrations/new?site=default`);
      await page.locator('#sf-integration-type').selectOption(type);
      await page.waitForTimeout(1200);
      const name = page.locator('#name');
      if (!(await name.count())) throw new Error('Name field missing after type select');
      const body = await page.locator('#content').innerText();
      if (body.length < 80) throw new Error('Settings form appears empty');
    });
  }

  // ── S6: captcha provider selector ────────────────────────────────────────
  await run('S6', async () => {
    await page.goto(`${CP}/simple-form/settings/spam?site=default`);
    await page.waitForLoadState('networkidle');
    const enableCaptcha = page.locator('input[type="checkbox"][name="enableCaptcha"]');
    if (!(await enableCaptcha.isChecked())) {
      const lightswitch = page.locator('.field-enableCaptcha .lightswitch').first();
      if (await lightswitch.count()) {
        await lightswitch.click();
      } else {
        await enableCaptcha.check();
      }
    }
    await page.locator('#captcha-settings').waitFor({ state: 'visible' });
    await page.locator('#selectedCaptchaProvider').selectOption('turnstile');
    await saveForm(page);
  });

  // ── S7: Akismet settings + spam filter ────────────────────────────────────
  await run('S7', async () => {
    await page.goto(`${CP}/simple-form/settings/spam?site=default`);
    await page.waitForLoadState('networkidle');
    const body = await page.locator('main').innerText();
    if (!/Akismet/i.test(body)) throw new Error('Akismet settings not visible');
    const sid = mysql(`SELECT id FROM simpleform_submissions ORDER BY id DESC LIMIT 1`).split('\n').pop()?.trim();
    if (sid && sid !== 'id') {
      mysql(`UPDATE simpleform_submissions SET readStatus='spam' WHERE id=${sid}`);
      await page.goto(`${CP}/simple-form/submissions?status=spam&site=default`);
      await page.waitForLoadState('networkidle');
    }
  });

  // ── CP routes (Playwright-only Cests) ────────────────────────────────────
  const cpRoutes = [
    ['CP-Forms', `${CP}/simple-form/forms?site=default`],
    ['CP-Submissions', `${CP}/simple-form/submissions?site=default`],
    ['CP-Settings', `${CP}/simple-form/settings?site=default`],
    ['CP-Integrations', `${CP}/simple-form/settings/integrations?site=default`],
    ['CP-Form-Integrations', `${CP}/simple-form/forms/${formId}/integrations?site=default`],
    ['CP-Form-Notifications', `${CP}/simple-form/forms/${formId}/notifications?site=default`],
    ['CP-Analytics', `${CP}/simple-form/submissions/analytics?site=default`],
  ];
  for (const [id, url] of cpRoutes) {
    await run(id, async () => {
      const resp = await page.goto(url);
      if (!resp || resp.status() >= 400) throw new Error(`HTTP ${resp?.status()}`);
      await page.waitForLoadState('networkidle');
    });
  }

  // ── Front-end render + submit ────────────────────────────────────────────
  await run('Front-Render', async () => {
    const resp = await page.goto(FRONT);
    if (!resp || resp.status() >= 400) throw new Error(`Front page HTTP ${resp?.status()}`);
    await page.waitForSelector('form.simple-form, form[class*="simple-form"]', { timeout: 15000 });
  });

  await run('Front-Submit', async () => {
    await page.goto(FRONT);
    const name = page.locator('input[type="text"]:visible').first();
    if (await name.count()) await name.fill('Smoke Runner');
    const next = page.getByRole('button', { name: /Next|Weiter/i });
    if (await next.count()) {
      await next.click();
      await page.waitForTimeout(500);
    }
    const email = page.locator('input[type="email"]:visible').first();
    if (await email.count()) await email.fill(`smoke-${Date.now()}@example.test`);
    await page.locator('form.simple-form button[type="submit"]:visible, form.simple-form input[type="submit"]:visible').first().click();
    await page.waitForTimeout(2000);
  });

  // ── S11: CSV export ──────────────────────────────────────────────────────
  await run('S11', async () => {
    await page.goto(`${CP}/simple-form/submissions?site=default`);
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 10000 }).catch(() => [null]),
      page.getByRole('link', { name: /Export|Exportieren/i }).click(),
    ]);
    if (download) {
      const path = await download.path();
      if (!path) throw new Error('No CSV downloaded');
    }
  });

  // ── S12: dashboard widgets ───────────────────────────────────────────────
  await run('S12', async () => {
    await page.goto(`${CP}/dashboard?site=default`);
    await page.waitForLoadState('networkidle');
    const body = await page.locator('main').innerText();
    if (!/Widget|Dashboard/i.test(body)) throw new Error('Dashboard did not load');
  });

  // ── External / heavy scenarios — skip with reason ────────────────────────
  const skips = [
    ['S3', 'Needs sync dispatch + front submit + resend UI — partial coverage in Codeception'],
    ['S4', 'Needs restricted CP user setup'],
    ['S13-GSheets', 'Needs live Google credentials'],
    ['S8-S9', 'File upload — run manually with asset volume'],
    ['S10', 'Multi-step — covered in integration suite'],
    ['S13-S17-Phone', 'Phone CP scenarios — covered in FieldTypesSmokeCest'],
    ['S18-S24', 'Hidden/consent browser UX — covered in functional smoke'],
    ['S25-S33', 'Rating/relation fields — partial Codeception coverage'],
    ['Theme-S13-S17', 'Custom Twig templates — needs template files on disk'],
    ['Mailpit', 'Email delivery — use Mailpit UI manually'],
  ];
  for (const [id, reason] of skips) {
    await run(id, async () => {}, reason);
  }

  await browser.close();

  const reportPath = join(__dir, `report-${new Date().toISOString().slice(0, 10)}.md`);
  const lines = [
    `# Simple Form Browser Smoke Report`,
    `Generated: ${new Date().toISOString()}`,
    `Form ID: ${formId}`,
    '',
    '| Scenario | Status | Notes |',
    '|----------|--------|-------|',
    ...results.map((r) => `| ${r.id} | ${r.status} | ${r.notes.replace(/\|/g, '\\|')} |`),
    '',
    `**Totals:** ${results.filter((r) => r.status === 'PASS').length} pass, ${results.filter((r) => r.status === 'FAIL').length} fail, ${results.filter((r) => r.status === 'SKIP').length} skip`,
  ];
  writeFileSync(reportPath, lines.join('\n'));
  console.log(`\nReport: ${reportPath}`);

  const fails = results.filter((r) => r.status === 'FAIL').length;
  process.exit(fails > 0 ? 1 : 0);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
