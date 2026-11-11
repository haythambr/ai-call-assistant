<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') · {{ auth()->user()->businessName() }}</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='12' fill='%2314213D'/%3E%3Crect x='9' y='16' width='3' height='8' rx='1.5' fill='%235B7BFF'/%3E%3Crect x='15' y='11' width='3' height='18' rx='1.5' fill='%235B7BFF'/%3E%3Crect x='21' y='14' width='3' height='12' rx='1.5' fill='%23F2A007'/%3E%3Crect x='27' y='17' width='3' height='6' rx='1.5' fill='%23F2A007'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="app @yield('body-class')" @yield('body-attributes')>
<a class="skip-link" href="#main">Skip to content</a>

<div class="shell">
    <aside class="rail" id="rail" aria-label="Main">
        <a class="rail-brand" href="{{ route('dashboard') }}">
            <x-brand-mark />
            <span>
                <strong>Call Assistant</strong>
                <small>{{ auth()->user()->businessName() }}</small>
            </span>
        </a>

        <nav class="rail-nav">
            @php
                $items = [
                    ['route' => 'dashboard', 'match' => 'dashboard', 'icon' => 'home', 'label' => 'Overview', 'badge' => null],
                    ['route' => 'contacts.index', 'match' => 'contacts.*', 'icon' => 'contacts', 'label' => 'Contacts', 'badge' => $nav['contacts']],
                    ['route' => 'calls.index', 'match' => 'calls.*', 'icon' => 'phone', 'label' => 'Calls', 'badge' => $nav['live_calls'] ? ['live', $nav['live_calls']] : null],
                    ['route' => 'campaigns.index', 'match' => 'campaigns.*', 'icon' => 'campaign', 'label' => 'Campaigns', 'badge' => $nav['running_campaigns'] ? ['live', $nav['running_campaigns']] : null],
                    ['route' => 'settings', 'match' => 'settings*', 'icon' => 'settings', 'label' => 'Settings', 'badge' => null],
                ];
            @endphp
            @foreach ($items as $item)
                @php($active = request()->routeIs($item['match']))
                <a href="{{ route($item['route']) }}" @class(['rail-link', 'is-active' => $active]) @if ($active) aria-current="page" @endif>
                    <x-icon :name="$item['icon']" />
                    <span>{{ $item['label'] }}</span>
                    @if (is_array($item['badge']))
                        <em class="rail-badge is-live" title="{{ $item['badge'][1] }} in progress">{{ $item['badge'][1] }}</em>
                    @elseif ($item['badge'])
                        <em class="rail-badge">{{ number_format($item['badge']) }}</em>
                    @endif
                    @if ($active)<x-wave class="rail-wave" :seed="$item['label']" :bars="5" />@endif
                </a>
            @endforeach
        </nav>

        <div class="rail-line {{ $nav['calling_open'] ? 'is-open' : 'is-closed' }}">
            <span class="rail-line-dot" aria-hidden="true"></span>
            <div>
                <strong>{{ $nav['calling_open'] ? 'Calling hours open' : 'Calling hours closed' }}</strong>
                <small>{{ $nav['hours'] }} · {{ str_replace('_', ' ', $nav['timezone']) }}</small>
            </div>
        </div>

        @if ($nav['demo'])
            <a class="rail-demo" href="{{ route('settings') }}#integrations">
                <x-icon name="spark" />
                <span><strong>Demo mode</strong> No real calls are placed.</span>
            </a>
        @endif

        <div class="rail-user">
            <span class="avatar avatar-ink" aria-hidden="true">{{ auth()->user()->initials() }}</span>
            <span class="rail-user-who">
                <strong>{{ auth()->user()->name }}</strong>
                <small>{{ auth()->user()->email }}</small>
            </span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="rail-logout" aria-label="Sign out" title="Sign out"><x-icon name="logout" /></button>
            </form>
        </div>
    </aside>

    <div class="rail-scrim" data-close-rail></div>

    <div class="stage">
        <header class="topbar">
            <button type="button" class="btn-icon" data-open-rail aria-controls="rail" aria-label="Open menu"><x-icon name="menu" /></button>
            <a class="topbar-brand" href="{{ route('dashboard') }}"><x-brand-mark /> Call Assistant</a>
        </header>

        <main id="main" class="page" tabindex="-1">
            @yield('content')
        </main>
    </div>
</div>

<div class="toasts" id="toasts" role="status" aria-live="polite">
    @if (session('status'))
        <div class="toast" data-flash>{{ session('status') }}</div>
    @endif
    @foreach (['campaign', 'call'] as $key)
        @error($key)<div class="toast is-alert" data-flash>{{ $message }}</div>@enderror
    @endforeach
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="{{ asset('js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
