<?php

namespace App\Mail;

use App\Models\SupportTicket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A new message on a ticket. Goes to the merchant when an admin answers, and to
 * the admins when the merchant answers — `$forAdmin` only changes the wording.
 */
class SupportTicketReplied extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public SupportTicket $ticket,
        public TicketReply $reply,
        public bool $forAdmin = false,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Re: {$this->ticket->subject} (#{$this->ticket->id})",
            replyTo: $this->forAdmin && $this->ticket->contact_email
                ? [$this->ticket->contact_email]
                : [],
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.support-ticket-replied',
            with: [
                'ticket' => $this->ticket,
                'reply' => $this->reply,
                'forAdmin' => $this->forAdmin,
                'shopDomain' => $this->ticket->shop?->shopify_domain,
                'url' => rtrim((string) config('services.shopify.app_url'), '/').'/support',
            ],
        );
    }
}
