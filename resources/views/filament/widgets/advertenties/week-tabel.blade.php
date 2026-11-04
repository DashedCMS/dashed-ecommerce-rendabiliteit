@php
    $euro = fn (?float $bedrag) => $bedrag === null ? '—' : '€ '.number_format($bedrag, 0, ',', '.');
    $factor = fn (?float $waarde) => $waarde === null ? '—' : number_format($waarde, 2, ',', '.');
    $th = 'px-3 py-2 font-medium';
    $td = 'px-3 py-2 tabular-nums';
    $labels = [
        'gezond' => ['Gezond', 'bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400'],
        'krap' => ['Krap', 'bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400'],
        'verlies' => ['Verlies', 'bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400'],
        'geen' => ['Geen marge', 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400'],
    ];
    $richtingen = [
        'beter' => ['De goede kant op', 'text-success-600'],
        'gelijk' => ['Ongeveer gelijk', 'text-gray-600 dark:text-gray-300'],
        'slechter' => ['De verkeerde kant op', 'text-danger-600'],
    ];
    [$enkel, $meervoud] = $namen;
    $trend = $overzicht['trend'];
    $vergelijking = $trend === 1 ? 'Laatste volle '.$enkel.' tegen het '.$enkel.' ervoor' : 'Laatste '.$trend.' volle '.$meervoud.' tegen de '.$trend.' ervoor';
    $winsten = array_column(array_reverse($overzicht['rijen']), 'winst');
    $hoogste = max(1, ...array_map('abs', $winsten ?: [0]));
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Per {{ $enkel }}: gaan we de goede kant op?" description="Winst = marge (na kostprijs en orderkosten) min advertenties, webshop, excl. btw. Gezond = MER minstens 20% boven break-even, krap = winst maar dunner, verlies = advertenties eten de marge op.">
        <x-slot name="afterHeader">
            <x-filament::tabs>
                @foreach(\Dashed\DashedEcommerceRendabiliteit\Filament\Widgets\Advertenties\WeekTabel::PER as $sleutel => [$naam])
                    <x-filament::tabs.item :active="$per === $sleutel" wire:click="kies('{{ $sleutel }}')">{{ ucfirst($naam) }}</x-filament::tabs.item>
                @endforeach
            </x-filament::tabs>
        </x-slot>

        <div class="mb-4 flex flex-wrap items-end gap-6">
            @if($overzicht['richting'])
                <div>
                    <div class="text-xs text-gray-500">{{ $vergelijking }}</div>
                    <div class="text-lg font-semibold {{ $richtingen[$overzicht['richting']][1] }}">{{ $richtingen[$overzicht['richting']][0] }}</div>
                    <div class="text-sm tabular-nums text-gray-600 dark:text-gray-300">
                        Gemiddeld {{ $euro($overzicht['laatste']) }} winst per {{ $enkel }}, was {{ $euro($overzicht['ervoor']) }}
                        ({{ $overzicht['laatste'] - $overzicht['ervoor'] >= 0 ? '+' : '−' }}{{ $euro(abs($overzicht['laatste'] - $overzicht['ervoor'])) }})
                    </div>
                </div>
            @endif
            <div class="flex gap-0.5" title="Winst per {{ $enkel }}, oudste links; boven de lijn winst, eronder verlies">
                @foreach($winsten as $winst)
                    <div class="flex w-2 flex-col">
                        <div class="flex h-6 items-end border-b border-gray-300 dark:border-white/20">
                            @if($winst >= 0)<div class="w-2 rounded-t-sm bg-success-500" style="height: {{ max(2, round(abs($winst) / $hoogste * 24)) }}px"></div>@endif
                        </div>
                        <div class="h-6">
                            @if($winst < 0)<div class="w-2 rounded-b-sm bg-danger-500" style="height: {{ max(2, round(abs($winst) / $hoogste * 24)) }}px"></div>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-right text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="{{ $th }} text-left">{{ ucfirst($enkel) }}</th>
                        <th class="{{ $th }}">Omzet</th>
                        <th class="{{ $th }}">Orders</th>
                        <th class="{{ $th }}">Advertenties</th>
                        <th class="{{ $th }}">Kosten per order</th>
                        <th class="{{ $th }}">Marge</th>
                        <th class="{{ $th }}">Winst</th>
                        <th class="{{ $th }}">Winst % omzet</th>
                        <th class="{{ $th }}">MER</th>
                        <th class="{{ $th }}">Break-even</th>
                        <th class="{{ $th }}">Tov {{ $trend === 1 ? 'het '.$enkel : $trend.' '.$meervoud }} ervoor</th>
                        @if($jaarvergelijking)<th class="{{ $th }}">Tov vorig jaar</th>@endif
                        <th class="{{ $th }} text-left">Gezondheid</th>
                    </tr>
                </thead>
                <tbody class="text-right">
                    @foreach($overzicht['rijen'] as $week)
                        <tr class="border-b border-gray-100 dark:border-white/5 {{ $week['vol'] ? '' : 'text-gray-500' }}">
                            <td class="{{ $td }} text-left font-medium whitespace-nowrap">
                                {{ $week['label'] }}
                                @if(! $week['vol'])
                                    <span class="text-xs font-normal">(loopt nog)</span>
                                @endif
                            </td>
                            <td class="{{ $td }}">{{ $euro($week['omzet']) }}</td>
                            <td class="{{ $td }}">{{ $week['orders'] }}</td>
                            <td class="{{ $td }}">
                                {{ $euro($week['uitgaven']) }}
                                @if($week['geschat'] > 0)
                                    <span class="text-xs text-gray-500" title="{{ $week['geschat'] }} platformdagen geschat op het daggemiddelde">*</span>
                                @endif
                            </td>
                            <td class="{{ $td }}">{{ $euro($week['kostenPerOrder']) }}</td>
                            <td class="{{ $td }}">{{ $euro($week['marge']) }}</td>
                            <td class="{{ $td }} font-semibold {{ $week['winst'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $euro($week['winst']) }}</td>
                            <td class="{{ $td }}">{{ $week['winstProcent'] === null ? '—' : number_format($week['winstProcent'], 1, ',', '.').'%' }}</td>
                            <td class="{{ $td }}">{{ $factor($week['mer']) }}</td>
                            <td class="{{ $td }}">{{ $factor($week['breakEven']) }}</td>
                            <td class="{{ $td }} {{ $week['tovGemiddelde'] === null ? '' : ($week['tovGemiddelde'] >= 0 ? 'text-success-600' : 'text-danger-600') }}">
                                @if($week['tovGemiddelde'] === null)
                                    —
                                @else
                                    {{ $week['tovGemiddelde'] >= 0 ? '▲ +' : '▼ −' }}{{ $euro(abs($week['tovGemiddelde'])) }}
                                @endif
                            </td>
                            @if($jaarvergelijking)
                                <td class="{{ $td }} {{ $week['tovVorigJaar'] === null ? '' : ($week['tovVorigJaar'] >= 0 ? 'text-success-600' : 'text-danger-600') }}">
                                    @if($week['tovVorigJaar'] === null)
                                        —
                                    @else
                                        {{ $week['tovVorigJaar'] >= 0 ? '▲ +' : '▼ −' }}{{ $euro(abs($week['tovVorigJaar'])) }}
                                    @endif
                                </td>
                            @endif
                            <td class="{{ $td }} text-left">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium {{ $labels[$week['gezondheid']][1] }}">{{ $labels[$week['gezondheid']][0] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            {{ $van->translatedFormat('j M Y') }} t/m {{ $tot->translatedFormat('j M Y') }} (per {{ $enkel }}, minstens {{ \Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\AdvertentieDashboard::OVERZICHT[$per]['toon'] }} {{ $meervoud }}) · * = advertenties deels geschat · vaste lasten tellen niet mee.
            "Tov … ervoor" = winst van die {{ $enkel }} min het gemiddelde van de {{ $trend === 1 ? 'volle '.$enkel : $trend.' volle '.$meervoud }} ervoor.
            @if($jaarvergelijking) "Tov vorig jaar" = winst min die van dezelfde {{ $enkel }} een jaar eerder (alleen volle {{ $meervoud }}).@endif
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
