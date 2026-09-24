{{-- Asana-style Incomplete / Completed / All switch. Expects $viewUrls (['incomplete' => url, 'completed' => url, 'all' => url]) and $viewCurrent. --}}
<div class="as-seg" title="Show">
    <a href="{{ $viewUrls['incomplete'] }}" class="{{ $viewCurrent === 'incomplete' ? 'on' : '' }}"><i class="fa fa-circle-o"></i> Incomplete</a>
    <a href="{{ $viewUrls['completed'] }}" class="{{ $viewCurrent === 'completed' ? 'on' : '' }}"><i class="fa fa-check-circle-o"></i> Completed</a>
    <a href="{{ $viewUrls['all'] }}" class="{{ $viewCurrent === 'all' ? 'on' : '' }}">All</a>
</div>
