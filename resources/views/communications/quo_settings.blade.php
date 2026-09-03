@extends('layouts.app')

@section('title', 'Quo Webhook Settings')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .quo-wrap { max-width: 720px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .quo-wrap h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .quo-wrap .sub { color: #6b6253; margin: 0 0 20px; font-size: 14px; }
body.pos-v2 .quo-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 20px 22px; margin-bottom: 16px; }
body.pos-v2 .quo-card h3 { font-size: 15px; font-weight: 700; margin: 0 0 8px; }
body.pos-v2 .quo-card p { font-size: 13.5px; color: #6b6253; margin: 0 0 12px; }
body.pos-v2 .quo-card ol { font-size: 13.5px; color: #6b6253; padding-left: 20px; margin: 0 0 12px; }
body.pos-v2 .quo-card ol li { margin-bottom: 6px; }
body.pos-v2 .url-box { background: var(--pos-accent-soft); border: 1px solid var(--pos-line); border-radius: 8px; padding: 10px 12px; font-family: "IBM Plex Mono", monospace; font-size: 13px; word-break: break-all; margin-bottom: 12px; }
body.pos-v2 label { font-weight: 600; font-size: 13px; }
body.pos-v2 .form-control { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 9px 11px; font-family: "IBM Plex Mono", monospace; font-size: 13px; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: "Inter Tight", sans-serif; margin-top: 12px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .status-line { font-size: 12.5px; color: #8a8070; margin-top: 8px; }
</style>

<div class="quo-wrap">
    <h1>Quo Webhook Settings</h1>
    <p class="sub">Connects your two Quo phone lines to the Communications Hub — missed calls and inbound texts log in automatically.</p>

    @if(is_array(session('status')))
        <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
    @endif

    <div class="quo-card">
        <h3>1. Create the webhook in Quo</h3>
        <p>In Quo, go to Settings &rarr; Webhooks &rarr; Create webhook. Point it at this URL and select the <strong>message.received</strong> and <strong>call.completed</strong> events (Quo has no separate "missed call" event &mdash; call.completed with an unanswered status is what a missed call looks like):</p>
        <div class="url-box">{{ $webhook_url }}</div>
        <p>Open the webhook you just created and reveal the <strong>Signing secret</strong> field &mdash; copy it.</p>
    </div>

    <div class="quo-card">
        <h3>2. Paste the signing key here</h3>
        @if($env_locked)
            <p><strong>This is set via the server environment</strong> and can't be changed from this screen.</p>
        @else
            <form method="POST" action="{{ action('QuoWebhookController@saveSettings') }}">
                @csrf
                <label>Webhook signing key</label>
                <input type="text" name="webhook_key" class="form-control" style="width:100%;" placeholder="whsec_..." value="">
                <div class="status-line">{{ $masked ? 'Currently set, ending in ' . $masked : 'Not set yet — missed calls and texts will not log automatically.' }}</div>
                <button type="submit" class="btn-accent">Save</button>
            </form>
        @endif
    </div>

    <div class="quo-card">
        <h3>3. Pull in recent history (optional)</h3>
        <p>Paste a Quo API key here to let the Hub's "Import Recent from Quo" button pull recent messages/calls that happened before the webhook was set up. In Quo, go to Settings &rarr; API &rarr; Generate API key (or copy an existing one).</p>
        @if($api_env_locked)
            <p><strong>This is set via the server environment</strong> and can't be changed from this screen.</p>
        @else
            <form method="POST" action="{{ action('QuoWebhookController@saveApiKey') }}">
                @csrf
                <label>Quo API key</label>
                <input type="text" name="api_key" class="form-control" style="width:100%;" placeholder="paste here" value="">
                <div class="status-line">{{ $api_masked ? 'Currently set, ending in ' . $api_masked : 'Not set yet — the Import button will show an error until this is added.' }}</div>
                <button type="submit" class="btn-accent">Save</button>
            </form>
        @endif
    </div>
</div>

@stop
