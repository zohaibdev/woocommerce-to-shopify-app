<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the merchant when an admin replies to their ticket. */
class SupportTicketReplied extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public TicketReply $reply,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Re: {$this->ticket->subject} (#{$this->ticket->id})");
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.support-ticket-replied',
            with: [
                'ticket' => $this->ticket,
                'reply' => $this->reply,
                'url' => rtrim((string) config('services.shopify.app_url'), '/').'/support',
            ],
        );
    }
}
