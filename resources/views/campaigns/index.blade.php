@extends('layouts.app')

@section('title', 'Campaigns')

@section('content')
    <header class="page-head">
        <div>
            <p class="eyebrow">Call a whole list, one person after another</p>
            <h1>Campaigns</h1>
        </div>
        <div class="page-actions">
            <a class="btn-call" href="{{ route('campaigns.create') }}"><x-icon name="plus" /> <span>New campaign</span></a>
        </div>
    </header>

    @if ($campaigns->isEmpty())
        <section class="empty-hero">
            <x-wave class="empty-hero-wave" seed="empty-campaigns" :bars="40" />
            <h2>Let the AI work through your list</h2>
            <p>Pick who to call and what about. The AI calls them one by one during calling hours, books appointments, and respects anyone who says “don’t call me”.</p>
            <div class="empty-hero-actions">
                <a class="btn-call" href="{{ route('campaigns.create') }}"><x-icon name="campaign" /> <span>Create your first campaign</span></a>
            </div>
        </section>
    @else
        <div class="campaign-grid">
            @foreach ($campaigns as $campaign)
                @php($progress = $campaign->progress())
                <a class="campaign-card status-{{ $campaign->status }}" href="{{ route('campaigns.show', $campaign) }}">
                    <div class="campaign-card-top">
                        <span class="chip chip-campaign-{{ $campaign->status }}">{{ $campaign->statusLabel() }}</span>
                        <span class="campaign-when">
                            @if ($campaign->status === 'scheduled')
                                <x-icon name="clock" /> {{ $campaign->scheduledLocal()->format('M j, g:i A') }}
                            @else
                                {{ ($campaign->started_at ?? $campaign->created_at)->diffForHumans() }}
                            @endif
                        </span>
                    </div>
                    <h2>{{ $campaign->name }}</h2>
                    <p class="campaign-purpose">“{{ $campaign->purpose }}”</p>
                    @include('campaigns._meter', ['progress' => $progress])
                    <dl class="campaign-figures">
                        <div><dt>People</dt><dd>{{ $progress['total'] }}</dd></div>
                        <div><dt>Called</dt><dd>{{ $progress['called'] }}</dd></div>
                        <div><dt>Booked</dt><dd class="hl-mint">{{ $progress['outcomes']['booked'] }}</dd></div>
                        <div><dt>Done</dt><dd>{{ $progress['percent'] }}%</dd></div>
                    </dl>
                </a>
            @endforeach
        </div>

        {{ $campaigns->links() }}
    @endif
@endsection
