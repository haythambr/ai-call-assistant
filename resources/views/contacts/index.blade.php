@extends('layouts.app')

@section('title', 'Contacts')

@php
    $statusTabs = [
        '' => ['All', $counts['all']],
        'callable' => ['Can be called', $counts['callable']],
        'no_consent' => ['No consent', $counts['no_consent']],
        'opted_out' => ['Do not call', $counts['opted_out']],
    ];
    $current = $filters['status'] ?? '';
    $hasFilters = filled($filters['q'] ?? null) || filled($filters['tag'] ?? null) || filled($current);
@endphp

@section('content')
    <header class="page-head">
        <div>
            <p class="eyebrow">{{ number_format($counts['all']) }} {{ Str::plural('person', $counts['all']) }} · {{ number_format($counts['callable']) }} can be called</p>
            <h1>Contacts</h1>
        </div>
        <div class="page-actions">
            @if ($counts['all'] > 0)
                <a class="btn-ghost" href="{{ route('contacts.export') }}"><x-icon name="download" /> <span>Export</span></a>
            @endif
            <a class="btn-outline" href="{{ route('contacts.import') }}"><x-icon name="upload" /> <span>Import CSV</span></a>
            <a class="btn-call" href="{{ route('contacts.create') }}"><x-icon name="plus" /> <span>New contact</span></a>
        </div>
    </header>

    @if ($counts['all'] === 0)
        <section class="empty-hero">
            <x-wave class="empty-hero-wave" seed="empty-contacts" :bars="40" />
            <h2>Who should the AI call?</h2>
            <p>Bring in your patient or customer list from Excel or Google Sheets, or add people one at a time.</p>
            <div class="empty-hero-actions">
                <a class="btn-call" href="{{ route('contacts.import') }}"><x-icon name="upload" /> <span>Import a CSV</span></a>
                <a class="btn-outline" href="{{ route('contacts.create') }}"><x-icon name="plus" /> <span>Add one contact</span></a>
            </div>
        </section>
    @else
        <form class="filters" method="GET" action="{{ route('contacts.index') }}" role="search">
            <label class="search">
                <x-icon name="search" />
                <span class="visually-hidden">Search contacts</span>
                <input type="search" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="Search by name, phone or email" autocomplete="off" data-autosubmit>
            </label>
            {{-- Keeps the status when searching; a clicked status button comes later and wins. --}}
            <input type="hidden" name="status" value="{{ $current }}">
            <div class="segmented" role="group" aria-label="Filter by status">
                @foreach ($statusTabs as $value => [$label, $count])
                    <button type="submit" name="status" value="{{ $value }}" @class(['is-active' => $current === $value]) @if ($current === $value) aria-pressed="true" @endif>
                        {{ $label }} <em>{{ number_format($count) }}</em>
                    </button>
                @endforeach
            </div>
            @if ($tags)
                <label class="tag-select">
                    <x-icon name="tag" />
                    <span class="visually-hidden">Filter by tag</span>
                    <select name="tag" data-autosubmit>
                        <option value="">All tags</option>
                        @foreach ($tags as $tag => $count)
                            <option value="{{ $tag }}" @selected(($filters['tag'] ?? '') === $tag)>{{ $tag }} ({{ $count }})</option>
                        @endforeach
                    </select>
                </label>
            @endif
            @if ($hasFilters)
                <a class="link-clear" href="{{ route('contacts.index') }}">Clear filters</a>
            @endif
        </form>

        <form id="bulk-form" method="POST" action="{{ route('contacts.bulk-destroy') }}" data-campaign-url="{{ route('campaigns.create') }}">
            @csrf
            @method('DELETE')

            <div class="table-card">
                @if ($contacts->isEmpty())
                    <div class="empty-mini">
                        <x-icon name="search" class="empty-icon" />
                        <p>No one matches these filters. <a href="{{ route('contacts.index') }}">Show everyone</a></p>
                    </div>
                @else
                    <table class="table">
                        <thead>
                            <tr>
                                <th class="col-check"><input type="checkbox" class="js-check-all" aria-label="Select everyone on this page"></th>
                                <th>Name</th>
                                <th class="hide-sm">Phone</th>
                                <th class="hide-sm">Tags</th>
                                <th>Status</th>
                                <th class="hide-md">Last called</th>
                                <th class="col-actions"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($contacts as $contact)
                                <tr @class(['is-muted' => $contact->status() !== 'callable'])>
                                    <td class="col-check"><input type="checkbox" name="contact_ids[]" value="{{ $contact->id }}" class="js-check" aria-label="Select {{ $contact->label() }}"></td>
                                    <td>
                                        <a class="person" href="{{ route('contacts.show', $contact) }}">
                                            <span class="avatar av-{{ crc32($contact->phone) % 6 }}" aria-hidden="true">{{ $contact->initials() }}</span>
                                            <span class="person-who">
                                                <strong>{{ $contact->name ?: 'No name' }}</strong>
                                                @if ($contact->email)<small class="hide-sm">{{ $contact->email }}</small>@endif
                                                <small class="show-sm mono">{{ $contact->displayPhone() }}</small>
                                            </span>
                                        </a>
                                    </td>
                                    <td class="mono hide-sm">{{ $contact->displayPhone() }}</td>
                                    <td class="hide-sm">
                                        <span class="tags">
                                            @foreach ($contact->tags ?? [] as $tag)
                                                <a class="tag" href="{{ route('contacts.index', ['tag' => $tag]) }}">{{ $tag }}</a>
                                            @endforeach
                                        </span>
                                    </td>
                                    <td><span class="chip chip-contact-{{ $contact->status() }}">{{ $contact->statusLabel() }}</span></td>
                                    <td class="hide-md muted">{{ $contact->last_called_at?->diffForHumans() ?? 'Never' }}</td>
                                    <td class="col-actions">
                                        <a class="icon-btn" href="{{ route('contacts.show', $contact) }}#call" title="Call {{ $contact->label() }}" aria-label="Call {{ $contact->label() }}" @if ($contact->status() !== 'callable') aria-disabled="true" tabindex="-1" @endif><x-icon name="phone" /></a>
                                        <a class="icon-btn" href="{{ route('contacts.edit', $contact) }}" title="Edit" aria-label="Edit {{ $contact->label() }}"><x-icon name="edit" /></a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            <div class="bulkbar" hidden>
                <span class="bulkbar-count"><b class="js-selected-count">0</b> selected</span>
                <button type="button" class="btn-call btn-sm js-bulk-campaign"><x-icon name="campaign" /> <span>Start a campaign</span></button>
                <button type="submit" class="btn-danger btn-sm" data-confirm="Delete the selected contacts? Their past calls are kept."><x-icon name="trash" /> <span>Delete</span></button>
                <button type="button" class="btn-icon js-bulk-clear" aria-label="Clear selection"><x-icon name="close" /></button>
            </div>
        </form>

        {{ $contacts->links() }}
    @endif
@endsection
