@extends('layouts.app')
@section('title', 'App Notifications')

@section('content')
@php
    $date = function ($v) { $t = strtotime((string) $v); return $t ? date('m/d/y g:i A', $t) : ''; };
    $flash = session('status');
@endphp
<section class="content-header"><h1>App Notifications</h1></section>
<section class="content">
  <p style="font-size:15px;max-width:900px">Send a notification to every phone that saved the Nivessa app and turned on notifications. Order updates (shipped, ready for pickup, delivered) are sent automatically, so you only need this page for news like events, restocks and sales.</p>

  @if (is_array($flash))
    <div class="alert {{ !empty($flash['success']) ? 'alert-success' : 'alert-danger' }}">{{ $flash['msg'] ?? '' }}</div>
  @endif
  @if ($errors->any())
    <div class="alert alert-danger">{{ $errors->first() }}</div>
  @endif

  @if ($error)
    <div class="alert alert-danger">{{ $error }}</div>
  @else
  <div class="row">
    <div class="col-sm-4"><div class="small-box bg-aqua"><div class="inner"><h3>{{ number_format($devices) }}</h3><p>Phones with notifications on</p></div></div></div>
    <div class="col-sm-4"><div class="small-box bg-green"><div class="inner"><h3>{{ number_format($loggedIn) }}</h3><p>Linked to a customer account</p></div></div></div>
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Send a notification</h3></div>
    <div class="box-body">
      <form method="POST" action="/push-notifications/send" style="max-width:640px">
        @csrf
        <div class="form-group">
          <label for="push_title">Title</label>
          <input id="push_title" name="title" class="form-control" maxlength="80" required value="{{ old('title') }}" placeholder="Listening party this Friday">
        </div>
        <div class="form-group">
          <label for="push_body">Message</label>
          <textarea id="push_body" name="body" class="form-control" rows="3" maxlength="200" required placeholder="Free RSVP, limited spots. Tap to save yours.">{{ old('body') }}</textarea>
        </div>
        <div class="form-group">
          <label for="push_url">Page it opens</label>
          <input id="push_url" name="url" class="form-control" maxlength="300" value="{{ old('url', '/') }}" placeholder="/events">
          <p class="help-block" style="color:#333">A nivessa.com page, for example /events or https://nivessa.com/products?productType=Vinyl Records</p>
        </div>
        <div class="form-group">
          <label for="push_confirm">Type SEND to confirm</label>
          <input id="push_confirm" name="confirm" class="form-control" style="max-width:160px" autocomplete="off" required>
        </div>
        <button type="submit" class="btn btn-primary">Send to {{ number_format($devices) }} phones</button>
      </form>
    </div>
  </div>

  <div class="box box-solid">
    <div class="box-header"><h3 class="box-title">Sent</h3></div>
    <div class="box-body">
      <table class="table table-striped">
        <thead><tr><th>Date</th><th>Title</th><th>Message</th><th>Page</th><th>Delivered</th><th>Sent by</th></tr></thead>
        <tbody>
        @forelse ($history as $h)
          <tr>
            <td>{{ $date($h['createdAt'] ?? '') }}</td>
            <td>{{ $h['title'] ?? '' }}</td>
            <td>{{ $h['body'] ?? '' }}</td>
            <td><a href="https://nivessa.com{{ $h['url'] ?? '/' }}" target="_blank" rel="noopener">{{ $h['url'] ?? '/' }}</a></td>
            <td>{{ (int) ($h['delivered'] ?? 0) }} of {{ (int) ($h['targeted'] ?? 0) }}</td>
            <td>{{ $h['sentBy'] ?? '' }}</td>
          </tr>
        @empty
          <tr><td colspan="6">Nothing sent yet.</td></tr>
        @endforelse
        </tbody>
      </table>
    </div>
  </div>
  @endif
</section>
@endsection
