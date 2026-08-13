<x-mail::message>
# Contact form message

**From:** {{ $data['name'] }} &lt;{{ $data['email'] }}&gt;
@if (!empty($data['shop']))
**Store:** {{ $data['shop'] }}
@endif
**Subject:** {{ $data['subject'] }}

<x-mail::panel>
{{ $data['message'] }}
</x-mail::panel>

Reply directly to this email to answer them.
</x-mail::message>
