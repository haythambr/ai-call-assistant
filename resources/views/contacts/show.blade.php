@extends('layouts.app')

@section('title', $contact->label())

@php
    $outcomeLabels = ['booked' => 'Booked', 'answered' => 'Answered', 'opted_out' => 'Do not call', 'failed' => 'Failed', 'in_progress' => 'In progress'];
    $status = $contact->status();
@endphp

@section('content')
    <a class="back-link" href="{{ route('contacts.index') }}"><x-icon name="back" /> Contacts</a>

    <section class="profile">
        <div class="profile-id">
            <span class="avatar avatar-xl av-{{ crc32($contact->phone) % 6 }}" aria-hidden="true">{{ $contact->initials() }}</span>
            <div>
                <h1>{{ $contact->name ?: $contact->displayPhone() }}</h1>
                <p class="profile-meta">
                    <a href="tel:{{ $contact->phone }}" class="mono">{{ $contact->displayPhone() }}</a>
                    @if ($contact->email)<a href="mailto:{{ $contact->email }}">{{ $contact->email }}</a>@endif
                    <span class="chip chip-contact-{{ $status }}">{{ $contact->statusLabel() }}</span>
                </p>
                @if ($contact->tags)
                    <span class="tags">
                        @foreach ($contact->tags as $tag)
                            <a class="tag" href="{{ route('contacts.index', ['tag' => $tag]) }}">{{ $tag }}</a>
                        @endforeach
                    </span>
                @endif
            </div>
        </div>
        <div class="page-actions">
            <a class="btn-outline" href="{{ route('contacts.edit', $contact) }}"><x-icon name="edit" /> <span>Edit</span></a>
            @if (! $contact->opted_out_at)
                <form method="POST" action="{{ route('contacts.opt-out', $contact) }}">
                    @csrf
                    <button type="submit" class="btn-ghost" data-confirm="Mark {{ $contact->label() }} as do not call? The AI will never call them again."><x-icon name="block" /> <span>Do not call</span></button>
                </form>
            @endif
        </div>
    </section>

    <div class="grid-2 grid-wide-left">
        <div class="stack">
            <section class="card call-card" id="call" aria-labelledby="call-title">
                <div class="call-card-head">
                    <h2 id="call-title" class="card-title">Call {{ Str::before($contact->label(), ' ') }} now</h2>
                    <x-wave seed="{{ $contact->phone }}" :bars="18" />
                </div>
                @if ($contact->permissionBlocker())
                    <p class="inline-alert"><x-icon name="block" /> {{ $contact->permissionBlocker() }}</p>
                @else
                    @error('call')<p class="inline-alert"><x-icon name="block" /> {{ $message }}</p>@enderror
                    <form method="POST" action="{{ route('contacts.call', $contact) }}" class="call-form" data-purpose-form>
                        @csrf
                        <label class="field">
                            <span>What should the AI call about?</span>
                            <select data-purpose-select>
                                @foreach ($purposes as $purpose)<option value="{{ $purpose }}">{{ $purpose }}</option>@endforeach
                                <option value="">Something else…</option>
                            </select>
                        </label>
                        <label class="field" data-purpose-custom hidden>
                            <span>Reason in your words</span>
                            <input type="text" maxlength="180" placeholder="Let them know the clinic is closed on Friday">
                        </label>
                        <input type="hidden" name="purpose" value="{{ $purposes[0] ?? '' }}" data-purpose-value>
                        <button type="submit" class="btn-call btn-full" @disabled($blocker)>
                            <x-icon name="phone" /> <span>Call now</span>
                        </button>
                        @if ($blocker && ! $contact->permissionBlocker())
                            <p class="field-hint">{{ $blocker }}</p>
                        @endif
                    </form>
                @endif
            </section>

            <section class="card" aria-labelledby="history-title">
                <div class="card-head">
                    <h2 id="history-title" class="card-title">Call history</h2>
                    <span class="card-note">{{ $calls->count() }} {{ Str::plural('call', $calls->count()) }}</span>
                </div>
                @if ($calls->isEmpty())
                    <div class="empty-mini">
                        <x-wave seed="no-history-{{ $contact->id }}" :bars="22" />
                        <p>No calls with {{ $contact->label() }} yet.</p>
                    </div>
                @else
                    <ol class="timeline">
                        @foreach ($calls as $call)
                            @php($outcome = $call->outcome())
                            <li class="timeline-item">
                                <span class="timeline-dot dot-{{ $outcome }}" aria-hidden="true"></span>
                                <a class="timeline-body" href="{{ route('calls.index', ['call' => $call->id]) }}">
                                    <span class="timeline-top">
                                        <strong>{{ $call->direction === 'outbound' ? 'Outgoing' : 'Incoming' }} call</strong>
                                        <span class="chip chip-outcome-{{ $outcome }}">{{ $outcomeLabels[$outcome] }}</span>
                                    </span>
                                    <span class="timeline-text">{{ $call->summary ?: ($call->error ?: $call->purpose) }}</span>
                                    <small class="muted">
                                        {{ $call->created_at->format('M j, Y · g:i A') }}
                                        @if ($call->campaign) · Campaign “{{ $call->campaign->name }}” @endif
                                    </small>
                                </a>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <aside class="stack">
            <section class="card facts">
                <h2 class="card-title">Details</h2>
                <dl>
                    <div><dt>Consent</dt><dd>{{ $contact->consent_at ? 'Given '.$contact->consent_at->format('M j, Y') : 'Not on record' }}</dd></div>
                    @if ($contact->opted_out_at)<div><dt>Do not call</dt><dd class="text-alert">Since {{ $contact->opted_out_at->format('M j, Y') }}</dd></div>@endif
                    <div><dt>Last called</dt><dd>{{ $contact->last_called_at?->diffForHumans() ?? 'Never' }}</dd></div>
                    <div><dt>Added</dt><dd>{{ $contact->created_at->format('M j, Y') }}</dd></div>
                </dl>
                @if ($contact->notes)
                    <h3 class="facts-sub">Notes</h3>
                    <p class="notes">{{ $contact->notes }}</p>
                @endif
            </section>

            <section class="card">
                <h2 class="card-title">Campaigns</h2>
                @forelse ($campaigns as $campaign)
                    <a class="mini-link" href="{{ route('campaigns.show', $campaign) }}">
                        <x-icon name="campaign" />
                        <span>{{ $campaign->name }}</span>
                        <span class="chip chip-recipient-{{ $campaign->pivot->status }}">{{ ucfirst($campaign->pivot->status) }}</span>
                    </a>
                @empty
                    <p class="muted">Not part of any campaign yet.</p>
                @endforelse
                @if ($status === 'callable')
                    <a class="btn-outline btn-sm btn-full" href="{{ route('campaigns.create', ['contacts' => [$contact->id]]) }}"><x-icon name="campaign" /> <span>Add to a new campaign</span></a>
                @endif
            </section>
        </aside>
    </div>
@endsection
