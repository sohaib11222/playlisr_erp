@php
    $dueDate = \Carbon\Carbon::parse($t['due_date']);
    $dueText = ($t['overdue'] ? 'Overdue - was due ' : 'Due ') . $dueDate->format('D, M j');
@endphp
<div class="task-row {{ $t['overdue'] ? 'overdue' : '' }} {{ $t['done'] ? 'done' : '' }}">
    <input type="checkbox" class="task-box" data-id="{{ $t['id'] }}" data-period="{{ $t['period_key'] }}" {{ $t['done'] ? 'checked' : '' }}>

    <div class="task-main">
        <span class="task-title">{{ $t['title'] }}</span>
        @if(!empty($t['notes']))
            <span class="task-notes">{{ $t['notes'] }}</span>
        @endif
        @if($t['done'] && $t['done_by'])
            <span class="task-done-by">Done by {{ $t['done_by'] }}</span>
        @endif
    </div>

    <div class="task-meta">
        @if($t['recurrence'] === 'weekly')
            <span class="chip repeat">Weekly{{ $t['weekday'] ? ' - ' . $weekdayNames[$t['weekday']] : '' }}</span>
        @endif
        <span class="chip {{ $t['overdue'] ? 'due-overdue' : '' }}">{{ $dueText }}</span>

        @if($canManage)
            <select class="assignee-select" data-id="{{ $t['id'] }}">
                <option value="" {{ !$t['assigned_to_user_id'] ? 'selected' : '' }}>Anyone</option>
                @foreach($employees as $e)
                    <option value="{{ $e['id'] }}" {{ (int) $t['assigned_to_user_id'] === (int) $e['id'] ? 'selected' : '' }}>{{ $e['name'] }}</option>
                @endforeach
            </select>
            <button type="button" class="del-btn" data-id="{{ $t['id'] }}" title="Remove task">&times;</button>
        @else
            <span class="avatar {{ $t['assigned_to_user_id'] ? '' : 'unassigned' }}" title="{{ $t['assignee_name'] ?? 'Anyone' }}">
                {{ $t['assigned_to_user_id'] ? $initials($t['assignee_name']) : '?' }}
            </span>
        @endif
    </div>
</div>
