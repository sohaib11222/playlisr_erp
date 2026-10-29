/*
 * Mass add: pick the "Category > Subcategory" option for a pasted row
 * (Sarah 2026-10-28). Used to be a loose word-overlap score where ties went to
 * the first option, so a vague hint ("Sports Cards, Basketball", "Records,
 * Rock") landed on the first entry of a group: Trading Cards > Digimon or
 * 7", 45 RPM. Now:
 *   1. exact "Category > Subcategory" (what the ChatGPT prompt asks for) wins;
 *   2. otherwise pick the category first (LP / record -> Vinyl, basketball ->
 *      NBA, plurals, sealed vs used), then the subcategory inside it;
 *   3. a tie or no real match leaves the box blank for the clerk instead of
 *      guessing the first option.
 *
 * massAddCategoryMatch(options, product) -> option value or ''.
 *   options: [{ value, label }], label "Category > Subcategory" or "Category".
 *   product: { category, subcategory, name, artist }.
 */
(function (root) {
    'use strict';

    // Phrases first (before splitting into words).
    var PHRASES = [
        [/7\s*(?:"|''|”|in\b|inch(?:es)?\b)|\b45\s*(?:rpm|s)\b/g, ' sevenin '],
        [/\b12\s*(?:"|''|”|in\b|inch(?:es)?\b)/g, ' vinyl '],
        [/\br\s*(?:&|and|n)\s*b\b|\brnb\b/g, ' rnb '],
        [/\bhip\s*-?\s*hop\b|\brap\b/g, ' hiphop '],
        [/\bnew\s*wave\b/g, ' newwave '],
        [/\b([kj])\s*-?\s*pop\b/g, ' $1pop '],
        [/\bpost\s*-?\s*punk\b/g, ' postpunk '],
        [/\bblu\s*-?\s*ray\b/g, ' dvd '],
        [/\bt\s*-?\s*shirts?\b|\btees?\b/g, ' shirt '],
        [/\bcompact\s+discs?\b/g, ' cd '],
        [/\b8\s*-?\s*tracks?\b/g, ' eighttrack '],
        [/\bfunko\s+pops?\b/g, ' funko '],
        [/\bpre\s*-?\s*owned\b|\bsecond\s*hand\b/g, ' used '],
        [/\bfactory\s+sealed\b|\bbrand\s+new\b/g, ' sealed '],
        // Things that say "vinyl" / "record" but aren't records.
        [/\bmini\s+vinyl\b/g, ' minivinyl '],
        [/\bvinyl\s+(?:figures?|toys?|art\s+toys?)\b/g, ' figure '],
        [/\brecord\s+players?\b/g, ' turntable '],
        [/\b(?:record|vinyl)\s+(?:accessor(?:y|ies)|care|cleaning|cleaner|brush|sleeves?|storage|crates?)\b/g, ' recordcare '],
        [/\bcassette\s+(?:decks?|recorders?)\b/g, ' cassettedeck '],
        [/\bvhs\s+tapes?\b/g, ' vhs '],
        [/\b(?:phono|turntable)\s+cartridges?\b/g, ' needle '],
        [/\bnon\s*-?\s*music\b/g, ' nonmusic '],
        [/\bvideo\s*games?\b/g, ' videogame '],
        [/\bboard\s*games?\b/g, ' boardgame '],
        [/\bgift\s*cards?\b/g, ' giftcard '],
        [/\bupper\s+deck\b/g, ' card '],
        [/\bcd\s+players?\b|\bdiscman\b/g, ' cdplayer '],
    ];
    // Single words -> one canonical word.
    var WORDS = {
        lp: 'vinyl', album: 'vinyl', record: 'vinyl', vinyl: 'vinyl', vinyle: 'vinyl', '33': 'vinyl', '33rpm': 'vinyl',
        '7in': 'sevenin', '45rpm': 'sevenin',
        tape: 'cassette', cassette: 'cassette', cass: 'cassette',
        cd: 'cd', dvd: 'dvd', bluray: 'dvd', vhs: 'vhs', laserdisc: 'laser',
        basketball: 'nba', football: 'nfl', baseball: 'mlb', hockey: 'nhl',
        sealed: 'sealed', unopened: 'sealed', shrinkwrap: 'sealed',
        used: 'used', preowned: 'used',
        mag: 'magazine', magazine: 'magazine', zine: 'zine',
        comic: 'comic', manga: 'manga', book: 'book', novel: 'novel',
        poster: 'poster', print: 'print', tshirt: 'shirt', shirt: 'shirt', hat: 'hat', cap: 'hat',
        electronica: 'electronic', edm: 'electronic', techno: 'electronic', house: 'electronic',
        alternative: 'alt', ost: 'soundtrack', score: 'soundtrack', soundtrack: 'soundtrack',
        turntable: 'turntable', pokémon: 'pokemon', yugioh: 'yu',
        stylus: 'needle', needle: 'needle',
        band: 'music', tour: 'music', concert: 'music', merch: 'music',
        clothing: 'apparel', clothes: 'apparel',
        rookie: 'card', rc: 'card', psa: 'card', bgs: 'card', topps: 'card', fleer: 'card', bowman: 'card', panini: 'card', prizm: 'card',
        synth: 'synthesizer', synthesizer: 'synthesizer', keyboard: 'synthesizer',
        hardcover: 'book', paperback: 'book', walkman: 'walkman', walkmen: 'walkman',
        costume: 'mask', figurine: 'figure', plushie: 'plush',
    };
    // Words never made singular ("Blues" is not "Blue").
    var KEEP = { blues: 1, series: 1, news: 1, pants: 1, jeans: 1, glasses: 1, sports: 1 };
    // Product-type words: strong evidence for a category, whatever else the
    // row says ("Tupac poster" under "Wall Art" is a poster).
    var TYPES = {
        poster: /^posters$/, magazine: /^magazines$/, comic: /^comics$/, vhs: /^vhs$/, dvd: /^dvd/, laser: /^laser/,
        cassette: /^cassettes/, cd: /^cd /, vinyl: /^vinyl/, sevenin: /^7"/, eighttrack: /^8 track/,
        shirt: /^apparel/, hat: /^apparel/, jersey: /^apparel/, sweatshirt: /^apparel/, apparel: /^apparel/,
        turntable: /^(audio gear|electronics)$/, walkman: /^(audio gear|electronics)$/, guitar: /^(audio gear|electronics)$/, cdplayer: /^(audio gear|electronics)$/,
        needle: /^audio gear$/, headphone: /^audio gear$/, speaker: /^audio gear$/, receiver: /^audio gear$/, amp: /^audio gear$/,
        pedal: /^audio gear$/, synthesizer: /^audio gear$/, mixer: /^audio gear$/, vcr: /^audio gear$/, cassettedeck: /^audio gear$/,
        camera: /^photo/, lens: /^photo/,
        figure: /^toys$/, plush: /^toys$/, puzzle: /^toys$/, funko: /^toys$/, squishy: /^toys$/, boardgame: /^toys$/, minivinyl: /^toys$/,
        pin: /^(toys|decorative collectibles)$/, sticker: /^gift items$/, slipmat: /^gift items$/, magnet: /^(gift items|toys)$/, keychain: /^(gift items|toys)$/,
        mask: /^accessories/, book: /^books$/, novel: /^books$/, manga: /^books$/,
        card: /^trading card/, pack: /^trading card packs$/, booster: /^trading card packs$/,
        videogame: /^video games$/, giftcard: /^gift cards$/,
    };
    // Title words that are also genre names: from the item name alone they
    // don't pick a subcategory ("Kind of Blue", "Star Wars", "Just Kids").
    var GENRE = {};
    ('war drama comedy love blue blues soul rock pop jazz country metal punk world classical action adventure family kid ' +
     'romance horror fantasy thriller western animation musical folk gospel latin disco house dance electronic reggae ' +
     'new wave alt indie hiphop rnb funk hardcore soundtrack christmas children sci fi tv drink candy art').split(' ').forEach(function (w) { GENRE[w] = 1; });
    // Words that say nothing about the category.
    var STOP = { and: 1, the: 1, of: 1, a: 1, an: 1, for: 1, with: 1, other: 1, misc: 1, item: 1, items: 1, general: 1 };

    function singular(w) {
        if (w.length > 2 && /s$/.test(w) && !/(ss|us|is)$/.test(w)) return w.slice(0, -1);
        return w;
    }

    function tokens(s) {
        var t = ' ' + String(s || '').toLowerCase() + ' ';
        PHRASES.forEach(function (p) { t = t.replace(p[0], p[1]); });
        var out = [];
        t.replace(/&/g, ' ').split(/[^a-z0-9]+/).forEach(function (w) {
            if (!w || STOP[w]) return;
            if (!KEEP[w]) w = singular(w);
            w = WORDS[w] || w;
            if (w.length < 2 && !/\d/.test(w)) return;
            if (out.indexOf(w) === -1) out.push(w);
        });
        return out;
    }

    // Close genres: a hint of "soul" also half-matches "R&B, Soul & Funk".
    var NEAR = {
        soul: ['rnb', 'funk'], funk: ['rnb', 'soul'], rnb: ['soul', 'funk'],
        alt: ['indie'], indie: ['alt'], electronic: ['dance'], dance: ['electronic'],
        newwave: ['postpunk'], postpunk: ['newwave'], hiphop: ['rap'],
    };
    function expand(tok) {
        var out = tok.slice();
        tok.forEach(function (t) { (NEAR[t] || []).forEach(function (n) { if (out.indexOf(n) === -1 && tok.indexOf(n) === -1) out.push('~' + n); }); });
        return out;
    }

    // How well hint words cover a label: matched words, plus a small bonus
    // for covering more of the label ("Magazines" beats "Books > Music").
    function overlap(labelTok, hintTok) {
        if (!labelTok.length || !hintTok.length) return 0;
        var m = 0, w = 0;
        hintTok.forEach(function (t) {
            var near = t.charAt(0) === '~';
            if (labelTok.indexOf(near ? t.slice(1) : t) !== -1) { m++; w += near ? 0.5 : 1; }
        });
        return m ? w + 0.5 * Math.min(1, m / labelTok.length) : 0;
    }

    // Same item in two places (Audio Gear > Walkmen, Electronics > Walkmen):
    // on an otherwise even score take the fuller category.
    var PREFER = { 'audio gear': 0.1, toys: 0.1 };

    function loose(s) { return String(s || '').toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim(); }

    function match(options, product, dbg) {
        product = product || {};
        var cat = String(product.category || '').trim();
        var sub = String(product.subcategory || product.sub_category || '').trim();
        var groups = {};
        var order = [];
        (options || []).forEach(function (o) {
            if (!o || !o.value) return;
            var label = String(o.label || '').trim();
            var i = label.indexOf(' > ');
            var top = i === -1 ? label : label.slice(0, i);
            var subName = i === -1 ? '' : label.slice(i + 3);
            if (!groups[top]) { groups[top] = { top: top, tok: tokens(top), subs: [] }; order.push(top); }
            groups[top].subs.push({ value: String(o.value), label: label, name: subName, tok: tokens(subName) });
        });

        // 1. The exact label (ignoring case and punctuation).
        if (cat) {
            var want = loose(cat + ' ' + sub);
            for (var gi = 0; gi < order.length; gi++) {
                var g0 = groups[order[gi]];
                for (var si = 0; si < g0.subs.length; si++) {
                    if (loose(g0.top + ' ' + g0.subs[si].name) === want) return g0.subs[si].value;
                }
            }
        }

        var catT = tokens(cat);
        var subT = expand(tokens(sub));
        var nameT = tokens(String(product.name || '') + ' ' + String(product.artist || ''));
        // Name words for picking a subcategory: no genre words from titles.
        var nameSubT = nameT.filter(function (t) { return !GENRE[t]; });
        var allT = catT.concat(subT, nameT);
        var says = function (w) { return allT.indexOf(w) !== -1; };

        // 2a. Pick the category.
        var scored = order.map(function (top) {
            var g = groups[top];
            var s = 6 * overlap(g.tok, catT) + 3 * overlap(g.tok, subT) + 1 * overlap(g.tok, nameT);
            // A subcategory that matches the hint pulls its category along
            // ("Basketball" -> Trading Cards > NBA Cards).
            var bestSub = 0;
            g.subs.forEach(function (sOpt) {
                var f = sOpt.tok.filter(function (t) { return g.tok.indexOf(t) === -1; });
                bestSub = Math.max(bestSub, 2 * overlap(f, subT) + 1.5 * overlap(f, nameSubT) + overlap(f, catT));
            });
            var typeHits = 0;
            allT.forEach(function (t) { if (TYPES[t] && TYPES[t].test(top.toLowerCase())) typeHits++; });
            if (typeHits) s += 8 * typeHits;
            if (s > 0) s += bestSub;
            else if (bestSub >= 2 && !catT.length) s = bestSub * 0.5;
            if (s > 0 && PREFER[top.toLowerCase()]) s += PREFER[top.toLowerCase()];
            // Sealed vs used only splits groups that already match.
            if (s > 0 && g.tok.indexOf('sealed') !== -1) s += says('sealed') ? 3 : (says('used') ? -3 : -1.5);
            if (s > 0 && g.tok.indexOf('used') !== -1) s += says('used') ? 3 : (says('sealed') ? -3 : 0);
            if (s > 0 && g.tok.indexOf('clearance') !== -1 && !says('clearance')) s -= 2;
            return { g: g, s: s };
        }).filter(function (x) { return x.s > 0; }).sort(function (a, b) { return b.s - a.s; });
        if (dbg) dbg.push('TOP ' + scored.slice(0, 4).map(function (x) { return x.g.top + '=' + x.s.toFixed(2); }).join(', ') + ' | cat=' + catT + ' sub=' + subT + ' name=' + nameT);
        if (!scored.length) return '';
        if (scored.length > 1 && scored[1].s >= scored[0].s - 0.01) return ''; // tie: let the clerk pick
        var g = scored[0].g;
        if (g.subs.length === 1) {
            // A lone named subcategory ("Accessories & Supplies > Halloween
            // Mask") only when the row is actually about it.
            var only = g.subs[0].tok.filter(function (t) { return g.tok.indexOf(t) === -1; });
            return !only.length || overlap(only, allT) ? g.subs[0].value : '';
        }

        // 2b. Pick the subcategory inside it.
        var rowT = subT.concat(nameSubT);
        var typeSub = function (sOpt) { return sOpt.tok.some(function (t) { return TYPES[t] && g.tok.indexOf(t) === -1 && rowT.indexOf(t) !== -1; }); };
        var anyType = g.subs.some(typeSub);
        var subs = g.subs.map(function (sOpt) {
            var f = sOpt.tok.filter(function (t) { return g.tok.indexOf(t) === -1; });
            var s = 5 * overlap(f, subT) + 4 * overlap(f, nameSubT) + 1 * overlap(f, catT);
            // Product-type words ("needle", "Funko") beat brand / title words,
            // and a subcategory the row names in full beats a partial one.
            // (A brand-named one like "audio technica" loses to the type the row names.)
            f.forEach(function (t) { if (TYPES[t] && rowT.indexOf(t) !== -1) s += 4; });
            if (s > 0 && f.length && f.every(function (t) { return rowT.indexOf(t) !== -1; }) && (!anyType || typeSub(sOpt))) s += 6;
            // Tie-break: the earlier word of the hint ("Latin Rock" -> Latin).
            var pos = subT.findIndex(function (t) { return f.indexOf(t) !== -1; });
            if (s > 0 && pos !== -1) s += 0.05 / (pos + 1);
            return { o: sOpt, s: s };
        }).sort(function (a, b) { return b.s - a.s; });
        if (dbg) dbg.push('SUB ' + subs.slice(0, 4).map(function (x) { return x.o.name + '=' + x.s.toFixed(2); }).join(', '));
        if (subs[0].s > 0 && !(subs.length > 1 && subs[1].s >= subs[0].s - 0.01)) return subs[0].o.value;
        // Nothing in the hint names a subcategory: a sub named like the
        // category itself ("Posters > Posters", "Toys > Toy") is the default.
        for (var k = 0; k < g.subs.length; k++) {
            var f2 = g.subs[k].tok;
            if (f2.length && f2.every(function (t) { return g.tok.indexOf(t) !== -1; })) return g.subs[k].value;
        }
        return '';
    }

    root.massAddCategoryMatch = match;
    root.massAddCategoryTokens = tokens;
    if (typeof module !== 'undefined' && module.exports) module.exports = { match: match, tokens: tokens };
})(typeof window !== 'undefined' ? window : this);
