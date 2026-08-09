@props([
    'merchant' => 'amazon',
    'brand' => null,
])

<div class="absolute bottom-1 left-1 flex items-center rounded-lg border border-slate-200 bg-white/95 px-2 py-1 shadow-sm backdrop-blur">
    @if ($merchant === 'amazon')
        <img src="/icons/amazon.png" alt="Amazon" width="72" height="24" loading="lazy" class="h-6 w-auto object-contain">
    @elseif ($merchant === 'noon')
        <span class="px-1 text-xs font-black text-amber-500">noon</span>
    @else
        <span class="px-1 text-xs font-bold text-slate-700">{{ $brand ?: 'أمازون' }}</span>
    @endif
</div>