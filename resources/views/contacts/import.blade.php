@extends('layouts.app')

@section('title', 'Import contacts')

@php($report = session('import_report'))

@section('content')
    <a class="back-link" href="{{ route('contacts.index') }}"><x-icon name="back" /> Contacts</a>

    <header class="page-head">
        <div>
            <h1>Import contacts</h1>
            <p class="page-lede">Bring in a list from Excel or Google Sheets. People already on your list are updated, never duplicated.</p>
        </div>
    </header>

    @if ($report)
        <section class="card import-result" aria-labelledby="result-title">
            <div class="import-result-head">
                <span class="result-badge"><x-icon name="check" /></span>
                <div>
                    <h2 id="result-title" class="card-title">{{ $report['file'] }} is in</h2>
                    <p class="muted">Here’s what happened to each row.</p>
                </div>
            </div>
            <dl class="result-stats">
                <div class="is-good"><dt>Added</dt><dd>{{ number_format($report['imported']) }}</dd></div>
                <div><dt>Updated</dt><dd>{{ number_format($report['updated']) }}</dd></div>
                <div @class(['is-warn' => count($report['skipped'])])><dt>Skipped</dt><dd>{{ number_format(count($report['skipped'])) }}</dd></div>
            </dl>
            @if ($report['skipped'])
                <details class="skipped" @if (count($report['skipped']) <= 5) open @endif>
                    <summary>See the skipped rows</summary>
                    <table class="table table-compact">
                        <thead><tr><th>Row</th><th>Why it was skipped</th></tr></thead>
                        <tbody>
                            @foreach (array_slice($report['skipped'], 0, 100) as $skip)
                                <tr><td class="mono">{{ $skip['row'] }}</td><td>{{ $skip['reason'] }}</td></tr>
                            @endforeach
                        </tbody>
                    </table>
                </details>
            @endif
            <div class="form-actions">
                <a class="btn-call" href="{{ route('campaigns.create') }}"><x-icon name="campaign" /> <span>Call them in a campaign</span></a>
                <a class="btn-outline" href="{{ route('contacts.index') }}">See contacts</a>
            </div>
        </section>
    @endif

    <div class="form-layout">
        <form class="card form-card" method="POST" action="{{ route('contacts.import.store') }}" enctype="multipart/form-data" novalidate>
            @csrf

            <label class="dropzone dropzone-xl @error('file') is-invalid @enderror" data-dropzone>
                <input type="file" name="file" accept=".csv,text/csv" required>
                <span class="dropzone-icon"><x-icon name="upload" /></span>
                <strong class="dropzone-title" data-default="Drop your CSV here, or click to choose it">Drop your CSV here, or click to choose it</strong>
                <span class="dropzone-hint">Up to 5,000 rows and 2 MB</span>
            </label>
            @error('file')<em class="field-error">{{ $message }}</em>@enderror

            <label class="field">
                <span>Tag everyone in this file <small class="muted">(optional)</small></span>
                <input type="text" name="tags" value="{{ old('tags') }}" placeholder="november-recall" list="import-tags" autocomplete="off">
                <datalist id="import-tags">@foreach (array_keys($tags) as $tag)<option value="{{ $tag }}">@endforeach</datalist>
                <em class="field-hint">Handy for calling just this list in a campaign later.</em>
            </label>

            <label class="consent @error('consent') is-invalid @enderror">
                <input type="checkbox" name="consent" value="1" @checked(old('consent'))>
                <span>
                    <strong>Everyone on this list agreed to be called by {{ auth()->user()->businessName() }}</strong>
                    <small>Automated calls without consent are illegal in many countries. People who asked not to be called stay blocked.</small>
                </span>
            </label>
            @error('consent')<em class="field-error">{{ $message }}</em>@enderror

            <div class="form-actions">
                <button type="submit" class="btn-call"><x-icon name="upload" /> <span>Import contacts</span></button>
            </div>
        </form>

        <aside class="card csv-guide">
            <h2 class="card-title">How the file should look</h2>
            <p class="muted">The first row names the columns. Only <b>phone</b> is required.</p>
            <div class="csv-sample" role="table" aria-label="Example file">
                <div role="row" class="csv-head"><span role="columnheader">name</span><span role="columnheader">phone</span><span role="columnheader">email</span><span role="columnheader">tags</span></div>
                <div role="row"><span role="cell">Nadia Karim</span><span role="cell">+962 79 123 4567</span><span role="cell">nadia@…</span><span role="cell">vip</span></div>
                <div role="row"><span role="cell">Tom Becker</span><span role="cell">+1 415 555 0102</span><span role="cell"></span><span role="cell">recall</span></div>
            </div>
            <ul class="guide-list">
                <li><x-icon name="check" /> Numbers need the country code, like <b>+962</b> or <b>00962</b>.</li>
                <li><x-icon name="check" /> Column names like “Mobile” or “Full name” work too.</li>
                <li><x-icon name="check" /> Commas or semicolons, straight from Excel.</li>
            </ul>
            <a class="btn-outline btn-sm" href="{{ asset('samples/contacts-example.csv') }}" download><x-icon name="download" /> <span>Download an example</span></a>
        </aside>
    </div>
@endsection
