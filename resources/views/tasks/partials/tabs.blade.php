@php
    $onTasks = request()->segment(1) === 'tasks' && request()->segment(2) !== 'projects';
@endphp
<ul class="nav nav-tabs" style="margin-bottom:15px;">
    <li class="{{ $onTasks ? 'active' : '' }}">
        <a href="{{ route('tasks.index') }}">Tasks</a>
    </li>
    <li class="{{ request()->segment(2) == 'projects' ? 'active' : '' }}">
        <a href="{{ route('projects.index') }}">Projects</a>
    </li>
</ul>
