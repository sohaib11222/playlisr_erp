<div class="form-group">
    <label>Title <span class="text-danger">*</span></label>
    <input type="text" class="form-control" name="title" value="{{ old('title', $task->title ?? '') }}" required maxlength="200">
</div>

<div class="form-group">
    <label>Description</label>
    <textarea name="description" class="form-control" rows="3">{{ old('description', $task->description ?? '') }}</textarea>
</div>

<div class="row">
    <div class="col-md-3">
        <div class="form-group">
            <label>Start date <span class="text-danger">*</span></label>
            <input type="date" id="task_start_date" class="form-control" name="start_date" value="{{ old('start_date', isset($task) ? $task->start_date->toDateString() : now()->toDateString()) }}" required>
        </div>
    </div>
    <div class="col-md-3">
        <div class="form-group">
            <label>End date</label>
            <input type="text" id="task_end_date_preview" class="form-control" value="{{ isset($task) ? $task->end_date->format('M j, Y') : now()->addDays(7)->format('M j, Y') }}" disabled>
            <small class="text-muted">Always 7 days after the start date.</small>
        </div>
    </div>
</div>

@push('scripts')
<script>
$(function() {
    $('#task_start_date').on('change', function() {
        if (!this.value) return;
        var d = new Date(this.value + 'T00:00:00');
        d.setDate(d.getDate() + 7);
        var opts = { year: 'numeric', month: 'short', day: 'numeric' };
        $('#task_end_date_preview').val(d.toLocaleDateString('en-US', opts));
    });
});
</script>
@endpush
