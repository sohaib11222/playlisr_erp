@extends('layouts.app')

@section('title', 'AMS Orders')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .ams-wrap { max-width: 1180px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .ams-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 18px; flex-wrap: wrap; }
body.pos-v2 .ams-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .ams-head .sub { color: #6b6253; margin: 0; font-size: 14px; max-width: 640px; }
body.pos-v2 .ams-card { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 16px 18px; margin-bottom: 18px; }
body.pos-v2 .ams-toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
body.pos-v2 .ams-tabs { display: inline-flex; gap: 6px; flex-wrap: wrap; }
body.pos-v2 .ams-tab { border: 1px solid var(--pos-line-2); border-radius: 999px; padding: 7px 14px; font-size: 13px; font-weight: 600; color: #5a5145; text-decoration: none; background: #fff; }
body.pos-v2 .ams-tab.active { background: var(--pos-accent); border-color: var(--pos-accent-deep); color: var(--pos-accent-text); }
body.pos-v2 .ams-search { margin-left: auto; }
body.pos-v2 .ams-search input { border: 1px solid var(--pos-line-2); border-radius: 9px; padding: 8px 12px; font-size: 14px; font-family: inherit; background: #fff; min-width: 240px; color: var(--pos-ink); }
body.pos-v2 .ams-search input:focus { outline: none; border-color: var(--pos-accent-deep); box-shadow: 0 0 0 3px var(--pos-accent-soft); }
body.pos-v2 .btn-accent { background: var(--pos-accent); color: var(--pos-accent-text); border: 1px solid var(--pos-accent-deep);
  border-radius: 10px; padding: 10px 18px; font-weight: 700; font-size: 14px; cursor: pointer; font-family: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: 7px; }
body.pos-v2 .btn-accent:hover { background: var(--pos-accent-deep); color: var(--pos-accent-text); }

body.pos-v2 .ams-order { border: 1px solid var(--pos-line); border-radius: 12px; padding: 14px 16px; margin-bottom: 12px; background: #fff; }
body.pos-v2 .ams-order-top { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
body.pos-v2 .ams-store { font-size: 16px; font-weight: 700; }
body.pos-v2 .ams-meta { color: #8a8070; font-size: 13px; }
body.pos-v2 .ams-meta strong { color: #5a5145; font-weight: 600; }
body.pos-v2 .ams-status { font-size: 11px; font-weight: 700; padding: 4px 11px; border-radius: 999px; text-transform: uppercase; letter-spacing: .04em; }
body.pos-v2 .st-placed { background: #2C5F8A; color: #fff; }
body.pos-v2 .st-partial { background: #E8A33D; color: #3a2c08; }
body.pos-v2 .st-arrived { background: #2f8a4e; color: #fff; }
body.pos-v2 .st-cancelled { background: #cfc8bb; color: #4a4438; }
body.pos-v2 .ams-spacer { margin-left: auto; }
body.pos-v2 .ams-items { margin: 10px 0 0; padding: 11px 13px; background: var(--pos-accent-soft); border-radius: 9px; font-size: 13.5px; white-space: pre-wrap; line-height: 1.5; color: var(--pos-ink); }
body.pos-v2 .ams-notes { margin: 8px 0 0; font-size: 13px; color: #6b6253; }
body.pos-v2 .ams-actions { display: flex; gap: 6px; margin-top: 12px; flex-wrap: wrap; }
body.pos-v2 .ams-btn { border: 1px solid var(--pos-line-2); border-radius: 8px; padding: 6px 12px; font-size: 12.5px; font-weight: 600; cursor: pointer; font-family: inherit; background: #fff; color: #5a5145; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
body.pos-v2 .ams-btn:hover { background: var(--pos-surface-2); color: #5a5145; }
body.pos-v2 .ams-btn.go { background: #2f8a4e; border-color: #2f8a4e; color: #fff; }
body.pos-v2 .ams-btn.go:hover { background: #267a42; color: #fff; }
body.pos-v2 .ams-btn.warn { background: #E8A33D; border-color: #d8902a; color: #3a2c08; }
body.pos-v2 .ams-btn.danger { color: #b3402f; border-color: #e3c4be; }
body.pos-v2 .ams-empty { text-align: center; color: #8a8070; padding: 40px 16px; }
body.pos-v2 form.ams-inline { display: inline; margin: 0; }
</style>

<div class="ams-wrap">
    <div class="ams-head">
        <div>
            <h1>AMS Orders</h1>
            <p class="sub">What we've ordered from AMS and what's still coming in. Log an order when you send AMS a list, then mark it <strong>Arrived</strong> when it lands. Check here before re-ordering so we don't double-up.</p>
        </div>
        <a href="{{ action('AmsOrderController@create') }}" class="btn-accent"><i class="fa fa-plus"></i> Log an AMS Order</a>
    </div>

    <div class="ams-card">
        <div class="ams-toolbar">
            <div class="ams-tabs">
                @php
                    $tabs = ['open' => 'Still coming (' . $openCount . ')', 'all' => 'All'] + $statuses;
                @endphp
                @foreach($tabs as $key => $label)
                    <a href="{{ action('AmsOrderController@index', ['status' => $key]) }}"
                       class="ams-tab {{ $filter === $key ? 'active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
            <div class="ams-search">
                <input type="text" id="ams_search" placeholder="Search title, artist, store…">
            </div>
        </div>

        <div id="ams_list">
            @forelse($rows as $o)
                @php
                    $oid = (int) ($o['id'] ?? 0);
                    $status = $o['status'] ?? 'placed';
                    $ordered = !empty($o['ordered_date']) ? \Carbon\Carbon::parse($o['ordered_date'])->format('n/j/y') : '-';
                    $eta = '';
                    $rawEta = $o['expected_date'] ?? null;
                    if ($rawEta && !in_array(substr((string) $rawEta, 0, 4), ['0000', '-000'], true)) {
                        $eta = \Carbon\Carbon::parse($rawEta)->format('n/j/y');
                    }
                @endphp
                <div class="ams-order" data-search="{{ strtolower(($o['store'] ?? '') . ' ' . ($o['items'] ?? '') . ' ' . ($o['ams_ref'] ?? '') . ' ' . ($o['notes'] ?? '')) }}">
                    <div class="ams-order-top">
                        <span class="ams-store">{{ $o['store'] ?: 'AMS Order' }}</span>
                        <span class="ams-status st-{{ $status }}">{{ $statuses[$status] ?? $status }}</span>
                        <span class="ams-meta">Ordered <strong>{{ $ordered }}</strong></span>
                        @if($eta)<span class="ams-meta">· ETA <strong>{{ $eta }}</strong></span>@endif
                        @if(!empty($o['ams_ref']))<span class="ams-meta">· Ref <strong>{{ $o['ams_ref'] }}</strong></span>@endif
                        <span class="ams-spacer"></span>
                        @if(!empty($o['created_by_name']))<span class="ams-meta">by {{ $o['created_by_name'] }}</span>@endif
                    </div>

                    @if(trim((string) ($o['items'] ?? '')) !== '')
                        <div class="ams-items">{{ $o['items'] }}</div>
                    @endif
                    @if(!empty($o['notes']))
                        <div class="ams-notes"><i class="fa fa-sticky-note"></i> {{ $o['notes'] }}</div>
                    @endif

                    <div class="ams-actions">
                        <a href="{{ action('AmsOrderController@edit', [$oid]) }}" class="ams-btn"><i class="fa fa-edit"></i> Edit</a>

                        @if(in_array($status, ['placed', 'partial']))
                            <form class="ams-inline ams-status-form" action="{{ action('AmsOrderController@setStatus', [$oid]) }}" method="POST">
                                {{ csrf_field() }}
                                <input type="hidden" name="status" value="arrived">
                                <button type="submit" class="ams-btn go"><i class="fa fa-check"></i> Mark Arrived</button>
                            </form>
                            @if($status !== 'partial')
                                <form class="ams-inline ams-status-form" action="{{ action('AmsOrderController@setStatus', [$oid]) }}" method="POST">
                                    {{ csrf_field() }}
                                    <input type="hidden" name="status" value="partial">
                                    <button type="submit" class="ams-btn warn"><i class="fa fa-box-open"></i> Partial</button>
                                </form>
                            @endif
                        @else
                            <form class="ams-inline ams-status-form" action="{{ action('AmsOrderController@setStatus', [$oid]) }}" method="POST">
                                {{ csrf_field() }}
                                <input type="hidden" name="status" value="placed">
                                <button type="submit" class="ams-btn"><i class="fa fa-undo"></i> Reopen</button>
                            </form>
                        @endif

                        <form class="ams-inline ams-delete-form" action="{{ action('AmsOrderController@destroy', [$oid]) }}" method="POST">
                            {{ csrf_field() }}
                            {{ method_field('DELETE') }}
                            <button type="submit" class="ams-btn danger"><i class="fa fa-trash"></i></button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="ams-empty">
                    <p>No AMS orders here yet.</p>
                    <a href="{{ action('AmsOrderController@create') }}" class="btn-accent" style="margin-top:8px;"><i class="fa fa-plus"></i> Log your first AMS order</a>
                </div>
            @endforelse
        </div>
    </div>
</div>

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        $('#ams_search').on('input', function() {
            var q = $(this).val().toLowerCase().trim();
            $('#ams_list .ams-order').each(function() {
                var hay = ($(this).attr('data-search') || '');
                $(this).toggle(q === '' || hay.indexOf(q) !== -1);
            });
        });

        $(document).on('submit', '.ams-delete-form', function(e) {
            e.preventDefault();
            var form = this;
            swal({
                title: LANG.sure,
                text: 'Delete this AMS order from the log?',
                icon: 'warning',
                buttons: true,
                dangerMode: true,
            }).then(function(confirmed) {
                if (confirmed) { form.submit(); }
            });
        });
    });
</script>
@stop
