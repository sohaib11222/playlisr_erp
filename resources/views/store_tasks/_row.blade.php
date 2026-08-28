@php
    $dueDate = \Carbon\Carbon::parse($t['due_date']);
    $dueText = $dueDate->format('M j');
@endphp
<div class="task-row {{ $t['overdue'] ? 'overdue' : '' }} {{ $t['done'] ? 'done' : '' }}"
     data-id="{{ $t['id'] }}"
     data-title="{{ $t['title'] }}"
     data-notes="{{ $t['notes'] }}"
     data-assignee="{{ $t['assignee_name'] ?? 'Anyone on shift' }}"
     data-due="{{ $t['overdue'] ? 'Overdue - was due ' : 'Due ' }}{{ $dueText }}"
     data-repeat="{{ $t['recurrence'] === 'weekly' ? 'Repeats weekly' . ($t['weekday'] ? ' on ' . $weekdayNames[$t['weekday']] : '') : '' }}">
    <input type="checkbox" class="task-box" data-id="{{ $t['id'] }}" data-period="{{ $t['period_key'] }}" {{ $t['done'] ? 'checked' : '' }}>

    <div class="task-main task-open">
        <span class="task-title">{{ $t['title'] }}</span>
        @if(!empty($t['notes']))
            <span class="task-notes">{{ $t['notes'] }}</span>
        @else
            <span class="task-notes task-notes-empty">Add description...</span>
        @endif
        @if($t['done'] && $t['done_by'])
            <span class="task-done-by">Done by {{ $t['done_by'] }}</span>
        @endif
    </div>

    <div class="assignee-cell">
        @if($canManage)
            <select class="assignee-select" data-id="{{ $t['id'] }}">
                <option value="" {{ !$t['assigned_to_user_id'] ? 'selected' : '' }}>Anyone</option>
                @foreach($employees as $e)
                    <option value="{{ $e['id'] }}" {{ (int) $t['assigned_to_user_id'] === (int) $e['id'] ? 'selected' : '' }}>{{ $e['name'] }}</option>
                @endforeach
            </select>
        @else
            <span class="avatar {{ $t['assigned_to_user_id'] ? '' : 'unassigned' }}"
                  style="{{ $t['assigned_to_user_id'] ? 'background:' . $avatarColor((int) $t['assigned_to_user_id']) : '' }}"
                  title="{{ $t['assignee_name'] ?? 'Anyone on shift' }}">
                {{ $t['assigned_to_user_id'] ? $initials($t['assignee_name']) : '' }}
            </span>
            @if($t['assigned_to_user_id'])
                <span class="assignee-name">{{ $t['assignee_name'] }}</span>
            @endif
        @endif
    </div>

    <div class="due-cell">
        @if($t['recurrence'] === 'weekly')
            <span class="repeat-mark" title="Repeats weekly{{ $t['weekday'] ? ' - ' . $weekdayNames[$t['weekday']] : '' }}">&#8635;</span>
        @endif
        {{ $t['overdue'] ? 'Overdue ' : '' }}{{ $dueText }}
    </div>

    @if($canManage)
        <button type="button" class="del-btn" data-id="{{ $t['id'] }}" title="Remove task">&times;</button>
    @else
        <span></span>
    @endif
</div>
