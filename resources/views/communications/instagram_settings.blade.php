@extends('layouts.app')

@section('title', 'Instagram DM Settings')

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
body.pos-v2 .quo-card ol { font-size: 13.5px; color: #6b6253; padding-left: 20px; margin: 0 0 12px; }
body.pos-v2 .quo-card ol li { margin-bottom: 8px; }
body.pos-v2 .url-box { background: var(--pos-accent-soft); border: 1px solid var(--pos-line); border-radius: 8px; padding: 10px 12px; font-family: "IBM Plex Mono", monospace; font-size: 13px; word-break: break-all; margin-bottom: 12px; }
body.pos-v2 label { font-weight: 600; font-size: 13px; }
body.pos-v2 .form-control { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 9px 11px; font-family: "IBM Plex Mono", monospace; font-size: 13px; margin-bottom: 10px; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: "Inter Tight", sans-serif; margin-top: 4px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .status-line { font-size: 12.5px; color: #8a8070; margin-bottom: 10px; }
</style>

<div class="quo-wrap">
    <h1>Instagram DM Settings</h1>
    <p class="sub">Pulls Nivessa's Instagram DMs into the Communications Hub. Uses your own Meta app in Development Mode against your own connected account &mdash; no Meta App Review needed, since that's only required to message accounts you don't already control.</p>

    @if(is_array(session('status')))
        <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
    @endif

    <div class="quo-card">
        <h3>1. Create the Meta app</h3>
        <ol>
            <li>Go to <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com/apps</a> &rarr; Create App &rarr; choose "Other" &rarr; "Business".</li>
            <li>Once created, add the <strong>Instagram</strong> product from the app dashboard's left sidebar (Add Product).</li>
            <li>Under Instagram &rarr; API setup, connect the Nivessa Facebook Page that's linked to the Nivessa Instagram professional account. (The Instagram account needs to be a Business or Creator account, and linked to a Facebook Page, for this to work at all &mdash; if it isn't yet, that's done from the Instagram app itself under Settings &rarr; Account type.)</li>
            <li>Generate a <strong>Page Access Token</strong> from that same setup screen (it lists the permissions it covers &mdash; make sure instagram_manage_messages is included).</li>
            <li><strong>The step that skips App Review:</strong> in the app dashboard, go to App Roles &rarr; Roles, and add the Nivessa Instagram account as an <strong>Instagram Tester</strong>. Then, from the Instagram app itself (on the Nivessa account), go to Settings &rarr; Apps and Websites &rarr; Tester Invites, and accept it. This is what lets the app read/send that account's DMs while still in Development Mode &mdash; App Review is only required to message accounts that AREN'T already an Admin/Developer/Tester on the app, which doesn't apply here since it's just Nivessa's own account.</li>
        </ol>
    </div>

    <div class="quo-card">
        <h3>2. Register the webhook</h3>
        <p>In the app dashboard, under Instagram &rarr; Webhooks (or Messenger &rarr; Instagram Settings, Meta moves this around), add a callback subscribed to the <strong>messages</strong> field, pointing at:</p>
        <div class="url-box">{{ $webhook_url }}</div>
        <p>It'll ask for a Verify Token &mdash; make one up (any string), enter it both there and below, then click Verify and Save &mdash; Meta will hit this URL once to confirm it's really us.</p>
    </div>

    <div class="quo-card">
        <h3>3. Paste the credentials here</h3>
        <form method="POST" action="{{ action('InstagramWebhookController@saveSettings') }}">
            @csrf
            <label>App Secret (App Dashboard &rarr; Settings &rarr; Basic)</label>
            <input type="text" name="app_secret" class="form-control" style="width:100%;" placeholder="paste here">
            <div class="status-line">{{ $app_secret_masked ? 'Currently set, ending in ' . $app_secret_masked : 'Not set yet.' }}</div>

            <label>Verify Token (the string you made up in step 2)</label>
            <input type="text" name="verify_token" class="form-control" style="width:100%;" value="{{ $verify_token }}" placeholder="e.g. nivessa-ig-2026">

            <label>Page Access Token</label>
            <input type="text" name="page_access_token" class="form-control" style="width:100%;" placeholder="paste here">
            <div class="status-line">{{ $page_token_masked ? 'Currently set, ending in ' . $page_token_masked : 'Not set yet.' }}</div>

            <button type="submit" class="btn-accent">Save</button>
        </form>
    </div>

    <div class="quo-card">
        <h3>Note on token expiry</h3>
        <p>Page Access Tokens from Meta can expire (short-lived ones in ~1 hour, long-lived ones in ~60 days depending on how it was generated). If DMs stop coming in, that's the first thing to check &mdash; generate a fresh one from the app dashboard and re-paste it above.</p>
    </div>
</div>

@stop
