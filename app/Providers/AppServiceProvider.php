<?php

namespace App\Providers;

use App\Models\Call;
use App\Models\Campaign;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The web server and the queue worker write to SQLite at the same time.
        // WAL lets reads and a write overlap, and the timeout makes a write wait
        // for the lock instead of failing with "database is locked".
        if (config('database.default') === 'sqlite' && config('database.connections.sqlite.database') !== ':memory:') {
            config([
                'database.connections.sqlite.busy_timeout' => config('database.connections.sqlite.busy_timeout') ?? 10000,
                'database.connections.sqlite.journal_mode' => config('database.connections.sqlite.journal_mode') ?? 'wal',
                'database.connections.sqlite.synchronous' => config('database.connections.sqlite.synchronous') ?? 'normal',
            ]);
        }
    }

    public function boot(): void
    {
        Paginator::defaultView('partials.pagination');

        // The sidebar's counts and calling-hours line, on every signed-in page.
        View::composer('layouts.app', function ($view) {
            $user = auth()->user();
            $hours = config('call_assistant.outbound.calling_hours');

            $view->with('nav', [
                'contacts' => $user->contacts()->count(),
                'live_calls' => $user->calls()->whereIn('status', [Call::STATUS_DIALING, Call::STATUS_RECEIVED, Call::STATUS_PROCESSING])->count(),
                'running_campaigns' => $user->campaigns()->where('status', Campaign::STATUS_RUNNING)->count(),
                'calling_open' => Contact::callingHoursBlocker() === null,
                'hours' => CarbonImmutable::parse($hours['start'])->format('g:i A').' to '.CarbonImmutable::parse($hours['end'])->format('g:i A'),
                'timezone' => config('call_assistant.timezone'),
                'demo' => Call::usesDemoMode() || ! Call::usesVapi(),
            ]);
        });
    }
}
