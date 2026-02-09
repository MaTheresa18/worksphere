<?php

namespace App\Console\Commands;

use App\Models\Email;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillEmailSizes extends Command
{
    protected $signature = 'email:backfill-sizes {--force : Force recalculation even if size is already set}';

    protected $description = 'Calculate and update size_bytes for existing emails';

    public function handle()
    {
        $this->info('Starting email size backfill...');

        $query = Email::query();

        if (! $this->option('force')) {
            $query->whereNull('size_bytes')->orWhere('size_bytes', 0);
        }

        $bar = $this->output->createProgressBar($query->count());
        $bar->start();

        $query->chunk(100, function ($emails) use ($bar) {
            foreach ($emails as $email) {
                // Approximate size calculation
                $bodySize = strlen($email->body_html ?? $email->body_plain ?? '');
                $headersSize = strlen(json_encode($email->headers ?? []));

                // Get attachment sizes
                $attachmentsSize = DB::table('media')
                    ->where('model_type', Email::class)
                    ->where('model_id', $email->id)
                    ->sum('size');

                $totalSize = $bodySize + $headersSize + $attachmentsSize;

                $email->update(['size_bytes' => $totalSize]);
                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();
        $this->info('Backfill completed.');
    }
}
