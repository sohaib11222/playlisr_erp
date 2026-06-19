@extends('layouts.app')
@section('title', 'Bot & Handbook Knowledge')

@section('content')
<section class="content-header">
    <h1>Bot &amp; Handbook Knowledge</h1>
    <p class="text-muted">Add anything staff should know &mdash; supplies, the printer, events, store rules, contacts. Each entry shows up <strong>both</strong> in the "Ask the ERP" bot and on the <a href="{{ url('/help') }}">Help &amp; Handbook</a> pages, instantly. No code, no waiting. Edit or remove anytime.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-5">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">{{ $edit ? 'Edit entry' : 'Add an entry' }}</h3>
            </div>
            <div class="box-body">
                <form method="POST" action="{{ url('/admin/help-knowledge') }}">
                    @csrf
                    <input type="hidden" name="id" value="{{ $edit['id'] ?? '' }}">
                    <div class="form-group">
                        <label for="title">Title</label>
                        <input type="text" name="title" id="title" class="form-control"
                               placeholder="e.g. How to change the label printer paper"
                               value="{{ $edit['title'] ?? '' }}" required>
                    </div>
                    <div class="form-group">
                        <label for="section">Category <span class="text-muted">(optional)</span></label>
                        <input type="text" name="section" id="section" class="form-control"
                               placeholder="Store Notes" value="{{ $edit['section'] ?? '' }}">
                        <p class="help-block" style="margin-bottom:0;">Groups it on the Help page. Leave blank for "Store Notes".</p>
                    </div>
                    <div class="form-group">
                        <label for="body">Details</label>
                        <textarea name="body" id="body" class="form-control" rows="10"
                                  placeholder="Write it the way you'd explain it to a new employee. Plain text is fine." required>{{ $edit['body'] ?? '' }}</textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">{{ $edit ? 'Save changes' : 'Add entry' }}</button>
                    @if($edit)
                        <a href="{{ url('/admin/help-knowledge') }}" class="btn btn-default">Cancel</a>
                    @endif
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-7">
        <div class="box box-solid">
            <div class="box-header with-border">
                <h3 class="box-title">Your entries ({{ count($entries) }})</h3>
            </div>
            <div class="box-body">
                @if(empty($entries))
                    <p class="text-muted">No entries yet. Add one on the left and it's live right away.</p>
                @else
                    @foreach($entries as $e)
                    <div class="panel panel-default">
                        <div class="panel-body">
                            <strong>{{ $e['title'] ?? '' }}</strong>
                            <span class="label label-default">{{ $e['section'] ?? 'Store Notes' }}</span>
                            <div class="pull-right">
                                <a href="{{ url('/admin/help-knowledge?edit=' . urlencode($e['id'] ?? '')) }}" class="btn btn-xs btn-default">Edit</a>
                                <form method="POST" action="{{ url('/admin/help-knowledge/delete') }}" style="display:inline;"
                                      onsubmit="return confirm('Remove this entry?');">
                                    @csrf
                                    <input type="hidden" name="id" value="{{ $e['id'] ?? '' }}">
                                    <button type="submit" class="btn btn-xs btn-danger">Delete</button>
                                </form>
                            </div>
                            <p class="text-muted" style="margin-top:8px;white-space:pre-wrap;">{{ \Illuminate\Support\Str::limit($e['body'] ?? '', 240) }}</p>
                        </div>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>
    </div>
</div>
</section>
@endsection
