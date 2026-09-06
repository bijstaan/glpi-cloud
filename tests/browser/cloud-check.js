// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// glpi-cloud and glpi-cloud-azure, in a browser, against the live instance.
//
// The suites already cover the arithmetic: the checksum, the paging, the cost
// columns, the lifecycle. What none of them can see is whether a technician can
// actually get a cloud account into GLPI — which is the whole product — so this
// walks that path and asserts the things a form gets wrong:
//
//  - the provider's credential fields appear on the form where you create the
//    account, not after a save-and-come-back;
//  - a stored secret is never sent back to the browser;
//  - saving an unrelated field does not wipe the secret, and saving one
//    settings card does not reset the others;
//  - a wrong credential produces a message, not a stack trace.
//
// It leaves the instance as it found it: the account it creates is purged and
// every setting it touches is restored, both asserted at the end.
//
//   cd glpi-cloud/tests/browser && SHOT_DIR=../../docs/screenshots node cloud-check.js
const { chromium } = require('playwright');
const { execSync } = require('child_process');
const { fullPage } = require('./shot');

const BASE  = 'http://localhost:8081';
const SHOTS = process.env.SHOT_DIR || '.';

const fail = [];
const check = (name, cond, detail) => {
  console.log(`${cond ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 200) : ''}`);
  if (!cond) fail.push(name);
};

/** Run PHP inside the container against a booted, logged-in GLPI. */
const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    maxBuffer: 32 * 1024 * 1024,
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);'
      + code,
  }).trim();

const q = (sql) => php(`global $DB; foreach ($DB->request(${sql}) as $r) { echo json_encode($r), "\\n"; }`)
  .split('\n').filter(Boolean).map((line) => JSON.parse(line));

/** GLPI's flash messages, which is where this plugin reports everything. */
const messages = (page) =>
  page.$$eval('.toast-body, .alert, .messages_after_redirect', (nodes) =>
    nodes.map((n) => n.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean)).catch(() => []);

let settingsBefore = {};
let theirs = { accounts: [], resources: 0, runs: 0 };

(async () => {
  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1000 } });

  const errors = [];
  page.on('pageerror', (e) => errors.push(String(e)));

  // GLPI answers an uncaught exception with a *styled* page saying "an
  // unexpected error occurred" — no PHP text in the body, HTTP 500. Reading the
  // page was not enough: the first run of this file called a settings save a
  // success while every POST was 500ing. Watch the status codes.
  const httpFailures = [];
  page.on('response', (r) => {
    if (r.request().resourceType() === 'document' && r.status() >= 400) {
      httpFailures.push(`${r.status()} ${r.request().method()} ${r.url().replace(BASE, '')}`);
    }
  });

  // Anything PHP printed into the page is a defect, whatever else the page did.
  const noFatal = async (where) => {
    const body = await page.textContent('body').catch(() => '');
    const bad = /Fatal error|Parse error|Uncaught|Warning:|Deprecated:|SQL Error|unexpected error occurred/i.exec(body || '');
    check(`${where}: no PHP error on the page`, !bad, bad ? bad[0] : '');
  };

  try {
    // ------------------------------------------------------------- log in
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.fill('#login_name', 'glpi');
    await page.fill('input[type=password]', 'glpi');
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');
    check('logged in', !/login/i.test(page.url()), page.url());

    // Everything this script changes is put back exactly as it was found, and
    // "as it was found" means read first — not assumed. An earlier version of
    // this file wrote the defaults back at the end and switched off a master
    // switch somebody had deliberately turned on.
    settingsBefore = Object.fromEntries(q(`['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'plugin:glpicloud']]`)
      .map((r) => [r.name, r.value]));

    // Rows that were here before this script ran belong to somebody else.
    theirs = {
      accounts: q(`['FROM' => 'glpi_plugin_glpicloud_accounts']`).map((r) => Number(r.id)),
      resources: q(`['FROM' => 'glpi_plugin_glpicloud_resources']`).length,
      runs: q(`['FROM' => 'glpi_plugin_glpicloud_runs']`).length,
    };

    // ------------------------------------------------------------- the menu
    // The three pages have to be reachable by somebody who does not know the
    // URLs — which is everybody.
    await page.goto(`${BASE}/front/central.php`, { waitUntil: 'networkidle' });
    const menu = await page.$$eval('a[href*="glpicloud/front"]', (a) => a.map((n) => n.getAttribute('href')));
    for (const [label, page_file] of [['resources', 'resource.php'], ['accounts', 'account.php'], ['runs', 'run.php']]) {
      check(`the Assets menu links ${label}`, menu.some((h) => h.includes(page_file)), menu.join(' '));
    }

    // -------------------------------------------------- both plugins active
    const active = q(`['FROM' => 'glpi_plugins', 'WHERE' => ['directory' => ['glpicloud', 'glpicloudazure']]]`);
    check('both plugins are installed and enabled', active.length === 2 && active.every((p) => Number(p.state) === 1),
      active.map((p) => `${p.directory}=${p.state}`).join(' '));

    // --------------------------------------------------- the settings page
    const config = `${BASE}/plugins/glpicloud/front/config.php`;
    await page.goto(config, { waitUntil: 'networkidle' });
    await noFatal('settings');

    const configText = await page.textContent('body');
    check('settings page names the installed provider', /Microsoft Azure/.test(configText));
    check('settings page names the plugin that provides it', /glpicloudazure/.test(configText));
    // Whether sync is on is the operator's business, not this script's. Assert
    // that the page tells the truth about it — not that it is off.
    const syncWasOn = settingsBefore.enabled === '1';
    check('the settings page reports the master switch as it actually is',
      /switched off/i.test(configText) !== syncWasOn, `enabled=${settingsBefore.enabled}`);
    check('settings page has the four cards',
      ['Collection', 'Cost', 'Retention', 'Native assets'].every((h) => configText.includes(h)));
    await fullPage(page, `${SHOTS}/cloud-01-settings.png`);

    // The partial-save trap: turn the master switch on, change one number, and
    // require that everything the form did not mention is untouched.
    const before = settingsBefore;

    await page.check('input[name=enabled]');
    await page.fill('input[name=history_days]', '400');
    await page.click('button[name=update]');
    await page.waitForLoadState('networkidle');
    await noFatal('settings save');

    const after = Object.fromEntries(q(`['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'plugin:glpicloud']]`)
      .map((r) => [r.name, r.value]));

    check('the master switch saved', after.enabled === '1', after.enabled);
    check('the number saved', after.history_days === '400', after.history_days);
    check('cost settings survived a save of another card', after.cost_enabled === before.cost_enabled && after.cost_rollup === before.cost_rollup);
    check('projection stayed off', after.projection_enabled === '0', after.projection_enabled);
    check('untouched numbers are unchanged',
      after.sync_interval === before.sync_interval && after.run_seconds === before.run_seconds && after.request_budget === before.request_budget);

    // ------------------------------------------------------- the menu pages
    for (const [label, url] of [
      ['resources', `${BASE}/plugins/glpicloud/front/resource.php`],
      ['accounts', `${BASE}/plugins/glpicloud/front/account.php`],
      ['runs', `${BASE}/plugins/glpicloud/front/run.php`],
    ]) {
      const res = await page.goto(url, { waitUntil: 'networkidle' });
      check(`${label} list responds`, res.status() === 200, res.status());
      await noFatal(`${label} list`);
    }

    // --------------------------------------------------- creating an account
    await page.goto(`${BASE}/plugins/glpicloud/front/account.form.php`, { waitUntil: 'networkidle' });
    await noFatal('new account form');

    const providerOptions = await page.$$eval('select[name=provider] option', (o) => o.map((n) => n.textContent.trim()));
    check('the provider dropdown offers Azure', providerOptions.includes('Microsoft Azure'), providerOptions.join(', '));
    check('and OVHcloud', providerOptions.includes('OVHcloud'), providerOptions.join(', '));

    // With more than one provider installed, the form has to show the fields of
    // the one selected and hide the rest — the case that could not be exercised
    // at all while Azure was the only provider.
    const azureFieldVisible = await page.isVisible('input[name="cred[tenant_id]"]').catch(() => false);
    const ovhFieldVisible = await page.isVisible('input[name="cred[application_key]"]').catch(() => false);
    check('only the selected provider\'s credential fields are shown', azureFieldVisible && !ovhFieldVisible,
      `azure=${azureFieldVisible} ovh=${ovhFieldVisible}`);

    // OVH's API region is a fixed set, so the core renders it as a dropdown
    // rather than asking somebody to type a region code.
    const regionOptions = await page.$$eval('select[name="cred[endpoint]"] option', (o) => o.map((n) => n.textContent.trim())).catch(() => []);
    check('a credential field with fixed choices renders as a dropdown', regionOptions.length >= 3, regionOptions.join(' | '));

    // The gap this check found the first time it ran: credential fields have to
    // be on the form you create the account with.
    for (const field of ['tenant_id', 'client_id', 'client_secret']) {
      const visible = await page.isVisible(`input[name="cred[${field}]"]`).catch(() => false);
      check(`the ${field} field is on the new-account form`, visible);
    }

    check('the form states the plugin never writes to Azure',
      /never writes to a cloud account/i.test(await page.textContent('body')));

    // A contract with no dates is the ordinary shape of a cloud subscription,
    // and core's contract dropdown hides it: "expired" is computed from
    // begin_date + duration, which is NULL arithmetic when there are no dates,
    // so the row silently fails the filter. Found in use, and worth a
    // regression check — an empty dropdown reads as "the plugin is broken".
    const datelessContract = Number(php(`
      global $DB;
      $DB->insert('glpi_contracts', ['name' => 'ZZTEST-dateless', 'entities_id' => 0, 'duration' => 12]);
      echo (int) $DB->insertId();
    `));

    await page.reload({ waitUntil: 'networkidle' });

    const contractOptions = await page.$$eval('select[name=contracts_id] option', (o) => o.map((n) => n.textContent.trim()));
    check('a contract with no dates is offered, not hidden by core\'s expiry filter',
      contractOptions.some((o) => o.includes('ZZTEST-dateless')), contractOptions.join(' | '));

    php(`global $DB; $DB->delete('glpi_contracts', ['id' => ${datelessContract}]);`);
    await page.reload({ waitUntil: 'networkidle' });

    // And with nothing to choose from, the field says why rather than looking
    // broken.
    const hints = await page.$$eval('.form-text.text-warning', (n) => n.map((x) => x.textContent.replace(/\s+/g, ' ').trim()));
    check('an empty financial dropdown explains itself', hints.length > 0, hints.join(' | '));

    await page.fill('input[name=name]', 'ZZTEST-azure');
    await page.fill('input[name="cred[tenant_id]"]', '00000000-1111-2222-3333-444444444444');
    await page.fill('input[name="cred[client_id]"]', '55555555-6666-7777-8888-999999999999');
    await page.fill('input[name="cred[client_secret]"]', 'not-a-real-secret');
    await fullPage(page, `${SHOTS}/cloud-02-account-form.png`);
    await page.click('button[name=add], input[name=add]');
    await page.waitForLoadState('networkidle');
    await noFatal('account saved');

    const accounts = q(`['FROM' => 'glpi_plugin_glpicloud_accounts', 'WHERE' => ['name' => 'ZZTEST-azure']]`);
    check('the account was created', accounts.length === 1);
    // An account that is created inactive is skipped by every cron tick, with
    // nothing on the page to say why.
    check('and it starts active', Number((accounts[0] || {}).is_active) === 1, String((accounts[0] || {}).is_active));

    const account = accounts[0] || {};
    check('it recorded the provider', account.provider === 'azure', account.provider);
    check('the credentials are not in the database in the clear',
      account.credentials && !account.credentials.includes('not-a-real-secret'),
      String(account.credentials).slice(0, 24) + '…');
    check('and they decrypt to what was typed',
      php(`$a = new GlpiPlugin\\Glpicloud\\Account(); $a->getFromDB(${Number(account.id)}); echo $a->credentials()['client_secret'] ?? '';`)
      === 'not-a-real-secret');

    // ------------------------------------------- the secret round trip
    const form = `${BASE}/plugins/glpicloud/front/account.form.php?id=${Number(account.id)}`;
    await page.goto(form, { waitUntil: 'networkidle' });
    await noFatal('account form');

    const secretShown = await page.inputValue('input[name="cred[client_secret]"]');
    check('the stored secret is never sent back to the browser', !secretShown.includes('not-a-real-secret'), secretShown);
    check('a placeholder stands in for it', secretShown.length > 0, secretShown);
    check('a non-secret credential is shown as stored',
      (await page.inputValue('input[name="cred[tenant_id]"]')).startsWith('00000000-'));

    // Saving an unrelated field must not overwrite the secret with bullets.
    await page.fill('input[name=sync_interval]', '900');
    await page.click('button[name=update], input[name=update]');
    await page.waitForLoadState('networkidle');

    check('editing another field left the secret intact',
      php(`$a = new GlpiPlugin\\Glpicloud\\Account(); $a->getFromDB(${Number(account.id)}); echo $a->credentials()['client_secret'] ?? '';`)
      === 'not-a-real-secret');
    check('and the field it did edit was saved',
      q(`['FROM' => 'glpi_plugin_glpicloud_accounts', 'WHERE' => ['id' => ${Number(account.id)}]]`)[0].sync_interval === 900);

    // -------------------------------------------- check credentials, and fail
    await page.goto(form, { waitUntil: 'networkidle' });
    await page.click('button[name=check]');
    await page.waitForLoadState('networkidle');
    await noFatal('credential check');

    const checkMsgs = (await messages(page)).join(' | ');
    check('a wrong credential is reported, not thrown',
      /refused|could not|Entra|slow down|reach/i.test(checkMsgs), checkMsgs);
    check('the failure message does not leak the secret', !checkMsgs.includes('not-a-real-secret'));
    await fullPage(page, `${SHOTS}/cloud-03-check-credentials.png`, { keepToasts: true });

    // ------------------------------------------------------------ sync now
    await page.goto(form, { waitUntil: 'networkidle' });
    await page.click('button[name=sync]');
    await page.waitForLoadState('networkidle');
    await noFatal('sync now');

    const syncMsgs = (await messages(page)).join(' | ');
    check('the sync reports its outcome in words', /Sync|refused|could not|Entra|reach/i.test(syncMsgs), syncMsgs);

    const runs = q(`['FROM' => 'glpi_plugin_glpicloud_runs', 'WHERE' => ['plugin_glpicloud_accounts_id' => ${Number(account.id)}]]`);
    check('the run was recorded', runs.length >= 1, runs.map((r) => r.status).join(','));
    check('a run against bad credentials is not recorded as ok',
      runs.every((r) => r.status !== 'ok'), runs.map((r) => r.status).join(','));
    check('and it is closed, not left holding the lock',
      runs.every((r) => r.finished_at), runs.map((r) => String(r.finished_at)).join(','));
    check('nothing was invented in the inventory',
      q(`['FROM' => 'glpi_plugin_glpicloud_resources', 'WHERE' => ['plugin_glpicloud_accounts_id' => ${Number(account.id)}]]`).length === 0);

    await page.goto(`${BASE}/plugins/glpicloud/front/run.php`, { waitUntil: 'networkidle' });
    await noFatal('runs list');
    check('the run is visible in the list', /failed|partial/i.test(await page.textContent('body')));
    await fullPage(page, `${SHOTS}/cloud-04-runs.png`);

    // ------------------------------------------------- a resource, rendered
    //
    // Everything above proves the plumbing; this is the page a technician
    // actually opens, and until something has been collected it has never been
    // rendered with anything in it. Seeded directly rather than swept, because
    // the sweep is what tests/sync.php covers and this is about the page.
    const seeded = JSON.parse(php(`
      global $DB;
      $now = date('Y-m-d H:i:s');
      $DB->insert('glpi_plugin_glpicloud_resources', [
          'plugin_glpicloud_accounts_id' => ${Number(account.id)},
          'provider' => 'azure', 'scope' => '11111111-2222-3333-4444-555555555555',
          'service' => 'compute', 'type' => 'virtualmachine',
          'native_id' => '/subscriptions/zztest/vm/web-01', 'name' => 'ZZTEST-web-01',
          'state' => 'running',
          'tags' => json_encode(['client' => 'acme', 'env' => 'prod']),
          'attributes' => json_encode(['azure_type' => 'microsoft.compute/virtualMachines', 'location' => 'uksouth', 'properties' => ['provisioningState' => 'Succeeded']]),
          'entities_id' => 0, 'checksum' => str_repeat('a', 64),
          'first_seen' => $now, 'last_seen' => $now, 'date_creation' => $now, 'date_mod' => $now,
      ]);
      $rid = (int) $DB->insertId();
      $DB->insert('glpi_plugin_glpicloud_resourcehistories', [
          'plugin_glpicloud_resources_id' => $rid, 'date' => $now,
          'field' => 'state', 'old_value' => 'stopped', 'new_value' => 'running',
      ]);
      $DB->insert('glpi_plugin_glpicloud_runs', [
          'plugin_glpicloud_accounts_id' => ${Number(account.id)}, 'entities_id' => 0,
          'status' => 'partial', 'started_at' => $now, 'finished_at' => $now,
          'resources_seen' => 4, 'resources_new' => 1, 'errors' => json_encode(['compute: the fixture was told to fail']),
      ]);
      echo json_encode(['resource' => $rid, 'run' => (int) $DB->insertId()]);
    `));

    await page.goto(`${BASE}/plugins/glpicloud/front/resource.form.php?id=${seeded.resource}`, { waitUntil: 'networkidle' });
    await noFatal('resource detail');

    const detail = await page.textContent('body');
    check('the resource page shows what it is', /virtualmachine/.test(detail) && /running/.test(detail));
    check('and which account and subscription it came from', /ZZTEST-azure/.test(detail) && /11111111-2222/.test(detail));
    check('its tags are shown', /client=acme/.test(detail.replace(/\s+/g, '')) || /acme/.test(detail));
    check('the provider payload is on the page, behind a disclosure',
      (await page.$$('details')).length > 0 && /microsoft.compute\/virtualMachines/.test(detail));
    check('and its recent changes are listed', /stopped/.test(detail) && /Recent changes/i.test(detail));
    // Nothing here is editable, so a Save button would be a control that
    // silently does nothing.
    check('the read-only page offers no Save button',
      (await page.$$('button[name=update], input[name=update]')).length === 0);
    await fullPage(page, `${SHOTS}/cloud-05-resource.png`);

    await page.goto(`${BASE}/plugins/glpicloud/front/run.form.php?id=${seeded.run}`, { waitUntil: 'networkidle' });
    await noFatal('run detail');

    const runPage = await page.textContent('body');
    check('the run page says what went wrong', /the fixture was told to fail/.test(runPage));
    check('and what it managed to do', /partial/i.test(runPage));
    await fullPage(page, `${SHOTS}/cloud-06-run.png`);

    // The list, now that it has something in it and real columns.
    await page.goto(`${BASE}/plugins/glpicloud/front/resource.php`, { waitUntil: 'networkidle' });
    const columns = await page.$$eval('table th', (n) => n.map((x) => x.textContent.replace(/\s+/g, ' ').trim()).filter(Boolean));
    check('the resource list opens with useful columns, not just a name',
      columns.length >= 4, columns.join(' | '));
    check('including what kind of thing each row is', columns.some((c) => /type/i.test(c)), columns.join(' | '));
    await fullPage(page, `${SHOTS}/cloud-07-resources.png`);

    // ------------------------------------------------ the same walk, on OVH
    //
    // A second provider is the only real test of the seam: the core must take
    // an account for it with no change of its own, including a credential field
    // whose value is a fixed choice rather than typed text.
    await page.goto(`${BASE}/plugins/glpicloud/front/account.form.php`, { waitUntil: 'networkidle' });
    await page.selectOption('select[name=provider]', 'ovh').catch(async () => {
      // GLPI renders the dropdown through select2, so the native select may be
      // hidden; set it and fire the event jQuery listeners are bound to.
      await page.evaluate(() => {
        const s = document.querySelector('select[name=provider]');
        s.value = 'ovh';
        window.jQuery ? window.jQuery(s).trigger('change') : s.dispatchEvent(new Event('change'));
      });
    });
    await page.waitForTimeout(300);

    check('choosing OVH reveals its fields and hides Azure\'s',
      (await page.isVisible('input[name="cred[application_key]"]').catch(() => false))
      && !(await page.isVisible('input[name="cred[tenant_id]"]').catch(() => false)));

    await page.fill('input[name=name]', 'ZZTEST-ovh');
    await page.fill('input[name="cred[application_key]"]', 'zztest-app-key');
    await page.fill('input[name="cred[application_secret]"]', 'zztest-app-secret');
    await page.fill('input[name="cred[consumer_key]"]', 'zztest-consumer-key');
    await fullPage(page, `${SHOTS}/cloud-08-ovh-account.png`);
    await page.click('button[name=add], input[name=add]');
    await page.waitForLoadState('networkidle');
    await noFatal('ovh account saved');

    const ovhAccounts = q(`['FROM' => 'glpi_plugin_glpicloud_accounts', 'WHERE' => ['name' => 'ZZTEST-ovh']]`);
    check('the OVH account was created', ovhAccounts.length === 1);

    const ovh = ovhAccounts[0] || {};
    check('with the OVH provider', ovh.provider === 'ovh', ovh.provider);
    check('and the region it was given',
      php(`$a = new GlpiPlugin\\Glpicloud\\Account(); $a->getFromDB(${Number(ovh.id)}); echo $a->credentials()['endpoint'] ?? '';`) === 'us');
    check('its secrets are encrypted too',
      ovh.credentials && !String(ovh.credentials).includes('zztest-app-secret'));

    await page.goto(`${BASE}/plugins/glpicloud/front/account.form.php?id=${Number(ovh.id)}`, { waitUntil: 'networkidle' });
    check('the region comes back as the stored choice',
      (await page.inputValue('select[name="cred[endpoint]"]').catch(() => '')) === 'us');
    check('and the consumer key is not sent back to the browser',
      !(await page.inputValue('input[name="cred[consumer_key]"]')).includes('zztest-consumer-key'));

    // A real round trip to OVH with credentials that cannot work: what matters
    // is that it comes back as a message rather than a stack trace.
    await page.click('button[name=check]');
    await page.waitForLoadState('networkidle');
    await noFatal('ovh credential check');

    const ovhMsgs = (await messages(page)).join(' | ');
    check('OVH refuses the credentials in words, not a trace',
      /refused|Invalid|could not|reach|slow down|granted/i.test(ovhMsgs), ovhMsgs);
    check('and the failure does not leak the secret', !ovhMsgs.includes('zztest-app-secret'));

    // ------------------------------------------- native Management wiring
    check('a cloud resource can carry an Infocom',
      php(`global $CFG_GLPI; echo in_array(GlpiPlugin\\Glpicloud\\Resource::class, $CFG_GLPI['infocom_types'], true) ? 'yes' : 'no';`) === 'yes');
    check('and hang off a contract',
      php(`global $CFG_GLPI; echo in_array(GlpiPlugin\\Glpicloud\\Resource::class, $CFG_GLPI['contract_types'], true) ? 'yes' : 'no';`) === 'yes');

  } catch (e) {
    check('the walk-through completed', false, e.message);
  } finally {
    // ------------------------------------------------------------- cleanup
    php(`
      global $DB;
      foreach ($DB->request(['FROM' => 'glpi_plugin_glpicloud_accounts', 'WHERE' => ['name' => ['LIKE', 'ZZTEST-%']]]) as $row) {
          $a = new GlpiPlugin\\Glpicloud\\Account();
          $a->getFromDB((int) $row['id']);
          $a->delete(['id' => (int) $row['id']], 1);
      }
    `);

    // Settings back to exactly what was read at the start — raw, never through
    // the Config API, which encrypts declared secrets on the way in.
    for (const [name, value] of Object.entries(settingsBefore)) {
      php(`global $DB; $DB->update('glpi_configs', ['value' => ${JSON.stringify(String(value))}], ['context' => 'plugin:glpicloud', 'name' => ${JSON.stringify(name)}]);`);
    }

    const now = {
      accounts: q(`['FROM' => 'glpi_plugin_glpicloud_accounts']`).map((r) => Number(r.id)),
      resources: q(`['FROM' => 'glpi_plugin_glpicloud_resources']`).length,
      runs: q(`['FROM' => 'glpi_plugin_glpicloud_runs']`).length,
    };

    check('every account this script created is gone',
      now.accounts.length === theirs.accounts.length, `${now.accounts.length} vs ${theirs.accounts.length} before`);
    check('and it left somebody else\'s inventory alone',
      now.resources === theirs.resources && now.runs === theirs.runs,
      `resources ${now.resources}/${theirs.resources}, runs ${now.runs}/${theirs.runs}`);

    const restored = Object.fromEntries(q(`['FROM' => 'glpi_configs', 'WHERE' => ['context' => 'plugin:glpicloud']]`)
      .map((r) => [r.name, r.value]));
    check('and every setting is back as it was found',
      Object.entries(settingsBefore).every(([k, v]) => restored[k] === v),
      JSON.stringify(restored));

    check('no javascript errors anywhere', errors.length === 0, errors.join(' | '));
    check('every page and form post answered without an HTTP error', httpFailures.length === 0, httpFailures.join(' | '));

    await browser.close();
    console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(', ')}` : '\nall checks passed');
    process.exit(fail.length ? 1 : 0);
  }
})();
