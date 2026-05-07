{{-- Floating "?" tour launcher. Page opts in by calling:
       @include('help.partials.tour_button', ['tourSteps' => $stepsArray])
     where $stepsArray is an array of ['selector' => '#x', 'title' => '...', 'body' => '...'].
     If $tourSteps is empty, this partial renders nothing — safe no-op. --}}
@php
    $tourSteps = $tourSteps ?? [];
    $tourLabel = $tourLabel ?? 'Show Me How';
@endphp

@if(!empty($tourSteps))
<button type="button" id="nv-tour-launch"
        class="nv-tour-launch-btn"
        title="Walk me through this page">
    <i class="fa fa-question-circle"></i>
    <span class="nv-tour-launch-label">{{ $tourLabel }}</span>
</button>

<style>
.nv-tour-launch-btn {
    position: fixed;
    bottom: 22px;
    right: 22px;
    z-index: 9998;
    background: #1a73e8;
    color: #fff;
    border: none;
    border-radius: 999px;
    padding: 10px 18px 10px 14px;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 4px 14px rgba(26,115,232,0.35);
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}
.nv-tour-launch-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(26,115,232,0.45);
}
.nv-tour-launch-btn:focus { outline: none; box-shadow: 0 0 0 3px rgba(26,115,232,0.3); }
.nv-tour-launch-btn i { font-size: 18px; }
@media (max-width: 768px) {
    .nv-tour-launch-btn { padding: 10px 12px; }
    .nv-tour-launch-label { display: none; }
}

/* Tour overlay (controlled by nivessa-help-tour.js) */
.nv-tour-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(15, 20, 30, 0.55);
    z-index: 10000;
    animation: nv-tour-fade-in 0.18s ease;
}
.nv-tour-highlight {
    display: none;
    position: absolute;
    z-index: 10001;
    border-radius: 10px;
    box-shadow:
        0 0 0 4px rgba(26,115,232,0.65),
        0 0 0 9999px rgba(15, 20, 30, 0.55);
    pointer-events: none;
    transition: all 0.22s cubic-bezier(0.4, 0.0, 0.2, 1);
}
.nv-tour-tooltip {
    display: none;
    position: absolute;
    z-index: 10002;
    width: 360px;
    max-width: calc(100vw - 32px);
    background: #fff;
    border-radius: 12px;
    padding: 18px 20px 16px;
    box-shadow: 0 10px 36px rgba(0,0,0,0.25);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    color: #2b3440;
    animation: nv-tour-pop-in 0.22s ease;
}
.nv-tour-tooltip h3 {
    margin: 0 0 8px;
    font-size: 18px;
    font-weight: 700;
    color: #1a1f29;
    letter-spacing: -0.2px;
}
.nv-tour-tooltip .nv-tour-step-counter {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #8a9098;
    font-weight: 600;
    margin-bottom: 4px;
}
.nv-tour-tooltip .nv-tour-body {
    font-size: 14px;
    line-height: 1.55;
    color: #4a5260;
}
.nv-tour-tooltip .nv-tour-body strong { color: #1a1f29; }
.nv-tour-tooltip .nv-tour-body code {
    background: #f4eee0;
    color: #6a5a30;
    padding: 1px 6px;
    border-radius: 4px;
    font-size: 13px;
}
.nv-tour-tooltip .nv-tour-body a { color: #1a73e8; }
.nv-tour-tooltip .nv-tour-actions {
    display: flex;
    gap: 6px;
    margin-top: 14px;
    justify-content: flex-end;
    align-items: center;
}
.nv-tour-tooltip .nv-tour-btn {
    border: 1px solid transparent;
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    background: #f5f3ec;
    color: #4a5260;
    transition: background 0.12s ease;
}
.nv-tour-tooltip .nv-tour-btn:hover { background: #ebe6d8; }
.nv-tour-tooltip .nv-tour-btn-skip { background: transparent; color: #8a9098; }
.nv-tour-tooltip .nv-tour-btn-skip:hover { color: #d9534f; background: transparent; }
.nv-tour-tooltip .nv-tour-btn-next,
.nv-tour-tooltip .nv-tour-btn-done {
    background: #1a73e8;
    color: #fff;
}
.nv-tour-tooltip .nv-tour-btn-next:hover,
.nv-tour-tooltip .nv-tour-btn-done:hover { background: #1561c4; }

@keyframes nv-tour-fade-in {
    from { opacity: 0; }
    to { opacity: 1; }
}
@keyframes nv-tour-pop-in {
    from { opacity: 0; transform: scale(0.96) translateY(4px); }
    to { opacity: 1; transform: scale(1) translateY(0); }
}
</style>

<script>
(function () {
    var steps = {!! json_encode($tourSteps) !!};
    function attach() {
        var btn = document.getElementById('nv-tour-launch');
        if (btn) {
            btn.addEventListener('click', function () {
                if (window.NivessaTour && typeof window.NivessaTour.start === 'function') {
                    window.NivessaTour.start(steps);
                }
            });
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', attach);
    } else {
        attach();
    }
})();
</script>

<script src="{{ asset('js/nivessa-help-tour.js?v=' . ($asset_v ?? '1')) }}" defer></script>
@endif
