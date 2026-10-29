// 100 messy mass-add rows (raw eBay titles, ChatGPT rows with made-up
// categories, words that point the wrong way) through
// public/js/mass_add_category_match.js on the real category list.
// RIGHT = an acceptable pick, BLANK = left for the clerk, WRONG = picked a
// category we don't mean (the one that matters).
const fs = require('fs');
const path = require('path');
const combos = JSON.parse(fs.readFileSync(process.argv[2], 'utf8'));
const options = combos.map(c => ({ value: c.id, label: c.label }));
const labelOf = v => (options.find(o => o.value === v) || {}).label || '';
const { match } = require(path.join(__dirname, '../../public/js/mass_add_category_match.js'));

const VU = 'Vinyl - Used > ', VS = 'Vinyl - Sealed > ', S7 = '7", 45 RPM > ', CU = 'CD - Used > ', CS = 'CD - Sealed > ';
const KU = 'Cassettes - Used > ', KS = 'Cassettes - Sealed > ', TC = 'Trading Cards > ';
const PLAYERS = ['Audio Gear > Record Players & Turntables', 'Electronics > Record Players'];
const WALKMAN = ['Audio Gear > Walkmen', 'Electronics > Walkmen', 'Audio Gear > Cassette Player - Portable'];
const PINS = ['Toys > Pins', 'Decorative Collectibles > Pins'];

// [acceptable label(s), name, artist, category, subcategory]
const CASES = [
  // --- raw eBay titles, no category ---
  [TC + 'Michael Jordan Cards', '1986 Fleer Michael Jordan #57 Rookie Card RC Chicago Bulls', '', '', ''],
  [TC + 'Kobe Bryant Cards', '1996 Topps Chrome Kobe Bryant Rookie #138 PSA 9', '', '', ''],
  [TC + 'NBA Cards', '2003 Upper Deck LeBron James Rookie Basketball Card', '', '', ''],
  [TC + 'NFL Cards', '2017 Panini Prizm Patrick Mahomes Rookie Football Card', '', '', ''],
  [TC + 'MLB', '1989 Upper Deck Ken Griffey Jr Rookie Baseball Card #1', '', '', ''],
  [TC + 'NHL Cards', '1979 O-Pee-Chee Wayne Gretzky Hockey Card', '', '', ''],
  [[TC + 'Pokemon Cards', TC + 'Pokemon 1st Edition'], 'Charizard Holo Base Set 4/102 Pokemon Card', '', '', ''],
  [TC + 'Pokemon Slabs', 'PSA 10 Pikachu Pokemon Slab', '', '', ''],
  [TC + 'Magic Cards', 'Magic the Gathering Black Lotus Unlimited MTG', '', '', ''],
  [TC + 'yu gi oh', 'Yu-Gi-Oh Blue-Eyes White Dragon LOB 1st Edition', '', '', ''],
  ['Trading Card Packs > Pokemon Packs', 'Pokemon Scarlet Violet Booster Pack Sealed', '', '', ''],
  [TC + 'Garbage Pail Kids', 'Garbage Pail Kids Series 1 Adam Bomb 1985', '', '', ''],
  [[VU + 'Rock', VS + 'Rock', ''], 'Led Zeppelin IV LP Vinyl Record Atlantic 1971', '', '', ''],
  [[VU + 'Jazz'], 'Miles Davis Kind of Blue LP 1959 Columbia 6-Eye', '', '', ''],
  [[VS + 'Pop', VS + 'Rock', ''], 'Taylor Swift 1989 Vinyl LP Sealed New', '', '', ''],
  [[S7 + 'Rock', S7 + 'Pop', ''], 'The Beatles Hey Jude 45 RPM 7" Single Apple', '', '', ''],
  [[CU + 'Rock', CU + 'Alt Rock/Indie', ''], 'Nirvana Nevermind CD 1991 DGC', '', '', ''],
  [[KU + 'Rock', KU + 'Metal', KU + 'Alt Rock', ''], 'Metallica Master of Puppets Cassette Tape', '', '', ''],
  [['VHS > Sci-Fi', 'VHS > Sci-fi/Adventure', '', ''], 'Star Wars VHS 1995 THX Original Trilogy', '', '', ''],
  [['DVD/Blu Ray > Horror', ''], 'The Shining Blu-ray Stanley Kubrick', '', '', ''],
  ['Magazines > Rolling Stone', 'Rolling Stone Magazine Kurt Cobain Cover 1994', '', '', ''],
  ['Magazines > Playboy', 'Playboy Magazine March 1979', '', '', ''],
  ['Magazines > Mad Magazine', 'MAD Magazine #1 1952', '', '', ''],
  [['Comics > Batman', 'Books > Comic Books'], 'Batman #423 DC Comics 1988 McFarlane Cover', '', '', ''],
  [['Comics > Spider-Man', 'Books > Comic Books'], 'Amazing Spider-Man #300 Marvel Comics 1988', '', '', ''],
  ['Toys > Funko Pop', 'Funko Pop Rocks Freddie Mercury #92', '', '', ''],
  [PLAYERS, 'Technics SL-1200MK2 Turntable', '', '', ''],
  ['Audio Gear > Record Needle', 'Audio Technica AT-VM95E Cartridge Stylus Needle', '', '', ''],
  [WALKMAN, 'Sony Walkman WM-F2015 Cassette Player', '', '', ''],
  [['Audio Gear > Headphones'], 'Sony MDR-7506 Headphones', '', '', ''],
  [['Audio Gear > Stereo Receiver'], 'Marantz 2230 Stereo Receiver Vintage', '', '', ''],
  [['Photo/Video > Camera Bodies', 'Photo/Video > Vintage & Film Photography'], 'Canon AE-1 Program 35mm Film Camera Body', '', '', ''],
  [['Apparel > Music T-shirts'], 'Vintage Nirvana Band T-Shirt XL 1993', '', '', ''],
  [['Posters > Rock', 'Posters > Posters'], 'Pink Floyd Dark Side of the Moon Poster', '', '', ''],
  [['Books > Biographies & Memoirs', 'Books > Artist & Band Books', 'Books > Music', ''], 'Just Kids Patti Smith Hardcover Book', '', '', ''],
  [['Toys > Action Figures'], 'Kenner Star Wars Boba Fett Action Figure 1979', '', '', ''],
  ['8 track', 'Fleetwood Mac Rumours 8 Track Tape', '', '', ''],
  [['Laser Disc > Sci-Fi', ''], 'Blade Runner LaserDisc', '', '', ''],
  [['Video Games > Other'], 'Super Mario 64 Nintendo 64 Video Game Cartridge', '', '', ''],
  [['Toys > Mini vinyl replicas & collectibles', 'Toys > Toy', 'Toys > Action Figures', ''], 'Kidrobot Dunny Vinyl Figure', '', '', ''],

  // --- ChatGPT rows with made-up category names ---
  [TC + 'Michael Jordan Cards', 'Michael Jordan 1997 Fleer Ultra', '', 'Collectibles', 'Sports Cards'],
  [TC + 'NBA Cards', 'Shaquille O\'Neal Rookie 1992', '', 'Sports Memorabilia', 'Basketball Cards'],
  [TC + 'NFL Cards', 'Tom Brady Rookie 2000 Bowman', '', 'Collectibles', 'Football'],
  [[TC + 'Pokemon Cards', ''], 'Mewtwo Holo', '', 'Collectible Card Games', 'Pokemon TCG'],
  [[VU + 'Rock', VS + 'Rock', ''], 'Abbey Road', 'The Beatles', 'Music', 'Rock'],
  [[VU + 'Hip Hop LPs', VS + 'Hip-Hop', ''], 'Illmatic', 'Nas', 'Music', 'Hip-Hop/Rap'],
  [[VU + 'Jazz', VS + 'Jazz', CU + 'Jazz', ''], 'A Love Supreme', 'John Coltrane', 'Music', 'Jazz'],
  [[VU + 'Rock'], 'Born to Run', 'Bruce Springsteen', 'Vinyl Records', 'Classic Rock'],
  [[VU + 'Electronic/Dance'], 'Homework', 'Daft Punk', 'Vinyl', 'Electronic'],
  [[VU + 'Soundtracks'], 'Star Wars Original Soundtrack', 'John Williams', 'Vinyl LP', 'Soundtrack'],
  [[VU + 'Punk / Hardcore'], 'Never Mind the Bollocks', 'Sex Pistols', 'Records', 'Punk'],
  [[VU + 'Metal'], 'Paranoid', 'Black Sabbath', 'Used Vinyl', 'Heavy Metal'],
  [[VU + 'Country'], 'At Folsom Prison', 'Johnny Cash', 'Used Records', 'Country'],
  [[VU + 'Latin', ''], 'Abraxas', 'Santana', 'LP', 'Latin Rock'],
  [[VU + 'Reggae'], 'Legend', 'Bob Marley', 'Vinyl Record', 'Reggae'],
  [[VU + 'Blues'], 'King of the Delta Blues Singers', 'Robert Johnson', 'Vinyl', 'Blues'],
  [[VU + 'Classical'], 'The Four Seasons', 'Vivaldi', 'Vinyl Records', 'Classical'],
  [[VU + 'new wave/post punk'], 'Unknown Pleasures', 'Joy Division', 'Records', 'Post-Punk'],
  [[VU + 'Alt / Indie Rock'], 'OK Computer', 'Radiohead', 'Vinyl', 'Alternative'],
  [[VU + 'Folk'], 'Blue', 'Joni Mitchell', 'Vinyl', 'Folk'],
  [[VU + 'Gospel'], 'Amazing Grace', 'Aretha Franklin', 'Vinyl', 'Gospel'],
  [[VS + 'K-Pop', VU + 'K-pop'], 'Map of the Soul 7', 'BTS', 'Sealed Vinyl', 'K-Pop'],
  [[VS + 'Disco', VU + 'R&B, Soul & Funk', VU + 'Electronic/Dance', ''], 'Saturday Night Fever', 'Bee Gees', 'Vinyl', 'Disco'],
  [[S7 + 'R&B, Soul & Funk'], 'My Girl', 'The Temptations', '45s', 'Soul'],
  [[S7 + 'Punk'], 'Anarchy in the UK', 'Sex Pistols', '7 inch', 'Punk'],
  [[CS + 'K-pop'], 'Love Yourself', 'BTS', 'CDs', 'K-Pop sealed'],
  [[CU + 'Hip-Hop'], 'The Marshall Mathers LP', 'Eminem', 'Compact Disc', 'Rap'],
  [[CU + 'Classical'], 'Goldberg Variations', 'Glenn Gould', 'CD', 'Classical'],
  [[KU + 'Hip hop'], 'Straight Outta Compton', 'N.W.A.', 'Tapes', 'Hip Hop'],
  [[KS + 'pop'], 'Thriller', 'Michael Jackson', 'Cassette', 'Pop (sealed)'],
  [['DVD/Blu Ray > Drama', ''], 'The Godfather', '', 'Movies', 'Drama'],
  [['DVD/Blu Ray > Horror'], 'Halloween', '', 'DVDs', 'Horror'],
  [['VHS > Animation'], 'The Little Mermaid', '', 'VHS Tapes', 'Disney Animation'],
  [['DVD/Blu Ray > TV'], 'The Sopranos Complete Series', '', 'Blu-ray', 'TV Shows'],
  [['Books > Manga'], 'Akira Vol 1', '', 'Books', 'Manga/Anime'],
  [['Books > Music History & Culture', 'Books > Music', 'Books > Artist & Band Books'], 'Please Kill Me: Oral History of Punk', '', 'Books', 'Music History'],
  [['Posters > Hip-Hop'], 'Tupac Shakur Poster', '', 'Wall Art', 'Hip Hop Poster'],
  [['Art & Photography > Framed Art'], 'Framed Andy Warhol Print', '', 'Art', 'Framed Print'],
  [['Apparel > Hats', 'Apparel > New Hat'], 'Lakers Snapback Hat', '', 'Clothing', 'Hats'],
  [['Apparel > Basketball Jersey'], 'Michael Jordan Bulls Jersey #23', '', 'Clothing', 'Jersey'],
  [PLAYERS, 'Audio-Technica AT-LP120X', '', 'Electronics', 'Turntables'],
  [['Audio Gear > Cassette Deck'], 'Nakamichi BX-300 Cassette Deck', '', 'Electronics', 'Audio Equipment'],
  [['Electronics > Portable CD Players', 'Audio Gear > CD Player - Portable'], 'Sony Discman D-50', '', 'Electronics', 'Portable CD Player'],
  [['Toys > Plush & Dolls'], 'Pikachu Plush 12 inch', '', 'Toys', 'Plush'],
  [['Toys > Board & Card Games'], 'Monopoly 1985 Edition', '', 'Toys & Games', 'Board Games'],
  [PINS, 'Grateful Dead Enamel Pin', '', 'Accessories', 'Pins'],
  [['Gift Items > Sticker'], 'Nivessa Logo Sticker', '', 'Merch', 'Stickers'],
  [['Gift Items > Tech Accessories (phone cases, slipmats, dust covers)'], 'Technics Slipmat', '', 'Record Accessories', 'Slipmat'],

  // --- words pointing the wrong way ---
  [['VHS > Action/Adventure', 'VHS > Action/Thriller', 'VHS > Drama', 'VHS > Comedy', ''], 'Batman 1989 VHS Michael Keaton', '', '', ''],
  [['Apparel > T-shirts (Non Music)', 'Apparel > Shirts', ''], 'Batman Logo T-Shirt Large', '', '', ''],
  [['DVD/Blu Ray > Animation', 'DVD/Blu Ray > Family', ''], 'Pokemon The First Movie DVD', '', '', ''],
  [['Toys > Funko Pop'], 'Funko Pop Vinyl Figure Darth Vader', '', 'Vinyl', 'Figure'],
  [['Posters > Rock', 'Posters > Posters'], 'Led Zeppelin Concert Poster', '', 'Music', 'Rock'],
  [['Apparel > Music T-shirts'], 'Metallica Tour Shirt 1988', '', 'Music', 'Metal'],
  [['Books > Artist & Band Books', 'Books > Music', 'Books > Biographies & Memoirs', 'Books > Music History & Culture'], 'The Beatles Anthology Book', '', 'Music', 'Rock'],
  [['DVD/Blu Ray > Music', ''], 'Nirvana Live at Reading DVD', '', 'Music', 'Rock'],
  [['Magazines > Rolling Stone'], 'Rolling Stone #1 1967', '', 'Music', 'Magazine'],
  [['Record Cover Only', ''], 'Beatles Butcher Cover Sleeve Only no record', '', '', ''],
  [['Gift Items > Tech Accessories (phone cases, slipmats, dust covers)', 'Accessories & Supplies > Halloween Mask', ''], 'Vinyl Record Cleaning Kit Brush', '', 'Accessories', 'Record Care'],
  [['Toys > Mini vinyl replicas & collectibles'], 'Mini vinyl replica Abbey Road', '', 'Toys', 'Mini vinyl replica'],
  [[TC + 'Star Wars'], 'Star Wars 1977 Topps Card #1 Luke Skywalker', '', 'Trading Cards', 'Star Wars'],
  [[TC + 'music cards'], '1991 Pro Set MusiCards Madonna', '', 'Trading Cards', 'Music'],
  [['Audio Gear > Guitar', 'Electronics > Guitars'], 'Fender Stratocaster Electric Guitar', '', 'Musical Instruments', 'Guitar'],
  [['Audio Gear > Keyboard / Synthesizer'], 'Roland Juno-106 Synthesizer', '', 'Musical Instruments', 'Synth'],
  [['Accessories & Supplies > Halloween Mask'], 'Michael Myers Halloween Mask', '', 'Costume', 'Mask'],
  [['DVD/Blu Ray > Musical', 'DVD/Blu Ray > Music', ''], 'Grease DVD', '', 'Movies', 'Musical'],
  [['Books > Comic Books', 'Comics > Spider-Man'], 'Spider-Man Graphic Novel', '', 'Books', 'Comics'],
  [[VU + 'Children\'s', VS + 'Children\'s'], 'Sesame Street Disco LP', '', 'Vinyl', 'Children\'s'],
  [['Snacks & Drinks > Drinks', 'Drinks'], 'Coca-Cola Can', '', 'Drinks', ''],
];

let right = 0, blank = 0, wrong = 0;
const lines = [];
for (const [acc, name, artist, category, subcategory] of CASES) {
  const ok = [].concat(acc);
  const got = labelOf(match(options, { name, artist, category, subcategory }));
  let cls;
  if (got && ok.includes(got)) { cls = 'RIGHT'; right++; }
  else if (!got) { cls = ok.includes('') ? 'RIGHT' : 'BLANK'; cls === 'RIGHT' ? right++ : blank++; }
  else { cls = 'WRONG'; wrong++; }
  lines.push(`${cls.padEnd(5)} [${category || '-'} / ${subcategory || '-'}] ${name}${artist ? ' — ' + artist : ''} :: ${got || '(blank)'}${cls === 'RIGHT' ? '' : '  want: ' + ok.filter(Boolean).join(' | ')}`);
}
['WRONG', 'BLANK', 'RIGHT'].forEach(c => lines.filter(l => l.startsWith(c)).forEach(l => console.log('R100 ' + l)));
console.log(`R100 TOTAL ${CASES.length}: right=${right} blank=${blank} wrong=${wrong}`);
