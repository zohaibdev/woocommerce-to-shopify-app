<x-mail::message>
@if ($forAdmin)
# New reply on ticket #{{ $ticket->id }}

**Store:** {{ $shopDomain ?? 'unknown' }}
**Contact:** {{ $ticket->contact_email ?? 'not provided' }}
@else
# Reply to your request #{{ $ticket->id }}
@endif

**Subject:** {{ $ticket->subject }}

<x-mail::panel>
{{ $reply->message }}
</x-mail::panel>

@if (! $forAdmin)
You can reply from the Support page inside the app.

<x-mail::button :url="$url">
Open support
</x-mail::button>
@endif

Thanks,<br>
Store Shift
</x-mail::message>
