// Drives the rendered buy form (from bfc_calc_check.php) in headless Chrome.
const puppeteer = require('puppeteer');
const fs = require('fs');
(async () => {
  const html = fs.readFileSync(process.argv[2], 'utf8').replace(/http:\/\/localhost\//g, 'https://playlist.nivessa.com/');
  fs.writeFileSync('/tmp/bfc.html', html);
  const browser = await puppeteer.launch({ args: ['--no-sandbox'] });
  const page = await browser.newPage();
  await page.setViewport({ width: 1600, height: 1200 });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  // Never let the test page talk to prod (autosave, demand lookups).
  await page.setRequestInterception(true);
  page.on('request', r => (r.method() !== 'GET' || /demand|autosave/.test(r.url())) ? r.abort() : r.continue());
  await page.goto('file:///tmp/bfc.html', { waitUntil: 'networkidle2', timeout: 90000 });
  const ok = (name, cond, extra) => console.log((cond ? 'PASS ' : 'FAIL ') + name + (extra !== undefined ? ' -> ' + JSON.stringify(extra) : ''));
  const row = i => `#offer_lines_table tbody tr:nth-child(${i})`;
  const st = await page.evaluate(() => ({
    rows: $('#offer_lines_table tbody tr').length,
    signedBox: $('.bfc-signed').length,
    typeS2: $('#offer_lines_table select[name$="[item_type]"]').first().hasClass('select2-hidden-accessible'),
    gradeS2: $('#offer_lines_table select.bfc-grade').first().hasClass('select2-hidden-accessible'),
    dispS2: $('#offer_lines_table select.bfc-disposition').first().hasClass('select2-hidden-accessible'),
    opts: $('#offer_lines_table select[name$="[item_type]"]').first().find('option').map(function () { return this.text; }).get().filter(t => /rare|Signed item/.test(t)),
  }));
  ok('page has line rows', st.rows > 0, st.rows);
  ok('signed checkbox gone', st.signedBox === 0);
  ok('type/grade/goes-to are searchable (select2)', st.typeS2 && st.gradeS2 && st.dispS2, st);
  ok('new types in Type list', st.opts.length === 11, st.opts);

  // Type-to-search: focus row 1's Type box, type "signed", Enter.
  async function pick(rowN, selSel, text) {
    await page.focus(`${row(rowN)} ${selSel} + .select2-container .select2-selection`);
    await page.keyboard.type(text, { delay: 40 });
    await new Promise(r => setTimeout(r, 300));
    await page.keyboard.press('Enter');
    await new Promise(r => setTimeout(r, 300));
    return page.$eval(`${row(rowN)} ${selSel}`, s => s.value);
  }
  async function setValue(rowN, v) {
    await page.click(`${row(rowN)} input[name$="[discogs_median_price]"]`, { clickCount: 3 });
    await page.keyboard.type(String(v));
    await new Promise(r => setTimeout(r, 200));
  }
  const cell = rowN => page.evaluate(sel => ({
    value: $(sel).find('.bfc-line-value').text(), formula: $(sel).find('.bfc-line-formula').text(),
    gradeDisabled: $(sel).find('select.bfc-grade').prop('disabled'),
    placeholder: $(sel).find('input[name$="[discogs_median_price]"]').attr('placeholder'),
  }), row(rowN));

  // Lines unlock once a seller is entered.
  await page.evaluate(() => {
    $('#seller_mode').val('phone').trigger('change');
    $('#buy_offer_form input[name="seller_first_name"]').val('Test').trigger('input');
    $('#buy_offer_form input[name="seller_phone"]').val('3105550100').trigger('input');
    $('#offer_lines_table select[name$="[item_type]"]').trigger('change');
  });
  ok('lines unlocked after seller entered', await page.$eval(`${row(1)} select[name$="[item_type]"]`, s => !s.disabled));
  let v = await pick(1, 'select[name$="[item_type]"]', 'signed');
  ok('typing "signed" on Type picks Signed item', v === 'signed_item', v);
  await setValue(1, 150);
  let c = await cell(1);
  ok('Signed item $150 -> $30.00, no grade', c.value === '$30.00' && c.gradeDisabled && c.placeholder === 'signed value $', c);

  v = await pick(2, 'select[name$="[item_type]"]', 'vhs - r');
  ok('typing "vhs - r" picks VHS - rare', v === 'rare_vhs', v);
  await setValue(2, 40);
  c = await cell(2);
  ok('VHS - rare $40 -> 20% before the speed lookup ($8.00)', c.value === '$8.00' && c.gradeDisabled, c);
  // Speed tiers: what the demand lookup would set on the row.
  for (const [attrs, want] of [[{ 'data-tier': 'slow', 'data-super-fast': '0' }, '$6.00'], [{ 'data-tier': 'fast', 'data-super-fast': '0' }, '$8.80'], [{ 'data-tier': 'fast', 'data-super-fast': '1' }, '$10.00']]) {
    const got = await page.evaluate((sel, a) => { const $r = $(sel); $r.attr(a); $r.find('input[name$="[discogs_median_price]"]').trigger('input'); return $r.find('.bfc-line-value').text(); }, row(2), attrs);
    ok('VHS - rare $40 ' + JSON.stringify(attrs) + ' -> ' + want, got === want, got);
  }
  // Under $15 is blocked on Calculate.
  await setValue(2, 10);
  const msg = await page.evaluate(() => { $('#bfc_calc_error').hide().empty(); const b = $('#buy_offer_form button[type="submit"], #buy_offer_form input[type="submit"]').first(); b.trigger('click'); return $('#bfc_calc_error').text(); });
  ok('VHS - rare at $10 is blocked', /worth \$15\+/.test(msg), msg.slice(0, 200));
  await setValue(2, 40);

  v = await pick(3, 'select[name$="[item_type]"]', 'comic - r');
  ok('typing "comic - r" picks Comic - rare', v === 'rare_comic', v);

  // Grade dropdown is type-to-search too.
  v = await pick(4, 'select[name$="[item_type]"]', '1-9');
  const t4 = v;
  v = await pick(4, 'select.bfc-grade', 'vg+');
  ok('typing "vg+" on Grade picks VG+', v === 'VG+', { type: t4, grade: v });
  await setValue(4, 30);
  c = await cell(4);
  ok('record line still prices with its grade', c.value !== '$0.00' && !c.gradeDisabled, c);

  // Goes-to dropdown.
  v = await pick(4, 'select.bfc-disposition', 'holly');
  ok('typing "holly" on Goes-to picks Hollywood', v === 'hollywood', v);

  v = await pick(5, 'select[name$="[item_type]"]', 'framed');
  c = await cell(5);
  ok('typing "framed" picks Framed Art, flat $3.50', v === 'framed_art' && c.value === '$3.50', { v, c });
  v = await pick(6, 'select[name$="[item_type]"]', 'framed art - r');
  await setValue(6, 80);
  c = await cell(6);
  ok('typing "framed art - r" picks Framed Art - rare, $80 -> $16.00 before speed lookup', v === 'rare_framed_art' && c.value === '$16.00', { v, c });

  v = await pick(7, 'select[name$="[item_type]"]', 'card p');
  await setValue(7, 6);
  c = await cell(7);
  ok('typing "card p" picks Card Packs, $6 allowed -> $1.20', v === 'card_pack' && c.value === '$1.20', { v, c });
  v = await pick(3, 'select[name$="[item_type]"]', 'pokemon - r');
  ok('typing "pokemon - r" picks Pokemon - rare', v === 'rare_pokemon', v);
  v = await pick(3, 'select[name$="[item_type]"]', 'nba - r');
  ok('typing "nba - r" picks NBA - rare', v === 'rare_nba', v);

  // Add line keeps working with select2 on the new row.
  await page.click('#add_line_btn');
  await new Promise(r => setTimeout(r, 300));
  const n = await page.$$eval('#offer_lines_table tbody tr', r => r.length);
  v = await pick(n, 'select[name$="[item_type]"]', 'dvd - r');
  ok('new row: typing "dvd - r" picks DVD - rare', v === 'rare_dvd', v);

  await page.screenshot({ path: 'bfc.png', clip: await page.$eval('#offer_lines_table', e => { const r = e.getBoundingClientRect(); return { x: 0, y: r.top + window.scrollY - 10, width: 1600, height: Math.min(r.height + 20, 900) }; }) });
  const lad = await page.evaluate(() => ({
    total: $('#bfc_running_total').text(), fin: $('#bfc_running_final').text(),
    start: $('[name="starting_offer_cash"]').val(), second: $('[name="second_offer_cash"]').val(),
    finalCash: $('[name="final_offer_cash"]').val(), finalCredit: $('[name="final_offer_credit"]').val(),
  }));
  const tot = parseFloat(lad.total.replace(/[$,]/g, ''));
  const want = Math.max(1, Math.round(tot * 0.95));
  ok('suggested final is whole dollars = round(95% of total)', lad.fin === '$' + want.toFixed(2) && (!lad.finalCash || parseFloat(lad.finalCash) === want), lad);
  ok('ladder cash offers are whole dollars', [lad.start, lad.second, lad.finalCash].every(v => !v || /\.00$/.test(v)), lad);
  ok('no JS errors', errors.length === 0, errors.slice(0, 5));
  await browser.close();
})().catch(e => { console.log('FAIL crashed: ' + e.message); process.exit(1); });
