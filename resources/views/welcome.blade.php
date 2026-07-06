@extends('layouts.home')
@section('title', config('app.name', 'ultimatePOS'))

@section('content')
    {{-- Nivessa-branded landing. A fixed overlay sits on top of the stock
         dark home scaffold so the rebrand is self-contained to this page and
         the shared home layout / header are untouched. The overlay also hides
         the generic "ERP" / "Home" nav on the landing and puts a single clear
         Log in action front and centre. Palette matches auth/login.blade.php. --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap">
    <style>
        .niv-home { position: fixed; inset: 0; z-index: 9000; overflow-y: auto;
            background: #FDF5E7; display: flex; align-items: center; justify-content: center;
            padding: 32px 16px; font-family: "Poppins", system-ui, -apple-system, sans-serif; }
        .niv-home-card { width: 100%; max-width: 440px; background: #FFFFFF;
            border: 1px solid #E7D9C0; border-radius: 18px;
            box-shadow: 0 18px 48px rgba(59,46,42,.14); padding: 44px 36px 36px; text-align: center; }
        .niv-home-logo { width: 200px; max-width: 70%; height: auto; object-fit: contain;
            margin: 0 auto 22px; display: block; }
        .niv-home-title { font-weight: 700; font-size: 24px; color: #3B2E2A; margin: 0 0 6px; letter-spacing: -.01em; }
        .niv-home-sub { color: #6B5B4F; font-size: 14px; margin: 0 0 30px; line-height: 1.5; }
        .niv-home-btn { display: block; width: 100%; height: 48px; line-height: 48px;
            border: none; border-radius: 10px; background: #3B2E2A; color: #FDF5E7;
            font-size: 15px; font-weight: 600; text-decoration: none;
            transition: background .15s, transform .05s; font-family: "Poppins", sans-serif; }
        .niv-home-btn:hover { background: #D59052; color: #fff; text-decoration: none; }
        .niv-home-btn:active { transform: translateY(1px); }
        .niv-home-foot { color: #8A7A6B; font-size: 12px; margin-top: 24px; letter-spacing: .02em; }
    </style>

    <div class="niv-home">
        <div>
            <div class="niv-home-card">
                <img src="https://nivessa.com/nivessa-new-logo.png" alt="Nivessa" class="niv-home-logo">
                @if(Auth::check())
                    <h1 class="niv-home-title">Welcome back</h1>
                    <p class="niv-home-sub">You are signed into the Nivessa inventory system.</p>
                    <a href="{{ action('HomeController@index') }}" class="niv-home-btn">Go to the ERP</a>
                @else
                    <h1 class="niv-home-title">Nivessa Records</h1>
                    <p class="niv-home-sub">Staff dashboard and point of sale.</p>
                    <a href="{{ route('login') }}" class="niv-home-btn">Log in</a>
                @endif
            </div>
            <p class="niv-home-foot">Nivessa Records &middot; Los Angeles</p>
        </div>
    </div>
@endsection
