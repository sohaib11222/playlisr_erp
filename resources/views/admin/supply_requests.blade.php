@extends('layouts.app')
@section('title', 'Manage Supply Requests')

@section('content')
<style>
    .sr-wrap { max-width: 1000px; }
    .sr-intro { font-size: 13px; color: #3D352C; margin: 4px 0 0; }
    .sr-table { width: 100%; border-collapse: collapse; background: #fff; }
    .sr-table th { font-size: 12px; text-transform: uppercase; letter-spacing: .04em; color: #3D352C; border-bottom: 1px solid #ECE3CF; padding: 6px 10px; text-align: left; }
    .sr-table td { border-bottom: 1px solid #F1EBDD; padding: 8px 10px; vertical-align: top; font-size: 14px; color: #1F1B16; }
    .sr-table tr.sr-store-head td { background: #FAF6EE; font-weight: 800; font-size: 13px; padding: 6px 10px; }
    .sr-qty { font-weight: 600; }
    .sr-note { font-size: 13px; margin-top: 2px; color: #3D352C; }
    .sr-store { white-space: nowrap; width: 1%; }
    .sr-pill { display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 13px; font-weight: 800; text-transform: capitalize; }
    .sr-hw { background: #E6DDF7; color: #4B2E83; border: 1px solid #C9B8EC; }
    .sr-pico { background: #DDF0E3; color: #1F5A30; border: 1px solid #B4DCC0; }
    .sr-wh { background: #FDE7CF; color: #7A3F0C; border: 1px solid #F3C99B; }
    .sr-none { background: #F3EEE3; color: #3D352C; border: 1px solid #DFD2B3; }
    .sr-who { font-size: 13px; white-space: nowrap; }
    .sr-act { width: 220px; }
    .sr-status { width: 100%; padding: 4px 6px; border: 1px solid #DFD2B3; border-radius: 6px; font-size: 13px; background: #fff; }
    .sr-details summary { cursor: pointer; font-size: 12px; font-weight: 700; margin-top: 4px; color: #1F5FA8; }
    .sr-fields input { width: 100%; margin-top: 4px; padding: 4px 6px; border: 1px solid #DFD2B3; border-radius: 6px; font-size: 13px; }
    .sr-save { margin-top: 6px; padding: 4px 12px; border-radius: 6px; border: 1px solid #2F6B3E; background: #2F6B3E; color: #fff; font-weight: 700; font-size: 13px; }
    .sr-empty { padding: 12px 4px; font-size: 14px; }
    @media (max-width: 700px) { .sr-act { width: 140px; } .sr-who { white-space: normal; } }
</style>

<section class="content-header">
    <h1>Supply Requests</h1>
    <p class="sr-intro">Change a status and it saves. Ordered moves it to Ordered &amp; done, where the employee sees the arriving date and tracking (under Details). Stock levels: <a href="{{ action('SuppliesController@index') }}">Supplies</a>.</p>
</section>

@php
    $byStore = function ($rows) {
        $rows = is_array($rows) ? $rows : (method_exists($rows, 'all') ? $rows->all() : (array) $rows);
        usort($rows, function ($a, $b) {
            return [strtolower($a['location_name'] ?? 'zz'), $b['requested_at'] ?? ''] <=> [strtolower($b['location_name'] ?? 'zz'), $a['requested_at'] ?? ''];
        });
        $out = [];
        foreach ($rows as $r) {
            $out[$r['location_name'] ?: 'No store'][] = $r;
        }
        return $out;
    };
@endphp

<section class="content sr-wrap">
    <div class="box box-solid">
        <div class="box-header with-border">
            <h3 class="box-title">Needs ordering ({{ count($requests) }})</h3>
        </div>
        <div class="box-body" style="padding:0;">
            @if (empty($requests) || count($requests) === 0)
                <div class="sr-empty">Nothing to order right now. New staff requests show up here.</div>
            @else
                <table class="sr-table">
                    <thead><tr><th>Store</th><th>Item</th><th>Asked by</th><th>Status</th></tr></thead>
                    <tbody>
                    @foreach ($byStore($requests) as $store => $rows)
                        @foreach ($rows as $r)
                            @include('admin._supply_request_row', ['r' => $r])
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>

    @if (!empty($done) && count($done) > 0)
    <div class="box box-solid collapsed-box">
        <div class="box-header with-border">
            <h3 class="box-title">Ordered &amp; done ({{ count($done) }})</h3>
            <div class="box-tools pull-right">
                <button type="button" class="btn btn-box-tool" data-widget="collapse"><i class="fa fa-plus"></i></button>
            </div>
        </div>
        <div class="box-body" style="padding:0;">
            <table class="sr-table">
                <thead><tr><th>Store</th><th>Item</th><th>Asked by</th><th>Status</th></tr></thead>
                <tbody>
                @foreach ($done as $r)
                    @include('admin._supply_request_row', ['r' => $r])
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</section>
@endsection
