{{-- One place for Clarissa (Sarah 10/14): count, then order, then what went missing. --}}
@php $ctOn = $ctOn ?? 'count'; $ctLoc = $ctLoc ?? request('location_id'); $q = $ctLoc ? '?location_id=' . $ctLoc : ''; @endphp
@php $tab = function ($on, $href, $label) use ($ctOn) { $style = $ctOn === $on ? 'background:#E8F0F8;color:#13507a !important;border:1px solid #1b6ca8;' : 'background:#fff;color:#1F1B16 !important;border:1px solid #E1D7C4;'; return '<a href="' . $href . '" style="padding:9px 18px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;' . $style . '">' . e($label) . '</a>'; }; @endphp
<div style="display:flex;gap:8px;margin:0 0 14px;flex-wrap:wrap;">
    {!! $tab('count', '/reports/shelf-count' . $q, '1. Count') !!}
    {!! $tab('order', '/reports/reorder' . $q, '2. Order') !!}
    {!! $tab('missing', '/reports/shelf-count/missing' . $q, 'Missing / possible theft') !!}
</div>
