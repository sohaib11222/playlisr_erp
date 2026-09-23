{{-- Asana-style project detail form body. Expects $project (or null), $storeLabels, $priorityLabels, $assignableUsers. --}}
@php
    $selectedAssignees = old('assignees', $project ? $project->assignees->pluck('id')->all() : []);
    $currentStatus = old('status', $project->status ?? 'not_started');
@endphp
<div class="as-task-body">
    <input type="text" class="as-title-input" name="title" value="{{ old('title', $project->title ?? '') }}" required maxlength="200" aria-label="Project name" placeholder="Project name" @if(!$project) autofocus @endif>

    <div class="as-fields">
        <div class="k">Owner</div>
        <div class="v" style="display:block;">
            {!! Form::select('assignees[]', $assignableUsers, $selectedAssignees, ['id' => 'project_assignees', 'class' => 'form-control select2', 'multiple', 'style' => 'width: 100%;', 'data-placeholder' => 'Unassigned']) !!}
            <small class="text-muted">Texted when the project is created, if they have a phone number on file.</small>
        </div>

        @if($project)
            <div class="k">Status</div>
            <div class="v">
                <select name="status" id="asStatus" class="as-status as-st-{{ $currentStatus }}">
                    <option value="not_started" @if($currentStatus === 'not_started') selected @endif>Not started</option>
                    <option value="in_progress" @if($currentStatus === 'in_progress') selected @endif>In progress</option>
                    <option value="complete" @if($currentStatus === 'complete') selected @endif>Complete</option>
                </select>
            </div>
        @endif

        <div class="k">Priority</div>
        <div class="v">
            <select name="priority" class="form-control">
                @foreach($priorityLabels as $key => $label)
                    <option value="{{ $key }}" @if(old('priority', $project->priority ?? 'medium') === $key) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>

        <div class="k">Store</div>
        <div class="v">
            <select name="store" class="form-control">
                <option value="" @if(empty(old('store', $project->store ?? ''))) selected @endif>Both stores</option>
                @foreach($storeLabels as $key => $label)
                    <option value="{{ $key }}" @if(old('store', $project->store ?? '') === $key) selected @endif>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="as-desc-label">Description</div>
    <textarea name="description" class="as-desc-box" placeholder="What is this project and why does it matter?">{{ old('description', $project->description ?? '') }}</textarea>
</div>
