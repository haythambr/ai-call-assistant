@extends('layouts.app')

@section('title', 'Calls')
@section('body-class', 'page-calls')
@section('body-attributes')
    data-index-url="{{ route('calls.feed') }}"
    data-simulate-url="{{ route('calls.simulate') }}"
    data-calls-base-url="{{ url('calls') }}"
    data-settings-url="{{ route('calls.settings') }}"
    data-call-number-url="{{ route('calls.call-number') }}"
    data-contacts-base-url="{{ url('contacts') }}"
    data-campaigns-base-url="{{ url('campaigns') }}"
    @if ($selectedCall) data-selected-call="{{ $selectedCall }}" @endif
@endsection

@section('content')
    <header class="masthead">
        <div class="brand">
            <div>
                <p class="eyebrow">Call desk</p>
                <h1>Every call, replayed</h1>
                <p class="line-status"><span class="line-dot" aria-hidden="true"></span>AI receptionist is answering for {{ auth()->user()->businessName() }}</p>
            </div>
        </div>

        <div class="masthead-actions">
            <button type="button" id="open-calls-sheet" class="btn-outline" aria-haspopup="dialog" aria-controls="call-sheet">
                <x-icon name="phone-out" />
                <span class="btn-label">Call one number</span>
            </button>
            <button type="button" id="simulate-call" class="btn-call" aria-label="Simulate an incoming call">
                <x-icon name="phone" />
                <span class="btn-label">Simulate a call</span>
            </button>
        </div>
    </header>

    @if ($aiDemoMode || $calendarDemoMode)
        <p class="notice" role="status">
            Demo mode:
            @if ($aiDemoMode) built-in rules stand in for OpenAI. @endif
            @if ($calendarDemoMode) Bookings stay in the app instead of Google Calendar. @endif
            <a href="{{ route('settings') }}#integrations">Connect your keys</a> to go live.
        </p>
    @endif

    <p class="today" id="today-line" aria-live="polite">Loading today’s calls…</p>

    <div class="desk">
        <section class="calls" aria-labelledby="calls-title">
            <div class="calls-head">
                <h2 id="calls-title">Calls</h2>
                <span class="sync" id="sync-state">Up to date</span>
            </div>
            <ul id="call-list" class="call-list"></ul>
        </section>

        <section class="detail" id="call-detail" tabindex="-1" aria-label="Call details" aria-live="polite">
            <div class="detail-empty">
                <div class="empty-wave" aria-hidden="true"></div>
                <h2>Pick a call to replay it</h2>
                <p>You’ll see what the caller wanted, the appointment the AI booked, and every word of the conversation.</p>
            </div>
        </section>
    </div>

    <div class="sheet" id="call-sheet" hidden>
        <div class="sheet-backdrop" data-close-sheet></div>
        <aside class="sheet-panel" role="dialog" aria-modal="true" aria-labelledby="sheet-title">
            <header class="sheet-head">
                <div>
                    <h2 id="sheet-title">Call one number</h2>
                    <p class="hours" id="calling-hours">Checking calling hours…</p>
                </div>
                <button type="button" class="btn-icon" data-close-sheet aria-label="Close"><x-icon name="close" /></button>
            </header>

            <div class="sheet-body">
                <p class="sheet-demo" id="outbound-demo" hidden>Demo mode: no real phone call is made. The call comes back with a sample conversation after a few seconds.</p>

                <form class="sheet-section" id="call-number-form" novalidate>
                    <div class="field-row">
                        <label class="field">
                            <span>Name</span>
                            <input type="text" name="name" id="cn-name" autocomplete="off" placeholder="Sarah Mitchell">
                        </label>
                        <label class="field">
                            <span>Phone number</span>
                            <input type="tel" name="phone" id="cn-phone" autocomplete="off" placeholder="+1 415 555 0142" required>
                        </label>
                    </div>
                    <label class="field">
                        <span>Reason for the call</span>
                        <select name="purpose_choice" id="cn-purpose" class="js-purpose"></select>
                    </label>
                    <label class="field js-custom-purpose" hidden>
                        <span>What should the AI call about?</span>
                        <input type="text" id="cn-purpose-custom" maxlength="180" placeholder="Let them know the clinic is closed on Friday">
                    </label>
                    <label class="check">
                        <input type="checkbox" name="consent" id="cn-consent" value="1">
                        <span>This person agreed to be called by {{ auth()->user()->businessName() }}.</span>
                    </label>
                    <p class="form-error" role="alert"></p>
                    <button type="submit" class="btn-call btn-full">
                        <x-icon name="phone" />
                        <span>Call now</span>
                    </button>
                </form>

                <div class="sheet-section sheet-more">
                    <h3>Calling a whole list?</h3>
                    <p>A campaign calls everyone one after another, now or at a time you pick, and shows how each call went.</p>
                    <a class="btn-outline btn-full" href="{{ route('campaigns.create') }}"><x-icon name="campaign" /> <span>New campaign</span></a>
                </div>
            </div>
        </aside>
    </div>
@endsection

@push('scripts')
    <script src="{{ asset('js/dashboard.js') }}"></script>
@endpush
