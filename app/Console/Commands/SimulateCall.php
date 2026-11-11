<?php

namespace App\Console\Commands;

use App\Models\Call;
use App\Models\User;
use Illuminate\Console\Command;

class SimulateCall extends Command
{
    protected $signature = 'calls:simulate
        {--email= : The account that receives the calls (defaults to the first account)}
        {--count=1 : How many sample calls to create}
        {--now : Process immediately instead of queueing}';

    protected $description = 'Create sample incoming calls as if the voice provider had sent them';

    public function handle(): int
    {
        $user = $this->option('email')
            ? User::where('email', $this->option('email'))->first()
            : User::orderBy('id')->first();

        if (! $user) {
            $this->error('No account found. Sign up in the app first, or run `php artisan db:seed` for the demo account.');

            return self::FAILURE;
        }

        $count = max(1, (int) $this->option('count'));

        for ($i = 0; $i < $count; $i++) {
            $call = Call::simulate($user, (bool) $this->option('now'));
            $this->line("Call #{$call->id} from {$call->callerLabel()} for {$user->email}: {$call->status}");
        }

        if (! $this->option('now')) {
            $this->info('Queued. Run `php artisan queue:work` to process them.');
        }

        return self::SUCCESS;
    }
}
