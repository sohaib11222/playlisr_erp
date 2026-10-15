@extends('layouts.app')

@section('title', $isNew ? 'Add Website Category' : 'Edit Website Category')

@section('content')
@include('website_admin.catalog._styles')
@php
  $img = [\App\Http\Controllers\WebsiteAdmin\CatalogBaseController::class, 'img'];
  $val = function ($k, $d = '') use ($c) { return old($k, $c[$k] ?? $d); };
  $list = function ($k) use ($c) { return old($k, implode("\n", array_filter((array) ($c[$k] ?? []), 'is_scalar'))); };
  $active = old('_submitted') ? (bool) old('isActive') : ($isNew ? true : !empty($c['isActive']));
  $action = $isNew ? route('wa.catalog.categories.store') : route('wa.catalog.categories.update', $c['_id']);
@endphp

<div class="ev-wrap">
  <div class="ev-head">
    <div>
      <h1>{{ $isNew ? 'Add category' : 'Edit category' }}</h1>
      <p class="sub">{{ $isNew ? 'A new buy, sell and trade category page.' : ($c['title'] ?? '') }}</p>
    </div>
    <a href="{{ route('wa.catalog.categories') }}" class="wc-btn">Back to categories</a>
  </div>
  @include('website_admin.catalog._nav')

  @if(session('error'))<div class="alert-err">{{ session('error') }}</div>@endif

  <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="wc-form">
    {{ csrf_field() }}
    <input type="hidden" name="_submitted" value="1">

    <div class="ev-card">
      <h2>Basic information</h2>
      <div class="ev-row">
        <div class="ev-field"><label>Title *</label><input type="text" name="title" value="{{ $val('title') }}" required placeholder="Category title"></div>
        <div class="ev-field"><label>Type</label>
          <select name="categoryType">
            @foreach($types as $k => $l)<option value="{{ $k }}" @if($val('categoryType', 'vinyl') === $k) selected @endif>{{ $l }}</option>@endforeach
          </select>
        </div>
      </div>
      <div class="ev-row">
        <div class="ev-field"><label>Subtitle</label><input type="text" name="subtitle" value="{{ $val('subtitle') }}"></div>
        <div class="ev-field"><label>Slug</label><input type="text" name="slug" value="{{ $val('slug') }}" placeholder="Left blank, it is made from the title"></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Description</label><textarea name="description" rows="4">{{ $val('description') }}</textarea></div>
      </div>
      <div class="ev-row">
        <div class="ev-field" style="flex:0 0 120px;">
          @if($val('featuredImage'))<img src="{{ $img($val('featuredImage')) }}" alt="" style="width:110px; height:110px; object-fit:cover; border-radius:8px; border:1px solid #e5e7eb;">@endif
        </div>
        <div class="ev-field">
          <label>Featured image URL</label>
          <input type="text" name="featuredImage" value="{{ $val('featuredImage') }}" placeholder="https://...">
          <label style="margin-top:8px;">Or upload an image</label>
          <input type="file" name="featuredImageFile" accept="image/*">
        </div>
      </div>
    </div>

    <div class="ev-card">
      <h2>Content sections</h2>
      <p class="wc-muted" style="margin-top:-6px;">One item per line. Duplicates are dropped when you save.</p>
      <div class="ev-row">
        <div class="ev-field wide"><label>Genres heading</label><input type="text" name="genreHeading" value="{{ $val('genreHeading') }}" placeholder="Some of the genres we're interested in include:"></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Genres</label><textarea name="genres" rows="6">{{ $list('genres') }}</textarea></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Artists heading</label><input type="text" name="artistHeading" value="{{ $val('artistHeading', 'Notable Artists') }}"></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Notable artists</label><textarea name="notableArtists" rows="6">{{ $list('notableArtists') }}</textarea></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Labels heading</label><input type="text" name="labelHeading" value="{{ $val('labelHeading', 'Record Labels') }}"></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Labels</label><textarea name="labels" rows="6">{{ $list('labels') }}</textarea></div>
      </div>
    </div>

    <div class="ev-card">
      <h2>SEO and settings</h2>
      <div class="ev-row">
        <div class="ev-field"><label>Meta title</label><input type="text" name="metaTitle" value="{{ $val('metaTitle') }}"></div>
        <div class="ev-field"><label>Sort order</label><input type="number" name="sortOrder" value="{{ $val('sortOrder', 0) }}"></div>
      </div>
      <div class="ev-row">
        <div class="ev-field wide"><label>Meta description</label><textarea name="metaDescription" rows="3">{{ $val('metaDescription') }}</textarea></div>
      </div>
      <div class="ev-checks"><label><input type="checkbox" name="isActive" value="1" @if($active) checked @endif> Active</label></div>
    </div>

    <button type="submit" class="btn-accent">{{ $isNew ? 'Create category' : 'Save category' }}</button>
  </form>
</div>
@endsection
