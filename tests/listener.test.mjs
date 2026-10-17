// Tests for assets/js/tallyho.js in jsdom.
// Run: npm install --no-save jsdom@24 && node tests/listener.test.mjs
import { JSDOM } from 'jsdom';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import assert from 'node:assert/strict';

const here = dirname(fileURLToPath(import.meta.url));
const listener = readFileSync(join(here, '..', 'assets', 'js', 'tallyho.js'), 'utf8');

// Same guarded helper the plugin prints in <head>.
const helper = "(function(){if(typeof window.isTrackingOptOut==='function')return;window.isTrackingOptOut=function isTrackingOptOut(){try{if(navigator.globalPrivacyControl===true)return true;var d=[navigator.doNotTrack,window.doNotTrack,navigator.msDoNotTrack];for(var i=0;i<d.length;i++){if(d[i]==='1'||d[i]===1||d[i]==='yes')return true;}return false;}catch(e){return false;}};})();";

const marker = (entry, extra = '') =>
  `<span class="tallyho-conversion" hidden data-source="gravityforms" data-form-id="3" data-entry-id="${entry}" data-form-title="Request an Estimate" data-conversion-name="estimate_form" data-delivery="page"${extra}></span>`;

// Minimal jQuery stand-in: on() + trigger() on document, enough for gform_confirmation_loaded.
const fakeJQuery = `
window.jQuery = (function(){
  var handlers = {};
  function $(target){ return {
    on: function(name, fn){ (handlers[name] = handlers[name] || []).push(fn); return this; },
    trigger: function(name, args){ (handlers[name] || []).forEach(function(fn){ fn.apply(null, [{type:name}].concat(args || [])); }); return this; }
  }; }
  $.fn = { on: function(){} };
  return $;
})();`;

function page({ body = '', cookie = '', gpc = false, dnt, preSent = [], jquery = false, url = 'https://example.test/thanks/' } = {}) {
  const dom = new JSDOM(`<!doctype html><html><head></head><body>${body}</body></html>`, {
    url,
    runScripts: 'outside-only',
    pretendToBeVisual: true,
  });
  const w = dom.window;
  if (gpc) Object.defineProperty(w.navigator, 'globalPrivacyControl', { value: true, configurable: true });
  if (dnt !== undefined) Object.defineProperty(w.navigator, 'doNotTrack', { value: dnt, configurable: true });
  if (cookie) w.document.cookie = cookie;
  for (const id of preSent) w.sessionStorage.setItem('tallyho_sent_' + id, '1');
  w.eval(helper);
  if (jquery) w.eval(fakeJQuery);
  w.eval(listener);
  return w;
}

const pushes = (w) => (w.dataLayer || []).filter((e) => e.event === 'tallyho_conversion');
const tick = (ms = 0) => new Promise((r) => setTimeout(r, ms));

const tests = [];
const test = (name, fn) => tests.push([name, fn]);

test('non-AJAX: marker in page pushes once with full payload', async () => {
  const w = page({ body: marker(1542) });
  await tick();
  const p = pushes(w);
  assert.equal(p.length, 1);
  assert.deepEqual(
    { ...p[0] },
    {
      event: 'tallyho_conversion',
      lx_source: 'gravityforms',
      form_id: '3',
      form_title: 'Request an Estimate',
      conversion_name: 'estimate_form',
      entry_id: '1542',
      event_id: 'gf_1542',
      delivery: 'page',
    }
  );
});

test('rescanning the same marker does not push again', async () => {
  const w = page({ body: marker(7) });
  await tick();
  w.tallyho.scan();
  w.tallyho.scan();
  assert.equal(pushes(w).length, 1);
});

test('entry already sent this session (refresh / back button) is skipped', async () => {
  const w = page({ body: marker(99), preSent: ['gf_99'] });
  await tick();
  assert.equal(pushes(w).length, 0);
});

test('Global Privacy Control suppresses the push', async () => {
  const w = page({ body: marker(5), gpc: true });
  await tick();
  assert.equal(pushes(w).length, 0);
});

test('Do Not Track "1" suppresses the push', async () => {
  const w = page({ body: marker(6), dnt: '1' });
  await tick();
  assert.equal(pushes(w).length, 0);
});

test('Do Not Track "0" does not suppress', async () => {
  const w = page({ body: marker(8), dnt: '0' });
  await tick();
  assert.equal(pushes(w).length, 1);
});

test('AJAX: marker inserted later is pushed on gform_confirmation_loaded', async () => {
  const w = page({ jquery: true });
  await tick();
  assert.equal(pushes(w).length, 0);
  const wrap = w.document.createElement('div');
  wrap.innerHTML = 'Thanks!' + marker(321).replace('data-delivery="page"', 'data-delivery="ajax"');
  w.document.body.appendChild(wrap);
  w.jQuery(w.document).trigger('gform_confirmation_loaded', [3]);
  const p = pushes(w);
  assert.equal(p.length, 1);
  assert.equal(p[0].event_id, 'gf_321');
  assert.equal(p[0].delivery, 'ajax');
});

test('AJAX: jQuery loading after the listener still binds', async () => {
  const w = page({});
  w.eval(fakeJQuery);
  await tick(400);
  const wrap = w.document.createElement('div');
  wrap.innerHTML = marker(654);
  w.document.body.appendChild(wrap);
  w.jQuery(w.document).trigger('gform_confirmation_loaded', [3]);
  assert.equal(pushes(w).length, 1);
});

test('redirect: cookie is pushed once and deleted', async () => {
  const value = ['v1', 'gravityforms', 'ab12cd', '3', '777', 'estimate_form', encodeURIComponent('Request an Estimate | Deck')].join('|');
  const w = page({ cookie: `tallyho_conv=${value}; path=/` });
  await tick();
  const p = pushes(w);
  assert.equal(p.length, 1);
  assert.equal(p[0].event_id, 'gf_ab12cd_777');
  assert.equal(p[0].delivery, 'redirect');
  assert.equal(p[0].form_title, 'Request an Estimate | Deck');
  assert.ok(!/tallyho_conv=/.test(w.document.cookie), 'cookie should be deleted');
});

test('redirect: cookie is deleted even when the visitor opted out', async () => {
  const value = ['v1', 'gravityforms', 'ab12cd', '3', '778', '', 'Contact'].join('|');
  const w = page({ cookie: `tallyho_conv=${value}; path=/`, gpc: true });
  await tick();
  assert.equal(pushes(w).length, 0);
  assert.ok(!/tallyho_conv=/.test(w.document.cookie));
});

test('malformed cookie and invalid entry IDs are ignored', async () => {
  const w = page({ cookie: 'tallyho_conv=garbage; path=/', body: marker(0) + marker('abc') });
  await tick();
  assert.equal(pushes(w).length, 0);
  assert.ok(!/tallyho_conv=/.test(w.document.cookie));
});

test('two different entries on one page push separately', async () => {
  const w = page({ body: marker(11) + marker(12) });
  await tick();
  assert.deepEqual([...pushes(w).map((p) => p.event_id)], ['gf_11', 'gf_12']);
});

test('site key is included in event_id', async () => {
  const w = page({ body: marker(42, ' data-site="ab12cd"') });
  await tick();
  assert.equal(pushes(w)[0].event_id, 'gf_ab12cd_42');
});

test('invalid site key is ignored', async () => {
  const w = page({ body: marker(43, ' data-site="<x>"') });
  await tick();
  assert.equal(pushes(w)[0].event_id, 'gf_43');
});

test('AJAX without jQuery: marker added later is caught by the page observer', async () => {
  const w = page({});
  await tick();
  const wrap = w.document.createElement('div');
  wrap.innerHTML = 'Thanks!' + marker(900);
  w.document.body.appendChild(wrap);
  await tick(20);
  const p = pushes(w);
  assert.equal(p.length, 1);
  assert.equal(p[0].event_id, 'gf_900');
});

test('observer and jQuery event together still push once', async () => {
  const w = page({ jquery: true });
  await tick();
  const wrap = w.document.createElement('div');
  wrap.innerHTML = marker(901);
  w.document.body.appendChild(wrap);
  w.jQuery(w.document).trigger('gform_confirmation_loaded', [3]);
  await tick(20);
  assert.equal(pushes(w).length, 1);
});

test('redirect cookie set with a parent domain is deleted', async () => {
  const value = ['v1', 'gravityforms', 'ab12cd', '3', '779', '', 'Contact'].join('|');
  const w = page({ url: 'https://www.example.test/thanks/', cookie: `tallyho_conv=${value}; path=/; domain=.example.test` });
  await tick();
  assert.equal(pushes(w).length, 1);
  assert.ok(!/tallyho_conv=/.test(w.document.cookie), 'domain cookie should be deleted');
});

test('debug flag from URL is stored and cleared', async () => {
  const w = page({ url: 'https://example.test/?tallyho_debug=1' });
  assert.equal(w.localStorage.getItem('tallyho_debug'), '1');
  const w2 = page({ url: 'https://example.test/?tallyho_debug=0' });
  assert.equal(w2.localStorage.getItem('tallyho_debug'), null);
});

let failed = 0;
for (const [name, fn] of tests) {
  try {
    await fn();
    console.log('ok   ' + name);
  } catch (e) {
    failed++;
    console.log('FAIL ' + name + '\n     ' + (e && e.message));
  }
}
console.log(failed ? `${failed} failed` : `all ${tests.length} passed`);
process.exit(failed ? 1 : 0);
