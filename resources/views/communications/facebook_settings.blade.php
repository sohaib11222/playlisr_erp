@extends('layouts.app')

@section('title', 'Facebook API Settings')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .quo-wrap { max-width: 760px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .quo-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .quo-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .quo-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; }
body.pos-v2 .quo-card h3 { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
body.pos-v2 .quo-card p { font-size: 13.5px; color: #6b6253; margin: 0 0 12px; }
body.pos-v2 label { font-weight: 600; font-size: 13px; }
body.pos-v2 .form-control { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 9px 11px; font-family: "IBM Plex Mono", monospace; font-size: 13px; margin-bottom: 10px; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: "Inter Tight", sans-serif; margin-top: 4px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .status-line { font-size: 12.5px; color: #8a8070; margin-bottom: 10px; }
</style>

<div class="quo-wrap">
    <h1>Facebook API Settings</h1>
    <p class="sub">Powers the live Facebook follower count on the Archer performance report. Uses a Page access token from the Nivessa Communications Hub app's "Facebook Login for Business" flow, scoped to the Nivessa Page.</p>

    @if(is_array(session('status')))
        <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
    @endif

    <div class="quo-card">
        <h3>Credentials</h3>
        <p>Page ID and a Page access token for the Nivessa Facebook Page.</p>
        <form method="POST" action="{{ action('FacebookAuthController@saveSettings') }}">
            @csrf
            <label>Page ID</label>
            <input type="text" name="page_id" class="form-control" style="width:100%;" value="{{ $page_id }}" placeholder="paste here">

            <label>Page access token</label>
            <input type="text" name="page_access_token" class="form-control" style="width:100%;" placeholder="paste here">
            <div class="status-line">{{ $page_access_token_masked ? 'Currently set, ending in ' . $page_access_token_masked : 'Not set yet.' }}</div>

            <button type="submit" class="btn-accent">Save</button>
        </form>
    </div>

    <div class="quo-card">
        <h3>Note on token expiry</h3>
        <p>A Page token minted from a long-lived user token doesn't expire in normal use. If the numbers ever silently go stale, redo the OAuth consent flow and repaste the new token here.</p>
    </div>
</div>

@stop
