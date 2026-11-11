@extends('layouts.app')

@section('title', $campaign->name)

@php
    $resultLabels = [
        'pending' => 'Waiting',
        'skipped' => 'Skipped',
        'booked' => 'Booked',
        'answered' => 'Answered',
        'opted_out' => 'Do not call',
        'failed' => 'Failed',
        'in_progress' => 'On the call',
    ];
@endphp

@section('content')
    <a class="back-link" href="{{ route('campaigns.index') }}"><x-icon name="back" /> Campaigns</a>

    <header class="page-head campaign-head">
        <div>
            <p class="eyebrow">
                @if ($campaign->status === 'scheduled')
                    Scheduled campaign · starts {{ $campaign->scheduledLocal()->format('l, M j \a\t g:i A') }}
                @else
                    Campaign · created {{ $campaign->created_at->format('M j, Y') }}
                @endif
            </p>
            <h1>{{ $campaign->name }}</h1>
            <p class="page-lede">The AI is calling about <b>“{{ $campaign->purpose }}”</b></p>
        </div>
        <div class="page-actions">
            @if ($campaign->canStartNow())
                <form method="POST" action="{{ route('campaigns.start-now', $campaign) }}">@csrf
                    <button class="btn-call" type="submit"><x-icon name="play" /> <span>Start now</span></button>
                </form>
            @endif
            @if ($campaign->canPause())
                <form method="POST" action="{{ route('campaigns.pause', $campaign) }}">@csrf
                    <button class="btn-outline" type="submit"><x-icon name="pause" /> <span>Pause</span></button>
                </form>
            @endif
            @if ($campaign->canResume())
                <form method="POST" action="{{ route('campaigns.resume', $campaign) }}">@csrf
                    <button class="btn-call" type="submit"><x-icon name="play" /> <span>Resume</span></button>
                </form>
            @endif
            @if ($campaign->canCancel())
                <form method="POST" action="{{ route('campaigns.cancel', $campaign) }}">@csrf
                    <button class="btn-ghost" type="submit" data-confirm="Cancel this campaign? Nobody else on the list will be called."><x-icon name="close" /> <span>Cancel</span></button>
                </form>
            @else
                <a class="btn-outline" href="{{ route('campaigns.create', ['from' => $campaign->id]) }}"><x-icon name="repeat" /> <span>Run again</span></a>
                <form method="POST" action="{{ route('campaigns.destroy', $campaign) }}">@csrf @method('DELETE')
                    <button class="btn-ghost" type="submit" data-confirm="Delete this campaign? Its calls stay on the Calls page."><x-icon name="trash" /> <span>Delete</span></button>
                </form>
            @endif
        </div>
    </header>

    @fragment('live')
    @php($live = $campaign->isActive() || $inFlight)
    <div id="campaign-live" data-live="{{ $live ? '1' : '0' }}" data-refresh-url="{{ route('campaigns.show', [$campaign, 'fragment' => 1, 'page' => $recipients->currentPage()]) }}">
        <section class="mission status-{{ $campaign->status }}" aria-labelledby="mission-title">
            <div class="mission-top">
                <div>
                    <span class="chip chip-campaign-{{ $campaign->status }}">{{ $campaign->statusLabel() }}</span>
                    <h2 id="mission-title" class="mission-figure"><b>{{ $progress['percent'] }}%</b> <span>{{ $progress['done'] }} of {{ $progress['total'] }} {{ Str::plural('person', $progress['total']) }} done</span></h2>
                </div>
                @if ($campaign->status === 'running' && \App\Models\Contact::callingHoursBlocker())
                    <p class="mission-wait"><x-icon name="clock" /> Waiting for calling hours. Calls resume {{ \App\Models\Contact::nextCallingTime()->format('D \a\t g:i A') }}.</p>
                @elseif ($campaign->status === 'paused')
                    <p class="mission-wait"><x-icon name="pause" /> Paused. Nobody is being called.</p>
                @elseif ($campaign->status === 'scheduled')
                    <p class="mission-wait"><x-icon name="clock" /> Starts {{ $campaign->scheduledLocal()->diffForHumans() }}.</p>
                @elseif ($live)
                    <p class="mission-wait is-live"><span class="line-dot" aria-hidden="true"></span> Live. This page updates on its own.</p>
                @endif
            </div>

            <div class="track @if ($campaign->status === 'running') is-running @endif" role="img" aria-label="One bar per person in calling order">
                @foreach ($track as $i => $result)
                    <i class="t-{{ $result }}" style="--i: {{ $i }}; --h: {{ 0.35 + (crc32($campaign->id.'-'.$i) % 65) / 100 }}" title="{{ $resultLabels[$result] }}"></i>
                @endforeach
            </div>

            <ul class="legend-row">
                @foreach (['booked' => $progress['outcomes']['booked'], 'answered' => $progress['outcomes']['answered'], 'opted_out' => $progress['outcomes']['opted_out'], 'failed' => $progress['outcomes']['failed'], 'in_progress' => $progress['outcomes']['in_progress'], 'skipped' => $progress['skipped'], 'pending' => $progress['pending']] as $key => $count)
                    <li class="lg-{{ $key }}"><i aria-hidden="true"></i> {{ $resultLabels[$key] }} <b>{{ $count }}</b></li>
                @endforeach
            </ul>
        </section>

        <section class="card" aria-labelledby="people-title">
            <div class="card-head">
                <h2 id="people-title" class="card-title">Everyone on the list</h2>
                <span class="card-note">{{ $progress['total'] }} {{ Str::plural('person', $progress['total']) }}</span>
            </div>
            <table class="table">
                <thead>
                    <tr><th>Person</th><th>Result</th><th class="hide-sm">What happened</th><th class="hide-md">When</th></tr>
                </thead>
                <tbody>
                    @foreach ($recipients as $recipient)
                        @php($result = $recipient->resultKey())
                        <tr>
                            <td>
                                <a class="person" href="{{ route('contacts.show', $recipient->contact_id) }}">
                                    <span class="avatar avatar-sm av-{{ crc32((string) $recipient->contact?->phone) % 6 }}" aria-hidden="true">{{ $recipient->contact?->initials() }}</span>
                                    <span class="person-who"><strong>{{ $recipient->contact?->label() }}</strong><small class="mono">{{ $recipient->contact?->displayPhone() }}</small></span>
                                </a>
                            </td>
                            <td><span class="chip chip-outcome-{{ $result }}">{{ $resultLabels[$result] }}</span></td>
                            <td class="hide-sm what">
                                @if ($recipient->call)
                                    <a href="{{ route('calls.index', ['call' => $recipient->call_id]) }}">{{ Str::limit($recipient->call->summary ?: ($recipient->call->error ?: 'Listen to the call'), 110) }}</a>
                                @else
                                    <span class="muted">{{ $recipient->reason ?: 'Not called yet' }}</span>
                                @endif
                            </td>
                            <td class="hide-md muted">{{ $recipient->status === 'pending' ? '—' : $recipient->updated_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            {{ $recipients->links() }}
        </section>
    </div>
    @endfragment
@endsection
