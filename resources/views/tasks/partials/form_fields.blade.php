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
            <label id="task_start_date_label">{{ $currentTaskType === 'daily' ? 'Date' : 'Start date' }} <span class="text-danger">*</span></label>
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

@push('scripts')
<script>
$(function() {
    function updateEndDatePreview() {
        var isDaily = $('#task_type').val() === 'daily';
        $('#task_start_date_label').text(isDaily ? 'Date' : 'Start date');
        $('#task_end_date_wrap').toggle(!isDaily);
        if (!$('#task_start_date').val()) return;
        var d = new Date($('#task_start_date').val() + 'T00:00:00');
        if (!isDaily) { d.setDate(d.getDate() + 7); }
        var opts = { year: 'numeric', month: 'short', day: 'numeric' };
        $('#task_end_date_preview').val(d.toLocaleDateString('en-US', opts));
    }
    $('#task_start_date, #task_type').on('change', updateEndDatePreview);
});
</script>
@endpush
