{{-- Expects: $dueTodayTasks — array of ['id','title','priority','status'], from
     session('tasks_due_today_bubble'). Flashed once by
     CashRegisterController@postCloseRegister right after a successful
     register close, so this shows exactly once, right when the cashier is
     still looking at the screen. Marking a task here updates the same
     weekly_tasks row shown on /tasks — it isn't a separate list, just an
     accountability nudge at the moment people actually act on it. --}}
<div id="tasks_due_today_bubble" style="background:#FFF3E0;border:1px solid #F0C27B;border-left:6px solid #E8912B;border-radius:12px;padding:16px 20px;margin:15px 0;font-family:'Inter Tight',system-ui,sans-serif;">
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;">
        <i class="fa fas fa-list-check" style="font-size:22px;color:#B26A00;"></i>
        <div style="flex:1 1 240px;">
            <div style="font-size:16px;font-weight:800;color:#5A4410;">Tasks due today</div>
            <div style="font-size:13px;color:#7a6a3a;">Mark these off before you go, or leave them for the next person.</div>
        </div>
        <a href="{{ route('tasks.index', ['type' => 'daily']) }}" style="font-size:13px;font-weight:700;color:#B26A00;white-space:nowrap;">View in Tasks &amp; Projects &rarr;</a>
    </div>
    <ul id="tasks_due_today_list" style="list-style:none;margin:0;padding:0;">
        @foreach($dueTodayTasks as $t)
        <li data-task-id="{{ $t['id'] }}" data-status-url="{{ route('tasks.update-status', $t['id']) }}" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#fff;border:1px solid #F0DDBE;border-radius:8px;padding:8px 12px;margin-bottom:8px;">
            <span class="label label-{{ ['high'=>'danger','medium'=>'warning','low'=>'default'][$t['priority']] ?? 'default' }}">{{ ucfirst($t['priority']) }}</span>
            <span style="flex:1 1 180px;font-weight:600;color:#333;">{{ $t['title'] }}</span>
            <button type="button" class="btn btn-xs btn-default tasks-due-today-btn" data-status="in_progress" @if($t['status']==='in_progress') disabled @endif>In progress</button>
            <button type="button" class="btn btn-xs btn-success tasks-due-today-btn" data-status="complete">Complete</button>
        </li>
        @endforeach
    </ul>
</div>
<script>
(function () {
    var bubble = document.getElementById('tasks_due_today_bubble');
    if (!bubble) { return; }
    var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    bubble.addEventListener('click', function (e) {
        var btn = e.target.closest('.tasks-due-today-btn');
        if (!btn) { return; }
        var row = btn.closest('li[data-task-id]');
        var statusUrl = row.getAttribute('data-status-url');
        var status = btn.getAttribute('data-status');

        row.querySelectorAll('.tasks-due-today-btn').forEach(function (b) { b.disabled = true; });

        fetch(statusUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json'
            },
            body: JSON.stringify({ status: status })
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (!data.success) { throw new Error('failed'); }
            if (status === 'complete') {
                row.style.transition = 'opacity .25s';
                row.style.opacity = '0';
                setTimeout(function () {
                    row.remove();
                    var list = document.getElementById('tasks_due_today_list');
                    if (list && !list.querySelector('li')) { bubble.remove(); }
                }, 250);
            } else {
                row.querySelector('[data-status="in_progress"]').disabled = true;
                row.querySelector('[data-status="complete"]').disabled = false;
            }
        }).catch(function () {
            row.querySelectorAll('.tasks-due-today-btn').forEach(function (b) { b.disabled = false; });
        });
    });
})();
</script>
