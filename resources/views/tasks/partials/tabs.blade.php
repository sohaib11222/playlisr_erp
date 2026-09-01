@php
    $onTasks = request()->segment(1) === 'tasks' && request()->segment(2) !== 'projects';
    $currentType = $type ?? request()->input('type', 'weekly');
    if (!in_array($currentType, ['daily', 'weekly'])) { $currentType = 'weekly'; }
@endphp
<ul class="nav nav-tabs" style="margin-bottom:15px;">
    <li class="{{ $onTasks && $currentType === 'daily' ? 'active' : '' }}">
        <a href="{{ route('tasks.index', ['type' => 'daily']) }}">Daily Tasks</a>
    </li>
    <li class="{{ $onTasks && $currentType === 'weekly' ? 'active' : '' }}">
        <a href="{{ route('tasks.index', ['type' => 'weekly']) }}">Weekly Tasks</a>
    </li>
    <li class="{{ request()->segment(2) == 'projects' ? 'active' : '' }}">
        <a href="{{ route('projects.index') }}">Projects</a>
    </li>
</ul>
