@extends('layouts.app')
@section('title', 'SEO Manager')

@section('content')
@include('website_admin.content._styles')
@php $H = \App\Http\Controllers\WebsiteAdmin\SeoController::class; @endphp
<div class="ev-wrap ev-wrap-wide">
  <div class="ev-head">
    <div>
      <h1>SEO Manager</h1>
      <p class="sub">Page titles, meta descriptions and robots settings for nivessa.com. These are rendered server-side so search engines see them.</p>
    </div>
    <div class="wc-actions">
      <button type="button" class="wc-btn wc-btn-primary" onclick="seoOpen(null)">Add SEO page</button>
      <form method="POST" action="{{ route('wa.content.seoDefaults') }}">{{ csrf_field() }}<button type="submit" class="wc-btn">Create defaults</button></form>
    </div>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  @if(count($unlinked) > 0)
    <div class="wc-card wc-card-pad" style="background:#fffbeb; border-color:#fde68a;">
      <b style="color:#92400e;">{{ count($unlinked) }} page(s) without SEO settings</b>
      <div class="wc-muted" style="color:#92400e; margin:4px 0 8px;">Some pages from Website Pages don't have SEO settings yet. Sync them to manage their SEO here.</div>
      <form method="POST" action="{{ route('wa.content.seoSync') }}">{{ csrf_field() }}<button type="submit" class="wc-btn">Sync all pages</button></form>
    </div>
  @endif

  <form method="GET" class="wc-filters">
    <div class="wc-field" style="flex:1; min-width:220px;"><label>Search</label><input type="text" name="search" value="{{ $search }}" placeholder="Search pages"></div>
    <button type="submit" class="wc-btn wc-btn-primary">Search</button>
    @if($search !== '')<a class="wc-btn" href="{{ route('wa.content.seo') }}">Clear</a>@endif
  </form>

  <div class="wc-card">
    @if(count($items) === 0)
      <div class="wc-empty">
        No SEO settings found.@if($search !== '') Try a different search.@else
        <form method="POST" action="{{ route('wa.content.seoDefaults') }}" style="display:inline;">{{ csrf_field() }}<button type="submit" class="wc-btn wc-btn-sm">Create default settings</button></form>@endif
      </div>
    @else
      <table class="wc-tbl">
        <tbody>
        @foreach($items as $it)
          <tr>
            <td>
              <div style="margin-bottom:4px;"><span class="wc-pill blue">{{ $it['pageType'] ?? '' }}</span> <span class="wc-mono">{{ $it['pageSlug'] ?? '' }}</span>@if(empty($it['isActive'])) <span class="wc-pill red">Inactive</span>@endif</div>
              <div style="font-weight:700; font-size:15px;">{{ $it['pageName'] ?? '' }}</div>
              <div style="font-weight:600; color:#374151;">{{ $it['title'] ?? '' }}</div>
              <div class="wc-muted">{{ $it['description'] ?? '' }}</div>
              <div class="wc-muted" style="margin-top:4px;">Robots: {{ $it['robots'] ?? '' }}. Updated {{ $H::fmtDate($it['updatedAt'] ?? null) }}.</div>
            </td>
            <td style="width:170px;">
              <div class="wc-actions" style="justify-content:flex-end;">
                <button type="button" class="wc-btn wc-btn-sm" onclick='seoOpen(@json($it))'>Edit</button>
                <form method="POST" action="{{ route('wa.content.seoDelete', $it['_id']) }}" onsubmit="return confirm('Are you sure you want to delete this SEO setting?');">
                  {{ csrf_field() }}<button type="submit" class="wc-btn wc-btn-sm wc-btn-danger">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>

<dialog class="wc-dialog" id="seo-dialog">
  <form method="POST" id="seo-form" class="wc-dialog-body" action="{{ route('wa.content.seoStore') }}">
    {{ csrf_field() }}
    <h3 id="seo-title">Add SEO settings</h3>
    <div class="wc-grid">
      <div class="wc-field"><label>Page type (required)</label>
        <select name="pageType" id="seo-pageType">@foreach($pageTypes as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
      </div>
      <div class="wc-field"><label>Page slug (required)</label><input type="text" name="pageSlug" id="seo-pageSlug" placeholder="/about" required><div class="wc-help">For example /about or /</div></div>
      <div class="wc-field wc-span2"><label>Page name (required)</label><input type="text" name="pageName" id="seo-pageName" placeholder="About Us" required></div>
      <div class="wc-field wc-span2"><label>Meta title (required, up to 60) <span id="seo-title-n"></span></label><input type="text" name="title" id="seo-f-title" maxlength="60" placeholder="About Nivessa Records | Music Store" required></div>
      <div class="wc-field wc-span2"><label>Meta description (required, up to 160) <span id="seo-desc-n"></span></label><textarea name="description" id="seo-description" maxlength="160" rows="3" required></textarea></div>
      <div class="wc-field wc-span2"><label>Keywords</label><input type="text" name="keywords" id="seo-keywords" placeholder="vinyl records, music store, Hollywood"><div class="wc-help">Comma separated.</div></div>
      <div class="wc-field wc-span2"><label>OG title</label><input type="text" name="ogTitle" id="seo-ogTitle"><div class="wc-help">Leave blank to use the meta title.</div></div>
      <div class="wc-field wc-span2"><label>OG description</label><textarea name="ogDescription" id="seo-ogDescription" rows="2"></textarea><div class="wc-help">Leave blank to use the meta description.</div></div>
      <div class="wc-field wc-span2"><label>OG image URL</label><input type="text" name="ogImage" id="seo-ogImage" placeholder="https://nivessa.com/images/og-image.png"></div>
      <div class="wc-field wc-span2"><label>Canonical URL (optional)</label><input type="text" name="canonical" id="seo-canonical" placeholder="https://nivessa.com/about"></div>
      <div class="wc-field"><label>Robots</label>
        <select name="robots" id="seo-robots">@foreach($robots as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
      </div>
      <label class="wc-check" style="align-self:end;"><input type="checkbox" name="isActive" id="seo-isActive" value="1" checked> Active (show on website)</label>
    </div>
    <div class="wc-dialog-actions">
      <button type="button" class="wc-btn" onclick="document.getElementById('seo-dialog').close()">Cancel</button>
      <button type="submit" class="wc-btn wc-btn-primary" id="seo-submit">Create</button>
    </div>
  </form>
</dialog>
<script>
  var SEO_STORE = @json(route('wa.content.seoStore'));
  var SEO_BASE = @json(url('/website-content/seo')) + '/';
  function seoCount() {
    document.getElementById('seo-title-n').textContent = document.getElementById('seo-f-title').value.length + '/60';
    document.getElementById('seo-desc-n').textContent = document.getElementById('seo-description').value.length + '/160';
  }
  ['seo-f-title', 'seo-description'].forEach(function (id) { document.getElementById(id).addEventListener('input', seoCount); });
  function seoOpen(it) {
    var f = document.getElementById('seo-form');
    f.action = it ? SEO_BASE + encodeURIComponent(it._id) : SEO_STORE;
    document.getElementById('seo-title').textContent = it ? 'Edit SEO settings' : 'Add SEO settings';
    document.getElementById('seo-submit').textContent = it ? 'Update' : 'Create';
    var d = it || { pageType: 'custom', robots: 'index,follow', isActive: true };
    ['pageType', 'pageSlug', 'pageName', 'description', 'keywords', 'ogTitle', 'ogDescription', 'ogImage', 'canonical', 'robots'].forEach(function (k) {
      document.getElementById('seo-' + k).value = d[k] || (k === 'pageType' ? 'custom' : k === 'robots' ? 'index,follow' : '');
    });
    document.getElementById('seo-f-title').value = d.title || '';
    document.getElementById('seo-isActive').checked = d.isActive !== false;
    seoCount();
    document.getElementById('seo-dialog').showModal();
  }
</script>
@endsection
