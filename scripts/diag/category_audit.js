// READ-ONLY category audit (Sarah 2026-10-29). Input: the JSON from
// category_audit_export.php. Output: overlap counts in the log, and
// suspects.csv / uncategorized.csv for review. Nothing is written to the DB.
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const data = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const outDir = process.argv[3] || '.';

// The live matcher, with its word lists cached (it runs ~2x per product).
const src = fs.readFileSync(path.join(__dirname, '../../public/js/mass_add_category_match.js'), 'utf8')
  .replace('function tokens(s) {', 'var __tc = new Map(); function tokens(s) { var k = String(s || ""); var v = __tc.get(k); if (!v) { v = tokens0(s); __tc.set(k, v); } return v.slice(); }\n    function tokens0(s) {');
const sb = { module: { exports: {} } };
vm.runInNewContext(src, sb);
const { match, tokens } = sb.module.exports;

const catName = {}, parent = {}, subsOf = {};
data.cats.forEach(([id, name, p]) => { catName[id] = String(name).trim(); parent[id] = p; });
data.cats.forEach(([id, , p]) => { if (p) (subsOf[p] = subsOf[p] || []).push(id); });
const tops = data.cats.filter(c => !c[2]).map(c => c[0]).sort((a, b) => catName[a].localeCompare(catName[b]));
const options = [];
tops.forEach(t => {
  const subs = (subsOf[t] || []).sort((a, b) => catName[a].localeCompare(catName[b]));
  if (subs.length) subs.forEach(s => options.push({ value: t + '_' + s, label: catName[t] + ' > ' + catName[s] }));
  else options.push({ value: t + '_0', label: catName[t] });
});
const labelOf = v => (options.find(o => o.value === v) || {}).label || '';

// Same thing in two places: not a mistake, that's the overlap question.
const FAMILY = [
  /^(audio gear|electronics)$/, /^(toys|gift items|decorative collectibles)$/, /^(drinks|candy & chips|snacks & drinks)$/,
  /^(comics|books)$/, /^trading card/, /^(vinyl - |clearance vinyl|record cover only)/, /^cd - /, /^cassettes - /,
];
const family = n => { const l = String(n).toLowerCase(); const i = FAMILY.findIndex(r => r.test(l)); return i === -1 ? l : 'F' + i; };

function fmtClass(f) {
  const s = String(f || '').toLowerCase();
  if (!s.trim()) return '';
  if (/7"|7''|7 ?inch|\b45 ?rpm\b|\bsingle\b/.test(s)) return 'sevenin';
  if (/\bcd\b|compact dis/.test(s)) return 'cd';
  if (/cassette|\btape\b/.test(s)) return 'cassette';
  if (/\bvhs\b/.test(s)) return 'vhs';
  if (/\bdvd\b|blu.?ray/.test(s)) return 'dvd';
  if (/\blp\b|12"|\balbum\b|vinyl|\b33\b/.test(s)) return 'lp';
  return '';
}
function topClass(t) {
  const s = String(t || '').toLowerCase();
  if (/^7"/.test(s)) return 'sevenin';
  if (/^vinyl|^clearance vinyl/.test(s)) return 'lp';
  if (/^cd /.test(s)) return 'cd';
  if (/^cassettes/.test(s)) return 'cassette';
  if (/^vhs/.test(s)) return 'vhs';
  if (/^dvd/.test(s)) return 'dvd';
  return '';
}
// Words that, in a product name, really say what it is (checked on the raw
// name: "album" / "record" / "guitar" / "book" are everyday title words).
const STRONG = [
  [/\bmagazines?\b/, /^magazines$/], [/\bposters?\b/, /^posters$/], [/\bvhs\b/, /^vhs$/], [/\bdvds?\b|\bblu-?ray\b/, /^dvd/],
  [/\blaser ?discs?\b/, /^laser/], [/\bcds?\b(?!\s*player)/, /^cd /], [/\bcassettes?\b(?!\s*(?:deck|player|recorder))/, /^cassettes/],
  [/\bvinyl\b(?!\s*(?:figure|toy))|\blps?\b|12"/, /^(vinyl|clearance vinyl)/], [/7"|\b7 ?inch\b|\b45 ?rpm\b/, /^7"/], [/\b8[- ]?track\b/, /^8 track/],
  [/\bt-?shirt\b|\bhoodie\b|\bsweatshirt\b|\bjersey\b/, /^apparel/],
  [/\bturntable\b|\brecord player\b/, /^(audio gear|electronics)$/], [/\bwalkman\b/, /^(audio gear|electronics)$/],
  [/\bdiscman\b|\bcd player\b/, /^(audio gear|electronics)$/], [/\bcassette (?:deck|player|recorder)\b/, /^(audio gear|electronics)$/],
  [/\bheadphones?\b|\bspeakers? \(|\bstereo receiver\b|\bstylus\b|\bphono cartridge\b/, /^audio gear$/],
  [/\bfunko\b/, /^toys$/], [/\bplush\b/, /^toys$/], [/\bjigsaw\b|\bpuzzle\b/, /^toys$/], [/\bslipmat\b/, /^gift items$/],
  [/\(a novel\)|\bhardcover\b|\bpaperback\b/, /^books$/], [/\bnintendo\b|\bplaystation\b|\bxbox\b|\bvideo ?game\b/, /^video games$/],
];
const CLASS_HINT = { sevenin: '7 inch', lp: 'Vinyl', cd: 'CD', cassette: 'Cassettes', vhs: 'VHS', dvd: 'DVD' };

function decidedTop(name) {
  const dbg = [];
  const value = match(options, { name }, dbg);
  const line = (dbg.find(d => d.startsWith('TOP ')) || '').slice(4).split(' | cat=')[0];
  const sc = [];
  line.replace(/([^=]+?)=(-?\d+\.\d\d)(?:, |$)/g, (_, n, v) => { sc.push([n.trim(), +v]); return ''; });
  if (!sc.length || (sc[1] && sc[0][1] - sc[1][1] <= 0.01)) return { value, top: '', score: 0 };
  return { value, top: sc[0][0], score: sc[0][1] };
}
const distinct = (subName, topName) => { const tt = tokens(topName); return tokens(subName).filter(t => tt.indexOf(t) === -1); };

const byTop = {}, bySub = {};
const suspects = [], uncategorized = [];
let none = 0;
const csvq = v => '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';

for (const [id, name, sku, format, via, cid, sid, stock, inactive, created] of data.products) {
  const top = catName[cid] || '';
  const sub = sid ? (catName[sid] || '') : '';
  const cur = top ? (sub ? top + ' > ' + sub : top) : '';
  const k = top || '(no category)';
  byTop[k] = byTop[k] || { n: 0, stock: 0 }; byTop[k].n++; if (stock > 0) byTop[k].stock++;
  if (cur) { bySub[cur] = bySub[cur] || { n: 0, stock: 0 }; bySub[cur].n++; if (stock > 0) bySub[cur].stock++; }
  const row = { id, sku, name, format, via, stock, inactive, created, cur };

  if (!top) {
    none++;
    const d = decidedTop(name);
    if (d.value) uncategorized.push({ ...row, sug: labelOf(d.value), reason: 'no category' });
    continue;
  }
  const nameT = tokens(name);
  // A. The product's own format disagrees with its category (LP filed under 7", CD under Vinyl...).
  const fc = fmtClass(format), tc = topClass(top);
  if (fc && tc && fc !== tc) {
    const cond = /sealed/i.test(top) ? 'Sealed' : 'Used';
    const v = match(options, { category: CLASS_HINT[fc] + ' ' + cond, subcategory: sub, name });
    suspects.push({ ...row, sug: labelOf(v) || CLASS_HINT[fc] + ' (pick genre)', reason: 'format is "' + format + '"' });
    continue;
  }
  // B. The name clearly says another kind of product ("... VHS" filed under
  // Vinyl). Only unambiguous words count; "album", "record", "guitar", "book"
  // are everyday title words. Discogs-imported names are album titles: trusted.
  const d = decidedTop(name);
  const lname = String(name).toLowerCase();
  const hits = STRONG.filter(([w]) => w.test(lname));
  const strong = hits.map(([w]) => (lname.match(w) || [''])[0].trim());
  const strongTops = tops.map(t => catName[t]).filter(tn => hits.some(([, t]) => t.test(tn.toLowerCase())));
  if (via !== 'discogs_inventory_import' && strongTops.length && !strongTops.some(tn => family(tn) === family(top))) {
    const sugTop = d.top && strongTops.indexOf(d.top) !== -1 ? d.top : (strongTops.length === 1 ? strongTops[0] : '');
    if (sugTop) {
      const v = d.top === sugTop && d.value ? labelOf(d.value) : sugTop;
      suspects.push({ ...row, sug: v, reason: 'name says "' + strong.join(', ') + '"' });
      continue;
    }
  }
  // C. Right category, wrong subcategory, and the name names the right one
  // ("Michael Jordan ..." under Trading Cards > Digimon Cards).
  const sl = labelOf(d.value);
  if (sub && sl && sl.split(' > ')[0] === top && sl !== cur) {
    const curD = distinct(sub, top), sugD = distinct(sl.split(' > ')[1] || '', top);
    if (!/^packs?$/i.test(sub) && curD.length && !curD.some(t => nameT.indexOf(t) !== -1) && sugD.length && sugD.every(t => nameT.indexOf(t) !== -1)) {
      suspects.push({ ...row, sug: sl, reason: 'name says ' + (sl.split(' > ')[1]) });
    }
  }
}

const pr = (label, o) => console.log('AUDIT ' + label + ': ' + (o ? o.n + ' items (' + o.stock + ' in stock)' : '0'));
console.log(`AUDIT products=${data.products.length} categories=${tops.length} options=${options.length} no-category=${none}`);
console.log('AUDIT --- overlapping categories ---');
['Drinks', 'Candy & Chips', 'Snacks & Drinks', 'Electronics', 'Audio Gear', 'Gift Items', 'Toys', 'Decorative Collectibles', 'Comics', 'Books'].forEach(t => pr(t, byTop[t]));
console.log('AUDIT --- overlap subcategories ---');
Object.keys(bySub).filter(l => /^(electronics|gift items|decorative collectibles|drinks|candy & chips|snacks & drinks|comics) /i.test(l + ' ') ||
  /^toys > (squish|keychain|magnet|pin)/i.test(l) || /^apparel > (hats|new hat)/i.test(l) || /^art & photography > (alt|pop)/i.test(l) ||
  /^trading cards > packs$/i.test(l) || /^books > comic/i.test(l) || /^audio gear > (record players|walkmen|guitar|cd player)/i.test(l))
  .sort().forEach(l => pr(l, bySub[l]));
const rulesRef = data.rules.filter(r => /^(electronics|gift items|decorative collectibles|drinks|candy & chips|snacks & drinks|comics|toys)$/i.test(catName[r.category_id] || ''));
console.log('AUDIT entry rules on overlap categories: ' + rulesRef.length + (rulesRef.length ? ' -> ' + rulesRef.map(r => `#${r.id} "${r.title}" (${catName[r.category_id]}${r.sub_category_id ? ' > ' + catName[r.sub_category_id] : ''})`).join('; ') : ''));

const sortS = (a, b) => (b.stock > 0) - (a.stock > 0) || (b.via === 'mass_add') - (a.via === 'mass_add') || b.id - a.id;
suspects.sort(sortS); uncategorized.sort(sortS);
const count = (arr, f) => arr.reduce((m, x) => (m[f(x)] = (m[f(x)] || 0) + 1, m), {});
console.log('AUDIT --- suspects (likely wrong category) ---');
console.log(`AUDIT suspects=${suspects.length} in-stock=${suspects.filter(s => s.stock > 0).length} from-mass-add=${suspects.filter(s => s.via === 'mass_add').length}`);
Object.entries(count(suspects, s => s.reason.startsWith('format') ? 'format mismatch' : (s.reason.startsWith('name says') && s.sug.split(' > ')[0] === s.cur.split(' > ')[0] ? 'wrong subcategory' : 'name says other product type'))).forEach(([k, v]) => console.log('AUDIT   by reason: ' + k + ' = ' + v));
Object.entries(count(suspects, s => s.cur.split(' > ')[0])).sort((a, b) => b[1] - a[1]).slice(0, 15).forEach(([k, v]) => console.log('AUDIT   filed under: ' + k + ' = ' + v));
Object.entries(count(suspects, s => s.cur.split(' > ')[0] + '  ->  ' + s.sug.split(' > ')[0])).sort((a, b) => b[1] - a[1]).slice(0, 15).forEach(([k, v]) => console.log('AUDIT   move: ' + k + ' = ' + v));
Object.entries(count(suspects, s => s.via || '(blank)')).forEach(([k, v]) => console.log('AUDIT   added via: ' + k + ' = ' + v));
suspects.slice(0, 40).forEach(s => console.log(`AUDIT   e.g. #${s.id} ${s.name.slice(0, 60)} [fmt ${s.format || '-'}] stock=${s.stock} :: ${s.cur} -> ${s.sug} (${s.reason})`));
console.log(`AUDIT uncategorized with a confident suggestion=${uncategorized.length} (of ${none})`);
uncategorized.slice(0, 10).forEach(s => console.log(`AUDIT   e.g. #${s.id} ${s.name.slice(0, 60)} stock=${s.stock} -> ${s.sug}`));

const head = ['product_id', 'sku', 'name', 'format', 'added_via', 'stock', 'inactive', 'created', 'current_category', 'suggested_category', 'why', 'approve (Y/N)'];
const write = (file, arr) => fs.writeFileSync(path.join(outDir, file), [head.map(csvq).join(',')].concat(arr.map(s =>
  [s.id, s.sku, s.name, s.format, s.via, s.stock, s.inactive, s.created, s.cur, s.sug, s.reason, ''].map(csvq).join(','))).join('\n') + '\n');
write('suspects.csv', suspects);
write('uncategorized.csv', uncategorized);
fs.writeFileSync(path.join(outDir, 'counts.csv'), ['category,items,in_stock'].concat(Object.keys(bySub).sort().map(l => [l, bySub[l].n, bySub[l].stock].map(csvq).join(','))).join('\n') + '\n');
console.log('AUDIT wrote suspects.csv, uncategorized.csv, counts.csv');
