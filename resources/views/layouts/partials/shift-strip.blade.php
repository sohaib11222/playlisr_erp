{{-- Global shift-progress strip — shown on every authenticated page so
     employees see their per-shift progress without going to /home.
     $shift_panel injected by view composer registered in AppServiceProvider. --}}
@if(isset($shift_panel))
<style>
    .st-strip { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin:8px 15px; box-shadow:0 1px 2px rgba(0,0,0,0.03); }
    .st-strip-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    .st-strip-title { font-size:12px; font-weight:600; color:#374151; letter-spacing:.3px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill { display:inline-block; padding:2px 9px; border-radius:999px; font-size:11px; font-weight:600; letter-spacing:.4px; vertical-align:middle; margin-left:6px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill.cashier { background:#e0f2fe; color:#075985; }
    .st-strip-title .st-duty-pill.shipping { background:#fef3c7; color:#92400e; }
    .st-strip-title .st-duty-pill.inventory { background:#ede9fe; color:#5b21b6; }
    .st-strip-meta { font-size:12px; color:#6b7280; }
    .st-strip-grid { display:flex; flex-direction:column; gap:8px; }
    .st-strip-grid + .st-strip-grid { margin-top:8px; padding-top:8px; border-top:1px solid #f1f2f4; }
    .st-row { min-width:0; }
    .st-row.scope-day_store { background:#f0f9ff; padding:6px 10px; border-radius:8px; }
    .st-row.scope-day_store .st-bar > .st-fill { background:#0ea5e9; }
    .st-row.scope-day_store .st-row-label { color:#075985; }
    .st-row-top { display:flex; justify-content:space-between; align-items:baseline; gap:10px; margin-bottom:4px; }
    .st-row-label { flex:1 1 auto; min-width:0; font-size:14px; font-weight:600; color:#111827; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .st-row-numbers { flex:0 0 auto; font-size:14px; font-weight:600; color:#111827; white-space:nowrap; }
    .st-row-numbers .st-target { color:#6b7280; font-weight:400; }
    .st-row-status { flex:0 0 auto; font-size:12px; color:#6b7280; white-space:nowrap; }
    .st-bar { height:9px; background:#eef2f7; border-radius:5px; overflow:hidden; }
    .st-bar > .st-fill { height:100%; transition:width .6s ease; background:#534ab7; border-radius:5px; }
    .st-bar > .st-fill.complete { background:#16a34a; }
    .st-pace { font-weight:600; }
    .st-pace.ahead { color:#3b6d11; }
    .st-pace.behind { color:#9a3412; }
    .st-pace.on { color:#1d4ed8; }
    .st-celebrate { background: linear-gradient(135deg, #fde68a, #fbbf24); color:#78350f; padding:6px 12px; border-radius:8px; margin-top:8px; font-weight:600; font-size:13px; text-align:center; }
    .st-empty { font-size:13px; color:#6b7280; }
    .st-live { display:inline-block; width:7px; height:7px; border-radius:50%; background:#9ca3af; vertical-align:middle; margin-left:6px; transition:background .3s, transform .3s; }
    .st-live.pulse { background:#16a34a; transform:scale(1.6); }
</style>

<div class="st-strip no-print" id="shift-tasks-panel">
    @if(!$shift_panel['active'])
        <div class="st-strip-head">
            <div class="st-strip-title">Your shift</div>
            <div class="st-strip-meta st-empty">No active shift — pick a duty to start tracking.</div>
        </div>
    @else
        <div class="st-strip-head">
            <div class="st-strip-title">
                Shift
                <span class="st-duty-pill {{ $shift_panel['duty'] }}">{{ $shift_panel['duty_label'] }}</span>
            </div>
            <div class="st-strip-meta">
                Started {{ $shift_panel['started_at'] }} · {{ $shift_panel['location_name'] ?? 'store' }} ·
                {{ number_format($shift_panel['hours'], 1) }}h of ~{{ number_format($shift_panel['expected_hours'], 1) }}h shift
                <span class="st-live" id="st-live-dot" title="Live — pulses on each refresh"></span>
            </div>
        </div>

        @php
            $personal_tasks = collect($shift_panel['tasks'])->filter(function ($t) { return ($t['scope'] ?? 'shift') !== 'day_store'; })->values();
            $store_tasks    = collect($shift_panel['tasks'])->filter(function ($t) { return ($t['scope'] ?? 'shift') === 'day_store'; })->values();

            $renderTask = function ($task) {
                $pp = $task['peer_per_hour'];
                $tp = $task['peer_top_per_hour'] ?? null;
                $is_money = $task['unit'] === '$';
                $is_per_day = ($task['scope'] ?? 'shift') === 'day_store';
                $fmt = function ($v) use ($is_money) {
                    if ($v === null) return '—';
                    return $is_money
                        ? '$' . number_format($v, 0)
                        : number_format($v, $v < 10 ? 1 : 0);
                };
                $paceLabel = [
                    'ahead'  => 'Ahead',
                    'on'     => 'On pace',
                    'behind' => 'Behind',
                ][$task['pace_status'] ?? ''] ?? null;
                $peer_unit = $is_per_day ? '/day avg' : '/hr';
                $tooltip = !is_null($pp)
                    ? ($is_per_day ? 'Typical day at this store: ' : 'Peers at this hour avg ') . $fmt($pp) . $peer_unit
                        . ($tp ? ' · best ' . $fmt($tp) . ($is_per_day ? '/day' : '/hr') : '')
                    : '';
                ob_start();
                ?>
                <div class="st-row scope-<?= e($task['scope'] ?? 'shift') ?>" data-task-key="<?= e($task['key']) ?>" data-task-complete="<?= $task['complete'] ? '1' : '0' ?>" data-task-scope="<?= e($task['scope'] ?? 'shift') ?>">
                    <div class="st-row-top">
                        <div class="st-row-label" title="<?= e($task['label']) ?>"><?= e($task['label']) ?></div>
                        <div class="st-row-numbers">
                            <?php if ($is_money): ?>
                                $<?= number_format($task['current'], 0) ?><span class="st-target"> / $<?= number_format($task['target'], 0) ?></span>
                            <?php else: ?>
                                <?= number_format($task['current'], 0) ?><span class="st-target"> / <?= number_format($task['target'], 0) ?> <?= e($task['unit']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="st-row-status" title="<?= e($tooltip) ?>">
                            <span class="st-pct"><?= number_format($task['percent'], 0) ?>%</span>
                            <?php if ($task['complete']): ?>
                                · <span class="st-pace ahead">Goal hit 🎉</span>
                            <?php elseif ($paceLabel): ?>
                                · <span class="st-pace <?= e($task['pace_status']) ?>"><?= $paceLabel ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="st-bar" title="<?= e($tooltip) ?>">
                        <div class="st-fill <?= $task['complete'] ? 'complete' : '' ?>" style="width: <?= $task['percent'] ?>%;"></div>
                    </div>
                </div>
                <?php
                return ob_get_clean();
            };
        @endphp

        @if($personal_tasks->count())
            <div class="st-strip-grid {{ $personal_tasks->count() >= 2 ? 'cols-2' : 'cols-1' }}">
                @foreach($personal_tasks as $task)
                    {!! $renderTask($task) !!}
                @endforeach
            </div>
        @endif

        @if($store_tasks->count())
            <div class="st-strip-grid cols-1">
                @foreach($store_tasks as $task)
                    {!! $renderTask($task) !!}
                @endforeach
            </div>
        @endif

        @if(!$personal_tasks->count() && !$store_tasks->count())
            <div class="st-empty">No tasks configured for this duty yet.</div>
        @endif

        @php $any_complete = collect($shift_panel['tasks'])->contains('complete', true); @endphp
        @if($any_complete)
            <div class="st-celebrate" data-celebrate-banner>
                Goal hit — nice work {{ Session::get('user.first_name') }}!
            </div>
        @endif
    @endif
</div>

<script type="text/javascript">
{{-- Defer until jQuery is on the page (vendor.js loads near end of body). --}}
(function bootShiftStrip() {
    if (typeof window.jQuery === 'undefined') {
        return setTimeout(bootShiftStrip, 30);
    }
    jQuery(function ($) {
        var $panel = $('#shift-tasks-panel');
        if (!$panel.length) return;
        if (window.__shiftStripBooted) return;
        window.__shiftStripBooted = true;

        var celebratedKeys = {};
        $panel.find('[data-task-key]').each(function () {
            var v = $(this).data('task-complete');
            if (v === 1 || v === '1') celebratedKeys[$(this).data('task-key')] = true;
        });

        function fireConfetti() {
            var $c = $('<div>', { css: {
                position: 'fixed', left: 0, top: 0, right: 0, bottom: 0,
                pointerEvents: 'none', zIndex: 9999, overflow: 'hidden'
            }});
            var colors = ['#fbbf24','#16a34a','#534ab7','#ef4444','#0ea5e9'];
            for (var i = 0; i < 60; i++) {
                var $piece = $('<div>', { css: {
                    position: 'absolute',
                    left: Math.random() * 100 + 'vw',
                    top: '-10px', width: '8px', height: '14px',
                    background: colors[i % colors.length],
                    opacity: 0.9,
                    transform: 'rotate(' + (Math.random() * 360) + 'deg)',
                    transition: 'transform 2.4s ease-out, top 2.4s ease-out, opacity 2.4s ease-out'
                }});
                $c.append($piece);
                setTimeout((function ($p) { return function () {
                    $p.css({
                        top: (80 + Math.random() * 20) + 'vh',
                        transform: 'rotate(' + (Math.random() * 720) + 'deg)',
                        opacity: 0
                    });
                }; })($piece), 30);
            }
            $('body').append($c);
            setTimeout(function () { $c.remove(); }, 2800);
        }

        function fmt(v, isMoney) {
            if (v === null || v === undefined) return '—';
            if (isMoney) return '$' + Math.round(v).toLocaleString();
            return v < 10 ? Number(v).toFixed(1) : String(Math.round(v));
        }
        var paceLabels = { ahead: 'Ahead', on: 'On pace', behind: 'Behind' };

        function buildStatusHtml(t) {
            var html = '<span class="st-pct">' + Math.round(t.percent) + '%</span>';
            if (t.complete) {
                return html + ' · <span class="st-pace ahead">Goal hit 🎉</span>';
            }
            var label = paceLabels[t.pace_status];
            if (label) {
                html += ' · <span class="st-pace ' + t.pace_status + '">' + label + '</span>';
            }
            return html;
        }
        function buildTooltip(t) {
            var pp = t.peer_per_hour, tp = t.peer_top_per_hour;
            if (pp == null) return '';
            var isPerDay = t.scope === 'day_store';
            var unit = isPerDay ? '/day avg' : '/hr';
            var leadIn = isPerDay ? 'Typical day at this store: ' : 'Peers at this hour avg ';
            var bestLabel = isPerDay ? '/day' : '/hr';
            return leadIn + fmt(pp, t.unit === '$') + unit
                + (tp ? ' · best ' + fmt(tp, t.unit === '$') + bestLabel : '');
        }

        function pulseLive() {
            var $dot = $('#st-live-dot');
            if (!$dot.length) return;
            $dot.addClass('pulse');
            setTimeout(function () { $dot.removeClass('pulse'); }, 350);
        }

        function refresh() {
            $.ajax({
                url: '{{ route('home.shiftProgress') }}',
                dataType: 'json',
                cache: false
            }).done(function (data) {
                pulseLive();
                if (window.console) console.log('[shift-strip] poll', data);
                if (!data || !data.active) return;
                (data.tasks || []).forEach(function (t) {
                    var $task = $panel.find('[data-task-key="' + t.key + '"]');
                    if (!$task.length) return;
                    $task.find('.st-fill')
                        .css('width', t.percent + '%')
                        .toggleClass('complete', !!t.complete);
                    var nums;
                    if (t.unit === '$') {
                        nums = '$' + Math.round(t.current).toLocaleString() +
                            '<span class="st-target"> / $' + Math.round(t.target).toLocaleString() + '</span>';
                    } else {
                        nums = Math.round(t.current).toLocaleString() +
                            '<span class="st-target"> / ' + Math.round(t.target).toLocaleString() + ' ' + t.unit + '</span>';
                    }
                    $task.find('.st-row-numbers').html(nums);
                    $task.find('.st-row-status').html(buildStatusHtml(t));
                    var tip = buildTooltip(t);
                    $task.find('.st-bar, .st-row-status').attr('title', tip);

                    if (t.complete && !celebratedKeys[t.key]) {
                        celebratedKeys[t.key] = true;
                        fireConfetti();
                        if (!$panel.find('[data-celebrate-banner]').length) {
                            var name = @json(Session::get('user.first_name') ?? '');
                            $panel.find('.st-strip-grid').last().after(
                                '<div class="st-celebrate" data-celebrate-banner>' +
                                'Goal hit — nice work ' + (name || 'there') + '!</div>'
                            );
                        }
                    }
                });
            }).fail(function (xhr, status, err) {
                if (window.console) console.error('[shift-strip] poll failed', status, err, xhr && xhr.status);
            });
        }

        // Initial refresh ~1s after page settle, then every 15s. Pause if
        // tab is hidden so we don't spam the endpoint.
        setTimeout(refresh, 1000);
        setInterval(function () { if (!document.hidden) refresh(); }, 15000);
    });
})();
</script>
@endif
