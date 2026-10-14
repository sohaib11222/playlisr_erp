@extends('layouts.app')
@section('title', 'Switch to Cashier to ring sales')

@section('content')
<section class="content" style="background:#FAF6EE;">
    <div style="max-width:560px;margin:40px auto;background:#fff;border:1px solid #ECE3CF;border-radius:12px;padding:24px;text-align:center;">
        <p style="font-size:18px;font-weight:700;color:#1F1B16;margin:0 0 16px;">To ring sales, switch to Cashier and count the drawer.</p>
        <a href="{{ action('SellPosController@selectPosDuty', ['duty' => 'cashier', 'intended' => action('SellPosController@create')]) }}" class="btn btn-primary btn-lg" style="border-radius:10px;font-weight:700;">Switch to Cashier</a>
        <div style="margin-top:12px;"><a href="{{ url('/home') }}" style="color:#5A5045;">Back to the ERP</a></div>
    </div>
</section>
@endsection
