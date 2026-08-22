@props([
    'merchant' => 'amazon',
    'brand' => null,
])

<div class="absolute bottom-1 left-1 flex items-center rounded-lg border border-slate-200 bg-white/95 px-2 py-1 shadow-sm backdrop-blur">
    @if ($merchant === 'amazon')
        <img src="/icons/amazon.svg" alt="Amazon" width="24" height="24" loading="lazy" class="h-6 w-6 object-contain">
    @elseif ($merchant === 'noon')
        <span class="px-1 text-xs font-black text-primary-600">noon</span>
    @else
        <span class="px-1 text-xs font-bold text-ink/80">{{ $brand ?: 'أمازون' }}</span>
    @endif
</div>