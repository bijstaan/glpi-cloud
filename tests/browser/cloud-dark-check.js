// SPDX-License-Identifier: GPL-3.0-or-later
// Copyright (C) 2026 Bijstaan
// Dark-palette conformance for glpi-cloud's own surfaces.
//
// public/css/glpicloud.css makes two specific claims, both borrowed from the
// house formula in DARKTHEME.md: that muted text on this plugin's pages clears
// 4.5:1 on `auror_dark` (the tightest of GLPI's dark palettes — body #1f2936,
// with a card surface *lighter* than the body), and that the tag badges do too.
// A stylesheet is the easiest place in a plugin to be confidently wrong, so the
// claims are measured rather than asserted.
//
// The measurement is the canonical one from dark-audit.js: colours resolved by
// the browser's own CSS engine through a 1x1 canvas — a regex over
// `color(srgb ...)` / scientific-notation channels fails closed and silently
// drops the element from the audit — and the background composited down the
// whole ancestor chain, because a `-lt` badge tint at 10% alpha is mostly
// whatever is behind it.
//
// It logs in as the existing disposable dark-test account rather than touching
// anybody's real palette.
//
//   cd glpi-cloud/tests/browser && node cloud-dark-check.js
'use strict';

const { chromium } = require('playwright');
const { execSync } = require('child_process');

const BASE = process.env.GLPI_URL || 'http://localhost:8081';
const LOGIN = 'glpimajor-darktest';
const PASSWORD = 'Glpimajor-Dark-2026!';

const fail = [];
const check = (name, ok, detail) => {
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? ' :: ' + String(detail).slice(0, 160) : ''}`);
  if (!ok) fail.push(name);
};

const php = (code) =>
  execSync('docker exec -i glpi-glpi-1 php', {
    encoding: 'utf8',
    input:
      '<?php require "/var/www/glpi/vendor/autoload.php";'
      + '(new Glpi\\Kernel\\Kernel(Glpi\\Application\\Environment::PRODUCTION->value))->boot();'
      + '(new Auth())->login("glpi","glpi",true);(new Plugin())->init(true);global $DB;' + code,
  }).trim();

const MEASURE = () => {
  let canvas, ctx;
  const parse = (str) => {
    if (!str) return null;
    if (!canvas) { canvas = document.createElement('canvas'); ctx = canvas.getContext('2d'); }
    ctx.fillStyle = '#000';
    ctx.fillStyle = str;
    ctx.fillRect(0, 0, 1, 1);
    const d = ctx.getImageData(0, 0, 1, 1).data;
    ctx.clearRect(0, 0, 1, 1);
    return { r: d[0], g: d[1], b: d[2], a: d[3] / 255 };
  };
  const flatten = (fg, bg) => ({
    r: fg.r * fg.a + bg.r * (1 - fg.a),
    g: fg.g * fg.a + bg.g * (1 - fg.a),
    b: fg.b * fg.a + bg.b * (1 - fg.a),
  });
  const lum = (c) => {
    const f = [c.r, c.g, c.b].map((v) => {
      const s = v / 255;
      return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * f[0] + 0.7152 * f[1] + 0.0722 * f[2];
  };
  const contrast = (a, b) => {
    const l1 = lum(a) + 0.05;
    const l2 = lum(b) + 0.05;
    return l1 > l2 ? l1 / l2 : l2 / l1;
  };
  const effectiveBg = (el) => {
    const chain = [];
    for (let n = el; n; n = n.parentElement) chain.unshift(n);
    let acc = { r: 255, g: 255, b: 255, a: 1 };
    for (const n of chain) {
      const c = parse(getComputedStyle(n).backgroundColor);
      if (c && c.a > 0) acc = flatten(c, acc);
    }
    return acc;
  };

  const dark = document.documentElement.getAttribute('data-glpi-theme-dark') === '1';
  const scopes = Array.from(document.querySelectorAll('.glpicloud-config, .glpicloud-surface, .glpicloud-page'));
  const seen = new Set();
  const out = { dark, scoped: scopes.length, measured: 0, worst: null, failures: [], nearWhite: [] };

  scopes.forEach((scope) => {
    [scope, ...scope.querySelectorAll('*')].forEach((el) => {
      if (seen.has(el)) return;
      seen.add(el);

      const cs = getComputedStyle(el);
      const bg = effectiveBg(el);

      // Trap 1: a surface painting itself near #e6e6e6 while still carrying the
      // dark body's light text — the --tblr-bg-surface-secondary trap.
      const own = parse(cs.backgroundColor);
      if (own && own.a > 0.9 && Math.abs(own.r - 230) < 25 && Math.abs(own.g - 230) < 25 && Math.abs(own.b - 230) < 25) {
        const fg = parse(cs.color);
        if (fg && contrast(flatten(fg, bg), bg) < 3) {
          out.nearWhite.push(el.className || el.tagName);
        }
      }

      // Only elements that actually paint text of their own.
      const text = Array.from(el.childNodes).some((n) => n.nodeType === 3 && n.textContent.trim().length > 1);
      if (!text) return;

      const fgRaw = parse(cs.color);
      if (!fgRaw) return;

      const opacity = parseFloat(cs.opacity);
      const fg = flatten({ ...fgRaw, a: fgRaw.a * (Number.isFinite(opacity) ? opacity : 1) }, bg);

      const px = parseFloat(cs.fontSize) || 16;
      const bold = (parseInt(cs.fontWeight, 10) || 400) >= 700;
      const floor = px >= 24 || (bold && px >= 18.5) ? 3 : 4.5;

      const ratio = contrast(fg, bg);
      out.measured++;

      if (!out.worst || ratio < out.worst.ratio) {
        out.worst = { ratio: Math.round(ratio * 100) / 100, cls: String(el.className).slice(0, 60), text: el.textContent.trim().slice(0, 40) };
      }

      if (ratio < floor) {
        out.failures.push({
          ratio: Math.round(ratio * 100) / 100,
          floor,
          cls: String(el.className).slice(0, 60),
          text: el.textContent.trim().slice(0, 50),
        });
      }
    });
  });

  return out;
};

(async () => {
  const exists = php(`$row = $DB->request(["FROM" => "glpi_users", "WHERE" => ["name" => "${LOGIN}"]])->current(); echo $row ? "yes" : "no";`);

  if (exists !== 'yes') {
    console.log(`skipped: the shared dark-test account ${LOGIN} does not exist on this instance`);
    process.exit(0);
  }

  // Only ever this disposable account's palette — never a real user's.
  php(`$DB->update("glpi_users", ["palette" => "auror_dark"], ["name" => "${LOGIN}"]);`);

  const browser = await chromium.launch();
  const page = await browser.newPage({ viewport: { width: 1500, height: 1100 } });

  try {
    await page.goto(`${BASE}/`, { waitUntil: 'networkidle' });
    await page.fill('#login_name', LOGIN);
    await page.fill('input[type=password]', PASSWORD);
    await page.click('button[type=submit]');
    await page.waitForLoadState('networkidle');

    const palette = await page.getAttribute('html', 'data-glpi-theme-dark');
    check('the dark palette is actually active', palette === '1', `data-glpi-theme-dark=${palette}`);

    for (const [label, url] of [
      ['settings', '/plugins/glpicloud/front/config.php'],
      ['resources', '/plugins/glpicloud/front/resource.php'],
      ['accounts', '/plugins/glpicloud/front/account.php'],
      ['account form', '/plugins/glpicloud/front/account.form.php'],
      ['runs', '/plugins/glpicloud/front/run.php'],
    ]) {
      await page.goto(BASE + url, { waitUntil: 'networkidle' });
      const r = await page.evaluate(MEASURE);

      // A list page is entirely core-rendered — Search::show() and nothing of
      // ours — so there is no plugin-owned markup to audit. Saying so beats
      // failing on markup this plugin does not own and cannot fix.
      if (r.scoped === 0) {
        console.log(`SKIP  ${label}: no plugin-owned markup on this page (core-rendered list)`);
        continue;
      }

      check(`${label}: ${r.measured} text elements measured`, r.measured > 0);
      check(`${label}: nothing paints a near-white panel under light text`, r.nearWhite.length === 0, r.nearWhite.join(', '));
      check(`${label}: every text element clears its contrast floor`, r.failures.length === 0,
        r.failures.map((f) => `${f.ratio}:1 < ${f.floor} [${f.cls}] "${f.text}"`).join(' | '));
      if (r.worst) {
        console.log(`      worst on ${label}: ${r.worst.ratio}:1  [${r.worst.cls}] "${r.worst.text}"`);
      }
    }
  } catch (e) {
    check('the audit completed', false, e.message);
  } finally {
    await browser.close();
    console.log(fail.length ? `\n${fail.length} FAILED: ${fail.join(', ')}` : '\nall dark checks passed');
    process.exit(fail.length ? 1 : 0);
  }
})();
