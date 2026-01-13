{!! Form::open(['action' => ['LoyaltyTierController@update', $tier->id], 'method' => 'put', 'id' => 'edit_tier_form']) !!}
<div class="modal-body">
    <div class="form-group">
        {!! Form::label('name', 'Name:*') !!}
        {!! Form::text('name', $tier->name, ['class' => 'form-control', 'required']); !!}
    </div>
    <div class="form-group">
        {!! Form::label('description', 'Description:') !!}
        {!! Form::textarea('description', $tier->description, ['class' => 'form-control', 'rows' => 2]); !!}
    </div>
    <div class="form-group">
        {!! Form::label('min_lifetime_purchases', 'Min Lifetime Purchases:*') !!}
        {!! Form::text('min_lifetime_purchases', $tier->min_lifetime_purchases, ['class' => 'form-control input_number', 'required']); !!}
    </div>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                {!! Form::label('discount_percentage', 'Discount Percentage:') !!}
                {!! Form::text('discount_percentage', $tier->discount_percentage, ['class' => 'form-control input_number']); !!}
                <small class="help-block">Discount percentage for this tier (0-100)</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                {!! Form::label('points_multiplier', 'Points Multiplier:') !!}
                {!! Form::text('points_multiplier', $tier->points_multiplier, ['class' => 'form-control input_number']); !!}
                <small class="help-block">Multiplier for reward points (e.g., 1.5x = 50% more points)</small>
            </div>
        </div>
    </div>
    <div class="form-group">
        {!! Form::label('sort_order', 'Sort Order:') !!}
        {!! Form::text('sort_order', $tier->sort_order, ['class' => 'form-control input_number']); !!}
    </div>
    <div class="form-group">
        <div class="checkbox">
            <label>
                {!! Form::checkbox('is_active', 1, $tier->is_active, ['class' => 'input-icheck']); !!}
                Active
            </label>
        </div>
    </div>
</div>
<div class="modal-footer">
    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
    <button type="submit" class="btn btn-primary">Update</button>
</div>
{!! Form::close() !!}

<script type="text/javascript">
    $(document).ready(function() {
        $('#edit_tier_form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            $.ajax({
                method: 'PUT',
                url: form.attr('action'),
                data: form.serialize(),
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        $('#edit_tier_modal').modal('hide');
                        $('#loyalty_tier_table').DataTable().ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                }
            });
        });
    });
</script>

