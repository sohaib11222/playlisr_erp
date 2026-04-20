<!-- default value -->
@php
    $go_back_url = action('SellPosController@index');
    $transaction_sub_type = '';
    $view_suspended_sell_url = action('SellController@index').'?suspended=1';
    $pos_redirect_url = action('SellPosController@create');
@endphp

@if(!empty($pos_module_data))
    @foreach($pos_module_data as $key => $value)
        @php
            if(!empty($value['go_back_url'])) {
                $go_back_url = $value['go_back_url'];
            }

            if(!empty($value['transaction_sub_type'])) {
                $transaction_sub_type = $value['transaction_sub_type'];
                $view_suspended_sell_url .= '&transaction_sub_type='.$transaction_sub_type;
                $pos_redirect_url .= '?sub_type='.$transaction_sub_type;
            }
        @endphp
    @endforeach
@endif
<input type="hidden" name="transaction_sub_type" id="transaction_sub_type" value="{{$transaction_sub_type}}">
@inject('request', 'Illuminate\Http\Request')
<div class="col-md-12 no-print pos-header">
  <input type="hidden" id="pos_redirect_url" value="{{$pos_redirect_url}}">
  <style>
    /* Nivessa-branded header: cream wordmark on the left, pill colors tied
       to the brand palette (deep brown + mustard). Per-store tint helps a
       cashier tell at a glance whether they're on the Hollywood or Pico
       register — mixups are costly. */
    .pos-header-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 6px 0; }
    .pos-brand-mark { display:inline-flex; align-items:center; gap:8px; padding:6px 14px; border-radius:999px; background:#2b1e16; color:#f5ce3e; font-weight:800; font-size:13px; letter-spacing:2px; text-transform:uppercase; box-shadow:0 1px 2px rgba(0,0,0,.06); }
    .pos-brand-mark::before { content:""; display:inline-block; width:14px; height:14px; border-radius:50%; background:#f5ce3e; box-shadow:inset 0 0 0 3px #2b1e16; }
    .pos-header-pill { display: inline-flex; align-items: center; padding: 8px 16px; border-radius: 999px; line-height: 1.2; white-space: nowrap; box-shadow: 0 1px 2px rgba(0,0,0,0.04); }
    .pos-header-pill i.fa { margin-right: 10px; font-size: 16px; }
    .pos-header-pill-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.8px; opacity: 0.75; margin-right: 8px; font-weight: 700; }
    .pos-header-pill-value { font-size: 16px; font-weight: 700; }
    /* Store chip defaults to brand cream/brown; per-store modifiers override. */
    .pos-header-pill-store { background: #faf0df; color: #5c3c10; }
    .pos-header-pill-store.store-hollywood { background: #fde68a; color: #5c3c10; border: 1px solid #f5ce3e; }
    .pos-header-pill-store.store-pico { background: #fed7aa; color: #7c2d12; border: 1px solid #fb923c; }
    .pos-header-pill-user { background: #e7f7ef; color: #1f7a45; }
    .pos-header-pill-time { background: #f3f4f6; color: #4b5563; }
    .pos-header-pill-store select.form-control { display: inline-block; width: auto; min-width: 150px; height: 28px; padding: 0 8px; font-size: 15px; font-weight: 700; background-color: rgba(255,255,255,0.7); color: inherit; border: 1px solid rgba(0,0,0,0.12); border-radius: 6px; }
  </style>
  <div class="row">
    <div class="col-md-6">
      <div class="pos-header-bar">
        @php
          $header_loc_name = !empty($transaction->location_id) ? ($transaction->location->name ?? '') : ($default_location->name ?? '');
          $header_store_class = stripos($header_loc_name, 'hollywood') !== false ? 'store-hollywood' : (stripos($header_loc_name, 'pico') !== false ? 'store-pico' : '');
        @endphp
        <span class="pos-brand-mark" aria-hidden="true">Nivessa</span>
        <span class="pos-header-pill pos-header-pill-store {{ $header_store_class }}" id="pos-header-store-chip">
          <i class="fa fa-building" aria-hidden="true"></i>
          <span class="pos-header-pill-label">Store</span>
          <span class="pos-header-pill-value">
            @if(empty($transaction->location_id))
              @if(count($business_locations) > 1)
                 {!! Form::select('select_location_id', $business_locations, $default_location->id ?? null , ['class' => 'form-control input-sm',
                  'id' => 'select_location_id',
                  'required', 'autofocus'], $bl_attributes); !!}
              @else
                {{$default_location->name}}
              @endif
            @endif
            @if(!empty($transaction->location_id)) {{$transaction->location->name}} @endif
          </span>
        </span>
        <script>
        (function () {
          // Keep the store chip tint in sync if the cashier switches locations mid-session.
          var sel = document.getElementById('select_location_id');
          var chip = document.getElementById('pos-header-store-chip');
          if (!sel || !chip) return;
          function syncTint() {
            var name = (sel.options[sel.selectedIndex] && sel.options[sel.selectedIndex].text || '').toLowerCase();
            chip.classList.remove('store-hollywood', 'store-pico');
            if (name.indexOf('hollywood') !== -1) chip.classList.add('store-hollywood');
            else if (name.indexOf('pico') !== -1) chip.classList.add('store-pico');
          }
          sel.addEventListener('change', syncTint);
          syncTint();
        })();
        </script>
        <span class="pos-header-pill pos-header-pill-user">
          <i class="fa fa-user" aria-hidden="true"></i>
          <span class="pos-header-pill-label">User</span>
          <span class="pos-header-pill-value">{{ auth()->user()->first_name ?? '' }} {{ auth()->user()->last_name ?? '' }}</span>
        </span>
        <span class="pos-header-pill pos-header-pill-time">
          <i class="fa fa-clock-o" aria-hidden="true"></i>
          <span class="curr_datetime pos-header-pill-value">{{ \Carbon\Carbon::now()->format('m/d/Y h:i A') }}</span>
        </span>
        <i class="fa fa-keyboard hover-q text-muted" aria-hidden="true" data-container="body" data-toggle="popover" data-placement="bottom" data-content="@include('sale_pos.partials.keyboard_shortcuts_details')" data-html="true" data-trigger="hover" data-original-title="" title="" style="cursor: pointer; font-size: 16px;"></i>
      </div>
    </div>
    <div class="col-md-6">
      {{-- Admin / register actions collapsed into a single small dropdown to reclaim
           prime real estate in the POS top bar. They're used a few times a day, not every sale. --}}
      <div class="dropdown pull-right" style="margin: 5px 6px 0 0;">
        <button type="button" class="btn btn-default btn-sm dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false" title="Register / admin actions" style="padding: 6px 12px; font-weight: 600;">
          <i class="fa fa-bars" aria-hidden="true"></i>&nbsp; Admin <span class="caret"></span>
        </button>
        <ul class="dropdown-menu dropdown-menu-right" style="padding: 4px 0; min-width: 200px;">
          @can('view_cash_register')
          <li>
            <a href="#" id="register_details" class="btn-modal" data-container=".register_details_modal" data-href="{{ action('CashRegisterController@getRegisterDetails')}}">
              <i class="fa fa-briefcase text-success" aria-hidden="true"></i>&nbsp; View Register
            </a>
          </li>
          @endcan
          @can('close_cash_register')
          <li>
            <a href="#" id="close_register" class="btn-modal" data-container=".close_register_modal" data-href="{{ action('CashRegisterController@getCloseRegister')}}">
              <i class="fa fa-window-close text-danger" aria-hidden="true"></i>&nbsp; Close Register
            </a>
          </li>
          @endcan
          <li role="separator" class="divider"></li>
          <li>
            <a href="{{$go_back_url}}" title="{{ __('lang_v1.go_back') }}">
              <i class="fa fa-backward text-info" aria-hidden="true"></i>&nbsp; Back to Home
            </a>
          </li>
        </ul>
      </div>



      @if(Module::has('Repair') && $transaction_sub_type != 'repair')
        @include('repair::layouts.partials.pos_header')
      @endif

        @if(in_array('pos_sale', $enabled_modules) && !empty($transaction_sub_type))
          @can('sell.create')
            <a href="{{action('SellPosController@create')}}" title="@lang('sale.pos_sale')" class="btn btn-success btn-flat m-6 btn-xs m-5 pull-right">
              <strong><i class="fa fa-th-large"></i> &nbsp; @lang('sale.pos_sale')</strong>
            </a>
          @endcan
        @endif

    </div>
    
  </div>
</div>
