<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Torann\GeoIP\Facades\GeoIP;

class BackfillUserTimezones extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:backfill-timezones {--force : Overwrite existing timezones}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Guess and set timezones for users based on their last login IP';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $query = User::query();

        if (! $this->option('force')) {
            $query->where(function ($q) {
                $q->whereNull('preferences->timezone')
                    ->orWhere('preferences->timezone', '');
            });
        }

        $users = $query->whereNotNull('last_login_ip')->get();

        if ($users->isEmpty()) {
            $this->info('No users found requiring timezone backfill.');

            return;
        }

        $this->info("Found {$users->count()} users to process.");
        $bar = $this->output->createProgressBar($users->count());

        foreach ($users as $user) {
            try {
                $location = GeoIP::getLocation($user->last_login_ip);
                $timezone = $location->timezone;

                if ($timezone && $timezone !== 'UTC') {
                    $preferences = $user->preferences ?? [];
                    $preferences['timezone'] = $timezone;
                    $user->preferences = $preferences;
                    $user->save();
                }
            } catch (\Exception $e) {
                $this->error("\nFailed to process user {$user->id}: ".$e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->info("\nTimezone backfill complete.");
    }
}
