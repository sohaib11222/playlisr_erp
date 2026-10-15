@extends('layouts.app')
@section('title', $blog ? 'Edit blog post' : 'New blog post')

@section('content')
@include('website_admin.content._styles')
@php
  $H = \App\Http\Controllers\WebsiteAdmin\BlogController::class;
  $b = $blog ?: [];
  $catId = is_array($b['category'] ?? null) ? ($b['category']['_id'] ?? '') : ($b['category'] ?? '');
  $sched = '';
  if (!empty($b['scheduledPublishAt'])) { $sched = $H::fmtDate($b['scheduledPublishAt'], 'Y-m-d\TH:i'); }
  $val = function ($k, $d = '') use ($b) { return old($k, $b[$k] ?? $d); };
  $isPublished = old('_mode') ? old('isPublished') : !empty($b['isPublished']);
@endphp
<div class="ev-wrap" style="max-width:980px;">
  <a class="wc-back" href="{{ route('wa.content.blog') }}">Back to blog posts</a>
  <div class="ev-head"><div><h1>{{ $blog ? 'Edit blog post' : 'New blog post' }}</h1></div></div>

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif
  @if($loadWarning)<div class="alert-err">{{ $loadWarning }}</div>@endif

  <form method="POST" action="{{ $blog ? route('wa.content.blogUpdate', $b['_id']) : route('wa.content.blogStore') }}" id="bl-form" class="wc-card wc-card-pad">
    {{ csrf_field() }}
    <input type="hidden" name="_mode" value="{{ $blog ? 'edit' : 'create' }}">
    <div class="wc-grid">
      <div class="wc-field wc-span2">
        <label>Title (required)</label>
        <input type="text" name="title" id="bl-title" value="{{ $val('title') }}" placeholder="Blog title" required>
        <div class="wc-help" id="bl-slug"></div>
      </div>
      <div class="wc-field"><label>Author</label><input type="text" name="author" value="{{ $val('author', 'Sarah') }}" placeholder="Author name"></div>
      <div class="wc-field">
        <label>Category</label>
        <select name="category">
          <option value="">No category</option>
          @foreach($categories as $c)
            <option value="{{ $c['_id'] }}" @if(old('category', $catId) === $c['_id']) selected @endif>{{ $c['name'] ?? '' }}</option>
          @endforeach
        </select>
        <div class="wc-help">Manage categories in <a href="{{ route('wa.content.blogCategories') }}">Blog Categories</a>.</div>
      </div>
      <div class="wc-field wc-span2">
        <label>Tags</label>
        <input type="text" name="tags" value="{{ old('tags', implode(', ', $b['tags'] ?? [])) }}" placeholder="music, vinyl, tips">
        <div class="wc-help">Separate tags with commas.</div>
      </div>

      <div class="wc-field wc-span2">
        <label>Featured image (required)</label>
        <div style="display:flex; gap:14px; align-items:flex-start; flex-wrap:wrap;">
          <div style="flex:1; min-width:260px; display:flex; flex-direction:column; gap:8px;">
            <input type="file" id="bl-file" accept="image/*">
            <div class="wc-help" id="bl-upload-msg">PNG, JPG or GIF up to 5 MB.</div>
            <input type="text" name="featuredImage" id="bl-image" value="{{ $val('featuredImage') }}" placeholder="Or paste an image URL, https://example.com/image.jpg">
          </div>
          <img id="bl-image-prev" src="{{ $H::siteImage($val('featuredImage')) }}" alt="" style="width:190px; height:126px; object-fit:cover; border-radius:8px; border:1px solid #e5e7eb; background:#f3f4f6; {{ $val('featuredImage') ? '' : 'display:none;' }}">
        </div>
      </div>

      <div class="wc-field wc-span2">
        <label>Meta description (SEO)</label>
        <textarea name="metaDescription" id="bl-meta" rows="2" maxlength="160" placeholder="Short description for search engines, up to 160 characters">{{ $val('metaDescription') }}</textarea>
        <div class="wc-help"><span id="bl-meta-n">0</span>/160 characters</div>
      </div>

      <div class="wc-field wc-span2">
        <label>Content (required)</label>
        <textarea name="content" id="bl-content" rows="18">{{ old('content', $b['content'] ?? '') }}</textarea>
        <div class="wc-help">Pasting from Word or Notion keeps text and image formatting. Use Tools, Source code to edit the HTML directly.</div>
      </div>

      <label class="wc-check wc-span2" style="background:#f9fafb; padding:12px; border-radius:8px;">
        <input type="checkbox" name="isPublished" id="bl-published" value="1" @if($isPublished) checked @endif> Publish (make this post visible to the public)
      </label>
      <div class="wc-field wc-span2" id="bl-sched-wrap" style="background:#f9fafb; padding:12px; border-radius:8px;">
        <label>Schedule publish (optional)</label>
        <input type="datetime-local" name="scheduledPublishAt" value="{{ old('scheduledPublishAt', $sched) }}">
        <div class="wc-help">Leave blank to keep it as a draft. If set, the post goes live automatically within 5 minutes of this time (Pacific time).</div>
      </div>
    </div>
    <div class="wc-actions" style="margin-top:16px;">
      <button type="submit" class="wc-btn wc-btn-primary" id="bl-submit">{{ $blog ? 'Update blog post' : 'Create blog post' }}</button>
      <a class="wc-btn" href="{{ route('wa.content.blog') }}">Cancel</a>
    </div>
  </form>
</div>
@endsection

@section('javascript')
<script>
(function () {
  var title = document.getElementById('bl-title'), slug = document.getElementById('bl-slug');
  function slugify(t) { return t.toLowerCase().replace(/[^\w\s-]/g, '').trim().replace(/\s+/g, '-').replace(/-+/g, '-'); }
  function upSlug() { slug.textContent = title.value ? 'Slug: /' + slugify(title.value) : ''; }
  title.addEventListener('input', upSlug); upSlug();

  var meta = document.getElementById('bl-meta'), metaN = document.getElementById('bl-meta-n');
  function upMeta() { metaN.textContent = meta.value.length; }
  meta.addEventListener('input', upMeta); upMeta();

  var pub = document.getElementById('bl-published'), sched = document.getElementById('bl-sched-wrap');
  function upSched() { sched.style.display = pub.checked ? 'none' : ''; }
  pub.addEventListener('change', upSched); upSched();

  var img = document.getElementById('bl-image'), prev = document.getElementById('bl-image-prev');
  function showPrev(src) { if (src) { prev.src = src; prev.style.display = ''; } else { prev.style.display = 'none'; } }
  img.addEventListener('input', function () { showPrev(/^https?:\/\//i.test(img.value) ? img.value : ''); });

  var file = document.getElementById('bl-file'), msg = document.getElementById('bl-upload-msg'), submit = document.getElementById('bl-submit');
  file.addEventListener('change', function () {
    var f = file.files[0];
    if (!f) return;
    if (!/^image\//.test(f.type)) { msg.textContent = 'Please pick an image file.'; return; }
    if (f.size > 5 * 1024 * 1024) { msg.textContent = 'Images must be under 5 MB.'; return; }
    var fd = new FormData(); fd.append('image', f);
    msg.textContent = 'Uploading...'; submit.disabled = true;
    fetch(@json(route('wa.content.blogImage')), { method: 'POST', headers: { 'X-CSRF-TOKEN': @json(csrf_token()), 'Accept': 'application/json' }, body: fd })
      .then(function (r) { return r.json(); })
      .then(function (res) {
        submit.disabled = false;
        if (!res.success) { msg.textContent = res.message || 'Upload failed.'; return; }
        img.value = res.url; showPrev(res.preview || res.url); msg.textContent = 'Image uploaded.';
      })
      .catch(function () { submit.disabled = false; msg.textContent = 'Upload failed.'; });
  });

  if (window.tinymce) {
    tinymce.init({
      selector: 'textarea#bl-content',
      height: 520,
      menubar: 'edit view insert format tools table',
      plugins: 'advlist autolink link image lists charmap hr anchor searchreplace wordcount visualblocks code fullscreen media table paste',
      toolbar: 'undo redo | styleselect | bold italic underline | alignleft aligncenter alignright | bullist numlist outdent indent | link image media | forecolor backcolor | code',
      convert_urls: false,
      paste_data_images: true
    });
  }
  document.getElementById('bl-form').addEventListener('submit', function () {
    if (window.tinymce) tinymce.triggerSave();
  });
})();
</script>
@endsection
