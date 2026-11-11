{{-- A campaign's progress: one segment per outcome, in a single track. --}}
@php
    $total = max(1, $progress['total']);
    $segments = [
        'booked' => $progress['outcomes']['booked'],
        'answered' => $progress['outcomes']['answered'],
        'opted_out' => $progress['outcomes']['opted_out'],
        'failed' => $progress['outcomes']['failed'],
        'in_progress' => $progress['outcomes']['in_progress'],
        'skipped' => $progress['skipped'],
    ];
@endphp
<div class="meter {{ $size ?? '' }}" role="img" aria-label="{{ $progress['done'] }} of {{ $progress['total'] }} done: {{ $segments['booked'] }} booked, {{ $segments['answered'] }} answered, {{ $segments['opted_out'] }} asked not to be called, {{ $segments['failed'] }} failed, {{ $segments['skipped'] }} skipped">
    @foreach ($segments as $key => $count)
        @if ($count > 0)
            <i class="meter-{{ $key }}" style="width: {{ round(100 * $count / $total, 2) }}%"></i>
        @endif
    @endforeach
</div>
