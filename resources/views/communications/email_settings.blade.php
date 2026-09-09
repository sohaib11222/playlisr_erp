@extends('layouts.app')

@section('title', 'Email Sync Settings')

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
body.pos-v2 label { font-weight: 600; font-size: 13px; }
body.pos-v2 .form-control { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 9px 11px; font-family: "IBM Plex Mono", monospace; font-size: 13px; margin-bottom: 10px; }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 9px 16px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: "Inter Tight", sans-serif; margin-top: 4px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .status-line { font-size: 12.5px; color: #8a8070; margin-bottom: 10px; }
body.pos-v2 .warn-line { font-size: 13px; color: #a8422f; margin-bottom: 10px; }
</style>

<div class="quo-wrap">
    <h1>Email Sync Settings</h1>
    <p class="sub">Pulls new mail sent to hello@ / orders@nivessa.com into the Communications Hub automatically, and picks up replies sent from Gmail as "Replied".</p>

    @if(is_array(session('status')))
        <div class="alert {{ session('status')['success'] ? 'alert-success' : 'alert-danger' }}">{{ session('status')['msg'] }}</div>
    @endif

    @if(!$imap_available)
        <div class="quo-card">
            <p class="warn-line"><strong>The server is missing the PHP IMAP extension</strong> — this needs to be installed before email sync can run. Everything below can still be saved, it just won't pull mail until that's fixed.</p>
        </div>
    @endif

    <div class="quo-card">
        <h3>1. Turn on IMAP for each mailbox</h3>
        <ol>
            <li>Log into Gmail as hello@nivessa.com (or orders@nivessa.com).</li>
            <li>Settings (gear icon) &rarr; See all settings &rarr; Forwarding and POP/IMAP tab.</li>
            <li>Under "IMAP access", select <strong>Enable IMAP</strong> &rarr; Save Changes.</li>
        </ol>
    </div>

    <div class="quo-card">
        <h3>2. Generate an App Password</h3>
        <p>This is a 16-character code Google generates for one app to use &mdash; not the real account password, and it can be revoked separately.</p>
        <ol>
            <li>2-Step Verification must be on for that account first: <a href="https://myaccount.google.com/security" target="_blank" rel="noopener">myaccount.google.com/security</a> &rarr; 2-Step Verification.</li>
            <li>Then go to <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">myaccount.google.com/apppasswords</a>, name it "Nivessa ERP", and copy the 16-character password it gives you.</li>
        </ol>
    </div>

    <div class="quo-card">
        <h3>3. Paste it here</h3>
        <form method="POST" action="{{ action('CommunicationController@saveEmailAccount') }}">
            @csrf
            <label>Mailbox</label>
            <select name="key" class="form-control" style="width:100%;">
                <option value="hello">hello@nivessa.com</option>
                <option value="orders">orders@nivessa.com</option>
            </select>
            <label>Email address</label>
            <input type="email" name="username" class="form-control" style="width:100%;" placeholder="hello@nivessa.com">
            <label>App Password</label>
            <input type="text" name="app_password" class="form-control" style="width:100%;" placeholder="16-character app password">
            <button type="submit" class="btn-accent">Save</button>
        </form>

        @if(!empty($mailboxes))
            <div class="status-line" style="margin-top:14px;">Currently configured: {{ implode(', ', array_column($mailboxes, 'username')) }}</div>
        @else
            <div class="status-line" style="margin-top:14px;">No mailboxes configured yet.</div>
        @endif
    </div>
</div>

@stop
