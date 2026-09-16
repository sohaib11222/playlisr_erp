@extends('layouts.app')
@section('title', 'Edit Task')

@section('content')
<section class="content-header"><h1>Edit Task</h1></section>

<section class="content">
    @include('tasks.partials.tabs')
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('TaskController@update', $task->id) }}" id="task_edit_form">
                @csrf
                @method('PUT')
                @include('tasks.partials.form_fields', ['task' => $task])
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="task_status_select" class="form-control">
                        <option value="not_started" @if($task->status==='not_started') selected @endif>Not started</option>
                        <option value="in_progress" @if($task->status==='in_progress') selected @endif>In progress</option>
                        <option value="complete" @if($task->status==='complete') selected @endif>Complete</option>
                    </select>
                </div>
                <input type="hidden" name="photo_confirmed" id="task_photo_confirmed" value="0">
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('TaskController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>

{{-- Gate saving with status=complete on a photo-required task behind an
     explicit confirmation — the photo lives in #taskphotos in Slack, not
     this app, so there's nothing to verify here, just an acknowledgement.
     Skipped entirely once the task already has a confirmed photo. --}}
<script>
(function () {
    var form = document.getElementById('task_edit_form');
    var statusSelect = document.getElementById('task_status_select');
    var photoConfirmedInput = document.getElementById('task_photo_confirmed');
    var requiresPhotoCheckbox = form ? form.querySelector('input[name="requires_photo"]') : null;
    var alreadyConfirmed = {{ $task->photo_confirmed_at ? 'true' : 'false' }};

    if (!form || !statusSelect || !photoConfirmedInput) { return; }

    form.addEventListener('submit', function (e) {
        var requiresPhoto = requiresPhotoCheckbox ? requiresPhotoCheckbox.checked : false;
        if (statusSelect.value === 'complete' && requiresPhoto && !alreadyConfirmed) {
            if (!confirm('Did you post a photo of the finished work to #taskphotos in Slack?\n\nClick OK to confirm and save this task as complete.')) {
                e.preventDefault();
                return;
            }
            photoConfirmedInput.value = '1';
        }
    });
})();
</script>
@stop
