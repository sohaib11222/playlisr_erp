@extends('layouts.app')
@section('title', 'Add Customer Want')

@section('content')
<section class="content-header"><h1>Add Customer Want</h1></section>

<section class="content">
    <div class="box box-primary">
        <div class="box-body">
            <form method="POST" action="{{ action('CustomerWantController@store') }}">
                @csrf
                @include('customer_wants.partials.form_fields', ['want' => null])
                <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save</button>
                <a href="{{ action('CustomerWantController@index') }}" class="btn btn-default">Cancel</a>
            </form>
        </div>
    </div>
</section>
@stop
