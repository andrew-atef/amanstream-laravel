@props([
    'rank' => 1,
    'label' => '',
])

@php
    if ($label === '') {
        $label = "الخيار رقم {$rank}";
    }
@endphp

<div class="mb-3 inline-flex items-center gap-1.5 rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-bold text-amber-800">
    <span class="grid h-5 w-5 place-items-center rounded-full bg-amber-600 text-[10px] text-white">{{ $rank }}</span>{{ $label }}
</div>