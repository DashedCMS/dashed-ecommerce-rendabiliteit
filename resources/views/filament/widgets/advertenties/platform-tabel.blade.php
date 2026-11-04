@php
    $euro = fn (?float $bedrag) => $bedrag === null ? '—' : '€ '.number_format($bedrag, 2, ',', '.');
    $factor = fn (?float $waarde) => $waarde === null ? '—' : number_format($waarde, 2, ',', '.');
    $th = 'px-3 py-2 font-medium';
    $td = 'px-3 py-2 tabular-nums';
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Per platform" description="Toegeschreven = last click via gclid (Google) of fbclid/utm (Meta). Orders met beide klik-ID's tellen alleen in het totaal.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-right text-gray-500 dark:text-gray-400">
                    <tr class="border-b border-gray-200 dark:border-white/10">
                        <th class="{{ $th }} text-left">Platform</th>
                        <th class="{{ $th }}">Uitgaven</th>
                        <th class="{{ $th }}">Omzet</th>
                        <th class="{{ $th }}">Orders</th>
                        <th class="{{ $th }}">Kosten per order</th>
                        <th class="{{ $th }}">ROAS</th>
                        <th class="{{ $th }}">Break-even</th>
                        <th class="{{ $th }}">Marge</th>
                        <th class="{{ $th }}">Marge min uitgaven</th>
                    </tr>
                </thead>
                <tbody class="text-right">
                    @forelse($totaal['perPlatform'] as $platform => $p)
                        @php($netto = round($p['marge'] - $p['uitgaven'], 2))
                        <tr class="border-b border-gray-100 dark:border-white/5">
                            <td class="{{ $td }} text-left font-medium">
                                {{ $platform }}
                                @if($p['dagenGeschat'] > 0)
                                    <span class="text-xs font-normal text-gray-500">({{ $p['dagenGeschat'] }} dagen geschat)</span>
                                @endif
                            </td>
                            <td class="{{ $td }}">{{ $euro($p['uitgaven']) }}</td>
                            <td class="{{ $td }}">{{ $euro($p['omzet']) }}</td>
                            <td class="{{ $td }}">{{ $p['orders'] }}</td>
                            <td class="{{ $td }}">{{ $euro($p['kostenPerOrder']) }}</td>
                            <td class="{{ $td }} {{ $p['roas'] !== null && $p['breakEvenRoas'] !== null ? ($p['roas'] >= $p['breakEvenRoas'] ? 'text-success-600' : 'text-danger-600') : '' }}">{{ $factor($p['roas']) }}</td>
                            <td class="{{ $td }}">{{ $factor($p['breakEvenRoas']) }}</td>
                            <td class="{{ $td }}">{{ $euro($p['marge']) }}</td>
                            <td class="{{ $td }} {{ $netto >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $euro($netto) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="{{ $td }} text-left text-gray-500">Geen advertentie-uitgaven in deze periode.</td></tr>
                    @endforelse
                </tbody>
                <tfoot class="text-right font-semibold">
                    <tr>
                        <td class="{{ $td }} text-left">Webshop totaal (MER)</td>
                        <td class="{{ $td }}">{{ $euro($totaal['uitgaven']) }}</td>
                        <td class="{{ $td }}">{{ $euro($totaal['omzet']) }}</td>
                        <td class="{{ $td }}">{{ $totaal['orders'] }}</td>
                        <td class="{{ $td }}">{{ $euro($totaal['kostenPerOrder']) }}</td>
                        <td class="{{ $td }}">{{ $factor($totaal['mer']) }}</td>
                        <td class="{{ $td }}">{{ $factor($totaal['breakEvenRoas']) }}</td>
                        <td class="{{ $td }}">{{ $euro($totaal['marge']) }}</td>
                        <td class="{{ $td }} {{ $totaal['netto'] >= 0 ? 'text-success-600' : 'text-danger-600' }}">{{ $euro($totaal['netto']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            {{ $periode->omschrijving() }} · alle bedragen excl. btw · vaste lasten tellen hier niet mee (zie Rendabiliteit → Rapport).
            Toegeschreven omzet Google + Meta: {{ $euro($totaal['toegeschrevenOmzet']) }} van {{ $euro($totaal['omzet']) }}.
        </p>
    </x-filament::section>
</x-filament-widgets::widget>
