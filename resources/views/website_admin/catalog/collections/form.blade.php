@extends('layouts.app')

@section('title', 'Add Website Collection')

@section('content')
@include('website_admin.catalog._styles')

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>Add collection</h1>
      <p class="sub">Pick a preset icon or upload your own image.</p>
    </div>
    <a href="{{ route('wa.catalog.collections') }}" class="wc-btn">Back to collections</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="POST" action="{{ route('wa.catalog.collections.store') }}" enctype="multipart/form-data" class="ev-card wc-form">
    {{ csrf_field() }}
    <div class="ev-row">
      <div class="ev-field"><label>Collection name *</label><input type="text" name="name" value="{{ old('name') }}" required></div>
      <div class="ev-field"><label>Status *</label>
        <select name="status" required>
          <option value="">Select</option>
          @foreach(['Active', 'Inactive'] as $s)<option value="{{ $s }}" @if(old('status') === $s) selected @endif>{{ $s }}</option>@endforeach
        </select>
      </div>
    </div>
    <div class="ev-row">
      <div class="ev-field"><label>Preset icon (optional)</label>
        <select name="presetIcon" id="wcIcon" onchange="wcIconPreview()">
          <option value="">Choose a preset icon</option>
          @foreach($icons as $path => $label)<option value="{{ $path }}" @if(old('presetIcon') === $path) selected @endif>{{ $label }}</option>@endforeach
        </select>
      </div>
      <div class="ev-field"><label>Or upload a custom image (optional)</label><input type="file" name="image" accept="image/*"></div>
    </div>
    <div class="ev-row" id="wcIconWrap" style="display:none;">
      <img id="wcIconImg" src="" alt="" style="width:64px; height:64px; object-fit:contain; border:1px solid #e5e7eb; border-radius:8px; padding:6px;">
    </div>
    <button type="submit" class="btn-accent">Add collection</button>
  </form>
</div>
<script>
  function wcIconPreview() {
    var v = document.getElementById('wcIcon').value;
    document.getElementById('wcIconWrap').style.display = v ? '' : 'none';
    document.getElementById('wcIconImg').src = v ? 'https://nivessa.com' + v : '';
  }
  wcIconPreview();
</script>
@endsection
