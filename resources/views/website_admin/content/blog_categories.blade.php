@extends('layouts.app')
@section('title', 'Blog Categories')

@section('content')
@include('website_admin.content._styles')
@php $presets = ['#3B2E2A', '#1E40AF', '#047857', '#B91C1C', '#7C3AED', '#C2410C', '#0E7490', '#4338CA', '#DB2777', '#65A30D', '#EA580C', '#0891B2']; @endphp
<style>
  .bc-sw { width:28px; height:28px; border-radius:999px; border:2px solid transparent; cursor:pointer; padding:0; }
  .bc-sw.on { border-color:#111827; box-shadow:0 0 0 2px #fff inset; }
</style>
<div class="ev-wrap" style="max-width:860px;">
  <a class="wc-back" href="{{ route('wa.content.blog') }}">Back to blog posts</a>
  <div class="ev-head">
    <div><h1>Blog Categories</h1><p class="sub">Group posts on nivessa.com/blog.</p></div>
    <button type="button" class="wc-btn wc-btn-primary" onclick="bcOpen(null)">Add category</button>
  </div>

  @if(session('status'))<div class="alert-ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($bridgeError)<div class="alert-err">Could not reach the website: {{ $bridgeError }}</div>@endif

  <form method="POST" id="bc-form" class="wc-card wc-card-pad" style="display:none;" action="{{ route('wa.content.blogCategoryStore') }}">
    {{ csrf_field() }}
    <h2 id="bc-form-title">New category</h2>
    <div class="wc-field" style="margin-bottom:10px;"><label>Name (required)</label><input type="text" name="name" id="bc-name" placeholder="e.g. Music News, Vinyl Tips" required></div>
    <div class="wc-field" style="margin-bottom:10px;"><label>Description</label><input type="text" name="description" id="bc-desc" placeholder="Optional short description"></div>
    <div class="wc-field">
      <label>Color</label>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
        @foreach($presets as $c)
          <button type="button" class="bc-sw" data-c="{{ $c }}" style="background:{{ $c }};" title="{{ $c }}"></button>
        @endforeach
        <input type="color" name="color" id="bc-color" value="#3B2E2A" title="Custom color" style="width:36px; height:30px; padding:0; border:1px solid #d1d5db; border-radius:6px;">
      </div>
    </div>
    <div class="wc-actions" style="margin-top:14px;">
      <button type="submit" class="wc-btn wc-btn-primary" id="bc-submit">Create</button>
      <button type="button" class="wc-btn" onclick="document.getElementById('bc-form').style.display='none'">Cancel</button>
    </div>
  </form>

  <div class="wc-card">
    @if(count($categories) === 0)
      <div class="wc-empty">No categories yet. Make categories to organize blog posts.</div>
    @else
      <table class="wc-tbl">
        <tbody>
        @foreach($categories as $cat)
          <tr>
            <td>
              <span class="wc-swatch" style="background:{{ $cat['color'] ?? '#3B2E2A' }};"></span><b>{{ $cat['name'] ?? '' }}</b>
              @if(!empty($cat['description']))<div class="wc-muted" style="margin-left:20px;">{{ $cat['description'] }}</div>@endif
            </td>
            <td>
              <div class="wc-actions" style="justify-content:flex-end;">
                <button type="button" class="wc-btn wc-btn-sm" onclick='bcOpen(@json($cat))'>Edit</button>
                <form method="POST" action="{{ route('wa.content.blogCategoryDelete', $cat['_id']) }}" onsubmit="return confirm({{ json_encode('Delete "' . ($cat['name'] ?? '') . '"? Any blogs using this category will become uncategorized.') }});">
                  {{ csrf_field() }}
                  <button type="submit" class="wc-btn wc-btn-sm wc-btn-danger">Delete</button>
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
<script>
  var BC_STORE = @json(route('wa.content.blogCategoryStore'));
  var BC_UPDATE = @json(url('/website-content/blog-categories')) + '/';
  function bcColor(c) {
    document.getElementById('bc-color').value = c;
    document.querySelectorAll('.bc-sw').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-c').toLowerCase() === c.toLowerCase()); });
  }
  document.querySelectorAll('.bc-sw').forEach(function (b) { b.addEventListener('click', function () { bcColor(b.getAttribute('data-c')); }); });
  document.getElementById('bc-color').addEventListener('input', function () { bcColor(this.value); });
  function bcOpen(cat) {
    var f = document.getElementById('bc-form');
    f.action = cat ? BC_UPDATE + encodeURIComponent(cat._id) : BC_STORE;
    document.getElementById('bc-form-title').textContent = cat ? 'Edit category' : 'New category';
    document.getElementById('bc-submit').textContent = cat ? 'Update' : 'Create';
    document.getElementById('bc-name').value = cat ? (cat.name || '') : '';
    document.getElementById('bc-desc').value = cat ? (cat.description || '') : '';
    bcColor(cat && cat.color ? cat.color : '#3B2E2A');
    f.style.display = '';
    document.getElementById('bc-name').focus();
  }
</script>
@endsection
