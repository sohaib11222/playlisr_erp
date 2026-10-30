// Mass add category matching: old matcher vs public/js/mass_add_category_match.js,
// run against the real prod category list (combos.json from mass_add_categories.php).
const fs = require('fs');
const path = require('path');
const combos = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const options = combos.map(c => ({ value: c.id, label: c.label }));
const labelOf = v => (options.find(o => o.value === v) || {}).label || '(blank)';
const { match } = require(path.join(__dirname, '../../public/js/mass_add_category_match.js'));

// Old matcher (mass-create.blade.php before 2026-10-28), verbatim logic.
function oldMatch(product) {
  const normalizeForMatch = s => String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
  const tokenize = s => { const n = normalizeForMatch(s); return n ? n.split(/\s+/).filter(t => t.length > 0 && t !== 'and' && t !== 'the') : []; };
  function score(labelTokens, hintTokens, w) {
    if (!hintTokens.length || !labelTokens.length) return 0;
    const ls = {}; labelTokens.forEach(t => ls[t] = true); const hs = {}; hintTokens.forEach(t => hs[t] = true);
    let m = 0; Object.keys(hs).forEach(t => { if (ls[t]) m++; }); if (!m) return 0;
    return w * (m + m / Object.keys(hs).length);
  }
  const c = tokenize(product.category), s = tokenize(product.subcategory), n = tokenize(product.name), a = tokenize(product.artist), r = tokenize(product.raw_line);
  let best = '', bs = 0;
  options.forEach(o => {
    const lt = tokenize(o.label); if (!lt.length) return;
    let sc = score(lt, s, 5) + score(lt, c, 4);
    const L = lt.join(' '), C = c.join(' '), S = s.join(' ');
    if (C && L.indexOf(C) !== -1) sc += 2; if (S && L.indexOf(S) !== -1) sc += 3;
    if (!c.length && !s.length) { sc += score(lt, n, 1) + score(lt, r, 1) + score(lt, a, 0.5); }
    if (sc > bs) { bs = sc; best = o.value; }
  });
  return bs >= 2 ? best : '';
}

// [expected label ('' = leave blank), name, artist, category, subcategory]
const CASES = [
  ['Trading Cards > Michael Jordan Cards', 'Michael Jordan 1986 Fleer Rookie', '', 'Sports Cards', 'Basketball'],
  ['Trading Cards > Michael Jordan Cards', 'Michael Jordan Upper Deck card', '', 'Trading Cards', 'NBA'],
  ['Trading Cards > NBA Cards', 'LeBron James Prizm Rookie', '', 'Sports Cards', 'Basketball'],
  ['Trading Cards > NBA Cards', 'Steph Curry Topps', '', 'Trading Cards', 'NBA'],
  ['Trading Cards > Kobe Bryant Cards', 'Kobe Bryant Topps Chrome', '', 'Sports Cards', ''],
  ['Trading Cards > NFL Cards', 'Patrick Mahomes Rookie', '', 'Trading Cards', 'Football'],
  ['Trading Cards > MLB', 'Shohei Ohtani Bowman', '', 'Sports Cards', 'Baseball'],
  ['Trading Cards > Pokemon Cards', 'Charizard Holo', '', 'Trading Cards', 'Pokemon'],
  ['Trading Card Packs > Pokemon Packs', 'Pokemon Scarlet booster pack', '', 'Trading Card Packs', 'Pokemon'],
  ['Magazines > Rolling Stone', 'Rolling Stone Issue 1', '', 'Magazine', 'Music'],
  ['Magazines > Rolling Stone', 'Rolling Stone Magazine 1975', '', '', ''],
  ['Magazines > Playboy', 'Playboy December 1953', '', 'Magazines', 'Adult'],
  ['', 'Vogue March 1990', '', 'Magazines', 'Fashion'],
  ['Vinyl - Used > Rock', 'Rumours', 'Fleetwood Mac', 'Records', 'Rock'],
  ['Vinyl - Used > Rock', 'Rumours', 'Fleetwood Mac', 'Used Vinyl', 'Rock'],
  ['Vinyl - Sealed > Pop', 'Thriller', 'Michael Jackson', 'Sealed Vinyl', 'Pop'],
  ['Vinyl - Sealed > Pop', 'Thriller (sealed)', 'Michael Jackson', 'Vinyl LP', 'Pop'],
  ['Vinyl - Used > Hip Hop LPs', 'Illmatic', 'Nas', 'LP', 'Hip Hop'],
  ['Vinyl - Used > Hip Hop LPs', 'The Chronic', 'Dr. Dre', 'Vinyl Records', 'Rap'],
  ['Vinyl - Used > R&B, Soul & Funk', "What's Going On", 'Marvin Gaye', 'Vinyl', 'Soul'],
  ['Vinyl - Used > R&B, Soul & Funk', 'Songs in A Minor', 'Alicia Keys', 'Records', 'R&B'],
  ['Vinyl - Used > R&B, Soul & Funk', 'Superfly', 'Curtis Mayfield', 'Vinyl - Used', 'R&B, Soul & Funk'],
  ['Vinyl - Used > Jazz', 'Kind of Blue', 'Miles Davis', 'Vinyl Record', 'Jazz'],
  ['7", 45 RPM > New Wave', 'Heart of Glass', 'Blondie', '7" Single', 'New Wave'],
  ['7", 45 RPM > Rock', 'Hey Jude', 'The Beatles', '45 RPM', 'Rock'],
  ['Cassettes - Used > Rock', 'Nevermind', 'Nirvana', 'Cassettes', 'Rock'],
  ['Cassettes - Sealed > hip hop', 'Ready to Die (sealed)', 'Notorious B.I.G.', 'Cassette', 'Hip Hop'],
  ['CD - Used > Jazz', 'Kind of Blue', 'Miles Davis', 'Used CD', 'Jazz'],
  ['CD - Sealed > K-pop', 'Map of the Soul', 'BTS', 'CD - Sealed', 'K-Pop'],
  ['VHS > Sci-Fi', 'Star Wars', '', 'VHS', 'Sci-Fi'],
  ['DVD > Drama', 'Pulp Fiction', '', 'DVD', 'Drama'],
  ['Blu-ray > Horror', 'The Shining', '', 'Blu-ray', 'Horror'],
  ['Blu-ray > Action/Adventure', 'Jumanji', '', '4K Ultra HD', 'Action/Adventure'],
  ['DVD > Comedy', 'Animal House', '', 'DVD', 'Comedy'],
  ['Toys > Funko Pop', 'Funko Pop Batman', '', 'Toys', 'Funko Pop'],
  ['Posters > Rock', 'Nirvana concert poster', '', 'Posters', 'Rock'],
  ['Books > Manga', 'Akira Vol 1', '', 'Books', 'Manga'],
  ['Comics > Spider-Man', 'Amazing Spider-Man #300', '', 'Comics', 'Spider-Man'],
  ['Audio Gear > Record Players & Turntables', 'Technics SL-1200', '', 'Audio Gear', 'Turntable'],
  ['Apparel > Music T-shirts', 'Metallica tour tee', '', 'Apparel', 'Music T-shirt'],
];
let pass = 0, fail = 0, fixed = 0;
for (const [want, name, artist, category, subcategory] of CASES) {
  const p = { name, artist, category, subcategory, raw_line: [name, artist, category, subcategory].join(',') };
  const before = labelOf(oldMatch(p));
  const after = labelOf(match(options, p));
  const ok = after === (want || '(blank)');
  ok ? pass++ : fail++;
  if (ok && before !== after) fixed++;
  if (!ok) { const dbg = []; match(options, p, dbg); dbg.forEach(d => console.log('   DBG ' + d)); }
  console.log(`CASE ${ok ? 'PASS' : 'FAIL'} [${category} / ${subcategory}] ${name} :: old=${before} | new=${after}${ok ? '' : ' | want=' + (want || '(blank)')}`);
}
console.log(`SUMMARY pass=${pass} fail=${fail} old-was-wrong-now-right=${fixed}`);
