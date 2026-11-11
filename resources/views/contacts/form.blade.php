@extends('layouts.app')

@section('title', $contact ? 'Edit '.$contact->label() : 'New contact')

@section('content')
    <a class="back-link" href="{{ $contact ? route('contacts.show', $contact) : route('contacts.index') }}"><x-icon name="back" /> {{ $contact ? $contact->label() : 'Contacts' }}</a>

    <header class="page-head">
        <div>
            <h1>{{ $contact ? 'Edit contact' : 'New contact' }}</h1>
        </div>
    </header>

    <div class="form-layout">
        <form class="card form-card" method="POST" action="{{ $contact ? route('contacts.update', $contact) : route('contacts.store') }}" novalidate>
            @csrf
            @if ($contact) @method('PUT') @endif

            <div class="field-row">
                <label class="field">
                    <span>Name</span>
                    <input type="text" name="name" value="{{ old('name', $contact?->name) }}" autocomplete="off" placeholder="Sarah Mitchell" autofocus @error('name') class="is-invalid" @enderror>
                    @error('name')<em class="field-error">{{ $message }}</em>@enderror
                </label>
                <label class="field">
                    <span>Phone number <b class="req" aria-hidden="true">*</b></span>
                    <input type="tel" name="phone" value="{{ old('phone', $contact?->phone) }}" autocomplete="off" placeholder="+1 415 555 0142" required @error('phone') class="is-invalid" aria-describedby="phone-error" @enderror>
                    @error('phone')<em class="field-error" id="phone-error">{{ $message }}</em>@else<em class="field-hint">With the country code, like +962 79 123 4567.</em>@enderror
                </label>
            </div>

            <label class="field">
                <span>Email</span>
                <input type="email" name="email" value="{{ old('email', $contact?->email) }}" autocomplete="off" placeholder="sarah@example.com" @error('email') class="is-invalid" @enderror>
                @error('email')<em class="field-error">{{ $message }}</em>@enderror
            </label>

            <div class="field">
                <span>Tags</span>
                <div class="tag-input" data-tag-input>
                    <input type="text" name="tags" value="{{ old('tags', implode(', ', $contact?->tags ?? [])) }}" placeholder="recall, vip" list="tag-suggestions" autocomplete="off">
                </div>
                <datalist id="tag-suggestions">
                    @foreach (array_keys($tags) as $tag)<option value="{{ $tag }}">@endforeach
                </datalist>
                <em class="field-hint">Separate tags with commas. Use them to pick who a campaign calls.</em>
                @if ($tags)
                    <span class="tag-suggest">
                        @foreach (array_slice(array_keys($tags), 0, 8) as $tag)
                            <button type="button" class="tag" data-add-tag="{{ $tag }}">+ {{ $tag }}</button>
                        @endforeach
                    </span>
                @endif
            </div>

            <label class="field">
                <span>Notes</span>
                <textarea name="notes" rows="3" placeholder="Prefers morning appointments">{{ old('notes', $contact?->notes) }}</textarea>
            </label>

            <label class="consent">
                <input type="checkbox" name="consent" value="1" @checked(old('consent', $contact ? $contact->consent_at !== null : false))>
                <span>
                    <strong>{{ $contact?->name ?: 'This person' }} agreed to be called by {{ auth()->user()->businessName() }}</strong>
                    <small>The AI only ever calls people with consent on record. Untick to stop calls without deleting the contact.</small>
                </span>
            </label>

            @if ($contact?->opted_out_at)
                <p class="inline-alert"><x-icon name="block" /> {{ $contact->label() }} asked not to be called again on {{ $contact->opted_out_at->format('M j, Y') }}. That request is kept even if consent is ticked.</p>
            @endif

            <div class="form-actions">
                <button type="submit" class="btn-call"><x-icon name="check" /> <span>{{ $contact ? 'Save changes' : 'Add contact' }}</span></button>
                <a class="btn-ghost" href="{{ $contact ? route('contacts.show', $contact) : route('contacts.index') }}">Cancel</a>
            </div>
        </form>

        @if ($contact)
            <aside class="card danger-zone">
                <h2 class="card-title">Delete contact</h2>
                <p>Removes {{ $contact->label() }} from your contacts and from campaigns that have not called them yet. Past calls stay on the Calls page.</p>
                <form method="POST" action="{{ route('contacts.destroy', $contact) }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn-danger" data-confirm="Delete {{ $contact->label() }}? This can’t be undone."><x-icon name="trash" /> <span>Delete contact</span></button>
                </form>
            </aside>
        @else
            <aside class="card side-tip">
                <x-wave seed="new-contact" :bars="20" />
                <h2 class="card-title">Many people at once?</h2>
                <p>Import a CSV from Excel or Google Sheets with name, phone, email and tags columns.</p>
                <a class="btn-outline btn-sm" href="{{ route('contacts.import') }}"><x-icon name="upload" /> <span>Import CSV</span></a>
            </aside>
        @endif
    </div>
@endsection
