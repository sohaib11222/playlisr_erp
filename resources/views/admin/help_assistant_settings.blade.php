@extends('layouts.app')
@section('title', 'Help Assistant')

@section('content')
<section class="content-header">
    <h1>Help Assistant <a href="{{ url('/admin/help-assistant/questions') }}" class="btn btn-default btn-sm pull-right">See what staff asked</a></h1>
    <p class="text-muted">Powers the "Ask the ERP" chat widget that helps staff figure out how to do things in here. Paste your Anthropic (Claude) API key below to switch it on. The key is stored on the server only and is never shown in full again.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-8">
        <div class="box box-solid">
            <div class="box-body">
                <p>
                    Status:
                    @if($has_key)
                        <span class="label label-success">Active</span>
                        <span class="text-muted">Current key {{ $masked }}</span>
                    @else
                        <span class="label label-warning">Not configured</span>
                        <span class="text-muted">The widget falls back to "ask a manager" until a key is saved.</span>
                    @endif
                </p>

                <form method="POST" action="{{ url('/admin/help-assistant/save') }}" style="margin-top:16px;">
                    @csrf
                    <div class="form-group">
                        <label for="api_key">Anthropic API key</label>
                        <input type="password" name="api_key" id="api_key" class="form-control"
                               placeholder="{{ $has_key ? 'Leave blank to keep the current key' : 'sk-ant-...' }}"
                               autocomplete="off">
                        <p class="help-block">Get this from the Anthropic console (console.anthropic.com → API keys). It starts with <code>sk-ant-</code>.</p>
                    </div>

                    <div class="form-group">
                        <label for="model">Model</label>
                        <input type="text" name="model" id="model" class="form-control" value="{{ $model }}">
                        <p class="help-block">Default <code>claude-haiku-4-5</code> (fast and cheap). Leave as-is unless told otherwise.</p>
                    </div>

                    <hr>
                    <div class="form-group">
                        <label for="store_knowledge">Store knowledge the bot answers from</label>
                        <p class="help-block" style="margin-top:0;">Fill in the blanks below (supplies, printer, events, listening parties, Spotify, refunds). Whatever you write here is what the bot tells staff. Anything left as <code>[FILL IN ...]</code> makes the bot say "ask a manager" instead of guessing. Edit and save this anytime.</p>
                        <textarea name="store_knowledge" id="store_knowledge" class="form-control" rows="22" style="font-family:monospace;font-size:12.5px;line-height:1.5;">{{ $store_knowledge }}</textarea>
                    </div>

                    @if($has_key)
                    <div class="checkbox">
                        <label><input type="checkbox" name="remove_key" value="1"> Remove the saved key (turns the assistant off)</label>
                    </div>
                    @endif

                    <button type="submit" class="btn btn-primary btn-lg">Save</button>
                </form>
            </div>
        </div>
    </div>
</div>
</section>
@endsection
