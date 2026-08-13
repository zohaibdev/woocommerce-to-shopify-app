<?php

namespace App\Mail;

use App\Models\SupportTicket;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent when a merchant opens a ticket. The same mail goes to the app admins (so
 * someone knows it exists) and back to the merchant (so they have a record and
 * know it arrived) — `$forAdmin` only changes the wording.
 */
class SupportTicketOpened extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public bool $forAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->forAdmin
                ? "New support ticket #{$this->ticket->id}: {$this->ticket->subject}"
                : "We received your request #{$this->ticket->id}",
            replyTo: $this->forAdmin && $this->ticket->contact_email
                ? [$this->ticket->contact_email]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.support-ticket-opened',
            with: [
                'ticket' => $this->ticket,
                'forAdmin' => $this->forAdmin,
                'shopDomain' => $this->ticket->shop?->shopify_domain,
            ],
        );
    }
}
