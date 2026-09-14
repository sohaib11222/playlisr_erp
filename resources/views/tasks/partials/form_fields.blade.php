@php($currentTaskType = old('task_type', $task->task_type ?? ($type ?? 'weekly')))

<div class="form-group">
    <label>Title <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="title" value="{{ old('title', $task->title ?? '') }}" required maxlength="200">
</div>

<div class="form-group">
    <label>Description</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $task->description ?? '') }}</textarea>
</div>

<div class="row">
    <div class="col-md-2">
        <div class="form-group">
            <label>Type</label>
            <select id="task_type" name="task_type" class="form-control">
                <option value="daily" @if($currentTaskType==='daily') selected @endif>Today</option>
                <option value="weekly" @if($currentTaskType==='weekly') selected @endif>This Week</option>
            </select>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label><span id="task_start_date_label_text">{{ $currentTaskType === 'daily' ? 'Date' : 'Start date' }}</span> <span class="text-danger">*</span></label>
            <input type="date" id="task_start_date" class="form-control" name="start_date" value="{{ old('start_date', isset($task) ? $task->start_date->toDateString() : now()->toDateString()) }}" required>
        </div>
    </div>
    <div class="col-md-3" id="task_end_date_wrap" style="{{ $currentTaskType === 'daily' ? 'display:none;' : '' }}">
        <div class="form-group">
            <label>End date</label>
            <input type="text" id="task_end_date_preview" class="form-control" value="{{ isset($task) ? $task->end_date->format('M j, Y') : now()->addDays(7)->format('M j, Y') }}" disabled>
            <small class="text-muted">Always 7 days after the start date.</small>
        </div>
    </div>
    <div class="col-md-3" id="task_repeat_wrap">
        <div class="form-group">
            <label>&nbsp;</label>
            @if(isset($task) && $task->repeat_of !== null)
                <div class="checkbox" style="margin-top:7px;">
                    <label class="text-muted">
                        <i class="fa fa-repeat"></i>
                        Repeats {{ $task->repeat_weekly ? 'weekly' : 'daily' }}
                        @if($task->repeatRoot)
                            (from "{{ $task->repeatRoot->title }}")
                        @endif
                    </label>
                </div>
                <small class="text-muted">Only the original task controls whether the series repeats.</small>
            @else
                @php
                    // "Today" tasks get a real cadence choice (manager
                    // decision 2026-09-14): Daily resets the same row in
                    // place every day; Weekly spawns a fresh 1-day instance
                    // every 7 days instead. "This Week" tasks stay locked
                    // to weekly-only (row below) — a week-long window
                    // resetting daily doesn't make sense.
                    $todayRepeatOn = old('repeat_daily', $task->repeat_daily ?? false) || old('repeat_weekly', $task->repeat_weekly ?? false);
                    $todayRepeatFreq = old('repeat_weekly', $task->repeat_weekly ?? false) ? 'weekly' : 'daily';
                @endphp
                <div class="checkbox" id="task_repeat_daily_row" style="margin-top:7px;{{ $currentTaskType === 'daily' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_today_enabled" @if($todayRepeatOn) checked @endif @if($currentTaskType !== 'daily') disabled @endif>
                        Repeat
                    </label>
                    <select id="task_repeat_today_freq" class="form-control" style="width:auto;display:inline-block;margin-left:8px;{{ $todayRepeatOn ? '' : 'display:none;' }}" @if($currentTaskType !== 'daily') disabled @endif>
                        <option value="daily" @if($todayRepeatFreq==='daily') selected @endif>Daily</option>
                        <option value="weekly" @if($todayRepeatFreq==='weekly') selected @endif>Weekly</option>
                    </select>
                    <input type="hidden" id="task_repeat_daily" name="repeat_daily" value="{{ $todayRepeatOn && $todayRepeatFreq==='daily' ? '1' : '0' }}" @if($currentTaskType !== 'daily') disabled @endif>
                    <input type="hidden" id="task_repeat_today_weekly" name="repeat_weekly" value="{{ $todayRepeatOn && $todayRepeatFreq==='weekly' ? '1' : '0' }}" @if($currentTaskType !== 'daily') disabled @endif>
                    <br>
                    <small class="text-muted" id="task_repeat_today_hint">
                        {{ $todayRepeatFreq==='weekly' ? 'Spawns a fresh 1-day task every 7 days instead of needing to be re-added. Uncheck to stop — past ones stay as history.' : 'Resets to "not started" every day instead of needing to be re-added or reset by hand. Uncheck to stop resetting.' }}
                    </small>
                </div>
                <div class="checkbox" id="task_repeat_weekly_row" style="margin-top:7px;{{ $currentTaskType === 'weekly' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_weekly" name="repeat_weekly" value="1" @if(old('repeat_weekly', $task->repeat_weekly ?? false)) checked @endif @if($currentTaskType !== 'weekly') disabled @endif>
                        Repeat weekly
                    </label>
                    <br>
                    <small class="text-muted">Auto-creates a fresh copy each week instead of needing to be re-added. Uncheck to stop — past ones stay as history.</small>
                </div>
            @endif
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>Store</label>
            <select name="store" class="form-control">
                <option value="" @if(empty($task->store ?? '')) selected @endif>Both stores</option>
                @foreach($storeLabels as $key => $label)
                    <option value="{{ $key }}" @if(($task->store ?? '')===$key) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="col-md-2">
        <div class="form-group">
            <label>Priority</label>
            <select name="priority" class="form-control">
                @foreach($priorityLabels as $key => $label)
                    <option value="{{ $key }}" @if(old('priority', $task->priority ?? 'medium')===$key) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>
</div>

<div class="form-group">
    <label>Assigned to</label>
    @php($selectedAssignees = old('assignees', isset($task) ? $task->assignees->pluck('id')->all() : []))
    {!! Form::select('assignees[]', $assignableUsers, $selectedAssignees, ['id' => 'task_assignees', 'class' => 'form-control select2', 'multiple', 'style' => 'width: 100%;', 'data-placeholder' => 'Unassigned']) !!}
</div>

{{-- Plain inline script, not @push('scripts') — this layout has no
     @stack('scripts') to render it into, so a pushed script here is
     silently dropped and never runs. Inline (and jQuery-free, since this
     executes before jQuery loads later in the body) is what actually
     works on this page. --}}
<script>
(function() {
    function updateTaskTypeUI() {
        var typeEl = document.getElementById('task_type');
        var startDateEl = document.getElementById('task_start_date');
        if (!typeEl) {
            return;
        }
        var isDaily = typeEl.value === 'daily';

        var labelText = document.getElementById('task_start_date_label_text');
        if (labelText) {
            labelText.textContent = isDaily ? 'Date' : 'Start date';
        }

        var endDateWrap = document.getElementById('task_end_date_wrap');
        if (endDateWrap) {
            endDateWrap.style.display = isDaily ? 'none' : '';
        }

        // Only one of the daily/weekly repeat rows is ever the "active"
        // type at a time, but both live in the DOM together — and the
        // daily row now also carries a hidden `repeat_weekly` field (the
        // "Today, repeat Weekly" option) alongside the weekly row's own
        // `repeat_weekly` checkbox. Disabling the inactive row's fields
        // (not just hiding them) keeps only one same-named field posting.
        var dailyRow = document.getElementById('task_repeat_daily_row');
        if (dailyRow) {
            dailyRow.style.display = isDaily ? '' : 'none';
            dailyRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = !isDaily; });
        }

        var weeklyRow = document.getElementById('task_repeat_weekly_row');
        if (weeklyRow) {
            weeklyRow.style.display = isDaily ? 'none' : '';
            weeklyRow.querySelectorAll('input, select').forEach(function (el) { el.disabled = isDaily; });
        }

        if (isDaily) {
            updateRepeatTodayFields();
        }

        var endDatePreview = document.getElementById('task_end_date_preview');
        if (!startDateEl || !startDateEl.value || !endDatePreview) {
            return;
        }
        var d = new Date(startDateEl.value + 'T00:00:00');
        if (!isDaily) {
            d.setDate(d.getDate() + 7);
        }
        var opts = { year: 'numeric', month: 'short', day: 'numeric' };
        endDatePreview.value = d.toLocaleDateString('en-US', opts);
    }

    // "Today" row's Repeat checkbox + Daily/Weekly frequency select drive
    // two hidden inputs (task_repeat_daily / task_repeat_today_weekly)
    // rather than posting themselves directly, since the actual submitted
    // fields need to be "0"/"1" regardless of checkbox state.
    function updateRepeatTodayFields() {
        var enabled = document.getElementById('task_repeat_today_enabled');
        var freq = document.getElementById('task_repeat_today_freq');
        var dailyHidden = document.getElementById('task_repeat_daily');
        var weeklyHidden = document.getElementById('task_repeat_today_weekly');
        var hint = document.getElementById('task_repeat_today_hint');
        if (!enabled || !freq || !dailyHidden || !weeklyHidden) {
            return;
        }

        var on = enabled.checked;
        freq.style.display = on ? 'inline-block' : 'none';

        dailyHidden.value = (on && freq.value === 'daily') ? '1' : '0';
        weeklyHidden.value = (on && freq.value === 'weekly') ? '1' : '0';

        if (hint) {
            hint.textContent = freq.value === 'weekly'
                ? 'Spawns a fresh 1-day task every 7 days instead of needing to be re-added. Uncheck to stop — past ones stay as history.'
                : 'Resets to "not started" every day instead of needing to be re-added or reset by hand. Uncheck to stop resetting.';
        }
    }

    var typeEl = document.getElementById('task_type');
    var startDateEl = document.getElementById('task_start_date');
    if (typeEl) {
        typeEl.addEventListener('change', updateTaskTypeUI);
    }
    if (startDateEl) {
        startDateEl.addEventListener('change', updateTaskTypeUI);
    }

    var repeatTodayEnabled = document.getElementById('task_repeat_today_enabled');
    var repeatTodayFreq = document.getElementById('task_repeat_today_freq');
    if (repeatTodayEnabled) {
        repeatTodayEnabled.addEventListener('change', updateRepeatTodayFields);
    }
    if (repeatTodayFreq) {
        repeatTodayFreq.addEventListener('change', updateRepeatTodayFields);
    }
    updateRepeatTodayFields();
})();
</script>
