@inject('request', 'Illuminate\Http\Request')
<!-- Main Header -->
  <header class="main-header no-print">
    <a href="{{route('home')}}" class="logo">
      
      <span class="logo-lg">{{ Session::get('business.name') }} <i class="fa fa-circle text-success" id="online_indicator"></i></span> 

    </a>

    <!-- Header Navbar -->
    <nav class="navbar navbar-static-top" role="navigation">
      <!-- Sidebar toggle button-->
      <a href="#" class="sidebar-toggle" data-toggle="offcanvas" role="button">
        &#9776;
        <span class="sr-only">Toggle navigation</span>
      </a>

      @if(Module::has('Superadmin'))
        @includeIf('superadmin::layouts.partials.active_subscription')
      @endif

        @if(!empty(session('previous_user_id')) && !empty(session('previous_username')))
            <a href="{{route('sign-in-as-user', session('previous_user_id'))}}" class="btn btn-flat btn-danger m-8 btn-sm mt-10"><i class="fas fa-undo"></i> @lang('lang_v1.back_to_username', ['username' => session('previous_username')] )</a>
        @endif

      <!-- Navbar Right Menu -->
      <div class="navbar-custom-menu">

        @if(Module::has('Essentials'))
          @includeIf('essentials::layouts.partials.header_part')
        @endif



        {{-- Register buttons used to render on both the global header AND the
             POS header when on /pos, producing duplicate IDs #register_details
             and #close_register. jQuery's getElementById returned the first
             match (the global header's), so the branded POS "Close Register"
             pill was fighting the old header's button for the same ID and
             some clicks resolved to the wrong handler. Global header now
             defers register controls to the POS header entirely on POS pages. --}}

        @if(in_array('pos_sale', $enabled_modules))
          @can('sell.create')
            <a href="{{action('SellPosController@create')}}" title="@lang('sale.pos_sale')" data-toggle="tooltip" data-placement="bottom" class="btn btn-flat pull-left m-8 btn-sm mt-10 btn-success">
              <strong><i class="fa fa-th-large"></i> &nbsp; @lang('sale.pos_sale')</strong>
            </a>
          @endcan
        @endif

        @if(Module::has('Repair'))
          @includeIf('repair::layouts.partials.header')
        @endif


        <div class="m-8 pull-left mt-15 hidden-xs" style="color: #fff;"><strong>{{ @format_date('now') }}</strong></div>

        <ul class="nav navbar-nav">
          @include('layouts.partials.header-notifications')
          <!-- User Account Menu -->
          <li class="dropdown user user-menu">
            <!-- Menu Toggle Button -->
            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
              <!-- The user image in the navbar-->
              @php
                $profile_photo = auth()->user()->media;
              @endphp
              @if(!empty($profile_photo))
                <img src="{{$profile_photo->display_url}}" class="user-image" alt="User Image">
              @endif
              <!-- hidden-xs hides the username on small devices so only the image appears. -->
              <span>{{ Auth::User()->first_name }} {{ Auth::User()->last_name }}</span>
            </a>
            <ul class="dropdown-menu">
              <!-- The user image in the menu -->
              <li class="user-header">
                @if(!empty(Session::get('business.logo')))
                  <img src="{{ asset( 'uploads/business_logos/' . Session::get('business.logo') ) }}" alt="Logo">
                @endif
                <p>
                  {{ Auth::User()->first_name }} {{ Auth::User()->last_name }}
                </p>
              </li>
              <!-- Menu Body -->
              <!-- Menu Footer-->
              <li class="user-footer">
                <div class="pull-left">
                  <a href="{{action('UserController@getProfile')}}" class="btn btn-default btn-flat">@lang('lang_v1.profile')</a>
                </div>
                <div class="pull-right">
                  <a href="{{action('Auth\LoginController@logout')}}" class="btn btn-default btn-flat">@lang('lang_v1.sign_out')</a>
                </div>
              </li>
            </ul>
          </li>
          <!-- Control Sidebar Toggle Button -->
        </ul>
      </div>
    </nav>
  </header>