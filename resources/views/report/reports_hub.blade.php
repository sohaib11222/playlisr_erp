@extends('layouts.app')
@section('title', 'Reports')

@section('content')
<section class="content-header">
    <h1>Reports <small>everything in one place</small></h1>
</section>

<section class="content">
    <style>
        .rh-group { margin-bottom: 24px; }
        .rh-group-head { font-size: 13px; text-transform: uppercase; letter-spacing: 1.2px; color: #475569; font-weight: 800; margin: 6px 0 10px 2px; display: flex; align-items: center; gap: 8px; }
        .rh-group-head i { color: #3b82f6; }
        .rh-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 10px; }
        .rh-card {
            position: relative; padding: 14px 40px 14px 14px;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 10px;
            display: flex; align-items: flex-start; gap: 12px; text-decoration: none !important;
            color: inherit; transition: border-color .08s ease, box-shadow .08s ease, transform .08s ease;
        }
        .rh-card:hover { border-color: #3b82f6; box-shadow: 0 2px 8px rgba(59,130,246,.12); transform: translateY(-1px); color: inherit; }
        .rh-card-icon { flex: 0 0 auto; width: 36px; height: 36px; border-radius: 8px; background: #eff6ff; color: #1d4ed8; display: inline-flex; align-items: center; justify-content: center; font-size: 16px; }
        .rh-card-body { flex: 1 1 auto; min-width: 0; }
        .rh-card-title { font-size: 14px; font-weight: 700; color: #0f172a; line-height: 1.3; }
        .rh-card-desc { font-size: 12px; color: #64748b; margin-top: 2px; line-height: 1.4; }
        .rh-fav-btn {
            position: absolute; top: 10px; right: 10px;
            border: none; background: transparent; color: #cbd5e1;
            font-size: 16px; cursor: pointer; padding: 4px; border-radius: 4px;
        }
        .rh-fav-btn:hover { color: #f59e0b; }
        .rh-fav-btn.is-fav { color: #f59e0b; }
        .rh-favorites .rh-card { background: linear-gradient(135deg, #fef9c3, #fef3c7); border-color: #f59e0b; }
        .rh-favorites .rh-card-icon { background: #fde68a; color: #78350f; }
        .rh-empty-fav { padding: 12px 14px; background: #f8fafc; border: 1px dashed #cbd5e1; border-radius: 10px; color: #64748b; font-size: 13px; }
    </style>

    {{-- Favorites --}}
    <div class="rh-group rh-favorites">
        <div class="rh-group-head"><i class="fa fa-star"></i> Favorites <small style="text-transform:none; letter-spacing:0; color:#94a3b8; font-weight:500; margin-left:4px;">click ☆ on any report to pin it here</small></div>
        @if(count($favorites))
            <div class="rh-grid">
                @foreach($favorites as $r)
                    @include('report.partials.reports_hub_card', ['r' => $r, 'is_fav' => true])
                @endforeach
            </div>
        @else
            <div class="rh-empty-fav">You haven't favorited any reports yet. Click the ☆ icon on any report card below to pin it here.</div>
        @endif
    </div>

    @foreach($catalog as $section_key => $section)
        <div class="rh-group">
            <div class="rh-group-head"><i class="fa {{ $section['icon'] }}"></i> {{ $section['title'] }}</div>
            <div class="rh-grid">
                @foreach($section['reports'] as $r)
                    @include('report.partials.reports_hub_card', ['r' => $r, 'is_fav' => in_array($r['key'], $favorite_keys)])
                @endforeach
            </div>
        </div>
    @endforeach
</section>

@section('javascript')
@parent
<script>
$(function () {
    $(document).on('click', '.rh-fav-btn', function (e) {
        e.preventDefault(); e.stopPropagation();
        var $btn = $(this);
        var key = $btn.data('key');
        $.ajax({
            url: "{{ action('ReportsHubController@toggleFavorite') }}",
            method: 'POST',
            data: { report_key: key, _token: "{{ csrf_token() }}" },
            success: function (resp) {
                if (resp && resp.ok) {
                    $btn.toggleClass('is-fav', !!resp.favorited);
                    $btn.find('i').toggleClass('fa-star', !!resp.favorited).toggleClass('fa-star-o', !resp.favorited);
                    // Reload so the Favorites section reorders itself cleanly
                    setTimeout(function () { window.location.reload(); }, 200);
                }
            }
        });
    });
});
</script>
@endsection
@stop
