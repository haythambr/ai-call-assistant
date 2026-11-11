@extends('layouts.app')

@section('title', 'Overview')

@php
    $user = auth()->user();
    $local = now(config('call_assistant.timezone'));
    $greeting = $local->hour < 12 ? 'Good morning' : ($local->hour < 18 ? 'Good afternoon' : 'Good evening');
    $outcomeLabels = ['booked' => 'Booked', 'answered' => 'Answered', 'opted_out' => 'Do not call', 'failed' => 'Failed', 'in_progress' => 'In progress'];
@endphp

@section('content')
    <header class="page-head">
        <div>
            <p class="eyebrow">{{ $local->format('l, F j') }}</p>
            <h1>{{ $greeting }}, {{ $user->firstName() }}</h1>
        </div>
        <div class="page-actions">
            <a class="btn-outline" href="{{ route('calls.index', ['new' => 1]) }}"><x-icon name="phone-out" /> <span>Call one number</span></a>
            <a class="btn-call" href="{{ route('campaigns.create') }}"><x-icon name="campaign" /> <span>New campaign</span></a>
        </div>
    </header>

    <section class="hero" aria-labelledby="hero-title">
        <div class="hero-copy">
            <p class="hero-kicker"><span class="line-dot" aria-hidden="true"></span> {{ $user->businessName() }} · AI receptionist on duty</p>
            <h2 id="hero-title" class="hero-sentence">
                @if ($stats['total'] === 0)
                    No calls yet. <span class="dim">Your first one will show up here the moment it ends.</span>
                @else
                    <b>{{ number_format($stats['today']) }}</b> {{ Str::plural('call', $stats['today']) }} today,
                    <b class="hl-mint">{{ number_format($stats['booked']) }}</b> booked so far
                    @if ($stats['needs_attention'])
                        and <b class="hl-alert">{{ $stats['needs_attention'] }}</b> {{ $stats['needs_attention'] === 1 ? 'needs' : 'need' }} you.
                    @else
                        <span class="dim">and nothing needs you.</span>
                    @endif
                @endif
            </h2>
        </div>
        <x-wave class="hero-wave" :seed="$user->id.'-'.$stats['total']" :bars="72" :live="$stats['in_progress'] > 0" />
        <dl class="hero-stats">
            <div><dt>All calls</dt><dd>{{ number_format($stats['total']) }}</dd></div>
            <div><dt>Booked</dt><dd class="hl-mint">{{ number_format($stats['booked']) }}</dd></div>
            <div><dt>In progress</dt><dd class="hl-cobalt">{{ number_format($stats['in_progress']) }}</dd></div>
            <div><dt>Contacts</dt><dd>{{ number_format($contactCount) }}</dd></div>
        </dl>
    </section>

    @if ($contactCount === 0 || $campaignCount === 0)
        <section class="card onboarding" aria-labelledby="start-title">
            <h2 id="start-title" class="card-title">Get your first campaign out</h2>
            <ol class="steps">
                <li @class(['is-done' => $contactCount > 0])>
                    <span class="step-dot">@if ($contactCount > 0)<x-icon name="check" />@else 1 @endif</span>
                    <div>
                        <strong>Add the people you call</strong>
                        <p>Upload a spreadsheet or add contacts one by one.</p>
                    </div>
                    @if ($contactCount === 0)<a class="btn-mini" href="{{ route('contacts.import') }}">Upload CSV</a>@endif
                </li>
                <li @class(['is-done' => $campaignCount > 0])>
                    <span class="step-dot">@if ($campaignCount > 0)<x-icon name="check" />@else 2 @endif</span>
                    <div>
                        <strong>Launch a campaign</strong>
                        <p>Pick who to call and what about. Start now or schedule it.</p>
                    </div>
                    @if ($campaignCount === 0)<a class="btn-mini" href="{{ route('campaigns.create') }}">New campaign</a>@endif
                </li>
                <li @class(['is-done' => ! $demoMode])>
                    <span class="step-dot">@if (! $demoMode)<x-icon name="check" />@else 3 @endif</span>
                    <div>
                        <strong>Connect a real phone line</strong>
                        <p>Until then, every call is a realistic demo.</p>
                    </div>
                    @if ($demoMode)<a class="btn-mini" href="{{ route('settings') }}#integrations">See how</a>@endif
                </li>
            </ol>
        </section>
    @endif

    <div class="grid-2">
        <section class="card" aria-labelledby="campaigns-title">
            <div class="card-head">
                <h2 id="campaigns-title" class="card-title">Campaigns in motion</h2>
                <a class="link-more" href="{{ route('campaigns.index') }}">All campaigns <x-icon name="arrow" /></a>
            </div>
            @forelse ($activeCampaigns as $campaign)
                @php($progress = $campaign->progress())
                <a class="campaign-line" href="{{ route('campaigns.show', $campaign) }}">
                    <div class="campaign-line-top">
                        <strong>{{ $campaign->name }}</strong>
                        <span class="chip chip-campaign-{{ $campaign->status }}">{{ $campaign->statusLabel() }}</span>
                    </div>
                    @include('campaigns._meter', ['progress' => $progress])
                    <p class="campaign-line-meta">
                        @if ($campaign->status === 'scheduled')
                            Starts {{ $campaign->scheduledLocal()->format('D, M j \a\t g:i A') }} · {{ $progress['total'] }} {{ Str::plural('person', $progress['total']) }}
                        @else
                            {{ $progress['done'] }} of {{ $progress['total'] }} done · {{ $progress['outcomes']['booked'] }} booked
                        @endif
                    </p>
                </a>
            @empty
                <div class="empty-mini">
                    <x-wave seed="no-campaigns" :bars="22" />
                    <p>No campaign is running. <a href="{{ route('campaigns.create') }}">Start one</a> to call a list of contacts.</p>
                </div>
            @endforelse
        </section>

        <section class="card" aria-labelledby="upcoming-title">
            <div class="card-head">
                <h2 id="upcoming-title" class="card-title">Coming up</h2>
                <span class="card-note">Booked by the AI</span>
            </div>
            @forelse ($upcoming as $appointment)
                @php($start = $appointment->starts_at->setTimezone($appointment->timezone))
                <a class="stub" href="{{ route('calls.index', ['call' => $appointment->call_id]) }}">
                    <span class="stub-date"><small>{{ $start->format('M') }}</small>{{ $start->format('j') }}</span>
                    <span class="stub-body">
                        <strong>{{ $appointment->title }}</strong>
                        <small>{{ $start->format('l · g:i A') }}</small>
                    </span>
                </a>
            @empty
                <div class="empty-mini">
                    <x-icon name="calendar" class="empty-icon" />
                    <p>Nothing booked yet. Appointments the AI agrees on a call land here.</p>
                </div>
            @endforelse
        </section>
    </div>

    <section class="card" aria-labelledby="recent-title">
        <div class="card-head">
            <h2 id="recent-title" class="card-title">Latest calls</h2>
            <a class="link-more" href="{{ route('calls.index') }}">Open call desk <x-icon name="arrow" /></a>
        </div>
        @if ($recentCalls->isEmpty())
            <div class="empty-mini">
                <x-wave seed="no-calls" :bars="22" />
                <p>No calls yet. Open the call desk and press <strong>Simulate a call</strong> to watch one come in.</p>
            </div>
        @else
            <ul class="recent">
                @foreach ($recentCalls as $call)
                    @php($outcome = $call->outcome())
                    <li>
                        <a href="{{ route('calls.index', ['call' => $call->id]) }}" class="recent-row">
                            <span class="dir-badge dir-{{ $call->direction }}" title="{{ $call->direction === 'outbound' ? 'Outgoing' : 'Incoming' }}">
                                <x-icon :name="$call->direction === 'outbound' ? 'phone-out' : 'phone'" />
                            </span>
                            <span class="recent-who">
                                <strong>{{ $call->callerLabel() }}</strong>
                                <small>{{ $call->summary ? Str::limit($call->summary, 90) : ($call->purpose ?: 'Waiting for the summary…') }}</small>
                            </span>
                            <x-wave class="recent-wave" :seed="$call->external_id" :bars="24" :live="$outcome === 'in_progress'" />
                            <span class="chip chip-outcome-{{ $outcome }}">{{ $outcomeLabels[$outcome] }}</span>
                            <time class="recent-time" datetime="{{ $call->created_at->toIso8601String() }}">{{ $call->created_at->diffForHumans(short: true) }}</time>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
