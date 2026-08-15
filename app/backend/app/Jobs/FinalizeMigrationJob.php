<?php

namespace App\Jobs;

use App\Mail\MigrationCompleted;
use App\Migration\MigrationRunner;
use App\Models\MigrationBatch;
use App\Models\StoreMigration;
use App\Services\MailSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Rolls up the final status once no batches are outstanding. Idempotent, and it
 * re-derives every counter from the mapping table first so the numbers a merchant
 * sees at the end are the truth rather than whatever the increments happened to
 * add up to.
 */
class FinalizeMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Above the worker's 60s default because the last thing this does is hand a
     * message to an SMTP server the merchant configured, which can sit there. The
     * status is written BEFORE the email goes out, so a kill during delivery costs
     * the notification and never the finished run.
     */
    public int $timeout = 180;

    public function __construct(public int $migrationId) {}

    public function handle(): void
    {
        $migration = StoreMigration::with('entities')->find($this->migrationId);

        if (! $migration || in_array($migration->status, MigrationRunner::TERMINAL, true) || $migration->status === 'paused') {
            return;
        }

        // Work may have been queued between the last batch finishing and this job
        // running — if so, hand back to the runner instead of closing the run.
        if (MigrationRunner::nextBatch($migration->id)) {
            MigrationRunner::advance($migration->id);

            return;
        }

        foreach ($migration->entities as $entity) {
            MigrationRunner::syncCounters($migration, $entity);
            MigrationRunner::refreshEntityStatus($migration, $entity);
        }

        $migration->load('entities');

        $failedItems = $migration->entities->sum('failed_items');
        $successfulItems = $migration->entities->sum('successful_items');

        $failedBatches = MigrationBatch::where('migration_id', $migration->id)
            ->where('status', 'failed')
            ->count();

        $status = match (true) {
            // Nothing at all landed and something went wrong — a real failure.
            $successfulItems === 0 && ($failedItems > 0 || $failedBatches > 0) => 'failed',
            $failedItems > 0 || $failedBatches > 0 => 'completed_with_errors',
            default => 'completed',
        };

        $migration->update(['status' => $status, 'completed_at' => now()]);

        MigrationRunner::log(
            $migration->id,
            $status === 'completed' ? 'info' : 'warning',
            null,
            "Migration finished: {$successfulItems} migrated, {$failedItems} failed.",
        );

        $this->notify($migration);
    }

    /**
     * Migrations can run for hours, so the merchant asks to be told when it is
     * done rather than watching the page. Never let a mail problem (bad SMTP,
     * unreachable host) undo a finished migration.
     */
    private function notify(StoreMigration $migration): void
    {
        $email = $migration->options['notify_email'] ?? null;

        if (! $email) {
            return;
        }

        try {
            Mail::to($email)->send(new MigrationCompleted($migration));

            // Handing the message to a mailer that goes nowhere is not the same
            // as delivering it. Reporting "sent" either way left merchants
            // waiting on an email that was never going to arrive, with the
            // activity log agreeing with them that it had been sent.
            MailSettings::canDeliver()
                ? MigrationRunner::log($migration->id, 'info', null, "Completion email sent to {$email}.")
                : MigrationRunner::log(
                    $migration->id,
                    'warning',
                    null,
                    "The completion email for {$email} could not be delivered — this app has no email "
                        .'server configured. Your migration itself finished normally.',
                );
        } catch (Throwable $e) {
            MigrationRunner::log(
                $migration->id,
                'warning',
                null,
                "Could not send the completion email to {$email}: {$e->getMessage()}",
            );
        }
    }
}
