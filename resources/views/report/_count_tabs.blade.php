{{-- One place for Clarissa (Sarah 10/14): count the shelves, then build the order. Both share the same counts. --}}
@php $ctOn = $ctOn ?? 'count'; $ctLoc = $ctLoc ?? request('location_id'); @endphp
<div style="display:flex;gap:8px;margin:0 0 14px;flex-wrap:wrap;">
    <a href="/reports/shelf-count{{ $ctLoc ? '?location_id=' . $ctLoc : '' }}" style="padding:9px 18px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;{{ $ctOn === 'count' ? 'background:#1F1B16;color:#FFE8A3;' : 'background:#fff;color:#1F1B16;border:1px solid #E1D7C4;' }}">1. Count shelves</a>
    <a href="/reports/reorder{{ $ctLoc ? '?location_id=' . $ctLoc : '' }}" style="padding:9px 18px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;{{ $ctOn === 'order' ? 'background:#1F1B16;color:#FFE8A3;' : 'background:#fff;color:#1F1B16;border:1px solid #E1D7C4;' }}">2. Weekly order</a>
    <a href="/reports/reorder?format=comics{{ $ctLoc ? '&location_id=' . $ctLoc : '' }}" style="padding:9px 18px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;{{ $ctOn === 'comics' ? 'background:#1F1B16;color:#FFE8A3;' : 'background:#fff;color:#1F1B16;border:1px solid #E1D7C4;' }}">Comics &amp; magazines</a>
    <a href="/reports/shelf-count/missing{{ $ctLoc ? '?location_id=' . $ctLoc : '' }}" style="padding:9px 18px;border-radius:999px;font-weight:700;font-size:15px;text-decoration:none;{{ $ctOn === 'missing' ? 'background:#1F1B16;color:#FFE8A3;' : 'background:#fff;color:#1F1B16;border:1px solid #E1D7C4;' }}">Missing list</a>
</div>
