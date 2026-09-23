<div class="as-nav">
    <a href="{{ route('tasks.my') }}" class="{{ request()->segment(2) === 'my' ? 'on' : '' }}">My Tasks</a>
    <a href="{{ route('tasks.index') }}">All Tasks</a>
    <a href="{{ route('projects.index') }}">Projects</a>
    @if(auth()->user()->hasRole('Admin#' . session('business.id')))
        <a href="{{ route('tasks.team-progress') }}" class="{{ request()->segment(2) === 'team-progress' ? 'on' : '' }}">Team Progress</a>
    @endif
</div>
