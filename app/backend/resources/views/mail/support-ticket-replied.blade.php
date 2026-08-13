<x-mail::message>
# Reply to your request #{{ $ticket->id }}

**Subject:** {{ $ticket->subject }}

<x-mail::panel>
{{ $reply->message }}
</x-mail::panel>

You can see the full conversation on the Support page in the app.

<x-mail::button :url="$url">
Open support
</x-mail::button>

Thanks,<br>
Store Shift
</x-mail::message>
