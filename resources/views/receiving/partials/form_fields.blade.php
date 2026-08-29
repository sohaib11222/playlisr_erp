{{-- Shared fields for create.blade.php and edit.blade.php. Expects
     $packageTypes, $locations, and (on edit) $package to be in scope. --}}
<div class="rcv-card">
    <h2><i class="fa fa-box"></i> What is it?</h2>
    <div class="rcv-row">
        <div class="rcv-field">
            {!! Form::label('package_type', 'Type *') !!}
            {!! Form::select('package_type', $packageTypes, null, ['class' => 'form-control', 'id' => 'package_type', 'required']); !!}
        </div>
        <div class="rcv-field" id="type_detail_field" style="display:none;">
            {!! Form::label('package_type_detail', 'Detail') !!}
            {!! Form::text('package_type_detail', null, ['class' => 'form-control', 'placeholder' => 'e.g. Walmart, Instacart']); !!}
        </div>
        <div class="rcv-field">
            {!! Form::label('location_id', 'Store *') !!}
            {!! Form::select('location_id', $locations, null, ['class' => 'form-control select2', 'placeholder' => 'Select store', 'required', 'style' => 'width: 100%']); !!}
        </div>
        <div class="rcv-field">
            {!! Form::label('bin_location', 'Bin / Location') !!}
            {!! Form::text('bin_location', null, ['class' => 'form-control', 'placeholder' => 'e.g. Receiving Bin 2']); !!}
            <small class="help-block">Where the physical box itself is sitting right now.</small>
        </div>
    </div>
</div>

<div class="rcv-card">
    <h2><i class="fa fa-file-invoice"></i> Order &amp; Invoice</h2>
    <div class="rcv-row">
        <div class="rcv-field">
            {!! Form::label('distributor', 'Distributor / Source') !!}
            {!! Form::text('distributor', null, ['class' => 'form-control', 'placeholder' => 'e.g. Alliance, Direct from label']); !!}
        </div>
        <div class="rcv-field">
            {!! Form::label('order_number', 'Order #') !!}
            {!! Form::text('order_number', null, ['class' => 'form-control', 'placeholder' => 'Optional']); !!}
        </div>
        <div class="rcv-field">
            {!! Form::label('invoice_number', 'Invoice #') !!}
            {!! Form::text('invoice_number', null, ['class' => 'form-control', 'placeholder' => 'Optional']); !!}
        </div>
    </div>
    @if(!isset($package))
    <div class="rcv-row" style="margin-top: 4px;">
        <div class="rcv-field" style="flex: 1 1 100%;">
            {!! Form::label('purchase_order_ids', 'Link to Purchase Order(s)') !!}
            {!! Form::select('purchase_order_ids[]', [], null, ['class' => 'form-control', 'id' => 'purchase_order_ids', 'multiple' => 'multiple', 'style' => 'width: 100%']); !!}
            <small class="help-block">Optional — a box can match one PO, several, or none at all (e.g. a Walmart supply run).</small>
        </div>
    </div>
    @endif
</div>

<div class="rcv-card">
    <h2><i class="fa fa-sticky-note"></i> Notes</h2>
    {!! Form::textarea('notes', null, ['class' => 'form-control', 'rows' => 3, 'placeholder' => 'Anything worth flagging about this package']); !!}
</div>

<div class="rcv-card">
    <h2><i class="fa fa-camera"></i> Photo</h2>
    @if(isset($package) && $package->photo)
        <div style="margin-bottom:10px;">
            <img src="{{ asset('uploads/receiving_photos/' . $package->photo) }}" alt="Package photo" style="max-width:220px;border-radius:9px;border:1px solid var(--pos-line);display:block;">
            <small class="help-block">Choose a new photo below to replace it.</small>
        </div>
    @endif
    {!! Form::file('photo', ['class' => 'form-control', 'accept' => 'image/*', 'capture' => 'environment']); !!}
    <small class="help-block">On a phone this opens the camera directly — snap what was received.</small>
</div>
