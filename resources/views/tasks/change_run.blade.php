@extends('layouts.app')
@section('title', 'Change run')

@section('content')
{{-- POS menu > Change run (Sarah 2026-10-11). Buttons log the $100 out / change in on the register so the recon matches. --}}
<section class="content">
<div style="max-width:560px; margin:0 auto; background:#fff; border-radius:10px; padding:20px 22px;">
    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <h3 style="margin-top:0;">Change run</h3>

    @if(!$register || !$storeKey)
        <p>Open your register first, then come back here.</p>
    @elseif($task)
        <p style="white-space:pre-line;">{{ \Illuminate\Support\Str::before($task->description, "\n\nTake") }}</p>
        <p><strong>Take $100 from the drawer to the bank and ask for:</strong></p>
        <ul>
            @foreach($bankList as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
            <form method="POST" action="{{ action('TaskController@changeRun', [$task->id, 'out']) }}">
                @csrf
                <button type="submit" class="btn btn-primary btn-lg" {{ $outDone ? 'disabled' : '' }}>1. Took $100 to bank</button>
            </form>
            <form method="POST" action="{{ action('TaskController@changeRun', [$task->id, 'in']) }}">
                @csrf
                <button type="submit" class="btn btn-success btn-lg" {{ $outDone ? '' : 'disabled' }}>2. Put change in drawer</button>
            </form>
        </div>
        <p class="text-muted" style="margin-top:10px;">Tap 1 when you leave with the $100. Tap 2 after the change is in the drawer.</p>
    @elseif($ranThisWeek)
        <p>This store already had a change run this week.</p>
    @else
        <p>No change run needed right now. If the drawer is running out of small bills or coins, request one.</p>
        <form method="POST" action="{{ action('TaskController@changeRunRequest') }}">
            @csrf
            <button type="submit" class="btn btn-primary">Request change run</button>
        </form>
    @endif

    <p style="margin-top:18px;"><a href="{{ action('SellPosController@create') }}">Back to POS</a></p>
</div>
</section>
@endsection
