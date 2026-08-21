<x-mail::message>
# {{ $migration->name ?: 'Migration #'.$migration->id }}

@if ($migration->status === 'completed')
Your WooCommerce migration finished successfully.
@elseif ($migration->status === 'completed_with_errors')
Your WooCommerce migration finished, but some records could not be migrated.
@elseif ($migration->status === 'cancelled')
Your WooCommerce migration was cancelled.
@else
Your WooCommerce migration did not complete.
@endif

<x-mail::table>
| Data       | Migrated | Failed |
|:-----------|---------:|-------:|
@foreach ($entities as $entity)
| {{ ucfirst($entity->entity_type) }} | {{ number_format($entity->successful_items) }} | {{ number_format($entity->failed_items) }} |
@endforeach
| **Total** | **{{ number_format($successful) }}** | **{{ number_format($failed) }}** |
</x-mail::table>

@if ($failed > 0)
Each failure is listed with its reason on the migration page, and can be retried on its own.
@endif

<x-mail::button :url="$url">
View migration
</x-mail::button>

Thanks,<br>
Shop Shift
</x-mail::message>
