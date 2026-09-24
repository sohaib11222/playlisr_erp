<div class="as-nav">
    <a href="{{ route('tasks.my') }}" class="{{ request()->segment(2) === 'my' ? 'on' : '' }}">My Tasks</a>
    <a href="{{ route('tasks.index') }}" class="{{ request()->segment(2) === null ? 'on' : '' }}">All Tasks</a>
    <a href="{{ route('projects.index') }}" class="{{ request()->segment(2) === 'projects' ? 'on' : '' }}">Projects</a>
    @if(\App\Http\Controllers\TeamProgressController::canView())
        <a href="{{ route('tasks.team-progress') }}" class="{{ in_array(request()->segment(2), ['team-progress', 'staff-phones'], true) ? 'on' : '' }}">Team Progress</a>
    @endif
</div>
