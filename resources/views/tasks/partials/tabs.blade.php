<ul class="nav nav-tabs" style="margin-bottom:15px;">
    <li class="{{ request()->segment(2) != 'projects' ? 'active' : '' }}">
        <a href="{{ route('tasks.index') }}">Weekly Tasks</a>
    </li>
    <li class="{{ request()->segment(2) == 'projects' ? 'active' : '' }}">
        <a href="{{ route('projects.index') }}">Projects</a>
    </li>
</ul>
