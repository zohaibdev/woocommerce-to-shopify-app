<?php

namespace App\Http\Controllers;

use App\Models\DashboardContent;
use App\Models\SupportTicket;
use App\Support\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Merchant-facing view of admin-managed dashboard content, plus support ticket
 * submission from inside the Shopify app.
 */
class DashboardContentController extends Controller
{
    /** Enabled content, grouped by type, in admin-defined order. */
    public function index(): JsonResponse
    {
        $items = DashboardContent::enabled()->ordered()->get()
            ->map(fn ($c) => [
                'id' => $c->id,
                'type' => $c->type,
                'title' => $c->title,
                'body' => $c->body,
                'url' => $c->url,
            ]);

        return response()->json([
            'data' => $items,
            'grouped' => $items->groupBy('type'),
        ]);
    }

    /** Merchant submits a support ticket from the app. */
    public function createTicket(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string'],
            'contact_email' => ['sometimes', 'nullable', 'email'],
            'migration_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        $shop = CurrentShop::resolve($request);

        $ticket = SupportTicket::create([
            'shop_id' => $shop->id,
            'migration_id' => $data['migration_id'] ?? null,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'contact_email' => $data['contact_email'] ?? $shop->email,
            'status' => 'open',
            'priority' => 'normal',
        ]);

        return response()->json(['id' => $ticket->id, 'status' => $ticket->status], 201);
    }

    /** Tickets belonging to the current shop (merchant view). */
    public function myTickets(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);

        $tickets = SupportTicket::where('shop_id', $shop->id)
            ->with('replies')->latest()->limit(50)->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'status' => $t->status,
                'created_at' => $t->created_at,
                'replies' => $t->replies->map(fn ($r) => [
                    'author_type' => $r->author_type,
                    'author_name' => $r->author_name,
                    'message' => $r->message,
                    'created_at' => $r->created_at,
                ]),
            ]);

        return response()->json(['data' => $tickets]);
    }
}
