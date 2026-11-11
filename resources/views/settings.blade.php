@extends('layouts.app')

@section('title', 'Settings')

@section('content')
    <header class="page-head">
        <div>
            <h1>Settings</h1>
            <p class="page-lede">Your account, how the AI introduces itself, and where calls come from.</p>
        </div>
    </header>

    <div class="form-layout">
        <div class="stack">
            <form class="card form-card" method="POST" action="{{ route('settings.update') }}" novalidate>
                @csrf
                @method('PUT')
                <h2 class="card-title">Account</h2>
                <div class="field-row">
                    <label class="field">
                        <span>Your name</span>
                        <input type="text" name="name" value="{{ old('name', $user->name) }}" required @error('name') class="is-invalid" @enderror>
                        @error('name')<em class="field-error">{{ $message }}</em>@enderror
                    </label>
                    <label class="field">
                        <span>Business name</span>
                        <input type="text" name="business_name" value="{{ old('business_name', $user->business_name) }}" required @error('business_name') class="is-invalid" @enderror>
                        @error('business_name')<em class="field-error">{{ $message }}</em>@else<em class="field-hint">The AI says this name on every call.</em>@enderror
                    </label>
                </div>
                <label class="field">
                    <span>Email</span>
                    <input type="email" name="email" value="{{ old('email', $user->email) }}" required @error('email') class="is-invalid" @enderror>
                    @error('email')<em class="field-error">{{ $message }}</em>@enderror
                </label>
                <div class="form-actions">
                    <button type="submit" class="btn-call"><x-icon name="check" /> <span>Save</span></button>
                </div>
            </form>

            <section class="card" aria-labelledby="webhook-title">
                <h2 id="webhook-title" class="card-title">Incoming calls</h2>
                <p class="muted">Paste this URL into your voice provider (Vapi, Retell or similar) as the place to send finished calls. Each request must be signed with your webhook secret.</p>
                <div class="copy-field">
                    <input type="text" readonly value="{{ $user->webhookUrl() }}" aria-label="Your webhook URL" id="webhook-url">
                    <button type="button" class="btn-mini" data-copy="#webhook-url"><x-icon name="copy" /> Copy</button>
                </div>
                <form method="POST" action="{{ route('settings.webhook-token') }}" class="inline-form">
                    @csrf
                    <button type="submit" class="btn-ghost btn-sm" data-confirm="Create a new URL? The current one stops working right away."><x-icon name="repeat" /> <span>Create a new URL</span></button>
                </form>
            </section>
        </div>

        <aside class="stack">
            <section class="card" id="integrations" aria-labelledby="integrations-title">
                <h2 id="integrations-title" class="card-title">Connections</h2>
                <ul class="integrations">
                    @foreach ($integrations as $integration)
                        <li>
                            <span @class(['int-dot', 'is-live' => $integration['live']]) aria-hidden="true"></span>
                            <span>
                                <strong>{{ $integration['name'] }}</strong>
                                <small>{{ $integration['what'] }}</small>
                            </span>
                            <span @class(['chip', 'chip-completed' => $integration['live']])>{{ $integration['live'] ? 'Live' : 'Demo' }}</span>
                        </li>
                    @endforeach
                </ul>
                <p class="field-hint">Add the keys in the <code>.env</code> file on the server (for example <code>OPENAI_API_KEY</code>), then restart the app. Until then, everything runs as a realistic demo.</p>
            </section>

            <section class="card facts">
                <h2 class="card-title">Calling hours</h2>
                <dl>
                    <div><dt>Calls go out</dt><dd>{{ \Carbon\Carbon::parse($hours['start'])->format('g:i A') }} to {{ \Carbon\Carbon::parse($hours['end'])->format('g:i A') }}</dd></div>
                    <div><dt>Time zone</dt><dd>{{ str_replace('_', ' ', $timezone) }}</dd></div>
                </dl>
                <p class="field-hint">Set with <code>OUTBOUND_CALLING_HOURS_START</code>, <code>OUTBOUND_CALLING_HOURS_END</code> and <code>CALL_ASSISTANT_TIMEZONE</code>.</p>
            </section>
        </aside>
    </div>
@endsection
