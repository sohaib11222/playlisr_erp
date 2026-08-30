@if($status === 'complete')
    <span class="label label-success">complete</span>
@elseif($status === 'in_progress')
    <span class="label label-warning">in progress</span>
@else
    <span class="label label-default">not started</span>
@endif
