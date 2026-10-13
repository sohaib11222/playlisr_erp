@extends('layouts.app')
@section('title', 'Email Lists')

@section('content')
<section class="content-header">
    <h1>Email Lists <small>every Brevo contact, kept in the ERP</small></h1>
</section>
<section class="content">
    @if(!$hasTable)
        <div class="alert alert-warning">The email list table isn't set up yet. It will be after the next database update.</div>
    @endif
    <div class="box box-solid">
        <div class="box-body">
            <p style="font-size:15px;margin:0 0 8px;">
                <b>{{ number_format($total) }}</b> contacts saved
                @if($unsub) <span class="text-muted">({{ number_format($unsub) }} unsubscribed)</span> @endif
                @if($lastPull) <span class="text-muted">, last pulled {{ \Carbon\Carbon::parse($lastPull)->format('M j, g:ia') }}</span> @endif
            </p>
            <p class="text-muted" style="margin:0 0 12px;">This only reads from Brevo. Once everyone is saved here you can delete old contacts in Brevo and bring any list back with its download below.</p>
            @if($hasKey)
                <form method="POST" action="{{ action('EmailListsController@pull') }}" style="display:inline-block;">
                    @csrf
                    <button type="submit" class="btn btn-primary">Pull all contacts from Brevo</button>
                </form>
                <a class="btn btn-default" href="{{ action('EmailListsController@csv') }}">Download everyone (CSV)</a>
            @endif
            <form method="POST" action="{{ action('EmailListsController@saveKey') }}" style="margin-top:14px;max-width:520px;">
                @csrf
                <label>Brevo API key {{ $hasKey ? '(saved, paste a new one to replace it)' : '' }}</label>
                <div class="input-group">
                    <input type="password" name="api_key" class="form-control" autocomplete="off" placeholder="Brevo > Settings > SMTP & API > API keys">
                    <span class="input-group-btn"><button type="submit" class="btn btn-default">Save key</button></span>
                </div>
            </form>
        </div>
    </div>

    @if(!empty($lists))
    <div class="box box-solid">
        <div class="box-header with-border"><b>Lists</b></div>
        <div class="box-body">
            <table class="table table-bordered" style="max-width:700px;">
                <thead><tr><th>List</th><th style="width:120px;">Contacts</th><th style="width:140px;"></th></tr></thead>
                <tbody>
                @foreach($lists as $name => $n)
                    <tr>
                        <td>{{ $name }}</td>
                        <td>{{ number_format($n) }}</td>
                        <td><a href="{{ action('EmailListsController@csv', ['list' => $name]) }}">Download CSV</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</section>
@endsection
