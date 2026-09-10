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
                <option value="daily" @if($currentTaskType==='daily') selected @endif>Daily</option>
                <option value="weekly" @if($currentTaskType==='weekly') selected @endif>Weekly</option>
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
                        Repeats {{ $task->task_type }}
                        @if($task->repeatRoot)
                            (from "{{ $task->repeatRoot->title }}")
                        @endif
                    </label>
                </div>
                <small class="text-muted">Only the original task controls whether the series repeats.</small>
            @else
                <div class="checkbox" id="task_repeat_daily_row" style="margin-top:7px;{{ $currentTaskType === 'daily' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_daily" name="repeat_daily" value="1" @if(old('repeat_daily', $task->repeat_daily ?? false)) checked @endif>
                        Repeat daily
                    </label>
                </div>
                <div class="checkbox" id="task_repeat_weekly_row" style="margin-top:7px;{{ $currentTaskType === 'weekly' ? '' : 'display:none;' }}">
                    <label>
                        <input type="checkbox" id="task_repeat_weekly" name="repeat_weekly" value="1" @if(old('repeat_weekly', $task->repeat_weekly ?? false)) checked @endif>
                        Repeat weekly
                    </label>
                </div>
                <small class="text-muted">Auto-creates a fresh copy on schedule instead of needing to be re-added. Uncheck to stop — past ones stay as history.</small>
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

        var dailyRow = document.getElementById('task_repeat_daily_row');
        if (dailyRow) {
            dailyRow.style.display = isDaily ? '' : 'none';
        }

        var weeklyRow = document.getElementById('task_repeat_weekly_row');
        if (weeklyRow) {
            weeklyRow.style.display = isDaily ? 'none' : '';
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

    var typeEl = document.getElementById('task_type');
    var startDateEl = document.getElementById('task_start_date');
    if (typeEl) {
        typeEl.addEventListener('change', updateTaskTypeUI);
    }
    if (startDateEl) {
        startDateEl.addEventListener('change', updateTaskTypeUI);
    }
})();
</script>
