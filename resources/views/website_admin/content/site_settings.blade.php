@extends('layouts.app')
@section('title', 'Website Settings')

@section('content')
@include('website_admin.content._styles')
<style>
  .ss-link { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1.4fr) auto auto; gap:8px; align-items:center; margin-bottom:6px; }
  .ss-nav { border:1px solid #e5e7eb; border-radius:8px; padding:10px; margin-bottom:10px; background:#fafafa; }
  .ss-sub { margin:8px 0 0 18px; padding-left:10px; border-left:3px solid #e5e7eb; }
  .ss-pane { display:none; }
  .ss-pane.active { display:block; }
  @media (max-width: 700px) { .ss-link { grid-template-columns:1fr; } }
</style>
<div class="ev-wrap" style="max-width:1100px;">
  <div class="ev-head">
    <div>
      <h1>Website Settings</h1>
      <p class="sub">The nivessa.com header (free shipping banner, menu), footer (store hours, links) and what the Ask Nivessa chat bot knows.</p>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @foreach($loadErrors as $e)<div class="alert-err">Could not load {{ $e }}</div>@endforeach

  <div class="wc-tabs">
    <a href="?tab=header" class="wc-tab {{ $tab === 'header' ? 'active' : '' }}" data-tab="header">Header</a>
    <a href="?tab=footer" class="wc-tab {{ $tab === 'footer' ? 'active' : '' }}" data-tab="footer">Footer and store hours</a>
    <a href="?tab=assistant" class="wc-tab {{ $tab === 'assistant' ? 'active' : '' }}" data-tab="assistant">Chat bot</a>
  </div>

  {{-- HEADER --}}
  <form method="POST" action="{{ route('wa.content.siteSettingsSave', 'header') }}" class="wc-card wc-card-pad ss-pane {{ $tab === 'header' ? 'active' : '' }}" id="pane-header">
    {{ csrf_field() }}
    <h2>Header</h2>
    @if(!$headerSaved)<p class="wc-muted">Nothing saved yet. These are the website's built-in defaults.</p>@endif
    <div class="wc-grid">
      <div class="wc-field"><label>Company name</label><input type="text" name="companyName" value="{{ $header['companyName'] ?? '' }}"></div>
      <div class="wc-field"><label>Phone number</label><input type="text" name="phone" value="{{ $header['phone'] ?? '' }}"></div>
    </div>

    <div class="wc-card wc-card-pad" style="margin-top:14px; background:#fafafa;">
      <h2>Shipping banner</h2>
      <label class="wc-check" style="margin-bottom:10px;"><input type="checkbox" name="shippingEnabled" value="1" @if(($header['shippingInfo']['enabled'] ?? true) !== false) checked @endif> Show the shipping banner</label>
      <div class="wc-grid">
        <div class="wc-field"><label>Shipping text</label><input type="text" name="shippingText" value="{{ $header['shippingInfo']['text'] ?? 'FREE Shipping on orders $39+' }}" placeholder="FREE Shipping on orders $39+"><div class="wc-help">This is where the free shipping threshold shows. Change the amount here when the rule changes.</div></div>
        <div class="wc-field"><label>Banner icon</label><input type="text" name="shippingIcon" value="{{ $header['shippingInfo']['icon'] ?? '' }}" style="max-width:110px;"></div>
      </div>
    </div>

    <h2 style="margin-top:6px;">Menu links</h2>
    <div id="ss-nav">
      @foreach(($header['navLinks'] ?? []) as $i => $link)
        <div class="ss-nav" data-key="n{{ $i }}">
          <div class="ss-link">
            <input type="text" class="wc-input" name="navLinks[n{{ $i }}][label]" value="{{ $link['label'] ?? '' }}" placeholder="Link label">
            <input type="text" class="wc-input" name="navLinks[n{{ $i }}][href]" value="{{ $link['href'] ?? '' }}" placeholder="Link URL">
            <label class="wc-check"><input type="checkbox" name="navLinks[n{{ $i }}][external]" value="1" @if(!empty($link['external'])) checked @endif> New tab</label>
            <button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-nav">Remove</button>
          </div>
          <div class="ss-sub" @if(!isset($link['submenu'])) style="display:none;" @endif>
            <input type="hidden" name="navLinks[n{{ $i }}][hasSubmenu]" value="{{ isset($link['submenu']) ? '1' : '' }}" class="ss-has-sub">
            <div class="wc-label" style="margin-bottom:4px;">Submenu items</div>
            <div class="ss-sub-rows">
              @foreach(($link['submenu'] ?? []) as $j => $sub)
                <div class="ss-link">
                  <input type="text" class="wc-input" name="navLinks[n{{ $i }}][submenu][s{{ $j }}][label]" value="{{ $sub['label'] ?? '' }}" placeholder="Submenu label">
                  <input type="text" class="wc-input" name="navLinks[n{{ $i }}][submenu][s{{ $j }}][href]" value="{{ $sub['href'] ?? '' }}" placeholder="Submenu URL">
                  <label class="wc-check"><input type="checkbox" name="navLinks[n{{ $i }}][submenu][s{{ $j }}][external]" value="1" @if(!empty($sub['external'])) checked @endif> New tab</label>
                  <button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-link">Remove</button>
                </div>
              @endforeach
            </div>
          </div>
          <button type="button" class="wc-btn wc-btn-sm" data-add-sub style="margin-top:6px;">{{ isset($link['submenu']) ? 'Add submenu item' : 'Add submenu' }}</button>
        </div>
      @endforeach
    </div>
    <button type="button" class="wc-btn" id="ss-add-nav">Add menu link</button>

    <h2 style="margin-top:18px;">Social media links</h2>
    <div class="wc-grid">
      <div class="wc-field"><label>Instagram URL</label><input type="text" name="instagram" value="{{ $header['socialLinks']['instagram'] ?? '' }}"></div>
      <div class="wc-field"><label>TikTok URL</label><input type="text" name="tiktok" value="{{ $header['socialLinks']['tiktok'] ?? '' }}"></div>
      <div class="wc-field"><label>YouTube URL</label><input type="text" name="youtube" value="{{ $header['socialLinks']['youtube'] ?? '' }}"></div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="wc-btn wc-btn-primary">Save header settings</button></div>
  </form>

  {{-- FOOTER --}}
  <form method="POST" action="{{ route('wa.content.siteSettingsSave', 'footer') }}" class="wc-card wc-card-pad ss-pane {{ $tab === 'footer' ? 'active' : '' }}" id="pane-footer">
    {{ csrf_field() }}
    <h2>Footer</h2>
    @if(!$footerSaved)<p class="wc-muted">Nothing saved yet. These are the website's built-in defaults.</p>@endif
    <div class="wc-grid">
      <div class="wc-field"><label>Company name</label><input type="text" name="companyName" value="{{ $footer['companyName'] ?? '' }}"></div>
      <div class="wc-field"><label>Phone number</label><input type="text" name="phone" value="{{ $footer['phone'] ?? '' }}"></div>
      <div class="wc-field"><label>Stay connected title</label><input type="text" name="stayConnectedTitle" value="{{ $footer['stayConnected']['title'] ?? '' }}"></div>
      <div class="wc-field"><label>Questions section title</label><input type="text" name="questionsTitle" value="{{ $footer['questions']['title'] ?? '' }}"></div>
      <div class="wc-field wc-span2"><label>Stay connected description</label><textarea name="stayConnectedDescription" rows="2">{{ $footer['stayConnected']['description'] ?? '' }}</textarea></div>
      <div class="wc-field"><label>Call text</label><input type="text" name="callText" value="{{ $footer['questions']['callText'] ?? '' }}"></div>
      <div class="wc-field"><label>Mission title</label><input type="text" name="missionTitle" value="{{ $footer['mission']['title'] ?? '' }}"></div>
      <div class="wc-field wc-span2"><label>Mission statement</label><textarea name="missionText" rows="3">{{ $footer['mission']['text'] ?? '' }}</textarea></div>
    </div>

    <h2 style="margin-top:18px;">Store locations and hours</h2>
    <p class="wc-muted" style="margin-top:-6px;">Shown in the site footer. One line per hours entry, for example "Sat-Sun 10:00 am - 8:00 pm".</p>
    <div id="ss-locs">
      @foreach(($footer['locations'] ?? []) as $i => $loc)
        <div class="ss-nav">
          <div class="wc-grid">
            <div class="wc-field"><label>Store name</label><input type="text" name="locations[l{{ $i }}][name]" value="{{ $loc['name'] ?? '' }}"></div>
            <div class="wc-field"><label>Address</label><input type="text" name="locations[l{{ $i }}][address]" value="{{ $loc['address'] ?? '' }}"></div>
            <div class="wc-field wc-span2"><label>Hours</label><textarea name="locations[l{{ $i }}][hours]" rows="4">{{ implode("\n", (array) ($loc['hours'] ?? [])) }}</textarea></div>
          </div>
          <button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-nav" style="margin-top:6px;">Remove store</button>
        </div>
      @endforeach
    </div>
    <button type="button" class="wc-btn" id="ss-add-loc">Add store</button>

    <h2 style="margin-top:18px;">Quick links</h2>
    <div class="wc-field" style="margin-bottom:10px; max-width:420px;"><label>Quick links title</label><input type="text" name="quickLinksTitle" value="{{ $footer['quickLinks']['title'] ?? '' }}"></div>
    <div id="ss-quick" data-name="quickLinks">
      @foreach(($footer['quickLinks']['links'] ?? []) as $i => $link)
        <div class="ss-link">
          <input type="text" class="wc-input" name="quickLinks[q{{ $i }}][label]" value="{{ $link['label'] ?? '' }}" placeholder="Link label">
          <input type="text" class="wc-input" name="quickLinks[q{{ $i }}][href]" value="{{ $link['href'] ?? '' }}" placeholder="Link URL">
          <label class="wc-check"><input type="checkbox" name="quickLinks[q{{ $i }}][external]" value="1" @if(!empty($link['external'])) checked @endif> New tab</label>
          <button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-link">Remove</button>
        </div>
      @endforeach
    </div>
    <button type="button" class="wc-btn" data-add-link="ss-quick">Add quick link</button>

    <h2 style="margin-top:18px;">Bottom footer links</h2>
    <div id="ss-bottom" data-name="bottomLinks">
      @foreach(($footer['bottomLinks'] ?? []) as $i => $link)
        <div class="ss-link">
          <input type="text" class="wc-input" name="bottomLinks[b{{ $i }}][label]" value="{{ $link['label'] ?? '' }}" placeholder="Link label">
          <input type="text" class="wc-input" name="bottomLinks[b{{ $i }}][href]" value="{{ $link['href'] ?? '' }}" placeholder="Link URL">
          <label class="wc-check"><input type="checkbox" name="bottomLinks[b{{ $i }}][external]" value="1" @if(!empty($link['external'])) checked @endif> New tab</label>
          <button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-link">Remove</button>
        </div>
      @endforeach
    </div>
    <button type="button" class="wc-btn" data-add-link="ss-bottom">Add bottom link</button>

    <h2 style="margin-top:18px;">Social media links</h2>
    <div class="wc-grid">
      <div class="wc-field"><label>Instagram URL</label><input type="text" name="instagram" value="{{ $footer['socialLinks']['instagram'] ?? '' }}"></div>
      <div class="wc-field"><label>TikTok URL</label><input type="text" name="tiktok" value="{{ $footer['socialLinks']['tiktok'] ?? '' }}"></div>
      <div class="wc-field"><label>YouTube URL</label><input type="text" name="youtube" value="{{ $footer['socialLinks']['youtube'] ?? '' }}"></div>
    </div>
    <div style="margin-top:16px;"><button type="submit" class="wc-btn wc-btn-primary">Save footer settings</button></div>
  </form>

  {{-- CHAT BOT --}}
  <form method="POST" action="{{ route('wa.content.siteSettingsSave', 'assistant') }}" class="wc-card wc-card-pad ss-pane {{ $tab === 'assistant' ? 'active' : '' }}" id="pane-assistant">
    {{ csrf_field() }}
    <h2>Chat bot knowledge</h2>
    <p class="wc-muted">This is what the "Ask Nivessa" chat bot knows. Edit the text to add or change what it tells customers (hours, prices, policies, shipping, jobs, events), then save. Changes go live within about a minute. Keep the section headings and the ANSWERING RULES at the bottom so answers stay accurate.</p>
    @if(!$assistantSaved)<p class="wc-muted">Nothing saved yet. This is the text bundled with the website.</p>@endif
    <div class="wc-field"><label>Knowledge base</label><textarea name="knowledgeBase" rows="28" class="wc-mono">{{ old('knowledgeBase', $knowledgeBase) }}</textarea></div>
    <div style="margin-top:16px;"><button type="submit" class="wc-btn wc-btn-primary">Save chat bot knowledge</button></div>
  </form>
</div>

<script>
(function () {
  var n = 0;
  function key(p) { n += 1; return p + 'x' + Date.now() + n; }
  function linkRow(base, k, lblPh, urlPh) {
    var d = document.createElement('div');
    d.className = 'ss-link';
    d.innerHTML = '<input type="text" class="wc-input" name="' + base + '[' + k + '][label]" placeholder="' + lblPh + '">' +
      '<input type="text" class="wc-input" name="' + base + '[' + k + '][href]" placeholder="' + urlPh + '">' +
      '<label class="wc-check"><input type="checkbox" name="' + base + '[' + k + '][external]" value="1"> New tab</label>' +
      '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-link">Remove</button>';
    return d;
  }
  document.querySelectorAll('[data-tab]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      e.preventDefault();
      var t = a.getAttribute('data-tab');
      document.querySelectorAll('[data-tab]').forEach(function (b) { b.classList.toggle('active', b === a); });
      document.querySelectorAll('.ss-pane').forEach(function (p) { p.classList.toggle('active', p.id === 'pane-' + t); });
      history.replaceState(null, '', '?tab=' + t);
    });
  });
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t.hasAttribute('data-rm')) { var row = t.closest(t.getAttribute('data-rm')); if (row) row.remove(); return; }
    if (t.hasAttribute('data-add-sub')) {
      var nav = t.closest('.ss-nav'), sub = nav.querySelector('.ss-sub');
      var base = nav.querySelector('input[name$="[label]"]').name.replace(/\[label\]$/, '') + '[submenu]';
      sub.style.display = ''; sub.querySelector('.ss-has-sub').value = '1';
      sub.querySelector('.ss-sub-rows').appendChild(linkRow(base, key('s'), 'Submenu label', 'Submenu URL'));
      t.textContent = 'Add submenu item';
      return;
    }
    if (t.hasAttribute('data-add-link')) {
      var box = document.getElementById(t.getAttribute('data-add-link'));
      box.appendChild(linkRow(box.getAttribute('data-name'), key('l'), 'Link label', 'Link URL'));
    }
  });
  document.getElementById('ss-add-nav').addEventListener('click', function () {
    var k = key('n'), d = document.createElement('div');
    d.className = 'ss-nav';
    d.appendChild(linkRow('navLinks', k, 'Link label', 'Link URL'));
    d.firstChild.lastChild.setAttribute('data-rm', '.ss-nav');
    var sub = document.createElement('div');
    sub.className = 'ss-sub'; sub.style.display = 'none';
    sub.innerHTML = '<input type="hidden" name="navLinks[' + k + '][hasSubmenu]" value="" class="ss-has-sub"><div class="wc-label" style="margin-bottom:4px;">Submenu items</div><div class="ss-sub-rows"></div>';
    d.appendChild(sub);
    var b = document.createElement('button');
    b.type = 'button'; b.className = 'wc-btn wc-btn-sm'; b.setAttribute('data-add-sub', ''); b.style.marginTop = '6px'; b.textContent = 'Add submenu';
    d.appendChild(b);
    document.getElementById('ss-nav').appendChild(d);
  });
  document.getElementById('ss-add-loc').addEventListener('click', function () {
    var k = key('l'), d = document.createElement('div');
    d.className = 'ss-nav';
    d.innerHTML = '<div class="wc-grid"><div class="wc-field"><label>Store name</label><input type="text" name="locations[' + k + '][name]"></div>' +
      '<div class="wc-field"><label>Address</label><input type="text" name="locations[' + k + '][address]"></div>' +
      '<div class="wc-field wc-span2"><label>Hours</label><textarea name="locations[' + k + '][hours]" rows="4"></textarea></div></div>' +
      '<button type="button" class="wc-btn wc-btn-sm wc-btn-danger" data-rm=".ss-nav" style="margin-top:6px;">Remove store</button>';
    document.getElementById('ss-locs').appendChild(d);
  });
})();
</script>
@endsection
