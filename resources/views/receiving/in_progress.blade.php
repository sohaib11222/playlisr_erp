@extends('layouts.app')

@section('title', 'Items Waiting to Be Priced')

@section('content')
@include('sale_pos.partials._redesign_v2')
<script>document.body.classList.add('pos-v2');</script>

<style>
body.pos-v2 .rcv-wrap { max-width: 1100px; margin: 0 auto; padding: 18px 16px 60px; font-family: "Inter Tight", system-ui, sans-serif; color: var(--pos-ink); }
body.pos-v2 .rcv-head h1 { font-size: 24px; font-weight: 700; margin: 0 0 4px; }
body.pos-v2 .rcv-head .sub { color: #6b6253; margin: 0 0 18px; font-size: 14px; }
body.pos-v2 .rcv-group { background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: 14px; padding: 16px 18px; margin-bottom: 16px; }
body.pos-v2 .rcv-group h3 { font-size: 14px; font-weight: 700; margin: 0 0 10px; }
body.pos-v2 .rcv-group h3 a { color: var(--pos-accent-deep); }
body.pos-v2 .rcv-item-row { display: flex; justify-content: space-between; align-items: center; padding: 7px 0; border-bottom: 1px solid var(--pos-line); font-size: 13.5px; }
body.pos-v2 .rcv-item-row:last-child { border-bottom: none; }
body.pos-v2 .rcv-item-row .name { font-weight: 600; }
body.pos-v2 .rcv-item-row .meta { color: #8a8070; font-size: 12px; }
body.pos-v2 .empty-state { text-align: center; padding: 60px 20px; color: #8a8070; }
</style>

<div class="rcv-wrap">
    <div class="rcv-head">
        <h1>Items Waiting to Be Priced</h1>
        <p class="sub">The shared bin — anything logged but not yet priced/shelved, from any box, for anyone to pick up.</p>
    </div>

    @if($items->isEmpty())
        <div class="empty-state"><i class="fa fa-check-circle" style="font-size:32px;"></i><p>Nothing waiting — everything received so far has been priced.</p></div>
    @else
        @foreach($items->groupBy('receiving_package_id') as $packageId => $group)
            @php($pkg = $group->first()->package)
            <div class="rcv-group">
                <h3><a href="{{ action('ReceivingPackageController@show', [$packageId]) }}">Package #{{ $packageId }}</a> — {{ \App\ReceivingPackage::$packageTypes[$pkg->package_type] ?? $pkg->package_type }} at {{ $pkg->location->name ?? '-' }}
                    @if($pkg->bin_location) &middot; <span class="label label-default">Bin: {{ $pkg->bin_location }}</span> @endif
                </h3>
                @foreach($group as $item)
                    <div class="rcv-item-row">
                        <div>
                            <div class="name">{{ $item->product_name ?: ($item->sku ?: 'Unmatched item') }}</div>
                            <div class="meta">{{ $item->sku ?: '-' }} &middot; qty {{ $item->quantity }}</div>
                        </div>
                        <a href="{{ action('ReceivingPackageController@show', [$packageId]) }}" class="btn-ghost" style="padding:6px 12px;font-size:12.5px;">Price it &rarr;</a>
                    </div>
                @endforeach
            </div>
        @endforeach
    @endif
</div>

@stop
