@php
    $queries = $queries ?? collect();
@endphp

@if($queries->isEmpty())
    <div class="flex h-[120px] items-center justify-center rounded-xl border border-dashed border-gray-300 text-sm text-gray-400 dark:border-gray-700 dark:text-gray-500">
        لا توجد كلمات مفتاحية لهذه الفترة — جرّب فترة أطول أو قم بمزامنة GSC أولاً.
    </div>
@else
    <div class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs font-semibold uppercase tracking-wider text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <tr>
                        <th class="px-4 py-2.5 text-right">الكلمة المفتاحية</th>
                        <th class="px-3 py-2.5 text-center">النقرات</th>
                        <th class="px-3 py-2.5 text-center">الظهور</th>
                        <th class="px-3 py-2.5 text-center">CTR</th>
                        <th class="px-3 py-2.5 text-center">الترتيب</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                    @foreach($queries as $row)
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-800/50">
                            <td class="max-w-[360px] truncate px-4 py-2 font-medium text-gray-900 dark:text-gray-100" title="{{ $row->query }}">{{ $row->query }}</td>
                            <td class="px-3 py-2 text-center">
                                @if((int) $row->total_clicks > 0)
                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 ring-1 ring-inset ring-green-600/20 dark:bg-green-900/30 dark:text-green-300">{{ number_format($row->total_clicks) }}</span>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-center text-gray-700 dark:text-gray-300">{{ number_format($row->total_impressions) }}</td>
                            <td class="px-3 py-2 text-center">
                                @php $ctr = (float) ($row->ctr ?? 0); @endphp
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $ctr >= 5 ? 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-900/30 dark:text-green-300' : ($ctr >= 2 ? 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-900/30 dark:text-amber-300' : 'bg-gray-50 text-gray-600 ring-gray-500/10 dark:bg-gray-800 dark:text-gray-400') }} ring-1 ring-inset">{{ $ctr }}%</span>
                            </td>
                            <td class="px-3 py-2 text-center">
                                @php $pos = round((float) ($row->avg_pos ?? 0), 1); @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $pos > 0 && $pos <= 3 ? 'bg-green-50 text-green-700 ring-green-600/20' : ($pos > 0 && $pos <= 10 ? 'bg-amber-50 text-amber-700 ring-amber-600/20' : 'bg-gray-50 text-gray-600 ring-gray-500/10') }} ring-1 ring-inset">#{{ $pos > 0 ? $pos : '—' }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="bg-gray-50 px-4 py-2 text-[11px] text-gray-400 dark:bg-gray-800 dark:text-gray-500">
            مرتبة حسب الظهور — Top {{ $queries->count() }} كلمات لهذه الفترة
        </div>
    </div>
@endif
