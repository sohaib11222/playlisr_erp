{{-- Per-shift role-aware task panel: progress bars vs peer-pace target,
     celebration when goal hit. Polled every 60s by JS in home/index.
     Inputs: $shift_panel from HomeController::buildShiftPanel(). --}}
<style>
    .st-card { background:#fff; border:1px solid #e5e7eb; border-radius:14px; padding:18px 22px; margin-bottom:14px; box-shadow:0 1px 3px rgba(0,0,0,0.03); }
    .st-head { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:14px; gap:12px; }
    .st-head h3 { margin:0 0 2px 0; font-size:18px; font-weight:600; }
    .st-meta { color:#6b7280; font-size:12px; text-align:right; }
    .st-duty-pill { display:inline-block; padding:3px 10px; border-radius:999px; font-size:11px; font-weight:600; letter-spacing:.4px; text-transform:uppercase; vertical-align:middle; margin-left:8px; }
    .st-duty-pill.cashier { background:#e0f2fe; color:#075985; }
    .st-duty-pill.shipping { background:#fef3c7; color:#92400e; }
    .st-duty-pill.inventory { background:#ede9fe; color:#5b21b6; }
    .st-task { padding:14px 16px; background:#f8fafc; border-radius:10px; margin-bottom:10px; }
    .st-task:last-child { margin-bottom:0; }
    .st-task-row { display:flex; justify-content:space-between; align-items:baseline; margin-bottom:8px; }
    .st-task-label { font-size:14px; font-weight:600; color:#111827; }
    .st-task-numbers { font-size:14px; font-weight:600; color:#111827; }
    .st-task-numbers .st-target { color:#6b7280; font-weight:400; }
    .st-bar { height:10px; background:#e5e7eb; border-radius:5px; overflow:hidden; position:relative; }
    .st-bar > .st-fill { height:100%; transition:width .6s ease; background:#534ab7; border-radius:5px; }
    .st-bar > .st-peer { position:absolute; top:-3px; bottom:-3px; width:2px; background:#9ca3af; }
    .st-task-foot { display:flex; justify-content:space-between; margin-top:8px; font-size:11px; color:#6b7280; }
    .st-task-foot .st-vs-peer.ahead { color:#3b6d11; font-weight:600; }
    .st-task-foot .st-vs-peer.behind { color:#9a3412; font-weight:600; }
    .st-celebrate { background: linear-gradient(135deg, #fde68a, #fbbf24); color:#78350f; padding:14px 16px; border-radius:10px; margin-bottom:10px; font-weight:600; font-size:14px; text-align:center; box-shadow:0 2px 6px rgba(251,191,36,0.3); }
    .st-empty { font-size:13px; color:#6b7280; padding:8px 0; }
    .st-fill.complete { background:#16a34a; }
</style>

<div class="st-card" id="shift-tasks-panel">
    @if(!$shift_panel['active'])
        <div class="st-head">
            <div>
                <h3>Your shift</h3>
                <div class="st-empty">No active shift — open your register and pick a duty to start tracking.</div>
            </div>
        </div>
    @else
        <div class="st-head">
            <div>
                <h3>
                    Your shift
                    <span class="st-duty-pill {{ $shift_panel['duty'] }}">{{ $shift_panel['duty_label'] }}</span>
                </h3>
                <div class="st-empty" style="padding:0;">Started {{ $shift_panel['started_at'] }} · {{ $shift_panel['location_name'] ?? 'store' }}</div>
            </div>
            <div class="st-meta">
                <div>{{ number_format($shift_panel['hours'], 1) }}h on shift</div>
            </div>
        </div>

        @php $any_complete = collect($shift_panel['tasks'])->contains('complete', true); @endphp
        @if($any_complete)
            <div class="st-celebrate" data-celebrate-banner>
                Goal hit — nice work {{ Session::get('user.first_name') }}! Keep the momentum going.
            </div>
        @endif

        @forelse($shift_panel['tasks'] as $task)
            <div class="st-task" data-task-key="{{ $task['key'] }}" data-task-complete="{{ $task['complete'] ? '1' : '0' }}">
                <div class="st-task-row">
                    <div class="st-task-label">{{ $task['label'] }}</div>
                    <div class="st-task-numbers">
                        @if($task['unit'] === '$')
                            ${{ number_format($task['current'], 0) }}<span class="st-target"> / ${{ number_format($task['target'], 0) }}</span>
                        @else
                            {{ number_format($task['current'], 0) }}<span class="st-target"> / {{ number_format($task['target'], 0) }} {{ $task['unit'] }}</span>
                        @endif
                    </div>
                </div>
                <div class="st-bar">
                    <div class="st-fill {{ $task['complete'] ? 'complete' : '' }}" style="width: {{ $task['percent'] }}%;"></div>
                </div>
                <div class="st-task-foot">
                    <span>{{ number_format($task['percent'], 0) }}% of shift goal</span>
                    @php
                        $pp = $task['peer_per_hour'];
                        $tp = $task['peer_top_per_hour'] ?? null;
                        $mp = $task['my_per_hour'];
                        $is_money = $task['unit'] === '$';
                        $fmt = function ($v) use ($is_money) {
                            if ($v === null) return '—';
                            return $is_money
                                ? '$' . number_format($v, 0)
                                : number_format($v, $v < 10 ? 1 : 0);
                        };
                    @endphp
                    @if(!is_null($pp) && !is_null($mp))
                        @php
                            $delta_pct = $pp > 0 ? (($mp - $pp) / $pp) * 100 : 0;
                            $cls = $delta_pct >= 0 ? 'ahead' : 'behind';
                            $sign = $delta_pct >= 0 ? '+' : '';
                        @endphp
                        <span class="st-vs-peer {{ $cls }}">
                            You: {{ $fmt($mp) }}/hr · Peer avg: {{ $fmt($pp) }}/hr ({{ $sign }}{{ number_format($delta_pct, 0) }}%){{ $tp ? ' · Top: '.$fmt($tp).'/hr' : '' }}
                        </span>
                    @elseif(!is_null($pp))
                        <span>Peer avg: {{ $fmt($pp) }}/hr{{ $tp ? ' · Top: '.$fmt($tp).'/hr' : '' }}</span>
                    @else
                        <span>No peer history yet — set the pace.</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="st-empty">No tasks configured for this duty yet.</div>
        @endforelse
    @endif
</div>
