<x-mail::message>
@if ($forAdmin)
# New support ticket #{{ $ticket->id }}

**Store:** {{ $shopDomain ?? 'unknown' }}
**Contact:** {{ $ticket->contact_email ?? 'not provided' }}
@if ($ticket->migration_id)
**Migration:** #{{ $ticket->migration_id }}
@endif
@else
# We received your request

Thanks for getting in touch. Your ticket number is **#{{ $ticket->id }}** — we'll reply to
this address.
@endif

**Subject:** {{ $ticket->subject }}

<x-mail::panel>
{{ $ticket->message }}
</x-mail::panel>

Thanks,<br>
Store Shift
</x-mail::message>
