@extends('layouts.app')
@section('title', 'Nivessa Intelligence')

@section('content')
<style>
.ni-wrap { max-width: 1180px; margin: 0 auto; padding: 24px 16px 60px; color: #1F1B16; }
.ni-wrap h1 { font-size: 28px; font-weight: 700; margin: 0 0 4px; }
.ni-wrap h2 { font-size: 20px; font-weight: 700; margin: 0 0 4px; }
.ni-help { color: #6B6155; font-size: 14px; margin: 0 0 14px; }
.ni-tabs { display: flex; flex-wrap: wrap; gap: 8px; margin: 14px 0 18px; }
.ni-tab { border: 1px solid #E1D7C4; background: #fff; border-radius: 999px; padding: 7px 15px; font-size: 14px; color: #1F1B16; text-decoration: none; }
.ni-tab:hover { background: #FAF6EE; text-decoration: none; color: #1F1B16; }
.ni-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 0 0 22px; }
.ni-kpi { background: #fff; border: 1px solid #E6DCCF; border-radius: 14px; padding: 14px 16px; }
.ni-kpi .lbl { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #8E8273; }
.ni-kpi .val { font-size: 26px; font-weight: 700; margin: 2px 0; font-variant-numeric: tabular-nums; }
.ni-kpi .sub { font-size: 13px; color: #6B6155; }
.ni-card { background: #fff; border: 1px solid #E6DCCF; border-radius: 14px; padding: 18px; margin: 0 0 20px; }
.ni-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.ni-table th { text-align: left; background: #FAF6EE; padding: 7px 8px; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; color: #6B6155; white-space: nowrap; }
.ni-table td { padding: 6px 8px; border-top: 1px solid #F3ECE1; vertical-align: top; }
.ni-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.ni-sub { color: #8E8273; font-size: 12px; }
.ni-scroll { overflow-x: auto; max-height: 520px; overflow-y: auto; }
.ni-grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 16px; }
.ni-pill { display: inline-block; border-radius: 8px; padding: 2px 8px; font-weight: 700; font-size: 12px; white-space: nowrap; }
.ni-pill.live { background: #E3F2E1; color: #24622A; }
.ni-pill.here { background: #E4EEF7; color: #2D5878; }
.ni-pill.next { background: #FCEFD9; color: #8A5A12; }
.ni-pill.bad { background: #FBE3DC; color: #9A3A1F; }
.ni-road h3 { font-size: 15px; font-weight: 700; margin: 14px 0 6px; }
.ni-road ul { list-style: none; padding: 0; margin: 0; }
.ni-road li { padding: 6px 0; border-top: 1px solid #F3ECE1; font-size: 14px; display: flex; gap: 10px; align-items: baseline; }
.ni-road li .ni-pill { flex: 0 0 auto; }
.ni-err { color: #9A3A1F; font-size: 14px; }
.ni-meta { font-size: 13px; color: #8E8273; }
</style>

@php
    $money = function ($n) { return '$' . number_format((float) $n, 0); };
    $money2 = function ($n) { return '$' . number_format((float) $n, 2); };
    $loc = function ($id) use ($d) { return $d['locations'][$id] ?? 'Unknown'; };
    $dead = $d['dead'] ?? ['error' => true];
    $pricing = $d['pricing'] ?? ['error' => true];
    $transfers = $d['transfers'] ?? ['error' => true];
    $missed = $d['missed'] ?? ['error' => true];
    $profit = $d['profit'] ?? ['error' => true];
    $allProfit = empty($profit['error']) ? $profit['all'] : [];
    $profit30 = array_sum(array_map(function ($r) { return $r->profit; }, $allProfit));
@endphp

<div class="ni-wrap">
    <h1>Nivessa Intelligence</h1>
    <p class="ni-help">Where Hollywood, Pico and online are making money, losing it, or missing it, read straight from the ERP. Cost = each item's purchase price, so profit is an estimate.</p>
    <div class="ni-meta">Updated {{ \Carbon\Carbon::parse($d['generated_at'])->diffForHumans() }} · <a href="{{ url('/nivessa-intelligence?refresh=1') }}">Refresh now</a></div>

    <div class="ni-tabs">
        <a class="ni-tab" href="#dead">Dead capital</a>
        <a class="ni-tab" href="#pricing">Pricing errors</a>
        <a class="ni-tab" href="#transfers">Transfers</a>
        <a class="ni-tab" href="#missed">Missed sales</a>
        <a class="ni-tab" href="#profit">Profit by category</a>
        <a class="ni-tab" href="#roadmap">All AI tools</a>
    </div>

    <div class="ni-kpis">
        <div class="ni-kpi">
            <div class="lbl">Dead capital</div>
            <div class="val">{{ empty($dead['error']) ? $money($dead['totals']['cost']) : '—' }}</div>
            <div class="sub">at cost, no sale in {{ $deadDays }} days @if(empty($dead['error']))· {{ number_format($dead['totals']['units']) }} units @endif</div>
        </div>
        <div class="ni-kpi">
            <div class="lbl">Pricing errors</div>
            <div class="val">{{ empty($pricing['error']) ? number_format($pricing['count']) : '—' }}</div>
            <div class="sub">in stock below cost or at $1 or less</div>
        </div>
        <div class="ni-kpi">
            <div class="lbl">Transfer opportunities</div>
            <div class="val">{{ empty($transfers['error']) ? number_format($transfers['total'] ?? 0) : '—' }}</div>
            <div class="sub">sells at one store, sitting at the other</div>
        </div>
        <div class="ni-kpi">
            <div class="lbl">Gross profit, 30 days</div>
            <div class="val">{{ empty($profit['error']) ? $money($profit30) : '—' }}</div>
            <div class="sub">top 15 categories, all stores (est.)</div>
        </div>
    </div>

    {{-- Dead capital --}}
    <div class="ni-card" id="dead">
        <h2>Dead capital</h2>
        <p class="ni-help">In-stock items added more than {{ $deadDays }} days ago that haven't sold in {{ $deadDays }} days. Options: mark down, move to the other store, bundle, or list online.</p>
        @if(!empty($dead['error']))
            <div class="ni-err">Couldn't load this section.</div>
        @else
            <div class="ni-grid2">
                <div>
                    <table class="ni-table">
                        <tr><th>Store</th><th class="ni-num">Tied up (cost)</th></tr>
                        @foreach($dead['by_location'] as $name => $cost)
                            <tr><td>{{ $name }}</td><td class="ni-num">{{ $money($cost) }}</td></tr>
                        @endforeach
                        <tr><td><strong>Retail value</strong></td><td class="ni-num"><strong>{{ $money($dead['totals']['retail']) }}</strong></td></tr>
                    </table>
                </div>
                <div class="ni-scroll" style="max-height:300px">
                    <table class="ni-table">
                        <tr><th>Store</th><th>Category</th><th class="ni-num">Units</th><th class="ni-num">Cost</th></tr>
                        @foreach($dead['by_category'] as $r)
                            <tr><td>{{ $loc($r->location_id) }}</td><td>{{ $r->category }}</td><td class="ni-num">{{ number_format($r->units) }}</td><td class="ni-num">{{ $money($r->cost) }}</td></tr>
                        @endforeach
                    </table>
                </div>
            </div>
            <h3 style="font-size:15px;font-weight:700;margin:18px 0 6px">Biggest dollars sitting still</h3>
            <div class="ni-scroll">
                <table class="ni-table">
                    <tr><th>Item</th><th>Category</th><th>Store</th><th class="ni-num">Qty</th><th class="ni-num">Cost</th><th class="ni-num">Price</th><th class="ni-num">Tied up</th></tr>
                    @foreach($dead['top'] as $r)
                        <tr>
                            <td>{{ $r->artist ? $r->artist . ' – ' : '' }}{{ $r->name }}<div class="ni-sub">{{ $r->sku }}</div></td>
                            <td>{{ $r->category }}</td><td>{{ $loc($r->location_id) }}</td>
                            <td class="ni-num">{{ (float) $r->qty }}</td><td class="ni-num">{{ $money2($r->cost) }}</td>
                            <td class="ni-num">{{ $money2($r->price) }}</td><td class="ni-num">{{ $money($r->tied_up) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
        @endif
    </div>

    {{-- Pricing errors --}}
    <div class="ni-card" id="pricing">
        <h2>Pricing errors</h2>
        <p class="ni-help">In-stock items priced below what we paid, or at $1 or less. Worst gaps first.</p>
        @if(!empty($pricing['error']))
            <div class="ni-err">Couldn't load this section.</div>
        @elseif($pricing['rows']->isEmpty())
            <p>Nothing flagged.</p>
        @else
            <div class="ni-scroll">
                <table class="ni-table">
                    <tr><th>Issue</th><th>Item</th><th>Category</th><th>Store</th><th class="ni-num">Qty</th><th class="ni-num">Cost</th><th class="ni-num">Price</th></tr>
                    @foreach($pricing['rows'] as $r)
                        <tr>
                            <td><span class="ni-pill bad">{{ $r->issue }}</span></td>
                            <td>{{ $r->artist ? $r->artist . ' – ' : '' }}{{ $r->name }}<div class="ni-sub">{{ $r->sku }}</div></td>
                            <td>{{ $r->category }}</td><td>{{ $loc($r->location_id) }}</td>
                            <td class="ni-num">{{ (float) $r->qty }}</td><td class="ni-num">{{ $money2($r->cost) }}</td><td class="ni-num">{{ $money2($r->price) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @if($pricing['count'] > count($pricing['rows']))<div class="ni-sub" style="margin-top:6px">Showing {{ count($pricing['rows']) }} of {{ number_format($pricing['count']) }}.</div>@endif
        @endif
    </div>

    {{-- Transfers --}}
    <div class="ni-card" id="transfers">
        <h2>Store transfer intelligence</h2>
        <p class="ni-help">Sold at one store in the last {{ $transferDays }} days and out of stock there now, while the other store has a copy that hasn't sold in {{ $deadDays }} days. Move these via <a href="{{ url('/transfers/bulk') }}">Transfers</a>.</p>
        @if(!empty($transfers['error']))
            <div class="ni-err">Couldn't load this section.</div>
        @elseif(empty($transfers['rows']))
            <p>No transfer opportunities right now.</p>
        @else
            <div class="ni-scroll">
                <table class="ni-table">
                    <tr><th>Item</th><th>Category</th><th>Move from</th><th>To</th><th class="ni-num">Sold there ({{ $transferDays }}d)</th><th class="ni-num">Qty to move</th><th class="ni-num">Price</th></tr>
                    @foreach($transfers['rows'] as $r)
                        <tr>
                            <td>{{ $r->artist ? $r->artist . ' – ' : '' }}{{ $r->name }}<div class="ni-sub">{{ $r->sku }}</div></td>
                            <td>{{ $r->category }}</td><td>{{ $r->from_name }}</td><td><strong>{{ $r->to_name }}</strong></td>
                            <td class="ni-num">{{ (float) $r->units_sold }}</td><td class="ni-num">{{ (float) $r->qty_from }}</td><td class="ni-num">{{ $money2($r->price) }}</td>
                        </tr>
                    @endforeach
                </table>
            </div>
            @if(($transfers['total'] ?? 0) > count($transfers['rows']))<div class="ni-sub" style="margin-top:6px">Showing top {{ count($transfers['rows']) }} of {{ number_format($transfers['total']) }}.</div>@endif
        @endif
    </div>

    {{-- Missed sales --}}
    <div class="ni-card" id="missed">
        <h2>Missed sales</h2>
        <p class="ni-help">What customers asked for that we didn't have: POS searches that found nothing (last 60 days, asked 2+ times) and open customer wants.</p>
        @if(!empty($missed['error']))
            <div class="ni-err">Couldn't load this section.</div>
        @else
            <div class="ni-grid2">
                <div class="ni-scroll">
                    <table class="ni-table">
                        <tr><th>POS search, no result</th><th class="ni-num">Times</th><th class="ni-num">Stores</th></tr>
                        @forelse($missed['searches'] as $r)
                            <tr><td>{{ $r->term }}</td><td class="ni-num">{{ $r->times }}</td><td class="ni-num">{{ $r->stores }}</td></tr>
                        @empty
                            <tr><td colspan="3" class="ni-sub">None logged.</td></tr>
                        @endforelse
                    </table>
                    <div class="ni-sub" style="margin-top:6px"><a href="{{ url('/reports/pos-requests') }}">Full POS requests report</a></div>
                </div>
                <div class="ni-scroll">
                    <table class="ni-table">
                        <tr><th>Customer wants (open)</th><th class="ni-num">Requests</th></tr>
                        @forelse($missed['wants'] as $r)
                            <tr><td>{{ $r->artist }}</td><td class="ni-num">{{ $r->wants }}</td></tr>
                        @empty
                            <tr><td colspan="2" class="ni-sub">None open.</td></tr>
                        @endforelse
                    </table>
                    <div class="ni-sub" style="margin-top:6px"><a href="{{ url('/customer-wants') }}">All customer wants</a></div>
                </div>
            </div>
        @endif
    </div>

    {{-- Profit --}}
    <div class="ni-card" id="profit">
        <h2>Profit by category, last 30 days</h2>
        <p class="ni-help">Which categories actually make the money, and where. Margin = (revenue − purchase cost) ÷ revenue.</p>
        @if(!empty($profit['error']))
            <div class="ni-err">Couldn't load this section.</div>
        @else
            <div class="ni-grid2">
                <div>
                    <table class="ni-table">
                        <tr><th>All stores</th><th class="ni-num">Units</th><th class="ni-num">Revenue</th><th class="ni-num">Profit</th><th class="ni-num">Margin</th></tr>
                        @foreach($profit['all'] as $r)
                            <tr><td>{{ $r->category }}</td><td class="ni-num">{{ number_format($r->units) }}</td><td class="ni-num">{{ $money($r->revenue) }}</td><td class="ni-num">{{ $money($r->profit) }}</td><td class="ni-num">{{ $r->revenue > 0 ? round($r->profit / $r->revenue * 100) . '%' : '—' }}</td></tr>
                        @endforeach
                    </table>
                </div>
                @foreach($profit['by_location'] as $name => $list)
                    <div>
                        <table class="ni-table">
                            <tr><th>{{ $name }}</th><th class="ni-num">Revenue</th><th class="ni-num">Profit</th><th class="ni-num">Margin</th></tr>
                            @foreach($list as $r)
                                <tr><td>{{ $r->category }}</td><td class="ni-num">{{ $money($r->revenue) }}</td><td class="ni-num">{{ $money($r->profit) }}</td><td class="ni-num">{{ $r->revenue > 0 ? round($r->profit / $r->revenue * 100) . '%' : '—' }}</td></tr>
                            @endforeach
                        </table>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Roadmap --}}
    @php
        $road = [
            'Find money left on the table' => [
                ['Dead capital detector', 'here', '#dead'],
                ['Pricing errors (below cost / $1)', 'here', '#pricing'],
                ['Missed sales detector', 'here', '#missed'],
                ['Underpricing detector vs. Discogs/eBay market', 'next', null],
                ['Missed margin detector by genre', 'next', null],
            ],
            'Understand demand early' => [
                ['Genre opportunity / fastest-selling genres', 'live', url('/home')],
                ['Restock & reorder signals', 'live', url('/reports/reorder')],
                ['Artist popularity & viral demand tracker', 'next', null],
                ['Tour, release & movie/TV demand predictor', 'next', null],
                ['Seasonal demand (Halloween, holidays, RSD)', 'next', null],
            ],
            'Optimize both stores' => [
                ['Store transfer intelligence', 'here', '#transfers'],
                ['Profit by category per store', 'here', '#profit'],
                ['Store or Online? (channel & store allocation)', 'live', url('/store-or-discogs')],
                ['Shelf productivity per section', 'next', null],
                ['Store-specific pricing', 'next', null],
            ],
            'Make employees more productive' => [
                ['Processing speed & receiving bottlenecks', 'next', null],
                ['Pricing accuracy vs. eventual sell price', 'next', null],
                ['Labor allocation from traffic & sales', 'next', null],
            ],
            'Buy better collections' => [
                ['Buying assistant with genre velocity & margins', 'live', url('/buy-from-customer')],
                ['Learn which collections earn most per $ spent', 'next', null],
            ],
            'Run the online business' => [
                ['Listing prioritization & channel allocation', 'live', url('/store-or-discogs')],
                ['Website search misses', 'next', null],
                ['Shipping profitability', 'next', null],
                ['Missing / poor image detection', 'next', null],
            ],
            'AI CFO' => [
                ['Gross profit by category', 'here', '#profit'],
                ['Break-even & sales forecast per store', 'next', null],
                ['Margin erosion & discount analysis', 'next', null],
            ],
            'Know our customers' => [
                ['Customer wants & preferences', 'live', url('/customer-wants')],
                ['Lifetime value & win-back alerts', 'next', null],
            ],
            'Catch operational problems' => [
                ['Shelf count / inventory discrepancies', 'live', url('/reports/shelf-count')],
                ['Pricing errors', 'here', '#pricing'],
                ['Unusual voids, discounts & adjustments', 'next', null],
                ['Duplicate products & SKUs', 'next', null],
            ],
            'AI command center' => [
                ['Ask the business questions in plain English', 'next', null],
            ],
        ];
        $pillText = ['here' => 'On this page', 'live' => 'Live in ERP', 'next' => 'Coming'];
    @endphp
    <div class="ni-card ni-road" id="roadmap">
        <h2>All AI tools</h2>
        <p class="ni-help">The full plan. Green ones already exist elsewhere in the ERP; blue ones are on this page; orange ones are next.</p>
        <div class="ni-grid2">
            @foreach($road as $group => $items)
                <div>
                    <h3>{{ $group }}</h3>
                    <ul>
                        @foreach($items as $it)
                            <li>
                                <span class="ni-pill {{ $it[1] }}">{{ $pillText[$it[1]] }}</span>
                                @if($it[2])<a href="{{ $it[2] }}">{{ $it[0] }}</a>@else<span>{{ $it[0] }}</span>@endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </div>
    </div>
</div>
@endsection
