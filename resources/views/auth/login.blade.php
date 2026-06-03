@extends('layouts.auth2')
@section('title', __('lang_v1.login'))

@section('content')
    @php
        $username = old('username');
        $password = null;
        if(config('app.env') == 'demo'){
            $username = 'admin';
            $password = '123456';

            $demo_types = array(
                'all_in_one' => 'admin',
                'super_market' => 'admin',
                'pharmacy' => 'admin-pharmacy',
                'electronics' => 'admin-electronics',
                'services' => 'admin-services',
                'restaurant' => 'admin-restaurant',
                'superadmin' => 'superadmin',
                'woocommerce' => 'woocommerce_user',
                'essentials' => 'admin-essentials',
                'manufacturing' => 'manufacturer-demo',
            );

            if( !empty($_GET['demo_type']) && array_key_exists($_GET['demo_type'], $demo_types) ){
                $username = $demo_types[$_GET['demo_type']];
            }
        }
    @endphp

    {{-- Nivessa-branded login. A fixed overlay sits on top of the stock
         auth2 two-column scaffold so the rebrand is fully self-contained to
         the login page (register / password-reset are untouched). Form ids,
         field names and the POST route are unchanged so login mechanics and
         login.js keep working exactly as before. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
    <style>
        .niv-auth { position: fixed; inset: 0; z-index: 9000; overflow-y: auto;
            background: #FDF5E7; display: flex; align-items: center; justify-content: center;
            padding: 32px 16px; font-family: "Poppins", system-ui, -apple-system, sans-serif; }
        .niv-card { width: 100%; max-width: 420px; background: #FFFFFF;
            border: 1px solid #E7D9C0; border-radius: 18px;
            box-shadow: 0 18px 48px rgba(59,46,42,.14); padding: 40px 36px 32px; text-align: center; }
        .niv-logo { width: 92px; height: 92px; object-fit: contain; margin: 0 auto 18px; display: block; }
        .niv-title { font-weight: 700; font-size: 24px; color: #3B2E2A; margin: 0 0 4px; letter-spacing: -.01em; }
        .niv-sub { color: #6B5B4F; font-size: 14px; margin: 0 0 26px; }
        .niv-field { text-align: left; margin-bottom: 16px; }
        .niv-field > label { display: block; font-size: 11px; font-weight: 600; letter-spacing: .1em;
            text-transform: uppercase; color: #6B5B4F; margin: 0 0 6px; }
        .niv-card .form-control, .niv-input { width: 100%; height: 46px; padding: 10px 14px;
            border: 1px solid #D9C9B0 !important; border-radius: 10px !important; background: #FFFDF8 !important;
            color: #3B2E2A !important; font-size: 15px; box-shadow: none !important;
            font-family: "Poppins", sans-serif !important; transition: border-color .15s, box-shadow .15s; }
        .niv-card .form-control:focus, .niv-input:focus { border-color: #D59052 !important;
            box-shadow: 0 0 0 3px rgba(213,144,82,.18) !important; outline: none; }
        .niv-card .form-control::placeholder { color: #B7A78E; }
        .niv-row { display: flex; align-items: center; justify-content: space-between;
            margin: 4px 0 22px; font-size: 13px; }
        .niv-remember { display: flex; align-items: center; gap: 8px; color: #6B5B4F; margin: 0; font-weight: 500; }
        .niv-remember input { width: 15px; height: 15px; accent-color: #3B2E2A; }
        .niv-forgot { color: #D59052; font-weight: 600; text-decoration: none; }
        .niv-forgot:hover { color: #B5742F; text-decoration: underline; }
        .niv-btn { width: 100%; height: 48px; border: none; border-radius: 10px;
            background: #3B2E2A; color: #FDF5E7; font-size: 15px; font-weight: 600;
            cursor: pointer; transition: background .15s, transform .05s;
            font-family: "Poppins", sans-serif; }
        .niv-btn:hover { background: #D59052; color: #fff; }
        .niv-btn:active { transform: translateY(1px); }
        .niv-error { display: block; color: #B91C1C; font-size: 12.5px; margin-top: 6px; font-weight: 500; }
        .niv-field.has-error .form-control { border-color: #DC2626 !important; }
        .niv-foot { color: #8A7A6B; font-size: 12px; margin-top: 22px; letter-spacing: .02em; }
        .niv-foot a { color: #6B5B4F; text-decoration: none; }
    </style>

    <div class="niv-auth">
        <div>
            <div class="niv-card">
                <a href="/"><img src="https://nivessa.com/nivessa-new-logo.png" alt="Nivessa" class="niv-logo"></a>
                <h1 class="niv-title">Welcome back</h1>
                <p class="niv-sub">Sign in to the Nivessa dashboard</p>

                <form method="POST" action="{{ route('login') }}" id="login-form">
                    {{ csrf_field() }}
                    <div class="niv-field {{ $errors->has('username') ? 'has-error' : '' }}">
                        <label for="username">@lang('lang_v1.username')</label>
                        <input id="username" type="text" class="form-control" name="username" value="{{ $username }}" required autofocus placeholder="@lang('lang_v1.username')">
                        @if ($errors->has('username'))
                            <span class="niv-error">{{ $errors->first('username') }}</span>
                        @endif
                    </div>
                    <div class="niv-field {{ $errors->has('password') ? 'has-error' : '' }}">
                        <label for="password">@lang('lang_v1.password')</label>
                        <input id="password" type="password" class="form-control" name="password" value="{{ $password }}" required placeholder="@lang('lang_v1.password')">
                        @if ($errors->has('password'))
                            <span class="niv-error">{{ $errors->first('password') }}</span>
                        @endif
                    </div>
                    <div class="niv-row">
                        <label class="niv-remember">
                            <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }}> @lang('lang_v1.remember_me')
                        </label>
                        @if(config('app.env') != 'demo')
                            <a href="{{ route('password.request') }}" class="niv-forgot">@lang('lang_v1.forgot_your_password')</a>
                        @endif
                    </div>
                    <button type="submit" class="niv-btn">@lang('lang_v1.login')</button>
                </form>
            </div>
            <p class="niv-foot">Nivessa Records &middot; Los Angeles</p>
        </div>
    </div>

    @if(config('app.env') == 'demo')
    <div class="col-md-12 col-xs-12" style="padding-bottom: 30px;">
        @component('components.widget', ['class' => 'box-primary', 'header' => '<h4 class="text-center">Demo Shops <small><i> Demos are for example purpose only, this application <u>can be used in many other similar businesses.</u></i></small></h4>'])

            <a href="?demo_type=all_in_one" class="btn btn-app bg-olive demo-login" data-toggle="tooltip" title="Showcases all feature available in the application." data-admin="{{$demo_types['all_in_one']}}"> <i class="fas fa-star"></i> All In One</a>

            <a href="?demo_type=pharmacy" class="btn bg-maroon btn-app demo-login" data-toggle="tooltip" title="Shops with products having expiry dates." data-admin="{{$demo_types['pharmacy']}}"><i class="fas fa-medkit"></i>Pharmacy</a>

            <a href="?demo_type=services" class="btn bg-orange btn-app demo-login" data-toggle="tooltip" title="For all service providers like Web Development, Restaurants, Repairing, Plumber, Salons, Beauty Parlors etc." data-admin="{{$demo_types['services']}}"><i class="fas fa-wrench"></i>Multi-Service Center</a>

            <a href="?demo_type=electronics" class="btn bg-purple btn-app demo-login" data-toggle="tooltip" title="Products having IMEI or Serial number code."  data-admin="{{$demo_types['electronics']}}" ><i class="fas fa-laptop"></i>Electronics & Mobile Shop</a>

            <a href="?demo_type=super_market" class="btn bg-navy btn-app demo-login" data-toggle="tooltip" title="Super market & Similar kind of shops." data-admin="{{$demo_types['super_market']}}" ><i class="fas fa-shopping-cart"></i> Super Market</a>

            <a href="?demo_type=restaurant" class="btn bg-red btn-app demo-login" data-toggle="tooltip" title="Restaurants, Salons and other similar kind of shops." data-admin="{{$demo_types['restaurant']}}"><i class="fas fa-utensils"></i> Restaurant</a>
            <hr>

            <i class="icon fas fa-plug"></i> Premium optional modules:<br><br>

            <a href="?demo_type=superadmin" class="btn bg-red-active btn-app demo-login" data-toggle="tooltip" title="SaaS & Superadmin extension Demo" data-admin="{{$demo_types['superadmin']}}"><i class="fas fa-university"></i> SaaS / Superadmin</a>

            <a href="?demo_type=woocommerce" class="btn bg-woocommerce btn-app demo-login" data-toggle="tooltip" title="WooCommerce demo user - Open web shop in minutes!!" style="color:white !important" data-admin="{{$demo_types['woocommerce']}}"> <i class="fab fa-wordpress"></i> WooCommerce</a>

            <a href="?demo_type=essentials" class="btn bg-navy btn-app demo-login" data-toggle="tooltip" title="Essentials & HRM (human resource management) Module Demo" style="color:white !important" data-admin="{{$demo_types['essentials']}}">
                    <i class="fas fa-check-circle"></i>
                    Essentials & HRM</a>
                    
            <a href="?demo_type=manufacturing" class="btn bg-orange btn-app demo-login" data-toggle="tooltip" title="Manufacturing module demo" style="color:white !important" data-admin="{{$demo_types['manufacturing']}}">
                    <i class="fas fa-industry"></i>
                    Manufacturing Module</a>

            <a href="?demo_type=superadmin" class="btn bg-maroon btn-app demo-login" data-toggle="tooltip" title="Project module demo" style="color:white !important" data-admin="{{$demo_types['superadmin']}}">
                    <i class="fas fa-project-diagram"></i>
                    Project Module</a>

            <a href="?demo_type=services" class="btn btn-app demo-login" data-toggle="tooltip" title="Advance repair module demo" style="color:white !important; background-color: #bc8f8f" data-admin="{{$demo_types['services']}}">
                    <i class="fas fa-wrench"></i>
                    Advance Repair Module</a>

            <a href="{{url('docs')}}" target="_blank" class="btn btn-app" data-toggle="tooltip" title="Advance repair module demo" style="color:white !important; background-color: #2dce89">
                    <i class="fas fa-network-wired"></i>
                    Connector Module / API Documentation</a>
        @endcomponent   
    </div>
    @endif 
@stop
@section('javascript')
<script type="text/javascript">
    $(document).ready(function(){
        $('#change_lang').change( function(){
            window.location = "{{ route('login') }}?lang=" + $(this).val();
        });

        $('a.demo-login').click( function (e) {
           e.preventDefault();
           $('#username').val($(this).data('admin'));
           $('#password').val("{{$password}}");
           $('form#login-form').submit();
        });

        // The shared auth layout runs iCheck on every input, which skins the
        // "remember me" box in a blue square that clashes with the Nivessa
        // theme. iCheck initialises after this handler, so undo it on the next
        // tick to restore the native (brown-accented) checkbox.
        setTimeout(function(){
            if ($.fn.iCheck) { $('input[name="remember"]').iCheck('destroy'); }
        }, 0);
    })
</script>
@endsection
