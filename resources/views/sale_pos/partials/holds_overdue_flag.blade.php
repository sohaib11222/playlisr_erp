{{-- ===========================================================
     Past-due holds flag (POS sidebar), Jon 2026-10-23.

     "N holds past due at this store" with a link to /holds filtered to
     past due, so someone pulls them from the hold bin. Server-rendered,
     no JS, and every failure is swallowed so it can never break the POS.
     ============================================================ --}}
@php
    $hofCount = 0;
    $hofLocationId = null;
    try {
        $hofLocationId = !empty($default_location) ? (int) $default_location->id : null;
        $hofCount = \App\Services\ProductHolds::overdueCount((int) session('user.business_id'), $hofLocationId);
    } catch (\Throwable $e) {
        $hofCount = 0;
    }
@endphp
@if($hofCount > 0)
<a href="{{ url('/holds') . '?filter=overdue' . ($hofLocationId ? '&location_id=' . $hofLocationId : '') }}" target="_blank" rel="noopener"
   style="display:block;margin:0 0 10px;padding:8px 12px;border-radius:8px;border:1px solid #E6C3C3;background:#F8E9E9;color:#7A1F1F;font-family:'Inter Tight',system-ui,sans-serif;font-size:12px;font-weight:700;text-decoration:none;">
    <i class="fa fas fa-pause-circle"></i>
    {{ $hofCount }} {{ $hofCount === 1 ? 'hold' : 'holds' }} past due at this store. Pull from the hold bin.
</a>
@endif
