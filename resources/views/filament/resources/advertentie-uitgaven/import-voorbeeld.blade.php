<?php
$labels = ['nieuw' => 'Nieuw', 'bijgewerkt' => 'Bijgewerkt', 'gelijk' => 'Ongewijzigd', 'fout' => 'Fout'];
$kleuren = [
    'nieuw' => 'text-success-700 dark:text-success-400',
    'bijgewerkt' => 'text-warning-700 dark:text-warning-400',
    'gelijk' => 'text-gray-500 dark:text-gray-400',
    'fout' => 'text-danger-700 dark:text-danger-400',
];
$tellers = array_merge(array_fill_keys(array_keys($labels), 0), array_count_values(array_column($regels, 'status')));
$geld = fn (?float $bedrag) => $bedrag === null ? '—' : '€ '.number_format($bedrag, 2, ',', '.');
?>
<div class="grid gap-3 text-sm">
    <p class="flex flex-wrap gap-x-4 gap-y-1">
        @foreach($labels as $status => $label)
            <span class="{{ $kleuren[$status] }}"><span class="font-medium">{{ $tellers[$status] }}</span> {{ strtolower($label) }}</span>
        @endforeach
    </p>

    @if($regels === [])
        <p class="text-gray-500">Geen regels gevonden.</p>
    @else
        <div class="max-h-96 overflow-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-left">
                <thead class="sticky top-0 bg-gray-50 text-gray-500 dark:bg-gray-800">
                    <tr>
                        <th class="px-3 py-2 font-medium">Regel</th>
                        <th class="px-3 py-2 font-medium">Datum</th>
                        <th class="px-3 py-2 font-medium">Platform</th>
                        <th class="px-3 py-2 text-right font-medium">Bedrag</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                    @foreach($regels as $regel)
                        <tr wire:key="import-regel-{{ $regel['regel'] }}">
                            <td class="px-3 py-1.5 tabular-nums text-gray-500">{{ $regel['regel'] }}</td>
                            <td class="px-3 py-1.5 tabular-nums whitespace-nowrap">{{ $regel['datum'] ? \Carbon\Carbon::parse($regel['datum'])->format('d-m-Y') : '—' }}</td>
                            <td class="px-3 py-1.5">{{ $regel['platform'] ?? '—' }}</td>
                            <td class="px-3 py-1.5 text-right tabular-nums whitespace-nowrap">{{ $geld($regel['bedrag']) }}</td>
                            <td class="px-3 py-1.5 {{ $kleuren[$regel['status']] }}">
                                <span class="font-medium">{{ $labels[$regel['status']] }}</span>
                                @if($regel['status'] === 'bijgewerkt')
                                    <span class="whitespace-nowrap">({{ $geld($regel['oud']) }} → {{ $geld($regel['bedrag']) }})</span>
                                @elseif($regel['status'] === 'fout')
                                    <span>: {{ $regel['reden'] }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
