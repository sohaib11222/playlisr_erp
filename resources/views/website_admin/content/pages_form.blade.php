@extends('layouts.app')
@section('title', $pageData ? 'Edit page' : 'New page')

@section('content')
@include('website_admin.content._styles')
<style>
  .pe-layout { display:grid; grid-template-columns:minmax(0,1fr) 300px; gap:18px; align-items:start; }
  @media (max-width: 1000px) { .pe-layout { grid-template-columns:1fr; } }
  .pe-sec { border:1px solid #e5e7eb; border-radius:10px; margin-bottom:12px; background:#fff; }
  .pe-sec-head { display:flex; align-items:center; gap:8px; padding:10px 12px; border-bottom:1px solid #f0f0f0; background:#f9fafb; border-radius:10px 10px 0 0; }
  .pe-sec-head .ttl { font-weight:700; font-size:14px; flex:1; min-width:0; cursor:pointer; }
  .pe-sec-head .ttl small { font-weight:400; color:#6b7280; margin-left:6px; }
  .pe-sec-body { padding:12px; }
  .pe-sec.collapsed .pe-sec-body { display:none; }
  .pe-obj { border-left:3px solid #e5e7eb; padding-left:10px; margin:6px 0 10px; }
  .pe-row { margin-bottom:9px; }
  .pe-row > .lbl { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.03em; color:#6b7280; margin-bottom:3px; }
  .pe-item { border:1px dashed #d1d5db; border-radius:8px; padding:8px 10px; margin-bottom:8px; }
  .pe-item-head { display:flex; justify-content:space-between; align-items:center; margin-bottom:6px; font-size:12px; color:#6b7280; font-weight:600; }
  .pe-prim { display:flex; gap:6px; margin-bottom:5px; }
  .pe-img { display:flex; gap:8px; align-items:center; }
  .pe-img img { width:48px; height:48px; object-fit:cover; border-radius:6px; border:1px solid #e5e7eb; background:#f3f4f6; }
  .pe-templates { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:12px; }
  .pe-template { border:1px solid #e5e7eb; border-radius:10px; padding:16px; background:#fff; cursor:pointer; text-align:left; font-family:inherit; }
  .pe-template:hover { border-color:#93c5fd; box-shadow:0 2px 8px rgba(0,0,0,.06); }
</style>
<div class="ev-wrap ev-wrap-wide">
  <a class="wc-back" href="{{ route('wa.content.pages') }}">Back to pages</a>
  <div class="ev-head">
    <div><h1 id="pe-heading">{{ $pageData ? 'Edit page' : 'New page' }}<span id="pe-dirty" style="color:#ea580c; display:none;"> *</span></h1>
      <p class="sub" id="pe-sub">@if($pageData)Status: <b>{{ $pageData['status'] ?? 'draft' }}</b>@endif</p></div>
    <div class="wc-actions" id="pe-topbar" style="{{ $pageData ? '' : 'display:none;' }}">
      @if($pageData && !empty($pageData['slug']))
        <a class="wc-btn" href="https://nivessa.com/{{ $pageData['slug'] === 'home' ? '' : $pageData['slug'] }}" target="_blank" rel="noopener">Preview</a>
      @endif
      <button type="button" class="wc-btn" data-save="draft">Save draft</button>
      <button type="button" class="wc-btn wc-btn-go" data-save="published">Publish</button>
    </div>
  </div>
  <div id="pe-flash"></div>

  @if(!$pageData)
  <div id="pe-choose">
    <p class="wc-muted">Pick a template to start from, or start with a blank page.</p>
    <div class="pe-templates" style="margin:12px 0 16px;">
      <button type="button" class="pe-template" data-template="basic"><b>Basic Page</b><div class="wc-muted">Simple page with a title and content.</div></button>
      <button type="button" class="pe-template" data-template="landing"><b>Landing Page</b><div class="wc-muted">Marketing page with a hero section and call to action.</div></button>
      <button type="button" class="pe-template" data-template="about"><b>About Page</b><div class="wc-muted">Company or personal about page.</div></button>
    </div>
    <button type="button" class="wc-btn" data-template="blank">Start with a blank page</button>
  </div>
  @endif

  <div class="pe-layout" id="pe-editor" style="{{ $pageData ? '' : 'display:none;' }}">
    <div>
      <div class="wc-card wc-card-pad">
        <h2>Page information</h2>
        <div class="wc-grid">
          <div class="wc-field"><label>Page title (required)</label><input type="text" id="pe-title" placeholder="Page title"></div>
          <div class="wc-field"><label>URL slug (required)</label>
            <div style="display:flex; align-items:center; gap:4px;"><span class="wc-muted">/</span><input type="text" id="pe-slug" placeholder="page-url" pattern="[a-z0-9-]+" title="Only lowercase letters, numbers, and hyphens"></div>
          </div>
        </div>
      </div>
      <div class="wc-card wc-card-pad">
        <h2>SEO settings</h2>
        <div class="wc-field" style="margin-bottom:10px;"><label>SEO title</label><input type="text" id="pe-seo-title" placeholder="SEO title"><div class="wc-help"><span id="pe-seo-title-n">0</span>/60 characters</div></div>
        <div class="wc-field" style="margin-bottom:10px;"><label>Meta description</label><textarea id="pe-seo-desc" rows="3" placeholder="Short description for search engines"></textarea><div class="wc-help"><span id="pe-seo-desc-n">0</span>/160 characters</div></div>
        <div class="wc-field"><label>Social media image</label>
          <div class="pe-img"><img id="pe-seo-img-prev" alt="" style="display:none;"><input type="text" id="pe-seo-img" placeholder="Image URL, or upload one"><input type="file" accept="image/*" id="pe-seo-img-file" style="max-width:200px;"></div>
        </div>
      </div>
      <div class="wc-card wc-card-pad">
        <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
          <h2 style="margin:0;">Page content</h2>
          <div class="wc-actions">
            <select id="pe-add-type" class="wc-input" style="width:auto;">
              <optgroup label="Basic content">
                <option value="compact-hero">Compact Hero Banner</option><option value="header">Header</option><option value="hero">Hero Section</option><option value="text">Text Block</option><option value="rich-text">Rich Text</option>
              </optgroup>
              <optgroup label="Layout"><option value="two-column">Two Columns</option><option value="three-column">Three Columns</option></optgroup>
              <optgroup label="Media"><option value="image">Image</option><option value="video">Video</option><option value="gallery">Image Gallery</option></optgroup>
              <optgroup label="Interactive"><option value="cta">Call to Action</option><option value="contact-form">Contact Form</option><option value="contact-two-column">Contact Form and FAQ</option></optgroup>
              <optgroup label="Content display">
                <option value="quote">Quote</option><option value="testimonial">Testimonial</option><option value="stats">Statistics</option><option value="accordion">Accordion</option><option value="team-grid">Team Grid</option><option value="faq">FAQ</option><option value="table">Table</option><option value="timeline">Timeline</option><option value="code-block">Code Block</option>
              </optgroup>
              <optgroup label="Special">
                <option value="info-sections">Info Sections</option><option value="category-buttons">Category Buttons</option><option value="collectibles-grid">Collectibles Grid</option><option value="hero-carousel">Homepage Hero Carousel</option><option value="event-banner">Event or Promo Banner</option><option value="__upcoming-events">Upcoming Events Widget</option>
              </optgroup>
              <optgroup label="RSD Black Friday">
                <option value="rsd-hero-banner">RSD Hero Banner</option><option value="rsd-info-box">RSD Info Box</option><option value="rsd-carousel">RSD Carousel</option><option value="rsd-banner">RSD Banner</option><option value="rsd-live-shows">RSD Live Shows</option>
              </optgroup>
              <optgroup label="Featured products">
                <option value="__featured:most-collected-week:Most Collected This Week">Most Collected This Week</option>
                <option value="__featured:staff-picks-vinyl:Staff Picks">Staff Picks</option>
                <option value="__featured:best-sellers-valuable:Best Sellers / Most Valuable">Best Sellers / Most Valuable</option>
              </optgroup>
            </select>
            <button type="button" class="wc-btn wc-btn-primary" id="pe-add-btn">Add section</button>
          </div>
        </div>
        <div id="pe-sections"></div>
      </div>
    </div>
    <div>
      <div class="wc-card wc-card-pad">
        <h2>Navbar settings</h2>
        <label class="wc-check" style="margin-bottom:10px;"><input type="checkbox" id="pe-navbar"> Show in navigation bar</label>
        <div class="wc-field" id="pe-navorder-wrap"><label>Navbar order</label><input type="number" id="pe-navorder" min="0"><div class="wc-help">Lower numbers show first.</div></div>
      </div>
      <div class="wc-card wc-card-pad">
        <h2>Publishing</h2>
        <p class="wc-muted" style="margin:0 0 10px;">Save draft keeps the page hidden. Publish makes it live and refreshes the cached copy on nivessa.com.</p>
        <div class="wc-actions"><button type="button" class="wc-btn" data-save="draft">Save draft</button><button type="button" class="wc-btn wc-btn-go" data-save="published">Publish</button></div>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var IS_NEW = {{ $pageData ? 'false' : 'true' }};
  var CSRF = @json(csrf_token());
  var SAVE_URL = {!! json_encode($pageData ? route('wa.content.pagesUpdate', $pageData['_id']) : route('wa.content.pagesStore')) !!};
  var UPLOAD_URL = @json(route('wa.content.uploadImage'));
  var page = @json($pageData);
  var dirty = false, slugManual = !!(page && page.slug);

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); }
  function genSlug(t) { return String(t || '').toLowerCase().replace(/[^a-z0-9\s-]/g, '').replace(/\s+/g, '-').replace(/-+/g, '-').replace(/^-+|-+$/g, ''); }
  function sid() { return 'section-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }
  function markDirty() { dirty = true; document.getElementById('pe-dirty').style.display = ''; }
  function flash(m, ok) { document.getElementById('pe-flash').innerHTML = '<div class="' + (ok ? 'alert-ok' : 'alert-err') + '">' + esc(m) + '</div>'; window.scrollTo({ top: 0, behavior: 'smooth' }); }

  // Section defaults, copied from the website's PageEditor.
  var DEFAULTS = {
    'hero-carousel': { bannerHeight: '400', heroSlide: { title: '2 Record Stores in Los Angeles', description: "Come dig through one of the largest collections of new & used vinyl, CDs, movies and collectibles in Los Angeles at our Hollywood Blvd & Mid-City stores. Stay a while & feel at home. Or we'll ship it to you anywhere.", image: '/nivessa-hollywood-cd-bins.jpg', imageAlt: 'Shoppers browsing packed CD and vinyl bins inside the Nivessa Hollywood store', buttons: [{ text: 'Shop Vinyl Records', link: '/products?productType=Vinyl+Records', type: 'primary' }, { text: 'Sell My Collection (call or text)', subtitle: '213-676-2645', link: 'tel:2136762645', type: 'secondary' }] }, slides: [{ image: '', imageAlt: 'Event Banner', tag: 'UPCOMING EVENT', title: 'Event Title Here', subtitle: '', link: '/events-schedule', ctaText: 'See Details', overlayOpacity: '40' }] },
    'event-banner': { bannerHeight: '200', slides: [{ image: '', imageAlt: 'Event Banner', tag: 'UPCOMING EVENT', title: 'Event Title Here', subtitle: '', link: '/events-schedule', ctaText: 'See Details', overlayOpacity: '40' }] },
    'compact-hero': { title: 'Sell Your Collection Today', subtitle: 'TRUSTED SINCE 1995', description: 'Get fair market value for your vinyl, CDs, and more. Quick appraisals and instant offers.', image: '', imageAlt: 'Sell Collection Banner', backgroundColor: '#FDF5E7', primaryCTA: { text: 'Sell My Collection', link: '/buy-sell-trade' }, secondaryCTA: { text: '', link: '' } },
    'hero': { title: 'Section Title', subtitle: 'Section subtitle', image: '', backgroundColor: '#000000' },
    'rich-text': { content: '<p>Your content here...</p>' },
    'header': { title: 'Header Title', backgroundColor: '#000000' },
    'text': { content: 'Your text content here' },
    'two-column': { leftTitle: 'Left Column', leftContent: '<p>Left column content</p>', rightTitle: 'Right Column', rightContent: '<p>Right column content</p>' },
    'three-column': { columns: [{ title: 'Column 1', content: '<p>Content 1</p>' }, { title: 'Column 2', content: '<p>Content 2</p>' }, { title: 'Column 3', content: '<p>Content 3</p>' }] },
    'image': { src: '', alt: 'Image description', caption: 'Image caption' },
    'video': { videoUrl: '', caption: 'Video caption' },
    'cta': { title: 'Ready to Get Started?', description: 'Join us today!', buttonText: 'Get Started', buttonLink: '#', backgroundColor: '#2563eb' },
    'contact-form': { title: 'Help! (I need somebody)', description: 'Call/text us at 213-676-2645 or send us a message:', phone: '213-676-2645', fields: [{ name: 'name', label: 'Name', type: 'text', placeholder: 'Your Name', required: true }, { name: 'email', label: 'Email', type: 'email', placeholder: 'your@email.com', required: true }, { name: 'subject', label: 'Subject', type: 'text', placeholder: "What's this about?", required: true }, { name: 'message', label: 'Message', type: 'textarea', rows: 6, placeholder: 'Your message...', required: true }], buttonText: 'Send Message', buttonStyle: 'primary' },
    'quote': { quote: 'This is an inspiring quote', author: 'Author Name' },
    'testimonial': { testimonial: 'Great service!', name: 'John Doe', position: 'CEO', avatar: '' },
    'stats': { stats: [{ value: '100+', label: 'Happy Customers' }, { value: '50+', label: 'Projects' }, { value: '24/7', label: 'Support' }, { value: '99%', label: 'Satisfaction' }] },
    'accordion': { items: [{ title: 'Question 1', content: '<p>Answer 1</p>' }, { title: 'Question 2', content: '<p>Answer 2</p>' }] },
    'team-grid': { title: 'Our Team', members: [{ name: 'John Doe', position: 'CEO', bio: 'John leads our company with passion and vision.', photo: '' }] },
    'faq': { title: 'Frequently Asked Questions', questions: [{ question: 'What is this?', answer: '<p>This is a FAQ answer.</p>' }] },
    'table': { headers: ['Name', 'Price', 'Stock'], rows: [['Product A', '$10', '100'], ['Product B', '$20', '50']] },
    'timeline': { title: 'Our History', events: [{ date: '2020', title: 'Founded', description: 'Company was founded' }, { date: '2021', title: 'First Product', description: 'Launched our first product' }] },
    'code-block': { code: "console.log('Hello World');", language: 'javascript' },
    'collectibles-grid': { items: [['Item 1', 'Item 2', 'Item 3'], ['Item 4', 'Item 5', 'Item 6']] },
    'info-sections': [{ title: 'Section Title', content: 'Section content goes here...' }],
    'category-buttons': { type: 'category-buttons' },
    'gallery': { images: [] },
    'rsd-hero-banner': { image: '/Nivessa-web-1920-x-400-px-3-1536x320.webp', alt: 'RSD Black Friday' },
    'rsd-info-box': { title: 'RSD Black Friday at Nivessa!', paragraphs: ["We're excited to celebrate <strong>RSD Black Friday</strong> this year at both Hollywood and Pico locations!", 'Come be part of the RSD Black Friday fun at Nivessa!'] },
    'rsd-carousel': { images: [{ image: '/black-friday-1.png', link: '/book-show' }, { image: '/black-friday-2.png', link: '/store-locations' }, { image: '/black-friday-3.png', link: '/titles-list' }] },
    'rsd-banner': { image: '/black-friday-banner.webp', alt: 'Free Donuts', height: '200px' },
    'rsd-live-shows': { title: 'LIVE SHOWS!', shows: [{ time: '@ 11 AM', artist: 'AUDIO ANDY', description: 'Starting the day off behind the board is our own rock and roll expert Audio Andy.', image: '/black-friday-person-1.webp' }] },
    'featured-products': { title: 'Section Title', cssClass: 'slider-section', limit: 10, buttons: [{ text: 'View All', link: '/products' }] }
  };
  // Template sections, from the website's pageUtils.createContentSection.
  var TEMPLATE_SECTION = {
    header: { title: 'Header Title', backgroundColor: '#000000' },
    text: { content: 'Your text content here...' },
    hero: { title: 'Hero Title', subtitle: 'Hero subtitle text', image: '', backgroundColor: '#000000' },
    image: { src: '', alt: '', caption: '' },
    cta: { title: 'Turn Your Collection Into Cash', description: 'We make house calls for large collections and offer fair market value.', phone: '213-676-2645', buttonText: 'Call or Text Today!', buttonLink: 'tel:213-676-2645', backgroundColor: '#2563eb' }
  };
  var TEMPLATES = {
    basic: { name: 'Basic Page', types: ['header', 'text'] },
    landing: { name: 'Landing Page', types: ['hero', 'text', 'cta'] },
    about: { name: 'About Page', types: ['header', 'text', 'image', 'text'] }
  };
  function blankPage(title, type, sections) {
    return { title: title, slug: '', status: 'draft', showInNavbar: true, navbarOrder: 0, seo: { title: '', description: '', image: '', keywords: [] }, content: { type: type, sections: sections }, isSystemPage: false };
  }

  // ---- generic editor for a section's data ----
  var HTML_KEY = /(content|html|answer|bio|paragraph|testimonial|excerpt)$/i;
  var LONG_KEY = /(description|text|quote|code|subtitle|callToAction)$/i;
  var IMG_KEY = /(image|src|photo|avatar|logo|mainImage|stageImage)$/i;
  function getAt(obj, path) { return path.reduce(function (o, k) { return o == null ? o : o[k]; }, obj); }
  function setAt(obj, path, val) { var o = obj; for (var i = 0; i < path.length - 1; i++) o = o[path[i]]; o[path[path.length - 1]] = val; }
  function emptyLike(v) {
    if (Array.isArray(v)) return [];
    if (v && typeof v === 'object') { var o = {}; Object.keys(v).forEach(function (k) { o[k] = emptyLike(v[k]); }); return o; }
    if (typeof v === 'number') return 0;
    if (typeof v === 'boolean') return false;
    return '';
  }
  function label(k) { return String(k).replace(/([a-z])([A-Z])/g, '$1 $2').replace(/[-_]/g, ' '); }
  function pathAttr(p) { return esc(JSON.stringify(p)); }

  function renderPrim(path, key, v) {
    var pa = pathAttr(path);
    if (typeof v === 'boolean') return '<label class="wc-check"><input type="checkbox" data-p="' + pa + '" data-kind="bool"' + (v ? ' checked' : '') + '> ' + esc(label(key)) + '</label>';
    if (typeof v === 'number') return '<input type="number" class="wc-input" data-p="' + pa + '" data-kind="num" value="' + esc(v) + '">';
    var s = v == null ? '' : String(v);
    var k = String(key);
    if (IMG_KEY.test(k)) {
      return '<div class="pe-img"><img src="' + esc(siteImg(s)) + '" alt=""' + (s ? '' : ' style="display:none;"') + '><input type="text" class="wc-input" data-p="' + pa + '" data-kind="str" value="' + esc(s) + '" placeholder="Image URL">' +
        '<input type="file" accept="image/*" data-upload="' + pa + '" style="max-width:190px;"></div>';
    }
    if (/color$/i.test(k) && /^#[0-9a-f]{6}$/i.test(s)) {
      return '<div class="pe-img"><input type="color" data-p="' + pa + '" data-kind="str" value="' + esc(s) + '" style="width:44px; height:32px; padding:0;"><span class="wc-mono">' + esc(s) + '</span></div>';
    }
    var isHtml = HTML_KEY.test(k) || /<[a-z][\s\S]*>/i.test(s);
    if (isHtml || LONG_KEY.test(k) || s.length > 80) {
      var rows = Math.min(14, Math.max(3, Math.ceil(s.length / 90)));
      return '<textarea class="wc-input' + (isHtml ? ' wc-mono' : '') + '" rows="' + rows + '" data-p="' + pa + '" data-kind="str"' + (isHtml ? ' data-html="1"' : '') + '>' + esc(s) + '</textarea>' +
        (isHtml ? '<div class="wc-help"><a href="#" data-togglepreview="1">Show preview</a></div><div class="wc-preview" style="display:none;"></div>' : '');
    }
    return '<input type="text" class="wc-input" data-p="' + pa + '" data-kind="' + (v === null ? 'strnull' : 'str') + '" value="' + esc(s) + '">';
  }
  function renderAny(path, key, v) {
    if (Array.isArray(v)) {
      var h = '<div class="pe-row"><span class="lbl">' + esc(label(key)) + ' (' + v.length + ')</span><div class="pe-obj">';
      v.forEach(function (item, i) {
        var p = path.concat([i]);
        var ctrl = '<span class="wc-actions"><button type="button" class="wc-btn wc-btn-sm" data-arr="up" data-p="' + pathAttr(p) + '"' + (i === 0 ? ' disabled' : '') + '>Up</button>' +
          '<button type="button" class="wc-btn wc-btn-sm" data-arr="down" data-p="' + pathAttr(p) + '"' + (i === v.length - 1 ? ' disabled' : '') + '>Down</button>' +
          '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-arr="del" data-p="' + pathAttr(p) + '">Remove</button></span>';
        if (item !== null && typeof item === 'object') {
          h += '<div class="pe-item"><div class="pe-item-head"><span>Item ' + (i + 1) + '</span>' + ctrl + '</div>' + renderFields(p, item) + '</div>';
        } else {
          h += '<div class="pe-prim"><div style="flex:1;">' + renderPrim(p, key, item) + '</div>' + ctrl + '</div>';
        }
      });
      h += '<button type="button" class="wc-btn wc-btn-sm" data-arr="add" data-p="' + pathAttr(path) + '">Add item</button></div></div>';
      return h;
    }
    if (v !== null && typeof v === 'object') {
      return '<div class="pe-row"><span class="lbl">' + esc(label(key)) + '</span><div class="pe-obj">' + renderFields(path, v) + '</div></div>';
    }
    if (typeof v === 'boolean') return '<div class="pe-row">' + renderPrim(path, key, v) + '</div>';
    return '<div class="pe-row"><span class="lbl">' + esc(label(key)) + '</span>' + renderPrim(path, key, v) + '</div>';
  }
  function renderFields(path, obj) {
    if (Array.isArray(obj)) return renderAny(path, 'items', obj);
    var keys = Object.keys(obj);
    if (!keys.length) return '<div class="wc-muted">No settings for this section. It fills itself in on the website.</div>';
    return keys.map(function (k) { return renderAny(path.concat([k]), k, obj[k]); }).join('');
  }
  function siteImg(s) {
    if (!s) return '';
    if (/^https?:\/\//i.test(s)) return s;
    return 'https://nivessa.com' + (s.charAt(0) === '/' ? '' : '/') + s;
  }

  var collapsed = {};
  function renderSections() {
    var secs = page.content.sections;
    var box = document.getElementById('pe-sections');
    if (!secs.length) { box.innerHTML = '<div class="wc-empty">No content sections yet. Pick a type above and click "Add section".</div>'; return; }
    box.innerHTML = secs.map(function (s, i) {
      var key = s.id || ('i' + i);
      var isCol = collapsed[key] !== undefined ? collapsed[key] : secs.length > 4;
      if (!s.data || typeof s.data !== 'object') s.data = clone(DEFAULTS[s.type] || {});
      return '<div class="pe-sec' + (isCol ? ' collapsed' : '') + '" data-sec="' + i + '">' +
        '<div class="pe-sec-head"><span class="ttl" data-collapse="' + esc(key) + '">' + (i + 1) + '. ' + esc(label(s.type)) + (s.data && s.data.title ? '<small>' + esc(s.data.title) + '</small>' : '') + '<small>id: ' + esc(s.id || '') + '</small></span>' +
        '<button type="button" class="wc-btn wc-btn-sm" data-sec-move="-1" data-i="' + i + '"' + (i === 0 ? ' disabled' : '') + '>Up</button>' +
        '<button type="button" class="wc-btn wc-btn-sm" data-sec-move="1" data-i="' + i + '"' + (i === secs.length - 1 ? ' disabled' : '') + '>Down</button>' +
        '<button type="button" class="wc-btn wc-btn-sm" data-sec-json="' + i + '">Edit as JSON</button>' +
        '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-sec-del="' + i + '">Delete</button></div>' +
        '<div class="pe-sec-body">' + renderFields(['content', 'sections', i, 'data'], s.data) + '</div></div>';
    }).join('');
  }
  function reorder() { page.content.sections.forEach(function (s, i) { s.order = i + 1; }); }

  function initForm() {
    document.getElementById('pe-title').value = page.title || '';
    document.getElementById('pe-slug').value = page.slug || '';
    page.seo = page.seo || { title: '', description: '', image: '', keywords: [] };
    page.content = page.content || { type: 'custom', sections: [] };
    page.content.sections = page.content.sections || [];
    document.getElementById('pe-seo-title').value = page.seo.title || '';
    document.getElementById('pe-seo-desc').value = page.seo.description || '';
    document.getElementById('pe-seo-img').value = page.seo.image || '';
    document.getElementById('pe-navbar').checked = !!page.showInNavbar;
    document.getElementById('pe-navorder').value = page.navbarOrder || 0;
    updCounts(); updNav(); updSeoImg();
    renderSections();
  }
  function updCounts() {
    document.getElementById('pe-seo-title-n').textContent = (page.seo.title || '').length;
    document.getElementById('pe-seo-desc-n').textContent = (page.seo.description || '').length;
  }
  function updNav() { document.getElementById('pe-navorder-wrap').style.display = page.showInNavbar ? '' : 'none'; }
  function updSeoImg() { var im = document.getElementById('pe-seo-img-prev'); var s = page.seo.image || ''; im.src = siteImg(s); im.style.display = s ? '' : 'none'; }

  function bind(id, fn) { document.getElementById(id).addEventListener('input', function (e) { fn(e.target); markDirty(); }); }
  bind('pe-title', function (el) { page.title = el.value; if (!slugManual) { page.slug = genSlug(el.value); document.getElementById('pe-slug').value = page.slug; } });
  bind('pe-slug', function (el) { slugManual = true; page.slug = el.value; });
  bind('pe-seo-title', function (el) { page.seo.title = el.value; updCounts(); });
  bind('pe-seo-desc', function (el) { page.seo.description = el.value; updCounts(); });
  bind('pe-seo-img', function (el) { page.seo.image = el.value; updSeoImg(); });
  document.getElementById('pe-navbar').addEventListener('change', function (e) { page.showInNavbar = e.target.checked; updNav(); markDirty(); });
  bind('pe-navorder', function (el) { page.navbarOrder = parseInt(el.value, 10) || 0; });

  function upload(file, done) {
    var fd = new FormData(); fd.append('image', file);
    fetch(UPLOAD_URL, { method: 'POST', headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) { if (!res.success) return flash(res.message || 'Upload failed.', false); done(res.url); })
      .catch(function () { flash('Upload failed.', false); });
  }
  document.getElementById('pe-seo-img-file').addEventListener('change', function (e) {
    var f = e.target.files[0]; if (!f) return;
    upload(f, function (url) { page.seo.image = url; document.getElementById('pe-seo-img').value = url; updSeoImg(); markDirty(); });
  });

  var box = document.getElementById('pe-sections');
  box.addEventListener('input', function (e) {
    var el = e.target, p = el.getAttribute('data-p');
    if (!p) return;
    var path = JSON.parse(p), kind = el.getAttribute('data-kind'), v;
    if (kind === 'bool') v = el.checked;
    else if (kind === 'num') v = el.value === '' ? 0 : Number(el.value);
    else if (kind === 'strnull') v = el.value === '' ? null : el.value;
    else v = el.value;
    setAt(page, path, v);
    if (el.type === 'color' && el.nextElementSibling) el.nextElementSibling.textContent = el.value;
    if (el.getAttribute('data-html')) { var pv = el.parentNode.querySelector('.wc-preview'); if (pv && pv.style.display !== 'none') pv.innerHTML = el.value; }
    if (el.previousElementSibling && el.previousElementSibling.tagName === 'IMG') { el.previousElementSibling.src = siteImg(el.value); el.previousElementSibling.style.display = el.value ? '' : 'none'; }
    markDirty();
  });
  box.addEventListener('change', function (e) {
    var el = e.target;
    if (el.getAttribute('data-kind') === 'bool') { setAt(page, JSON.parse(el.getAttribute('data-p')), el.checked); markDirty(); return; }
    var up = el.getAttribute('data-upload');
    if (up && el.files[0]) {
      upload(el.files[0], function (url) {
        setAt(page, JSON.parse(up), url);
        var input = el.previousElementSibling; input.value = url;
        var im = input.previousElementSibling; im.src = siteImg(url); im.style.display = '';
        markDirty();
      });
    }
  });
  box.addEventListener('click', function (e) {
    var t = e.target;
    if (t.getAttribute('data-togglepreview')) {
      e.preventDefault();
      var wrap = t.closest('.wc-help'), pv = wrap.nextElementSibling, ta = wrap.previousElementSibling;
      var show = pv.style.display === 'none';
      pv.style.display = show ? '' : 'none'; pv.innerHTML = show ? ta.value : '';
      t.textContent = show ? 'Hide preview' : 'Show preview';
      return;
    }
    if (t.hasAttribute('data-collapse')) { var k = t.getAttribute('data-collapse'); var sec = t.closest('.pe-sec'); sec.classList.toggle('collapsed'); collapsed[k] = sec.classList.contains('collapsed'); return; }
    var arr = t.getAttribute('data-arr');
    if (arr) {
      var path = JSON.parse(t.getAttribute('data-p'));
      if (arr === 'add') {
        var list = getAt(page, path);
        list.push(list.length ? emptyLike(list[list.length - 1]) : '');
      } else {
        var idx = path[path.length - 1], parent = getAt(page, path.slice(0, -1));
        if (arr === 'del') parent.splice(idx, 1);
        if (arr === 'up' && idx > 0) parent.splice(idx - 1, 0, parent.splice(idx, 1)[0]);
        if (arr === 'down' && idx < parent.length - 1) parent.splice(idx + 1, 0, parent.splice(idx, 1)[0]);
      }
      markDirty(); renderSections(); return;
    }
    if (t.hasAttribute('data-sec-move')) {
      var i = Number(t.getAttribute('data-i')), to = i + Number(t.getAttribute('data-sec-move')), s = page.content.sections;
      if (to < 0 || to >= s.length) return;
      s.splice(to, 0, s.splice(i, 1)[0]); reorder(); markDirty(); renderSections(); return;
    }
    if (t.hasAttribute('data-sec-del')) {
      if (!confirm('Are you sure you want to delete this section?')) return;
      page.content.sections.splice(Number(t.getAttribute('data-sec-del')), 1); reorder(); markDirty(); renderSections(); return;
    }
    if (t.hasAttribute('data-sec-json')) {
      var j = Number(t.getAttribute('data-sec-json')), body = t.closest('.pe-sec').querySelector('.pe-sec-body');
      t.closest('.pe-sec').classList.remove('collapsed');
      body.innerHTML = '<textarea class="wc-input wc-mono" rows="16">' + esc(JSON.stringify(page.content.sections[j].data, null, 2)) + '</textarea>' +
        '<div class="wc-actions" style="margin-top:8px;"><button type="button" class="wc-btn wc-btn-sm wc-btn-primary" data-json-apply="' + j + '">Apply JSON</button><button type="button" class="wc-btn wc-btn-sm" data-json-cancel="1">Cancel</button></div>';
      return;
    }
    if (t.hasAttribute('data-json-apply')) {
      var ta2 = t.closest('.pe-sec-body').querySelector('textarea');
      try { page.content.sections[Number(t.getAttribute('data-json-apply'))].data = JSON.parse(ta2.value); markDirty(); renderSections(); }
      catch (err) { flash('That JSON is not valid: ' + err.message, false); }
      return;
    }
    if (t.hasAttribute('data-json-cancel')) { renderSections(); }
  });

  document.getElementById('pe-add-btn').addEventListener('click', function () {
    var v = document.getElementById('pe-add-type').value, s = page.content.sections, sec;
    if (v === '__upcoming-events') {
      // The website renders this rich-text section by its id.
      sec = { id: 'upcoming-events', type: 'rich-text', data: { title: 'Upcoming at Nivessa', subtitle: 'Live shows, in-store performances, and community nights at both stores.', limit: 4 } };
    } else if (v.indexOf('__featured:') === 0) {
      var parts = v.split(':');
      sec = { id: parts[1], type: 'featured-products', data: { title: parts.slice(2).join(':'), cssClass: 'slider-section', limit: 10, buttons: [{ text: 'View All Vinyl', link: '/products?productType=Vinyl+Records' }] } };
    } else {
      sec = { id: 'section-' + Date.now(), type: v, data: clone(DEFAULTS[v] || {}) };
    }
    sec.order = s.length + 1;
    s.push(sec);
    collapsed[sec.id] = false;
    markDirty(); renderSections();
    var last = document.querySelector('.pe-sec:last-child'); if (last) last.scrollIntoView({ behavior: 'smooth', block: 'center' });
  });

  function validateNew() {
    if (!(page.title || '').trim()) return 'Title is required.';
    if (!(page.seo.title || '').trim()) return 'SEO title is required.';
    if (!(page.seo.description || '').trim()) return 'SEO description is required.';
    if (page.seo.title.length > 60) return 'SEO title must be 60 characters or less.';
    if (page.seo.description.length > 160) return 'SEO description must be 160 characters or less.';
    if (page.slug && !/^[a-z0-9-]+$/.test(page.slug)) return 'Slug can only contain lowercase letters, numbers, and hyphens.';
    return null;
  }
  document.querySelectorAll('[data-save]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var status = btn.getAttribute('data-save');
      if (IS_NEW) { var err = validateNew(); if (err) return flash(err, false); if (!page.slug) page.slug = genSlug(page.title); }
      page.status = status;
      var all = document.querySelectorAll('[data-save]'); all.forEach(function (b) { b.disabled = true; });
      fetch(SAVE_URL, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(page) })
        .then(function (r) { return r.json().catch(function () { return { success: false, message: 'Unexpected response (HTTP ' + r.status + ').' }; }); })
        .then(function (res) {
          all.forEach(function (b) { b.disabled = false; });
          if (!res.success) return flash(res.message || 'Failed to save the page.', false);
          dirty = false; document.getElementById('pe-dirty').style.display = 'none';
          if (res.redirect) { window.location.href = res.redirect; return; }
          if (res.data && res.data._id) { page = res.data; initForm(); }
          document.getElementById('pe-sub').innerHTML = 'Status: <b>' + esc(page.status) + '</b>';
          flash(status === 'draft' ? 'Page saved as a draft.' : 'Page published.', true);
        })
        .catch(function () { all.forEach(function (b) { b.disabled = false; }); flash('Failed to save the page.', false); });
    });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  function start(p) {
    page = p;
    var ch = document.getElementById('pe-choose'); if (ch) ch.style.display = 'none';
    document.getElementById('pe-editor').style.display = '';
    document.getElementById('pe-topbar').style.display = '';
    initForm();
  }
  document.querySelectorAll('[data-template]').forEach(function (b) {
    b.addEventListener('click', function () {
      var k = b.getAttribute('data-template');
      if (k === 'blank') return start(blankPage('New Page', 'custom', [{ id: sid(), type: 'text', order: 1, data: clone(TEMPLATE_SECTION.text) }]));
      var t = TEMPLATES[k];
      start(blankPage(t.name, k, t.types.map(function (ty, i) { return { id: sid(), type: ty, order: i + 1, data: clone(TEMPLATE_SECTION[ty]) }; })));
    });
  });
  if (page) initForm();
})();
</script>
@endsection
