@extends('layouts.app')
@section('title', 'Staff phones')

@section('content')
@include('tasks.partials.asana_styles')

<section class="content">
<div class="as-wrap" style="max-width:760px;">

    <div class="as-head">
        <div>
            <h1 class="as-title">Staff phones</h1>
            <div class="as-sub">Cell numbers for the start-of-shift text: "you have tasks due today". Only sent when their Sling shift starts.</div>
        </div>
    </div>

    @include('tasks.partials.asana_nav')

    @if(session('status'))
        <div class="alert alert-{{ session('status.success') ? 'success' : 'danger' }}">{{ session('status.msg') }}</div>
    @endif

    <form method="POST" action="{{ route('staff-phones.save') }}">
        @csrf
        <div class="as-table">
            @foreach($staff as $u)
                <div class="as-row" style="grid-template-columns:minmax(0,1fr) 220px;">
                    <div>
                        {!! \App\Http\Controllers\TeamProgressController::avatar($u->id, trim($u->first_name . ' ' . $u->last_name), 'sm') !!}
                        <span class="as-name">{{ trim($u->first_name . ' ' . $u->last_name) }}</span>
                        @if(in_array((int) $u->id, $onSling, true))
                            <span class="as-pill as-tag-shift">On Sling</span>
                        @endif
                    </div>
                    <div>
                        <input type="tel" name="phones[{{ $u->id }}]" value="{{ old('phones.' . $u->id, $u->contact_number) }}" placeholder="(213) 555-0123" class="as-filter" style="width:100% !important;">
                    </div>
                </div>
            @endforeach
        </div>
        <div style="margin-top:12px;">
            <button type="submit" class="as-btn" style="border:0;">Save numbers</button>
        </div>
    </form>

</div>
</section>
@stop
