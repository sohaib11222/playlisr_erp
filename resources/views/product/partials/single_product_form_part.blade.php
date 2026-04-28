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

<div class="table-responsive">
    <table class="table table-bordered add-product-price-table table-condensed {{$class}}">
        <tr>
          <th>Cost (what you paid)</th>
          <th>@lang('product.profit_percent') @show_tooltip(__('tooltip.profit_percent'))</th>
          <th>@lang('product.default_selling_price')</th>
          @if(empty($quick_add))
            <th>@lang('lang_v1.product_image')</th>
          @endif
        </tr>
        <tr>
          <td>
            <!--<div class="col-sm-6">-->
              <!--{!! Form::label('single_dpp', trans('product.exc_of_tax') . ':*') !!}-->

              <!--{!! Form::text('single_dpp', $default, ['class' => 'form-control input-sm dpp input_number', 'placeholder' => __('product.exc_of_tax'), 'required']); !!}-->
              {!! Form::text('single_dpp', $default, [
                'class' => 'form-control input-sm dpp input_number',
                'placeholder' => __('product.exc_of_tax'),
                'required' => true,
                'value' => 0, // Set value to 0
                'style' => 'display: none;' // Hide the input using inline CSS
               ]) !!}
            <!--</div>-->

            <div class="col-sm-12">
              {!! Form::label('single_dpp_inc_tax', 'Cost (what you paid):*') !!}

              {!! Form::text('single_dpp_inc_tax', $default, ['class' => 'form-control input-sm dpp_inc_tax input_number', 'placeholder' => 'What you paid', 'required']); !!}
              <small class="help-block" id="cost_typical_hint" style="margin-top:4px; font-size:12px; color:#8E8273;">
                  Pick a category to see its typical cost.
              </small>
            </div>
          </td>

          <td>
            <br/>
            {!! Form::text('profit_percent', @num_format($profit_percent), ['class' => 'form-control input-sm input_number', 'id' => 'profit_percent', 'required']); !!}
          </td>

          <td>
            <label><span class="dsp_label">@lang('product.exc_of_tax')</span></label>
            {!! Form::text('single_dsp', $default, ['class' => 'form-control input-sm dsp input_number', 'placeholder' => __('product.exc_of_tax'), 'id' => 'single_dsp', 'required']); !!}

            {!! Form::text('single_dsp_inc_tax', $default, ['class' => 'form-control input-sm hide input_number', 'placeholder' => __('product.inc_of_tax'), 'id' => 'single_dsp_inc_tax', 'required']); !!}
          </td>
          @if(empty($quick_add))
          <td>
              <div class="form-group">
                {!! Form::label('variation_images', __('lang_v1.product_image') . ':') !!}
                {!! Form::file('variation_images[]', ['class' => 'variation_images', 'accept' => 'image/*', 'multiple']); !!}
                <small><p class="help-block">@lang('purchase.max_file_size', ['size' => (config('constants.document_size_limit') / 1000000)]) <br> @lang('lang_v1.aspect_ratio_should_be_1_1')</p></small>
              </div>
          </td>
          @endif
        </tr>
    </table>
</div>

<script>
(function () {
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
        $(document).on('change', '#category_combo, #category_id, #sub_category_id', function () {
            setTimeout(updateCostHint, 50);
        });
    });
})();
</script>