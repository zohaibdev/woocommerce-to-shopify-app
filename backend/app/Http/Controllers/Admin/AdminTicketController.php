<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SupportTicket;
use App\Models\TicketReply;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminTicketController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = SupportTicket::query()->with('shop');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($shopId = $request->query('shop_id')) {
            $query->where('shop_id', $shopId);
        }

        $rows = $query->latest()->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $rows->getCollection()->map(fn ($t) => $this->present($t)),
            'meta' => [
                'current_page' => $rows->currentPage(),
                'last_page' => $rows->lastPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'has_more' => $rows->hasMorePages(),
            ],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $t = SupportTicket::with(['shop', 'replies', 'migration'])->findOrFail($id);

        return response()->json($this->present($t) + [
            'message' => $t->message,
            'migration_id' => $t->migration_id,
            'replies' => $t->replies->map(fn ($r) => [
                'id' => $r->id,
                'author_type' => $r->author_type,
                'author_name' => $r->author_name,
                'message' => $r->message,
                'created_at' => $r->created_at,
            ]),
        ]);
    }

    /** Admin replies to a ticket (moves it to "pending" unless told otherwise). */
    public function reply(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string'],
            'status' => ['sometimes', 'in:open,pending,resolved,closed'],
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $admin = $request->attributes->get('admin');

        TicketReply::create([
            'support_ticket_id' => $ticket->id,
            'author_type' => 'admin',
            'author_name' => $admin->name,
            'message' => $data['message'],
        ]);

        $ticket->update([
            'status' => $data['status'] ?? 'pending',
            'last_reply_at' => now(),
        ]);

        return $this->show($ticket->id);
    }

    /** Update status / priority. */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:open,pending,resolved,closed'],
            'priority' => ['sometimes', 'in:low,normal,high,urgent'],
        ]);

        $ticket = SupportTicket::findOrFail($id);
        $ticket->update($data);

        return $this->show($ticket->id);
    }

    private function present(SupportTicket $t): array
    {
        return [
            'id' => $t->id,
            'subject' => $t->subject,
            'status' => $t->status,
            'priority' => $t->priority,
            'shop' => $t->shop?->shopify_domain,
            'shop_id' => $t->shop_id,
            'contact_email' => $t->contact_email,
            'replies_count' => $t->replies()->count(),
            'last_reply_at' => $t->last_reply_at,
            'created_at' => $t->created_at,
        ];
    }
}
