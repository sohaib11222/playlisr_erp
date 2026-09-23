{{-- One task row. Expects: $t (WeeklyTask), $due (Carbon|null), $viaShift (bool), $storeLabels, optional $owners ([id => name]). --}}
<div class="as-row" data-task="{{ $t->id }}">
    <div>
        <button type="button" class="as-check" title="Mark complete" data-url="{{ action('TaskController@updateStatus', $t->id) }}" data-edit="{{ action('TaskController@edit', $t->id) }}">{!! \App\Http\Controllers\TeamProgressController::CHECK_SVG !!}</button>
        <a class="as-name" href="{{ action('TaskController@edit', $t->id) }}">{{ $t->title }}</a>
        @if($t->repeat_daily || $t->repeat_weekly || $t->repeat_of)
            <span class="as-meta" title="Repeats"><i class="fa fa-repeat"></i></span>
        @endif
        @if($t->requires_photo)
            <span class="as-meta" title="Needs a photo in #taskphotos"><i class="fa fa-camera"></i></span>
        @endif
        @if($t->status === 'in_progress')
            <span class="as-pill as-tag as-hide-sm">In progress</span>
        @endif
    </div>
    <div class="as-hide-sm">
        @if($due)
            <span class="{{ $due->lt(now()) ? 'as-due-late' : ($due->isToday() ? 'as-due-today' : '') }}">
                {{ $due->isToday() ? 'Today' : ($due->isTomorrow() ? 'Tomorrow' : ($due->isYesterday() ? 'Yesterday' : $due->format('M j'))) }}
                @if($t->due_time)
                    {{ $due->format('g:i A') }}
                @endif
            </span>
        @endif
    </div>
    <div>
        <span class="as-pill as-p-{{ $t->priority }}">{{ ucfirst($t->priority) }}</span>
    </div>
    <div class="as-hide-sm">
        @if($viaShift)
            <span class="as-pill as-tag-shift" title="Nobody assigned. You're on shift at this store today.">On shift</span>
        @else
            <span class="as-pill as-tag">{{ $storeLabels[$t->store] ?? 'Both' }}</span>
        @endif
    </div>
    <div class="as-hide-sm">
        <span class="as-avatars">
            @foreach($t->assignees->take(3) as $u)
                {!! \App\Http\Controllers\TeamProgressController::avatar($u->id, trim($u->first_name . ' ' . $u->last_name), 'sm') !!}
            @endforeach
        </span>
        @if($t->assignees->count() > 3)<span class="as-meta" style="margin-left:4px;">+{{ $t->assignees->count() - 3 }}</span>@endif
    </div>
</div>
