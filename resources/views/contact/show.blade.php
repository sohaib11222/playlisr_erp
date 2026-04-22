@extends('layouts.app')
@section('title', __('contact.view_contact'))

@section('css')
<style>
body.contact-show-page-active .content-wrapper { background: #f4f1ec !important; }
section.content.cp-page { background: transparent !important; padding: 20px 24px; }

/* --- Profile Header --- */
.cp-header {
    background: #fff; border-radius: 12px; padding: 28px 32px; margin-bottom: 22px;
    box-shadow: 0 2px 8px rgba(0,0,0,.06); display: flex; align-items: center; flex-wrap: wrap; gap: 24px;
}
.cp-avatar-wrap { position: relative; width: 96px; height: 96px; flex-shrink: 0; }
.cp-avatar {
    width: 96px; height: 96px; border-radius: 50%; object-fit: cover; background: #e8e4df;
    display: flex; align-items: center; justify-content: center; font-size: 38px; color: #b5a899; overflow: hidden;
    border: 3px solid #f0ebe4;
}
.cp-avatar img { width: 100%; height: 100%; object-fit: cover; }
.cp-avatar-overlay {
    position: absolute; inset: 0; border-radius: 50%; background: rgba(0,0,0,.4);
    opacity: 0; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: opacity .2s;
}
.cp-avatar-wrap:hover .cp-avatar-overlay { opacity: 1; }
.cp-avatar-overlay i { color: #fff; font-size: 20px; }
.cp-info { flex: 1; min-width: 200px; }
.cp-name {
    font-size: 28px; font-weight: 800; color: #1a1a1a; margin: 0 0 4px;
    display: flex; align-items: center; gap: 10px; flex-wrap: wrap; line-height: 1.2;
}
.cp-badge { display: inline-block; padding: 3px 12px; border-radius: 14px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
.cp-badge-vip { background: #f5a623; color: #fff; }
.cp-badge-tier { background: #2d2d2d; color: #fff; }
.cp-subtitle { font-size: 13px; color: #888; margin-bottom: 6px; }
.cp-contact-row { color: #777; font-size: 13px; display: flex; flex-wrap: wrap; gap: 18px; align-items: center; }
.cp-contact-row i { margin-right: 4px; color: #aaa; }

/* --- Snapshot --- */
.cp-snapshot {
    background: linear-gradient(135deg, #e8550a 0%, #f5a623 100%); border-radius: 12px;
    padding: 18px 24px; margin-left: auto; flex-shrink: 0; color: #fff; min-width: 380px;
}
.cp-snapshot-title { font-size: 14px; font-weight: 700; margin-bottom: 10px; opacity: .9; }
.cp-snapshot-grid { display: flex; gap: 20px; }
.cp-stat { text-align: center; flex: 1; }
.cp-stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: .5px; opacity: .8; }
.cp-stat-value { font-size: 22px; font-weight: 800; margin-top: 2px; }

/* --- Switcher --- */
.cp-switcher { margin-bottom: 16px; }

/* --- Cards --- */
.cp-card {
    background: #fff; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,.05);
    padding: 20px 22px; margin-bottom: 18px;
}
.cp-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.cp-card-title { font-size: 17px; font-weight: 800; color: #1a1a1a; margin: 0; }

/* --- Genres --- */
.cp-genre-tag {
    display: inline-block; padding: 5px 16px; border-radius: 20px;
    font-size: 12px; font-weight: 700; margin: 3px 4px 3px 0;
}
.cp-genre-0 { background: #ffe0e0; color: #d32f2f; }
.cp-genre-1 { background: #e0f2e0; color: #2e7d32; }
.cp-genre-2 { background: #e0ecf7; color: #1565c0; }
.cp-genre-3 { background: #fff3e0; color: #e65100; }
.cp-genre-4 { background: #f3e5f5; color: #7b1fa2; }
.cp-genre-5 { background: #e0f7fa; color: #00695c; }
.cp-genre-edit { display: none; }
.cp-genre-edit.active { display: block; margin-top: 10px; }

/* --- Recent Purchases --- */
.cp-recent-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.cp-recent-card {
    display: flex; align-items: center; gap: 14px; background: #faf8f5;
    border-radius: 10px; padding: 12px 16px; transition: box-shadow .15s;
}
.cp-recent-card:hover { box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.cp-recent-img { width: 52px; height: 52px; border-radius: 8px; object-fit: cover; background: #e8e4df; flex-shrink: 0; }
.cp-recent-info { min-width: 0; }
.cp-recent-artist { font-weight: 800; font-size: 13px; color: #1a1a1a; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-recent-album { font-size: 12px; color: #999; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cp-recent-meta { font-size: 11px; color: #bbb; margin-top: 2px; }

/* --- Purchase History (Clean Table) --- */
.cp-history-toolbar { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.cp-history-table { width: 100%; border-collapse: collapse; }
.cp-history-table th {
    text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .4px;
    color: #999; font-weight: 600; padding: 10px 12px; border-bottom: 2px solid #f0ebe4;
}
.cp-history-table td { padding: 12px; border-bottom: 1px solid #f5f2ed; font-size: 13px; color: #444; vertical-align: middle; }
.cp-history-table tr:hover td { background: #fdfbf8; }
.cp-history-item { display: flex; align-items: center; gap: 10px; }
.cp-history-thumb { width: 36px; height: 36px; border-radius: 6px; object-fit: cover; background: #e8e4df; flex-shrink: 0; }
.cp-history-item-text { font-weight: 600; color: #1a1a1a; }
.cp-history-sub { font-size: 11px; color: #aaa; }
.cp-history-amount { font-weight: 700; color: #1a1a1a; }

/* --- Notes --- */
.cp-note-item { padding: 12px 0; border-bottom: 1px solid #f5f2ed; }
.cp-note-item:last-child { border-bottom: none; }
.cp-note-star { color: #f5a623; margin-right: 6px; font-size: 14px; }
.cp-note-heading { font-weight: 700; font-size: 14px; color: #1a1a1a; }
.cp-note-body { font-size: 12px; color: #777; margin-top: 3px; line-height: 1.5; }
.cp-note-meta { font-size: 11px; color: #bbb; margin-top: 4px; }

/* --- Birthday --- */
.cp-birthday { display: flex; align-items: center; gap: 10px; }
.cp-birthday-icon { font-size: 20px; }
.cp-birthday-text { font-weight: 700; font-size: 14px; color: #1a1a1a; }
.cp-birthday-meta { font-size: 11px; color: #bbb; }

/* --- Nivessa Bucks --- */
.cp-bucks-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
.cp-bucks-label { font-size: 17px; font-weight: 800; color: #1a1a1a; }
.cp-bucks-value { font-size: 24px; font-weight: 800; color: #1a1a1a; }
.cp-bucks-progress { height: 10px; border-radius: 5px; background: #f0ebe4; overflow: hidden; }
.cp-bucks-bar { height: 100%; border-radius: 5px; background: linear-gradient(90deg, #f5a623, #e8550a); transition: width .4s; }
.cp-bucks-meta { font-size: 12px; color: #999; margin-top: 6px; }

/* --- Quick Actions --- */
.cp-actions-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cp-action-btn {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    padding: 12px 10px; border-radius: 8px; font-size: 13px; font-weight: 600;
    border: 1.5px solid #e0dcd6; background: #fff; cursor: pointer;
    transition: all .15s; text-decoration: none; color: #444;
}
.cp-action-btn:hover { background: #faf8f5; border-color: #ccc; text-decoration: none; color: #333; }
.cp-action-btn i { font-size: 15px; }
.cp-action-btn.btn-green { border-color: #27ae60; color: #27ae60; }
.cp-action-btn.btn-green:hover { background: #eafaf1; }

/* --- Credits --- */
.cp-credit-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: 13px; border-bottom: 1px solid #f5f2ed; }
.cp-credit-row:last-child { border-bottom: none; }
.cp-credit-val { font-weight: 700; }
.cp-credit-due { color: #d32f2f; }
.cp-credit-positive { color: #2e7d32; }

/* --- Collapsed sections --- */
.cp-more-section { margin-top: 20px; }
.cp-more-toggle {
    display: inline-flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 600;
    color: #888; cursor: pointer; padding: 10px 0; border: none; background: none;
}
.cp-more-toggle:hover { color: #555; }
.cp-more-body { display: none; margin-top: 10px; }
.cp-more-body.open { display: block; }
.cp-more-card { background: #fff; border-radius: 10px; padding: 18px 20px; margin-bottom: 12px; box-shadow: 0 1px 4px rgba(0,0,0,.04); }
.cp-more-card h4 { font-size: 15px; font-weight: 700; margin: 0 0 12px; color: #1a1a1a; }

/* --- Responsive --- */
@media (max-width: 991px) {
    .cp-header { flex-direction: column; align-items: flex-start; }
    .cp-snapshot { margin-left: 0; width: 100%; min-width: unset; }
    .cp-snapshot-grid { flex-wrap: wrap; }
    .cp-recent-grid { grid-template-columns: 1fr; }
}
</style>
@endsection

@section('content')

<section class="content no-print cp-page">

<input type="hidden" id="sell_list_filter_customer_id" value="{{$contact->id}}">
<input type="hidden" id="purchase_list_filter_supplier_id" value="{{$contact->id}}">

{{-- Contact Switcher --}}
<div class="row cp-switcher">
    <div class="col-md-3 col-md-offset-9">
        {!! Form::select('contact_id', $contact_dropdown, $contact->id, ['class' => 'form-control select2', 'id' => 'contact_id', 'style' => 'width:100%']) !!}
    </div>
</div>

{{-- ==================== PROFILE HEADER ==================== --}}
<div class="cp-header">
    <div class="cp-avatar-wrap">
        <div class="cp-avatar">
            @if($contact->avatar_url)
                <img src="{{ $contact->avatar_url }}" alt="" id="cp_avatar_img">
            @else
                <i class="fa fa-user" id="cp_avatar_icon"></i>
                <img src="" alt="" id="cp_avatar_img" style="display:none;">
            @endif
        </div>
        <label class="cp-avatar-overlay" for="cp_avatar_input"><i class="fa fa-camera"></i></label>
        <input type="file" id="cp_avatar_input" accept="image/*" style="display:none;">
    </div>

    <div class="cp-info">
        <h2 class="cp-name">
            {{ $contact->name }}
            @if(!empty($current_tier))
                <span class="cp-badge cp-badge-tier">{{ $current_tier->name }}</span>
            @endif
        </h2>
        @if(!empty($contact->loyalty_tier))
            <div class="cp-subtitle">{{ $contact->loyalty_tier }}</div>
        @endif
        <div class="cp-contact-row">
            @if($contact->mobile)
                <span><i class="fa fa-phone"></i> {{ $contact->mobile }}</span>
            @endif
            @if($contact->email)
                <span><i class="fa fa-envelope"></i> {{ $contact->email }}</span>
            @endif
            @if($contact->last_purchase_date)
                <span><i class="fa fa-clock-o"></i> Last visit: {{ \Carbon\Carbon::parse($contact->last_purchase_date)->format('M d, Y') }}</span>
            @endif
        </div>
    </div>

    <div class="cp-snapshot">
        <div class="cp-snapshot-title">Customer Snapshot</div>
        <div class="cp-snapshot-grid">
            <div class="cp-stat">
                <div class="cp-stat-label">Lifetime Spend</div>
                <div class="cp-stat-value"><span class="display_currency" data-currency_symbol="true">{{ $contact->total_invoice ?? 0 }}</span></div>
            </div>
            <div class="cp-stat">
                <div class="cp-stat-label">{{ session('business.rp_name') ?? 'Nivessa Bucks' }}</div>
                <div class="cp-stat-value">{{ $contact->total_rp ?? 0 }}</div>
            </div>
            <div class="cp-stat">
                <div class="cp-stat-label">Avg. Order</div>
                <div class="cp-stat-value"><span class="display_currency" data-currency_symbol="true">{{ number_format($avg_order, 2) }}</span></div>
            </div>
            <div class="cp-stat">
                <div class="cp-stat-label">Visits (90d)</div>
                <div class="cp-stat-value">{{ $visits_90d }}</div>
            </div>
        </div>
    </div>
</div>

{{-- Print block (hidden) --}}
<div class="hide print_table_part">
    <style>.info_col { width: 25%; float: left; padding: 0 10px; }</style>
    <div style="width:100%;">
        <div class="info_col">@include('contact.contact_basic_info')</div>
        <div class="info_col">@include('contact.contact_more_info')</div>
        @if($contact->type != 'customer')
            <div class="info_col">@include('contact.contact_tax_info')</div>
        @endif
        <div class="info_col">@include('contact.contact_payment_info')</div>
    </div>
</div>

{{-- ==================== TWO-COLUMN BODY ==================== --}}
<div class="row">

{{-- ========== LEFT COLUMN ========== --}}
<div class="col-md-8">

    {{-- Favorite Genres --}}
    @if(in_array($contact->type, ['customer', 'both']))
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Favorite Genres</h3>
            <a href="#" id="cp_genre_edit_btn" style="font-size:13px; color:#e8550a; font-weight:600;">Edit</a>
        </div>
        <div id="cp_genre_display">
            @if(!empty($contact->favorite_genres))
                @foreach($contact->favorite_genres as $i => $genre)
                    <span class="cp-genre-tag cp-genre-{{ $i % 6 }}">{{ $genre }}</span>
                @endforeach
            @else
                <span style="color:#bbb; font-size:13px;">No genres added yet.</span>
            @endif
        </div>
        <div class="cp-genre-edit" id="cp_genre_form">
            <div class="input-group">
                <input type="text" class="form-control" id="cp_genre_input" placeholder="e.g. Pop, Indie, Soundtracks" value="{{ !empty($contact->favorite_genres) ? implode(', ', $contact->favorite_genres) : '' }}">
                <span class="input-group-btn">
                    <button class="btn btn-primary btn-flat" id="cp_genre_save">Save</button>
                    <button class="btn btn-default btn-flat" id="cp_genre_cancel">Cancel</button>
                </span>
            </div>
        </div>
    </div>
    @endif

    {{-- Recent Purchases --}}
    @if($recent_purchases->count() > 0)
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Recent Purchases</h3>
        </div>
        <div class="cp-recent-grid">
            @foreach($recent_purchases as $rp)
                @php
                    $img = !empty($rp->product_image) ? asset('/uploads/img/' . rawurlencode($rp->product_image)) : asset('/img/default.png');
                    // Resolve display artist + album with graceful fallbacks:
                    //   1) Product.artist + Product.name (normal POS sale)
                    //   2) legacy_artist + legacy_title (historical imports)
                    //   3) Split "ARTIST / ALBUM" out of product_name if the
                    //      line came in as a single combined string
                    //   4) "—" for artist so we stop showing "Unknown Artist"
                    //      on every imported row (Sarah 2026-04-22)
                    $displayArtist = trim((string) ($rp->artist ?? ''));
                    $displayAlbum  = trim((string) ($rp->product_name ?? ''));
                    if ($displayArtist === '' && !empty($rp->legacy_artist)) {
                        $displayArtist = trim((string) $rp->legacy_artist);
                    }
                    if (!empty($rp->legacy_title) && ($displayAlbum === '' || $displayAlbum === 'Product')) {
                        $displayAlbum = trim((string) $rp->legacy_title);
                    }
                    if ($displayArtist === '' && strpos($displayAlbum, ' / ') !== false) {
                        [$a, $b] = array_map('trim', explode(' / ', $displayAlbum, 2));
                        if ($a !== '' && $b !== '') {
                            $displayArtist = $a;
                            $displayAlbum = $b;
                        }
                    }
                    if ($displayArtist === '') $displayArtist = '—';
                    if ($displayAlbum === '')  $displayAlbum = 'Product';
                @endphp
                <div class="cp-recent-card">
                    <img src="{{ $img }}" class="cp-recent-img" alt="">
                    <div class="cp-recent-info">
                        <div class="cp-recent-artist">{{ $displayArtist }}</div>
                        <div class="cp-recent-album">{{ $displayAlbum }}</div>
                        <div class="cp-recent-meta">{{ \Carbon\Carbon::parse($rp->transaction_date)->format('M d') }} &bull; <span class="display_currency" data-currency_symbol="true">{{ $rp->unit_price_inc_tax }}</span></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Purchase History --}}
    @if(in_array($contact->type, ['customer', 'both']))
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Purchase History</h3>
            <div class="cp-history-toolbar">
                <a href="{{action('SellController@create')}}" class="btn btn-sm" style="background:#27ae60; color:#fff; border:none; border-radius:6px; font-weight:600;" target="_blank"><i class="fa fa-plus"></i> New Sale</a>
            </div>
        </div>
        @if($purchase_history->count() > 0)
        <table class="cp-history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Item</th>
                    <th>Amount</th>
                    <th>Location</th>
                    <th>Staff</th>
                </tr>
            </thead>
            <tbody>
                @foreach($purchase_history as $ph)
                    @php
                        $phImg = !empty($ph->product_image) ? asset('/uploads/img/' . rawurlencode($ph->product_image)) : asset('/img/default.png');
                        $itemName = '';
                        if (!empty($ph->artist)) {
                            $itemName = $ph->artist . ' — ' . ($ph->product_name ?: '');
                        } else {
                            $itemName = $ph->product_name ?: 'Item';
                        }
                    @endphp
                    <tr>
                        <td style="white-space:nowrap; color:#999;">{{ \Carbon\Carbon::parse($ph->transaction_date)->format('M d') }}</td>
                        <td>
                            <div class="cp-history-item">
                                <img src="{{ $phImg }}" class="cp-history-thumb" alt="">
                                <div>
                                    <div class="cp-history-item-text">{{ \Illuminate\Support\Str::limit($itemName, 40) }}</div>
                                    @if($ph->quantity > 1)
                                        <div class="cp-history-sub">{{ $ph->quantity }} items</div>
                                    @endif
                                </div>
                            </div>
                        </td>
                        <td class="cp-history-amount"><span class="display_currency" data-currency_symbol="true">{{ $ph->unit_price_inc_tax * $ph->quantity }}</span></td>
                        <td style="color:#888;">{{ $ph->location_name ?: '-' }}</td>
                        <td style="color:#888;">{{ trim($ph->staff_name) ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @else
            <p style="color:#bbb; font-size:13px; text-align:center; padding:20px;">No purchase history yet.</p>
        @endif
    </div>
    @endif

</div>

{{-- ========== RIGHT COLUMN ========== --}}
<div class="col-md-4">

    {{-- Customer Notes --}}
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Customer Notes</h3>
            <button class="btn btn-sm btn-default cp-add-note-btn" style="border-radius:6px; font-weight:600;"><i class="fa fa-plus"></i> Add</button>
        </div>
        <div id="cp_notes_list">
            @if($customer_notes->count() > 0)
                @foreach($customer_notes as $note)
                    <div class="cp-note-item">
                        <div>
                            <span class="cp-note-star"><i class="fa fa-star"></i></span>
                            <span class="cp-note-heading">{{ $note->heading ?? '' }}</span>
                        </div>
                        @if(!empty($note->description))
                            <div class="cp-note-body">{{ \Illuminate\Support\Str::limit(strip_tags($note->description), 140) }}</div>
                        @endif
                        <div class="cp-note-meta">
                            {{ $note->createdBy->first_name ?? '' }} {{ $note->createdBy->last_name ?? '' }}
                            &bull; {{ \Carbon\Carbon::parse($note->created_at)->format('M d') }}
                        </div>
                    </div>
                @endforeach
            @else
                <p style="color:#bbb; font-size:13px;">No notes yet.</p>
            @endif
        </div>
        <div style="display:none;">
            @include('contact.partials.documents_and_notes_tab')
        </div>
    </div>

    {{-- Birthday --}}
    @if(!empty($contact->dob))
    <div class="cp-card">
        <div class="cp-birthday">
            <span class="cp-birthday-icon">&#11088;</span>
            <div>
                <div class="cp-birthday-text">Birthday: {{ \Carbon\Carbon::parse($contact->dob)->format('M d') }}</div>
            </div>
        </div>
    </div>
    @endif

    {{-- Nivessa Bucks --}}
    @if(in_array($contact->type, ['customer', 'both']) && session('business.enable_rp'))
    <div class="cp-card">
        <div class="cp-bucks-row">
            <span class="cp-bucks-label">{{ session('business.rp_name') ?? 'Nivessa Bucks' }}</span>
            <span class="cp-bucks-value">{{ $contact->total_rp ?? 0 }}</span>
            @if(!empty($current_tier))
                <span class="cp-badge cp-badge-tier" style="font-size:10px;">{{ $current_tier->name }}</span>
            @endif
        </div>
        <div class="cp-bucks-progress">
            <div class="cp-bucks-bar" style="width:{{ $tier_progress }}%;"></div>
        </div>
        @if(!empty($next_tier))
            <div class="cp-bucks-meta">{{ $tier_progress }}% to {{ $next_tier->name }} ({{ number_format($next_tier->min_lifetime_purchases, 0) }})</div>
        @else
            <div class="cp-bucks-meta">Top tier reached</div>
        @endif
        @if(isset($gift_cards) && $gift_cards->count() > 0)
            <div style="margin-top:12px; padding-top:10px; border-top:1px solid #f0ebe4;">
                <strong style="font-size:13px;"><i class="fa fa-credit-card" style="color:#27ae60;"></i> Gift Cards: <span class="display_currency" data-currency_symbol="true">{{ $total_gift_card_balance ?? 0 }}</span></strong>
                <span style="font-size:12px; color:#999;"> ({{ $gift_cards->count() }} active)</span>
            </div>
        @endif
    </div>
    @endif

    {{-- Quick Actions --}}
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Quick Actions</h3>
        </div>
        <div class="cp-actions-grid">
            <a class="cp-action-btn cp-add-note-btn"><i class="fa fa-sticky-note"></i> Add Note</a>
            @if(in_array($contact->type, ['customer', 'both']) && auth()->user()->can('customer.update'))
                <a href="#" class="cp-action-btn btn-green cp-add-store-credit" data-contact-id="{{ $contact->id }}"><i class="fa fa-plus-circle"></i> Add Credit</a>
            @else
                <a class="cp-action-btn btn-green" style="opacity:.4; cursor:default;"><i class="fa fa-plus-circle"></i> Add Credit</a>
            @endif
            @if($contact->email)
                <a href="mailto:{{ $contact->email }}" class="cp-action-btn"><i class="fa fa-envelope"></i> Send Email</a>
            @else
                <a class="cp-action-btn" style="opacity:.4; cursor:default;"><i class="fa fa-envelope"></i> Send Email</a>
            @endif
            <a class="cp-action-btn" onclick="window.print();"><i class="fa fa-print"></i> Print Receipt</a>
        </div>
    </div>

    {{-- Credits & Adjustments --}}
    <div class="cp-card">
        <div class="cp-card-header">
            <h3 class="cp-card-title">Credits & Adjustments</h3>
            <button type="button" class="btn btn-xs btn-default" data-toggle="modal" data-target="#add_discount_modal" style="border-radius:6px; font-weight:600;">Add</button>
        </div>
        @if(in_array($contact->type, ['customer', 'both']))
            <div class="cp-credit-row"><span>Total Sales</span><span class="cp-credit-val"><span class="display_currency" data-currency_symbol="true">{{ $contact->total_invoice ?? 0 }}</span></span></div>
            <div class="cp-credit-row"><span>Total Paid</span><span class="cp-credit-val"><span class="display_currency" data-currency_symbol="true">{{ $contact->invoice_received ?? 0 }}</span></span></div>
            <div class="cp-credit-row"><span>Due</span><span class="cp-credit-val cp-credit-due"><span class="display_currency" data-currency_symbol="true">{{ ($contact->total_invoice ?? 0) - ($contact->invoice_received ?? 0) }}</span></span></div>
        @endif
        @if(in_array($contact->type, ['supplier', 'both']))
            <div class="cp-credit-row"><span>Total Purchase</span><span class="cp-credit-val"><span class="display_currency" data-currency_symbol="true">{{ $contact->total_purchase ?? 0 }}</span></span></div>
            <div class="cp-credit-row"><span>Purchase Paid</span><span class="cp-credit-val"><span class="display_currency" data-currency_symbol="true">{{ $contact->purchase_paid ?? 0 }}</span></span></div>
        @endif
        <div class="cp-credit-row">
            <span>Store Credit <small style="color:#9ca3af;font-weight:400;">(available to apply at checkout)</small></span>
            <span class="cp-credit-val cp-credit-positive"><span class="display_currency" data-currency_symbol="true" id="cp_advance_balance">{{ $contact->balance ?? 0 }}</span></span>
        </div>

        {{-- Store-credit audit trail — Sarah 2026-04-22: "how does this guy have
             $125 store credit?" We now append a line to contacts.balance_notes
             every time someone uses the green Add or yellow Adjust button, so
             the history is visible at a glance. Legacy credit (added before
             this audit existed) will show nothing here — the balance is real
             but its origin predates tracking. --}}
        @if(!empty($contact->balance_notes))
            <div style="margin-top:14px; padding:10px 12px; background:#f9fafb; border:1px solid #e5e7eb; border-radius:8px;">
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:.06em; color:#6b7280; font-weight:700; margin-bottom:6px;">
                    Credit history
                </div>
                <pre style="margin:0; font-size:12px; color:#374151; font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,monospace; white-space:pre-wrap; word-break:break-word; background:transparent; border:none; padding:0;">{{ trim($contact->balance_notes) }}</pre>
            </div>
        @elseif(($contact->balance ?? 0) > 0)
            <div style="margin-top:14px; padding:10px 12px; background:#fff7ed; border:1px solid #fed7aa; border-radius:8px; font-size:12px; color:#9a3412;">
                <strong>No credit history yet.</strong> This balance of
                <span class="display_currency" data-currency_symbol="true">{{ $contact->balance }}</span>
                was added before audit tracking existed (pre-2026-04-22). Every
                credit add / adjust from today onward will log a line here with
                the cashier's name, amount, and reason.
            </div>
        @endif
    </div>

</div>
</div>

{{-- ==================== MORE SECTIONS (Hidden by default) ==================== --}}
<div class="cp-more-section">
    <button class="cp-more-toggle" id="cp_more_toggle">
        <i class="fa fa-chevron-down"></i> Show more details (Payments, Reward Points, Activities...)
    </button>
    <div class="cp-more-body" id="cp_more_body">

        {{-- Payments --}}
        <div class="cp-more-card">
            <h4><i class="fas fa-money-bill-alt" style="color:#27ae60;"></i> Payments</h4>
            <div id="contact_payments_div" style="min-height:60px; max-height:500px; overflow-y:auto;"></div>
        </div>

        {{-- Reward Points Log --}}
        @if(in_array($contact->type, ['customer', 'both']) && session('business.enable_rp'))
        <div class="cp-more-card">
            <h4><i class="fas fa-gift" style="color:#f5a623;"></i> {{ session('business.rp_name') ?? 'Reward Points' }} Log</h4>
            @if(isset($gift_cards) && $gift_cards->count() > 0)
                <div class="table-responsive" style="margin-bottom:12px;">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead><tr><th>Card Number</th><th>Balance</th><th>Initial Value</th><th>Expiry</th><th>Status</th></tr></thead>
                        <tbody>
                            @foreach($gift_cards as $card)
                                <tr>
                                    <td><strong>{{$card->card_number}}</strong></td>
                                    <td>@format_currency($card->balance)</td>
                                    <td>@format_currency($card->initial_value)</td>
                                    <td>{{$card->expiry_date ? \Carbon\Carbon::parse($card->expiry_date)->format('Y-m-d') : 'No expiry'}}</td>
                                    <td><span class="label label-success">{{ucfirst($card->status)}}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            <div class="table-responsive">
                <table class="table table-bordered table-striped table-condensed" id="rp_log_table" width="100%">
                    <thead><tr>
                        <th>@lang('messages.date')</th>
                        <th>@lang('sale.invoice_no')</th>
                        <th>@lang('lang_v1.earned')</th>
                        <th>@lang('lang_v1.redeemed')</th>
                    </tr></thead>
                </table>
            </div>
        </div>
        @endif

        {{-- Purchases (supplier) --}}
        @if(in_array($contact->type, ['both', 'supplier']))
        <div class="cp-more-card">
            <h4><i class="fas fa-arrow-circle-down" style="color:#e8550a;"></i> Purchases (Supplier)</h4>
            <div class="row">
                <div class="col-md-3">
                    <div class="form-group">
                        {!! Form::label('purchase_list_filter_date_range', __('report.date_range') . ':') !!}
                        {!! Form::text('purchase_list_filter_date_range', null, ['placeholder' => __('lang_v1.select_a_date_range'), 'class' => 'form-control', 'readonly']) !!}
                    </div>
                </div>
                <div class="col-md-12">@include('purchase.partials.purchase_table')</div>
            </div>
        </div>
        <div class="cp-more-card">
            <h4><i class="fas fa-hourglass-half" style="color:#f5a623;"></i> Stock Report</h4>
            @include('contact.partials.stock_report_tab')
        </div>
        @endif

        {{-- Activities --}}
        <div class="cp-more-card">
            <h4><i class="fas fa-pen-square" style="color:#888;"></i> Activities</h4>
            @include('activity_log.activities')
        </div>

        {{-- Module tabs --}}
        @if(!empty($contact_view_tabs))
            @foreach($contact_view_tabs as $key => $tabs)
                @foreach($tabs as $index => $value)
                    @if(!empty($value['tab_content_path']))
                        @php $tab_data = !empty($value['tab_data']) ? $value['tab_data'] : []; @endphp
                        <div class="cp-more-card">
                            <h4>{{ $value['tab_menu_heading'] ?? 'Module' }}</h4>
                            @include($value['tab_content_path'], $tab_data)
                        </div>
                    @endif
                @endforeach
            @endforeach
        @endif

    </div>
</div>

</section>

{{-- Modals --}}
<div class="modal fade payment_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
<div class="modal fade edit_payment_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
<div class="modal fade pay_contact_due_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
<div class="modal fade" id="edit_ledger_discount_modal" tabindex="-1" role="dialog" aria-labelledby="gridSystemModalLabel"></div>
@include('ledger_discount.create')

@stop

@section('javascript')
<script type="text/javascript">
$(document).ready(function(){
    $('body').addClass('contact-show-page-active');

    // --- Show more toggle ---
    $('#cp_more_toggle').click(function(){
        var $body = $('#cp_more_body');
        $body.toggleClass('open');
        var isOpen = $body.hasClass('open');
        $(this).find('i').toggleClass('fa-chevron-down fa-chevron-up');
        $(this).html(isOpen
            ? '<i class="fa fa-chevron-up"></i> Hide details'
            : '<i class="fa fa-chevron-down"></i> Show more details (Payments, Reward Points, Activities...)'
        );
        if (isOpen) { get_contact_payments(); }
    });

    // --- Contact switcher ---
    $('#contact_id').change(function(){
        if ($(this).val()) window.location = "{{url('/contacts')}}/" + $(this).val();
    });

    // --- Avatar upload ---
    $('#cp_avatar_input').change(function(){
        var file = this.files[0];
        if (!file) return;
        var fd = new FormData();
        fd.append('avatar', file);
        $.ajax({
            method: 'POST', url: '/contacts/{{ $contact->id }}/avatar',
            data: fd, processData: false, contentType: false, dataType: 'json',
            success: function(r){
                if (r.success) {
                    $('#cp_avatar_img').attr('src', r.avatar_url).show();
                    $('#cp_avatar_icon').hide();
                    toastr.success('Avatar updated.');
                } else { toastr.error(r.msg || 'Upload failed.'); }
            },
            error: function(){ toastr.error('Upload failed.'); }
        });
    });

    // --- Genre editing ---
    $('#cp_genre_edit_btn').click(function(e){ e.preventDefault(); $('#cp_genre_form').addClass('active'); });
    $('#cp_genre_cancel').click(function(){ $('#cp_genre_form').removeClass('active'); });
    $('#cp_genre_save').click(function(){
        var val = $('#cp_genre_input').val();
        $.ajax({
            method: 'POST', url: '/contacts/{{ $contact->id }}/genres',
            data: { genres: val }, dataType: 'json',
            success: function(r){
                if (r.success) {
                    var html = '';
                    if (r.genres && r.genres.length) {
                        for (var i = 0; i < r.genres.length; i++) {
                            html += '<span class="cp-genre-tag cp-genre-' + (i % 6) + '">' + $('<span>').text(r.genres[i]).html() + '</span>';
                        }
                    } else { html = '<span style="color:#bbb;font-size:13px;">No genres added yet.</span>'; }
                    $('#cp_genre_display').html(html);
                    $('#cp_genre_form').removeClass('active');
                    toastr.success('Genres updated.');
                } else { toastr.error(r.msg || 'Save failed.'); }
            }
        });
    });

    // --- Add Note ---
    $(document).on('click', '.cp-add-note-btn', function(e){
        e.preventDefault();
        var $btn = $('.document_note_body').find('.add_note_btn, a[data-href*="note-documents/create"]').first();
        if ($btn.length) { $btn.trigger('click'); } else { toastr.info('Notes system loading...'); }
    });

    // --- Add Store Credit ---
    $(document).on('click', '.cp-add-store-credit', function(e){
        e.preventDefault();
        var contactId = $(this).data('contact-id');
        if (!contactId) {
            toastr.error('Contact ID not found.');
            return;
        }

        swal({
            title: 'Add Store Credit',
            text: 'Enter amount to add to customer credit balance:',
            content: {
                element: 'input',
                attributes: {
                    type: 'number',
                    step: '0.01',
                    min: '0.01',
                    placeholder: 'Amount'
                }
            },
            buttons: true
        }).then(function(value){
            var amount = parseFloat(value) || 0;
            if (amount <= 0) {
                return;
            }

            $.ajax({
                method: 'POST',
                url: '/contacts/' + contactId + '/store-credit',
                dataType: 'json',
                data: {
                    amount: amount,
                    _token: $('meta[name="csrf-token"]').attr('content')
                },
                success: function(result){
                    if (result.success) {
                        toastr.success(result.msg);
                        if ($('#cp_advance_balance').length) {
                            $('#cp_advance_balance').text(__currency_trans_from_en(result.new_balance || 0, true));
                        }
                    } else {
                        toastr.error(result.msg || 'Unable to add store credit.');
                    }
                },
                error: function(){
                    toastr.error('Unable to add store credit.');
                }
            });
        });
    });

    // --- Reward Points log ---
    if ($('#rp_log_table').length) {
        $('#rp_log_table').DataTable({
            processing: true, serverSide: true, aaSorting: [[0, 'desc']],
            ajax: '/sells?customer_id={{ $contact->id }}&rewards_only=true',
            columns: [
                { data: 'transaction_date', name: 'transactions.transaction_date' },
                { data: 'invoice_no', name: 'transactions.invoice_no' },
                { data: 'rp_earned', name: 'transactions.rp_earned' },
                { data: 'rp_redeemed', name: 'transactions.rp_redeemed' },
            ]
        });
    }

    // --- Supplier stock report ---
    if ($('#supplier_stock_report_table').length) {
        var srt = $('#supplier_stock_report_table').DataTable({
            processing: true, serverSide: true,
            ajax: { url: "{{action('ContactController@getSupplierStockReport', [$contact->id])}}", data: function(d){ d.location_id = $('#sr_location_id').val(); } },
            columns: [
                { data: 'product_name', name: 'p.name' }, { data: 'sub_sku', name: 'v.sub_sku' },
                { data: 'purchase_quantity', name: 'purchase_quantity', searchable: false },
                { data: 'total_quantity_sold', name: 'total_quantity_sold', searchable: false },
                { data: 'total_quantity_transfered', name: 'total_quantity_transfered', searchable: false },
                { data: 'total_quantity_returned', name: 'total_quantity_returned', searchable: false },
                { data: 'current_stock', name: 'current_stock', searchable: false },
                { data: 'stock_price', name: 'stock_price', searchable: false }
            ],
            fnDrawCallback: function(){ __currency_convert_recursively($('#supplier_stock_report_table')); },
        });
        $('#sr_location_id').change(function(){ srt.ajax.reload(); });
    }

    // --- Discount form ---
    $('#discount_date').datetimepicker({ format: moment_date_format + ' ' + moment_time_format, ignoreReadonly: true });

    $(document).on('submit', 'form#add_discount_form, form#edit_discount_form', function(e){
        e.preventDefault();
        var form = $(this);
        $.ajax({
            method: 'POST', url: $(this).attr('action'), dataType: 'json', data: form.serialize(),
            success: function(result){
                if (result.success === true) {
                    $('div#add_discount_modal').modal('hide');
                    $('div#edit_ledger_discount_modal').modal('hide');
                    toastr.success(result.msg);
                    form[0].reset();
                    form.find('button[type="submit"]').removeAttr('disabled');
                } else { toastr.error(result.msg); }
            },
        });
    });

    $(document).on('click', 'button.delete_ledger_discount', function(){
        var href = $(this).data('href');
        swal({ title: LANG.sure, icon: 'warning', buttons: true, dangerMode: true }).then(function(ok){
            if (!ok) return;
            $.ajax({
                method: 'DELETE', url: href, dataType: 'json',
                success: function(r){ if (r.success) { toastr.success(r.msg); } else { toastr.error(r.msg); } },
            });
        });
    });
});

$(document).on('shown.bs.modal', '#edit_ledger_discount_modal', function(){
    $('#edit_ledger_discount_modal').find('#edit_discount_date').datetimepicker({ format: moment_date_format + ' ' + moment_time_format, ignoreReadonly: true });
});

$(document).on('click', '#contact_payments_pagination a', function(e){
    e.preventDefault();
    get_contact_payments($(this).attr('href'));
});

function get_contact_payments(url) {
    if (!url) url = "{{action('ContactController@getContactPayments', [$contact->id])}}";
    $.ajax({ url: url, dataType: 'html', success: function(r){ $('#contact_payments_div').html(r); } });
}
</script>

<script src="{{ asset('js/payment.js?v=' . $asset_v) }}"></script>
@if(in_array($contact->type, ['both', 'supplier']))
    <script src="{{ asset('js/purchase.js?v=' . $asset_v) }}"></script>
@endif
@include('documents_and_notes.document_and_note_js')
@if(!empty($contact_view_tabs))
    @foreach($contact_view_tabs as $key => $tabs)
        @foreach($tabs as $index => $value)
            @if(!empty($value['module_js_path']))
                @include($value['module_js_path'])
            @endif
        @endforeach
    @endforeach
@endif
<script type="text/javascript">
    $(document).ready(function(){
        if ($('#purchase_list_filter_date_range').length) {
            $('#purchase_list_filter_date_range').daterangepicker(dateRangeSettings, function(start, end){
                $('#purchase_list_filter_date_range').val(start.format(moment_date_format) + ' ~ ' + end.format(moment_date_format));
                purchase_table.ajax.reload();
            });
            $('#purchase_list_filter_date_range').on('cancel.daterangepicker', function(){ $(this).val(''); purchase_table.ajax.reload(); });
        }
    });
</script>
@include('sale_pos.partials.subscriptions_table_javascript', ['contact_id' => $contact->id])
@endsection
