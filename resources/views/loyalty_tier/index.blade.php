@extends('layouts.app')

@section('title', 'Loyalty Tiers')

@section('content')

<!-- Content Header (Page header) -->
<section class="content-header">
    <h1>Loyalty Tiers</h1>
</section>

<!-- Main content -->
<section class="content">
    @component('components.widget', ['class' => 'box-primary', 'title' => 'All Loyalty Tiers'])
        @slot('tool')
            <div class="box-tools">
                <button type="button" class="btn btn-block btn-primary" data-toggle="modal" data-target="#add_tier_modal">
                    <i class="fa fa-plus"></i> Add
                </button>
            </div>
        @endslot
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover" id="loyalty_tier_table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Min Lifetime Purchases</th>
                        <th>Discount %</th>
                        <th>Points Multiplier</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
            </table>
        </div>
    @endcomponent

    <!-- Add Tier Modal -->
    <div class="modal fade" id="add_tier_modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">Add Loyalty Tier</h4>
                </div>
                {!! Form::open(['action' => 'LoyaltyTierController@store', 'method' => 'post', 'id' => 'add_tier_form']) !!}
                <div class="modal-body">
                    <div class="form-group">
                        {!! Form::label('name', 'Name:*') !!}
                        {!! Form::text('name', null, ['class' => 'form-control', 'required', 'placeholder' => 'e.g., Bronze, Silver, Gold']); !!}
                    </div>
                    <div class="form-group">
                        {!! Form::label('description', 'Description:') !!}
                        {!! Form::textarea('description', null, ['class' => 'form-control', 'rows' => 2]); !!}
                    </div>
                    <div class="form-group">
                        {!! Form::label('min_lifetime_purchases', 'Min Lifetime Purchases:*') !!}
                        {!! Form::text('min_lifetime_purchases', null, ['class' => 'form-control input_number', 'required', 'placeholder' => '0.00']); !!}
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('discount_percentage', 'Discount Percentage:') !!}
                                {!! Form::text('discount_percentage', 0, ['class' => 'form-control input_number', 'placeholder' => '0']); !!}
                                <small class="help-block">Discount percentage for this tier (0-100)</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                {!! Form::label('points_multiplier', 'Points Multiplier:') !!}
                                {!! Form::text('points_multiplier', 1, ['class' => 'form-control input_number', 'placeholder' => '1.0']); !!}
                                <small class="help-block">Multiplier for reward points (e.g., 1.5x = 50% more points)</small>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        {!! Form::label('sort_order', 'Sort Order:') !!}
                        {!! Form::text('sort_order', 0, ['class' => 'form-control input_number', 'placeholder' => '0']); !!}
                    </div>
                    <div class="form-group">
                        <div class="checkbox">
                            <label>
                                {!! Form::checkbox('is_active', 1, true, ['class' => 'input-icheck']); !!}
                                Active
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
                {!! Form::close() !!}
            </div>
        </div>
    </div>

    <!-- Edit Tier Modal -->
    <div class="modal fade" id="edit_tier_modal" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                    <h4 class="modal-title">Edit Loyalty Tier</h4>
                </div>
                <span id="edit_tier_modal_content"></span>
            </div>
        </div>
    </div>

</section>
<!-- /.content -->

@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function() {
        var tier_table = $('#loyalty_tier_table').DataTable({
            processing: true,
            serverSide: true,
            ajax: '{{ action("LoyaltyTierController@index") }}',
            columns: [
                { data: 'name', name: 'name' },
                { data: 'min_lifetime_purchases', name: 'min_lifetime_purchases' },
                { data: 'discount_percentage', name: 'discount_percentage' },
                { data: 'points_multiplier', name: 'points_multiplier' },
                { data: 'is_active', name: 'is_active' },
                { data: 'action', name: 'action', orderable: false, searchable: false },
            ],
        });

        // Edit tier button
        $(document).on('click', '.edit_tier_button', function(e) {
            e.preventDefault();
            var url = $(this).attr('data-href');
            $.ajax({
                url: url,
                dataType: 'html',
                success: function(result) {
                    $('#edit_tier_modal_content').html(result);
                    $('#edit_tier_modal').modal('show');
                }
            });
        });

        // Delete tier button
        $(document).on('click', '.delete_tier_button', function(e) {
            e.preventDefault();
            var url = $(this).attr('href');
            swal({
                title: LANG.sure,
                icon: "warning",
                buttons: true,
                dangerMode: true,
            }).then((confirmed) => {
                if (confirmed) {
                    $.ajax({
                        method: 'DELETE',
                        url: url,
                        dataType: 'json',
                        success: function(result) {
                            if (result.success) {
                                toastr.success(result.msg);
                                tier_table.ajax.reload();
                            } else {
                                toastr.error(result.msg);
                            }
                        }
                    });
                }
            });
        });

        // Form submission
        $('#add_tier_form').on('submit', function(e) {
            e.preventDefault();
            var form = $(this);
            
            // Check if form is valid
            if (!form[0].checkValidity()) {
                form[0].reportValidity();
                return false;
            }
            
            // Disable submit button to prevent double submission
            var submitBtn = form.find('button[type="submit"]');
            submitBtn.prop('disabled', true).text('Saving...');
            
            $.ajax({
                method: 'POST',
                url: form.attr('action'),
                data: form.serialize(),
                dataType: 'json',
                success: function(result) {
                    if (result.success) {
                        toastr.success(result.msg);
                        $('#add_tier_modal').modal('hide');
                        form[0].reset();
                        // Reset form values to defaults
                        form.find('input[name="discount_percentage"]').val(0);
                        form.find('input[name="points_multiplier"]').val(1);
                        form.find('input[name="sort_order"]').val(0);
                        form.find('input[name="is_active"]').prop('checked', true);
                        tier_table.ajax.reload();
                    } else {
                        toastr.error(result.msg);
                    }
                },
                error: function(xhr) {
                    var errorMsg = 'Something went wrong';
                    if (xhr.responseJSON && xhr.responseJSON.msg) {
                        errorMsg = xhr.responseJSON.msg;
                    } else if (xhr.responseJSON && xhr.responseJSON.errors) {
                        var errors = xhr.responseJSON.errors;
                        errorMsg = Object.values(errors).flat().join(', ');
                    }
                    toastr.error(errorMsg);
                },
                complete: function() {
                    // Re-enable submit button
                    submitBtn.prop('disabled', false).text('Save');
                }
            });
        });
        
        // Reset form when modal is closed
        $('#add_tier_modal').on('hidden.bs.modal', function() {
            var form = $('#add_tier_form');
            form[0].reset();
            form.find('input[name="discount_percentage"]').val(0);
            form.find('input[name="points_multiplier"]').val(1);
            form.find('input[name="sort_order"]').val(0);
            form.find('input[name="is_active"]').prop('checked', true);
            // Remove any validation error messages
            form.find('.error').remove();
            form.find('.is-invalid').removeClass('is-invalid');
            // Re-enable submit button
            form.find('button[type="submit"]').prop('disabled', false).text('Save');
        });
    });
</script>
@endsection

