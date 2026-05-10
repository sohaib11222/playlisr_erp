{{-- Global shift-progress strip — shown on every authenticated page so
     employees see their per-shift progress without going to /home.
     $shift_panel injected by view composer registered in AppServiceProvider. --}}
@if(isset($shift_panel))
<style>
    .st-strip { background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:10px 14px; margin:10px 15px 14px; box-shadow:0 1px 2px rgba(0,0,0,0.03); }
    .st-strip-head { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-bottom:8px; }
    .st-strip-title { font-size:12px; font-weight:600; color:#374151; letter-spacing:.3px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill { display:inline-block; padding:1px 8px; border-radius:999px; font-size:10px; font-weight:600; letter-spacing:.4px; vertical-align:middle; margin-left:6px; text-transform:uppercase; }
    .st-strip-title .st-duty-pill.cashier { background:#e0f2fe; color:#075985; }
    .st-strip-title .st-duty-pill.shipping { background:#fef3c7; color:#92400e; }
    .st-strip-title .st-duty-pill.inventory { background:#ede9fe; color:#5b21b6; }
    .st-strip-meta { font-size:11px; color:#6b7280; }
    .st-strip-grid { display:grid; gap:10px 18px; }
    .st-strip-grid.cols-2 { grid-template-columns: repeat(2, 1fr); }
    .st-strip-grid.cols-1 { grid-template-columns: 1fr; }
    .st-row { min-width:0; }
    .st-row-label { display:flex; justify-content:space-between; align-items:baseline; font-size:12px; font-weight:600; color:#111827; margin-bottom:4px; gap:8px; }
    .st-row-label .st-target { color:#6b7280; font-weight:400; font-size:11px; }
    .st-bar { height:6px; background:#eef2f7; border-radius:3px; overflow:hidden; }
    .st-bar > .st-fill { height:100%; transition:width .6s ease; background:#534ab7; border-radius:3px; }
    .st-bar > .st-fill.complete { background:#16a34a; }
    .st-row-foot { font-size:10px; color:#6b7280; margin-top:3px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .st-pace { font-weight:600; }
    .st-pace.ahead { color:#3b6d11; }
    .st-pace.behind { color:#9a3412; }
    .st-pace.on { color:#1d4ed8; }
    .st-celebrate { background: linear-gradient(135deg, #fde68a, #fbbf24); color:#78350f; padding:6px 12px; border-radius:8px; margin-top:8px; font-weight:600; font-size:12px; text-align:center; }
    .st-empty { font-size:12px; color:#6b7280; }
    .st-live { display:inline-block; width:6px; height:6px; border-radius:50%; background:#9ca3af; vertical-align:middle; margin-left:6px; transition:background .3s, transform .3s; }
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

        @php $count = count($shift_panel['tasks']); @endphp
        <div class="st-strip-grid {{ $count >= 2 ? 'cols-2' : 'cols-1' }}">
            @forelse($shift_panel['tasks'] as $task)
                <div class="st-row" data-task-key="{{ $task['key'] }}" data-task-complete="{{ $task['complete'] ? '1' : '0' }}">
                    <div class="st-row-label">
                        <span>{{ $task['label'] }}</span>
                        <span class="st-task-numbers">
                            @if($task['unit'] === '$')
                                ${{ number_format($task['current'], 0) }}<span class="st-target"> / ${{ number_format($task['target'], 0) }}</span>
                            @else
                                {{ number_format($task['current'], 0) }}<span class="st-target"> / {{ number_format($task['target'], 0) }} {{ $task['unit'] }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="st-bar">
                        <div class="st-fill {{ $task['complete'] ? 'complete' : '' }}" style="width: {{ $task['percent'] }}%;"></div>
                    </div>
                    <div class="st-row-foot">
                        @php
                            $pp = $task['peer_per_hour'];
                            $tp = $task['peer_top_per_hour'] ?? null;
                            $is_money = $task['unit'] === '$';
                            $fmt = function ($v) use ($is_money) {
                                if ($v === null) return '—';
                                return $is_money
                                    ? '$' . number_format($v, 0)
                                    : number_format($v, $v < 10 ? 1 : 0);
                            };
                            $paceLabel = [
                                'ahead'  => 'Ahead of pace',
                                'on'     => 'On pace',
                                'behind' => 'Behind pace',
                            ][$task['pace_status'] ?? ''] ?? null;
                            $tooltip = '';
                            if (!is_null($pp)) {
                                $tooltip = 'Peers at this hour avg ' . $fmt($pp) . '/hr'
                                    . ($tp ? ' · top ' . $fmt($tp) . '/hr' : '');
                            }
                        @endphp
                        <span class="st-pct">{{ number_format($task['percent'], 0) }}%</span>
                        @if($task['complete'])
                            · <span class="st-pace ahead">Goal hit 🎉</span>
                        @elseif($paceLabel)
                            · <span class="st-pace {{ $task['pace_status'] }}" @if($tooltip) title="{{ $tooltip }}" @endif>{{ $paceLabel }}</span>
                            @if(!is_null($pp))
                                · <span title="{{ $tooltip }}">Peer {{ $fmt($pp) }}/hr</span>
                            @endif
                        @elseif(!is_null($pp))
                            · Peer {{ $fmt($pp) }}/hr
                        @else
                            · Just getting started
                        @endif
                    </div>
                </div>
            @empty
                <div class="st-empty">No tasks configured for this duty yet.</div>
            @endforelse
        </div>

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
        var paceLabels = { ahead: 'Ahead of pace', on: 'On pace', behind: 'Behind pace' };

        function buildFootHtml(t) {
            var pp = t.peer_per_hour, tp = t.peer_top_per_hour;
            var tooltip = pp != null
                ? 'Peers at this hour avg ' + fmt(pp, t.unit === '$') + '/hr' + (tp ? ' · top ' + fmt(tp, t.unit === '$') + '/hr' : '')
                : '';
            var html = '<span class="st-pct">' + Math.round(t.percent) + '%</span>';
            if (t.complete) {
                return html + ' · <span class="st-pace ahead">Goal hit 🎉</span>';
            }
            var label = paceLabels[t.pace_status];
            if (label) {
                html += ' · <span class="st-pace ' + t.pace_status + '"'
                    + (tooltip ? ' title="' + tooltip + '"' : '') + '>' + label + '</span>';
                if (pp != null) {
                    html += ' · <span title="' + tooltip + '">Peer ' + fmt(pp, t.unit === '$') + '/hr</span>';
                }
            } else if (pp != null) {
                html += ' · Peer ' + fmt(pp, t.unit === '$') + '/hr';
            } else {
                html += ' · Just getting started';
            }
            return html;
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
                    $task.find('.st-task-numbers').html(nums);
                    $task.find('.st-row-foot').html(buildFootHtml(t));

                    if (t.complete && !celebratedKeys[t.key]) {
                        celebratedKeys[t.key] = true;
                        fireConfetti();
                        if (!$panel.find('[data-celebrate-banner]').length) {
                            var name = @json(Session::get('user.first_name') ?? '');
                            $panel.find('.st-strip-grid').after(
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
