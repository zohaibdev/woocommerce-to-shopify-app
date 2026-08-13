<?php

namespace App\Mail;

use App\Models\StoreMigration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a migration finishes, so the merchant doesn't have to sit and watch
 * a progress bar for what can be hours.
 */
class MigrationCompleted extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public StoreMigration $migration) {}

    public function envelope(): Envelope
    {
        $name = $this->migration->name ?: "Migration #{$this->migration->id}";

        $subject = match ($this->migration->status) {
            'completed' => "{$name} finished",
            'completed_with_errors' => "{$name} finished with some errors",
            'cancelled' => "{$name} was cancelled",
            default => "{$name} failed",
        };

        return new Envelope(subject: 'Store Shift — '.$subject);
    }

    public function content(): Content
    {
        $entities = $this->migration->entities;

        return new Content(
            markdown: 'mail.migration-completed',
            with: [
                'migration' => $this->migration,
                'entities' => $entities,
                'successful' => $entities->sum('successful_items'),
                'failed' => $entities->sum('failed_items'),
                'url' => rtrim((string) config('services.shopify.app_url'), '/')
                    .'/migrations/'.$this->migration->id,
            ],
        );
    }
}
