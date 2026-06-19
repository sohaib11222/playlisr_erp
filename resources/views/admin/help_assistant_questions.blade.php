@extends('layouts.app')
@section('title', 'Help Assistant - Questions')

@section('content')
<section class="content-header">
    <h1>What staff asked the assistant</h1>
    <p class="text-muted">Every question typed into the "Ask the ERP" chat widget, most recent first. Use this to spot what the bot should be taught next &mdash; add the answers in the Store knowledge box on the <a href="{{ url('/admin/help-assistant') }}">Help Assistant settings</a> page.</p>
</section>

<section class="content">
<div class="row">
    <div class="col-md-10">
        <div class="box box-solid">
            <div class="box-body">
                @if(empty($questions))
                    <p class="text-muted">No questions have been asked yet. Once staff use the chat widget, they'll show up here.</p>
                @else
                <p class="text-muted">{{ count($questions) }} question{{ count($questions) === 1 ? '' : 's' }} logged.</p>
                <table class="table table-striped table-condensed">
                    <thead>
                        <tr>
                            <th style="width:160px;">When</th>
                            <th style="width:160px;">Who</th>
                            <th>Question</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($questions as $row)
                        <tr>
                            <td class="text-muted">{{ $row['at'] ?? '' }}</td>
                            <td>{{ $row['user'] ?? 'Unknown' }}</td>
                            <td>{{ $row['q'] ?? '' }}</td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
                @endif
            </div>
        </div>
    </div>
</div>
</section>
@endsection
