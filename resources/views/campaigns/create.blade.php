@extends('layouts.app')

@section('title', 'New campaign')

@php
    $oldAudience = old('audience', $preselected ? 'selected' : ($callableCount ? 'all' : 'selected'));
    $oldIds = collect(old('contact_ids', $preselected))->map(fn ($id) => (int) $id)->all();
    $oldPurpose = old('purpose', $from?->purpose ?? ($purposes[0] ?? ''));
    $presetPurpose = in_array($oldPurpose, $purposes, true);
    $minDate = now($timezone)->addMinutes(5)->format('Y-m-d\TH:i');
    $defaultDate = now($timezone)->addDay()->setTimeFromTimeString($hours['start'])->format('Y-m-d\TH:i');
@endphp

@section('content')
    <a class="back-link" href="{{ route('campaigns.index') }}"><x-icon name="back" /> Campaigns</a>

    <header class="page-head">
        <div>
            <h1>{{ $from ? 'Run “'.$from->name.'” again' : 'New campaign' }}</h1>
            <p class="page-lede">Three choices and the AI does the dialing.</p>
        </div>
    </header>

    @if ($errors->any())
        <p class="inline-alert"><x-icon name="block" /> {{ $errors->first() }}</p>
    @endif

    <form method="POST" action="{{ route('campaigns.store') }}" class="builder" id="campaign-builder" novalidate
          data-gap="{{ config('call_assistant.outbound.seconds_between_calls') }}"
          data-callable="{{ $callableCount }}">
        @csrf

        <div class="builder-steps">
            {{-- 1. Message --------------------------------------------------- --}}
            <section class="card step-card" aria-labelledby="step-1">
                <header class="step-head"><span class="step-num">1</span><h2 id="step-1">What’s the call about?</h2></header>

                <label class="field">
                    <span>Campaign name</span>
                    <input type="text" name="name" value="{{ old('name', $from ? $from->name.' (again)' : '') }}" placeholder="November check-up reminders" maxlength="120" required @error('name') class="is-invalid" @enderror data-summary-name>
                    @error('name')<em class="field-error">{{ $message }}</em>@enderror
                </label>

                <div class="field" data-purpose-form>
                    <span>The AI says it’s calling about</span>
                    <div class="purpose-cards" role="radiogroup">
                        @foreach ($purposes as $purpose)
                            <label class="purpose-card">
                                <input type="radio" name="purpose_choice" value="{{ $purpose }}" @checked($presetPurpose && $oldPurpose === $purpose) data-purpose-radio>
                                <span>{{ $purpose }}</span>
                            </label>
                        @endforeach
                        <label class="purpose-card">
                            <input type="radio" name="purpose_choice" value="" @checked(! $presetPurpose) data-purpose-radio>
                            <span>Something else…</span>
                        </label>
                    </div>
                    <label class="field" data-purpose-custom @if ($presetPurpose) hidden @endif>
                        <span class="visually-hidden">Reason in your words</span>
                        <input type="text" maxlength="180" value="{{ $presetPurpose ? '' : $oldPurpose }}" placeholder="Let them know the clinic is closed on Friday">
                    </label>
                    <input type="hidden" name="purpose" value="{{ $oldPurpose }}" data-purpose-value>
                    @error('purpose')<em class="field-error">{{ $message }}</em>@enderror
                </div>
            </section>

            {{-- 2. Audience --------------------------------------------------- --}}
            <section class="card step-card" aria-labelledby="step-2">
                <header class="step-head"><span class="step-num">2</span><h2 id="step-2">Who should it call?</h2></header>

                <div class="choice-cards" role="radiogroup">
                    <label class="choice-card">
                        <input type="radio" name="audience" value="all" @checked($oldAudience === 'all') data-audience>
                        <span class="choice-icon"><x-icon name="contacts" /></span>
                        <span><strong>Everyone who can be called</strong><small>{{ $callableCount }} {{ Str::plural('person', $callableCount) }} with consent</small></span>
                    </label>
                    <label class="choice-card" @if (! $tags) aria-disabled="true" @endif>
                        <input type="radio" name="audience" value="tag" @checked($oldAudience === 'tag') @disabled(! $tags) data-audience>
                        <span class="choice-icon"><x-icon name="tag" /></span>
                        <span><strong>People with a tag</strong><small>{{ $tags ? count($tags).' '.Str::plural('tag', count($tags)) : 'No tags yet' }}</small></span>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="audience" value="selected" @checked($oldAudience === 'selected') data-audience>
                        <span class="choice-icon"><x-icon name="check" /></span>
                        <span><strong>People I pick</strong><small>Choose from your list</small></span>
                    </label>
                </div>

                <div class="audience-panel" data-panel="tag" @if ($oldAudience !== 'tag') hidden @endif>
                    <div class="tag-cards">
                        @foreach ($tags as $tag => $count)
                            <label class="tag-card">
                                <input type="radio" name="tag" value="{{ $tag }}" data-count="{{ $count }}" @checked(old('tag') === $tag || ($loop->first && ! old('tag')))>
                                <span><x-icon name="tag" /> {{ $tag }}</span>
                                <em>{{ $count }}</em>
                            </label>
                        @endforeach
                    </div>
                </div>

                <div class="audience-panel" data-panel="selected" @if ($oldAudience !== 'selected') hidden @endif>
                    @if ($contacts->isEmpty())
                        <div class="empty-mini">
                            <p>You don’t have contacts yet. <a href="{{ route('contacts.import') }}">Import a CSV</a> or <a href="{{ route('contacts.create') }}">add one</a>.</p>
                        </div>
                    @else
                        <div class="picker" data-picker>
                            <div class="picker-tools">
                                <label class="search search-sm">
                                    <x-icon name="search" />
                                    <span class="visually-hidden">Find a contact</span>
                                    <input type="search" placeholder="Find by name, phone or tag" data-picker-search autocomplete="off">
                                </label>
                                <button type="button" class="btn-mini" data-picker-all>Select all who can be called</button>
                                <button type="button" class="btn-mini" data-picker-none>Clear</button>
                            </div>
                            <ul class="picker-list">
                                @foreach ($contacts as $contact)
                                    @php($blocked = $contact->permissionBlocker())
                                    <li @class(['is-blocked' => $blocked]) data-search="{{ Str::lower($contact->name.' '.$contact->phone.' '.implode(' ', $contact->tags ?? [])) }}">
                                        <label>
                                            <input type="checkbox" name="contact_ids[]" value="{{ $contact->id }}" @checked(! $blocked && in_array($contact->id, $oldIds, true)) @disabled($blocked)>
                                            <span class="avatar avatar-sm av-{{ crc32($contact->phone) % 6 }}" aria-hidden="true">{{ $contact->initials() }}</span>
                                            <span class="picker-who">
                                                <strong>{{ $contact->label() }}</strong>
                                                <small>{{ $contact->displayPhone() }}@if ($blocked) · {{ $contact->statusLabel() }}@endif</small>
                                            </span>
                                            <span class="tags">@foreach ($contact->tags ?? [] as $tag)<span class="tag">{{ $tag }}</span>@endforeach</span>
                                        </label>
                                    </li>
                                @endforeach
                            </ul>
                            <p class="picker-empty muted" hidden>No one matches.</p>
                        </div>
                    @endif
                </div>
                @error('audience')<em class="field-error">{{ $message }}</em>@enderror
                @error('contact_ids')<em class="field-error">{{ $message }}</em>@enderror
            </section>

            {{-- 3. Timing --------------------------------------------------- --}}
            <section class="card step-card" aria-labelledby="step-3">
                <header class="step-head"><span class="step-num">3</span><h2 id="step-3">When should it start?</h2></header>

                <div class="choice-cards choice-cards-2" role="radiogroup">
                    <label class="choice-card">
                        <input type="radio" name="when" value="now" @checked(old('when', 'now') === 'now') data-when>
                        <span class="choice-icon"><x-icon name="play" /></span>
                        <span><strong>Start now</strong><small>Regular campaign, calling begins right away</small></span>
                    </label>
                    <label class="choice-card">
                        <input type="radio" name="when" value="later" @checked(old('when') === 'later') data-when>
                        <span class="choice-icon"><x-icon name="clock" /></span>
                        <span><strong>Schedule it</strong><small>Pick a date and time to start</small></span>
                    </label>
                </div>

                <div class="schedule-panel" data-schedule @if (old('when') !== 'later') hidden @endif>
                    <label class="field">
                        <span>Start at ({{ str_replace('_', ' ', $timezone) }} time)</span>
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $defaultDate) }}" min="{{ $minDate }}" @error('scheduled_at') class="is-invalid" @enderror>
                        @error('scheduled_at')<em class="field-error">{{ $message }}</em>@enderror
                    </label>
                </div>
                <p class="field-hint"><x-icon name="clock" /> Calls only go out between {{ \Carbon\Carbon::parse($hours['start'])->format('g:i A') }} and {{ \Carbon\Carbon::parse($hours['end'])->format('g:i A') }}. Outside those hours the campaign waits and picks up at the next opening.</p>
            </section>
        </div>

        {{-- Live summary ----------------------------------------------------- --}}
        <aside class="summary" aria-live="polite">
            <div class="summary-card">
                <p class="summary-kicker">Campaign preview</p>
                <h2 class="summary-name" data-summary-title>{{ old('name', $from?->name) ?: 'Untitled campaign' }}</h2>
                <x-wave class="summary-wave" seed="preview" :bars="34" :live="true" />
                <p class="summary-sentence">
                    The AI will call <b data-summary-count>0 people</b>
                    about <b data-summary-purpose>{{ Str::lower($oldPurpose) }}</b>,
                    <b data-summary-when>starting right away</b>.
                </p>
                <dl class="summary-facts">
                    <div><dt>Time to dial everyone</dt><dd data-summary-duration>—</dd></div>
                    <div><dt>Gap between calls</dt><dd>{{ config('call_assistant.outbound.seconds_between_calls') }} s</dd></div>
                </dl>
                <button type="submit" class="btn-call btn-full btn-lg" data-summary-submit>
                    <x-icon name="campaign" /> <span data-submit-label>Launch campaign</span>
                </button>
                <p class="summary-fine">People without consent or who asked not to be called are skipped automatically.</p>
            </div>
        </aside>
    </form>
@endsection
