@if(!session('business.enable_price_tax'))
  @php
    $default = 0;
    $class = 'hide';
  @endphp
@else
  @php
    $default = null;
    $class = '';
  @endphp
@endif

@php
    // Build a category_id => typical cost map from the cost-price-rules.
    // Used to render a "Typical: $X" hint that updates when category changes.
    $costRules = \App\Http\Controllers\CostPriceRulesController::RULES;
    $businessId = session('user.business_id');
    $categoryCostMap = [];
    if ($businessId) {
        $cats = \DB::table('categories')
            ->where('business_id', $businessId)
            ->where('parent_id', 0)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);
        foreach ($cats as $cat) {
            $needle = strtolower(trim($cat->name));
            foreach ($costRules as $rule) {
                if (in_array($needle, $rule['match'])) {
                    $categoryCostMap[$cat->id] = ['cost' => $rule['cost'], 'label' => $rule['label']];
                    break;
                }
            }
        }
    }
@endphp

<div class="col-sm-12"><br>
    <div class="table-responsive">
    <table class="table table-bordered add-product-price-table table-condensed {{$class}}">
        <tr>
          <th>Cost (what you paid)</th>
          <th>@lang('product.profit_percent') @show_tooltip(__('tooltip.profit_percent'))</th>
          <th>@lang('product.default_selling_price')</th>
        </tr>
        @foreach($product_deatails->variations as $variation )
            @if($loop->first)
                <tr>
                    <td>
                        <input type="hidden" name="single_variation_id" value="{{$variation->id}}">

                        {{-- Nivessa has a resale certificate — purchase prices have no sales tax,
                             so the ex-tax field is kept hidden and mirrored to inc-tax on save. --}}
                        {!! Form::text('single_dpp', @num_format($variation->default_purchase_price), [
                            'class' => 'form-control input-sm dpp input_number',
                            'required' => true,
                            'style' => 'display:none;',
                        ]) !!}

                        <div class="col-sm-12">
                          {!! Form::label('single_dpp_inc_tax', 'Cost (what you paid):*') !!}
                          {!! Form::text('single_dpp_inc_tax', @num_format($variation->dpp_inc_tax), ['class' => 'form-control input-sm dpp_inc_tax input_number', 'placeholder' => 'What you paid', 'required']); !!}
                          <small class="help-block" id="cost_typical_hint" style="margin-top:4px; font-size:12px; color:#8E8273;">
                              Pick a category to see its typical cost.
                          </small>
                        </div>
                    </td>

                    <td>
                        <br/>
                        {!! Form::text('profit_percent', @num_format($variation->profit_percent), ['class' => 'form-control input-sm input_number', 'id' => 'profit_percent', 'required']); !!}
                    </td>

                    <td>
                        <label><span class="dsp_label"></span></label>
                        {!! Form::text('single_dsp', @num_format($variation->default_sell_price), ['class' => 'form-control input-sm dsp input_number', 'placeholder' => __('product.exc_of_tax'), 'id' => 'single_dsp', 'required']); !!}

                        {!! Form::text('single_dsp_inc_tax', @num_format($variation->sell_price_inc_tax), ['class' => 'form-control input-sm hide input_number', 'placeholder' => __('product.inc_of_tax'), 'id' => 'single_dsp_inc_tax', 'required']); !!}
                    </td>


                </tr>
            @endif
        @endforeach
    </table>
    </div>
</div>

<script>
(function () {
    // Map of category_id => { cost, label } for the typical-cost hint.
    var costMap = {!! json_encode($categoryCostMap, JSON_NUMERIC_CHECK) !!};

    function updateCostHint() {
        var $hint = $('#cost_typical_hint');
        if (!$hint.length) return;

        var catId = $('input#category_id').val() || $('select#category_id').val();
        if (!catId) {
            $hint.text('Pick a category to see its typical cost.').css('color', '#8E8273');
            return;
        }
        var entry = costMap[catId];
        if (!entry) {
            $hint.html('No typical cost on file for this category — enter the actual amount you paid.').css('color', '#8E8273');
            return;
        }
        var cost = parseFloat(entry.cost).toFixed(2);
        $hint.html('Typical cost for <strong>' + entry.label + '</strong>: <strong>$' + cost + '</strong>. Use this if you don\'t remember the exact amount.').css('color', '#5A4410');
    }

    $(document).ready(function () {
        updateCostHint();
        // Category combo (visible) writes to hidden #category_id when picked,
        // and the visible select#category_id is what's submitted on edit.
        $(document).on('change', '#category_combo, #category_id, #sub_category_id', function () {
            // Defer one tick so the hidden category_id field is updated first.
            setTimeout(updateCostHint, 50);
        });
    });
})();
</script>