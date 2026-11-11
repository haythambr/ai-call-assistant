<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · AI Call Assistant</title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 40 40'%3E%3Crect width='40' height='40' rx='12' fill='%2314213D'/%3E%3Crect x='9' y='16' width='3' height='8' rx='1.5' fill='%235B7BFF'/%3E%3Crect x='15' y='11' width='3' height='18' rx='1.5' fill='%235B7BFF'/%3E%3Crect x='21' y='14' width='3' height='12' rx='1.5' fill='%23F2A007'/%3E%3Crect x='27' y='17' width='3' height='6' rx='1.5' fill='%23F2A007'/%3E%3C/svg%3E">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Atkinson+Hyperlegible:wght@400;700&family=Bricolage+Grotesque:opsz,wght@12..96,400;12..96,600;12..96,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/dashboard.css') }}">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body class="auth">
<div class="auth-split">
    <section class="auth-scene" aria-hidden="true">
        <div class="auth-scene-brand"><x-brand-mark /> <span>Call Assistant</span></div>

        <div class="scene-call">
            <div class="scene-top">
                <span class="scene-live"><i></i> Live call</span>
                <span class="scene-timer">02:26</span>
            </div>
            <x-wave class="scene-wave" seed="bright-smile" :bars="56" :live="true" />
            <div class="scene-turns">
                <p class="scene-turn is-agent" style="--d: 0.4s"><b>AI receptionist</b>Hi Sarah, this is Ava from Bright Smile Dental. You're due for a check-up.</p>
                <p class="scene-turn is-caller" style="--d: 1.6s"><b>Caller</b>Oh, perfect timing. Is Thursday at 10 free?</p>
                <p class="scene-turn is-agent" style="--d: 2.8s"><b>AI receptionist</b>It is. You're booked for Thursday at 10 AM.</p>
            </div>
            @php($thursday = now()->next(\Carbon\Carbon::THURSDAY))
            <div class="scene-ticket" style="--d: 3.9s">
                <span class="scene-ticket-day"><small>{{ $thursday->format('M') }}</small>{{ $thursday->format('j') }}</span>
                <span><strong>Check-up: Sarah Mitchell</strong><small>Thursday · 10:00 AM</small></span>
                <span class="scene-stamp">Booked</span>
            </div>
        </div>

        <div class="scene-copy">
            <h2>Your front desk,<br>on every line at once.</h2>
            <p>Upload your contacts, launch a calling campaign, and watch the AI book appointments while you work.</p>
            <ul class="scene-points">
                <li><x-icon name="contacts" /> Contacts from any spreadsheet</li>
                <li><x-icon name="campaign" /> Campaigns now or on a schedule</li>
                <li><x-icon name="calendar" /> Bookings straight into your calendar</li>
            </ul>
        </div>
    </section>

    <main class="auth-panel">
        <div class="auth-card">
            <a class="auth-mobile-brand" href="{{ route('login') }}"><x-brand-mark /> Call Assistant</a>
            @if (session('status'))
                <p class="inline-alert auth-notice" role="status"><x-icon name="block" /> {{ session('status') }}</p>
            @endif
            @yield('content')
        </div>
    </main>
</div>
<script src="https://code.jquery.com/jquery-3.7.1.min.js" integrity="sha256-/JqT3SQfawRcv/BIHPThkBvs0OEvtFFmqPF/lYI/Cxo=" crossorigin="anonymous"></script>
<script src="{{ asset('js/app.js') }}"></script>
</body>
</html>
