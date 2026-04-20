@extends('layouts.app')
@section('title', 'Edit Customer Want')

@section('content')
<section class="content-header"><h1>Edit Customer Want</h1></section>

<section class="content">
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('CustomerWantController@update', $want->id) }}">
                @csrf
                @method('PUT')
                @include('customer_wants.partials.form_fields', ['want' => $want])
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="active" @if($want->status==='active') selected @endif>Active</option>
                        <option value="fulfilled" @if($want->status==='fulfilled') selected @endif>Fulfilled</option>
                        <option value="cancelled" @if($want->status==='cancelled') selected @endif>Cancelled</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('CustomerWantController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
