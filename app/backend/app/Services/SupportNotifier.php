<?php

namespace App\Services;

use App\Mail\SupportTicketOpened;
use App\Mail\SupportTicketReplied;
use App\Models\AdminUser;
use App\Models\SupportTicket;
use App\Models\TicketReply;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Support email, both ways: the admins learn a ticket exists, and the merchant
 * learns it arrived and gets the reply.
 *
 * Mail never throws out of here — a ticket that was saved must stay saved even
 * if SMTP is misconfigured, and an admin reply must still be recorded.
 */
class SupportNotifier
{
    public function ticketOpened(SupportTicket $ticket): void
    {
        $ticket->loadMissing('shop');

        foreach ($this->adminRecipients() as $admin) {
            $this->send($admin, new SupportTicketOpened($ticket, forAdmin: true), 'admin ticket notification');
        }

        if ($ticket->contact_email) {
            $this->send($ticket->contact_email, new SupportTicketOpened($ticket), 'merchant ticket confirmation');
        }
    }

    public function replied(SupportTicket $ticket, TicketReply $reply): void
    {
        $to = $ticket->contact_email ?: $ticket->shop?->email;

        if (! $to) {
            return;
        }

        $this->send($to, new SupportTicketReplied($ticket, $reply), 'merchant ticket reply');
    }

    /**
     * Who gets told about new tickets: an explicitly configured support inbox if
     * there is one, otherwise every super admin account.
     *
     * @return array<int, string>
     */
    private function adminRecipients(): array
    {
        if ($configured = config('services.support.admin_email')) {
            return array_filter(array_map('trim', explode(',', (string) $configured)));
        }

        return AdminUser::query()
            ->where('is_super', true)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function send(string $to, $mailable, string $what): void
    {
        try {
            Mail::to($to)->send($mailable);
        } catch (Throwable $e) {
            // Deliberately swallowed: the ticket is already saved and that is what
            // matters. Logged so a broken SMTP config is still discoverable.
            Log::warning("Could not send {$what}", ['to' => $to, 'error' => $e->getMessage()]);
        }
    }
}
