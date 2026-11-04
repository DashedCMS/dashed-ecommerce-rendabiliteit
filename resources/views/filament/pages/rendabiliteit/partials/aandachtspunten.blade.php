{{-- Aandachtspunten voor de gekozen periode (Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Aandachtspunten); verwacht $punten. --}}
@php
    $pagina = \Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\RendabiliteitPage::class;
    $geld = fn (?float $b) => $pagina::geld($b);
    $getal = fn (?float $g) => $g === null ? '—' : number_format($g, 2, ',', '.');
    $maanden = fn (array $lijst) => collect($lijst)->map(fn ($m) => $pagina::maandLabel($m))->implode(', ');
    $ex = new \Illuminate\Support\HtmlString('<span class="block font-normal text-gray-400" style="font-size: 0.7rem;">excl. btw</span>');
    $th = 'px-3 py-2 font-medium whitespace-nowrap';
    $td = 'px-3 py-1 tabular-nums whitespace-nowrap text-right';
    $link = 'font-medium text-primary-600 hover:underline dark:text-primary-400';
    $aantalMarge = count($punten['marge']['rijen']) + $punten['marge']['rest'] + count($punten['kanalen']);
    $aantalMist = count($punten['mist']);
    $aantalAdvertenties = count($punten['advertenties']);
    $doelmarge = number_format($punten['doelmarge'], 0, ',', '.').'%';
    $zichtbaar = 15;
    $negeerKnop = 'text-xs text-gray-400 hover:text-gray-600 hover:underline dark:hover:text-gray-300';
    $genegeerd = $punten['genegeerd'];
    $soortLabels = ['kostprijs' => 'kostprijzen', 'tarief' => 'tarieven', 'verzendfactuur' => 'verzendfacturen', 'advertenties' => 'advertentiedagen', 'vaste_lasten' => 'vaste lasten', 'commissie' => 'commissies', 'overig' => 'overige'];
    $mistPerSoort = collect($punten['mist'])->countBy('soort');
@endphp

<details open class="rounded-xl border border-gray-200 bg-white text-sm dark:border-gray-800 dark:bg-gray-900" wire:key="aandachtspunten">
    {{-- Geen flex op summary: dan blijft het standaard in-/uitklapdriehoekje staan. --}}
    <summary class="cursor-pointer px-4 py-3">
        <span class="text-base font-medium">Aandachtspunten</span>
        <span class="ml-4 text-gray-500">Te weinig marge: {{ $aantalMarge }}</span>
        <span class="ml-4 text-gray-500">Wat mist: {{ $aantalMist }}</span>
        <span class="ml-4 text-gray-500">Advertenties onder break-even: {{ $aantalAdvertenties }}</span>
    </summary>

    <div class="grid gap-6 border-t border-gray-100 px-4 py-4 dark:border-gray-800">
        {{-- 1. Te weinig marge --}}
        <section>
            <p class="font-medium">1. Te weinig marge <span class="font-normal text-gray-500">({{ $aantalMarge }})</span></p>
            <p class="mb-2 text-xs text-gray-500">
                Productgroep × maat met een productmarge (omzet − kostprijs van de verkochte regels) onder de doelmarge van {{ $doelmarge }} of negatief, grootste tekort eerst.
                Tekort = omzet × doelmarge − productmarge. Alleen regels mét kostprijs; regels zonder kostprijs staan onder "Wat mist".
            </p>
            @if(count($punten['marge']['rijen']))
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-gray-500">
                            <tr>
                                <th class="{{ $th }} text-left">Productgroep · maat</th>
                                <th class="{{ $th }}">Verkocht</th>
                                <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                                <th class="{{ $th }}">Productmarge{!! $ex !!}</th>
                                <th class="{{ $th }}">Productmarge %</th>
                                <th class="{{ $th }}">Marge na orderkosten{!! $ex !!}</th>
                                <th class="{{ $th }}">Gem. verkoopprijs{!! $ex !!}</th>
                                <th class="{{ $th }}">Adviesprijs{!! $ex !!}</th>
                                <th class="{{ $th }}">Tekort t.o.v. doel{!! $ex !!}</th>
                                <th class="{{ $th }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($punten['marge']['rijen'] as $rij)
                                <tr wire:key="aandacht-marge-{{ md5($rij['sleutel']) }}" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-1 text-left">{{ $rij['groep'] }} <span class="text-gray-500">· {{ $rij['maat'] }}</span></td>
                                    <td class="{{ $td }}">{{ $rij['aantal'] }}</td>
                                    <td class="{{ $td }}">{{ $geld($rij['omzet']) }}</td>
                                    <td @class([$td, 'text-danger-600 dark:text-danger-400' => $rij['marge'] < 0])>{{ $geld($rij['marge']) }}</td>
                                    <td class="{{ $td }} text-danger-600 dark:text-danger-400">{{ $pagina::procent($rij['margeProcent']) }}</td>
                                    <td @class([$td, 'text-danger-600 dark:text-danger-400' => $rij['margeNaOrderkosten'] < 0])>{{ $geld($rij['margeNaOrderkosten']) }}</td>
                                    <td class="{{ $td }}">{{ $geld($rij['gemiddeldePrijs']) }}</td>
                                    <td class="{{ $td }}">
                                        {{ $geld($rij['adviesprijs']) }}
                                        @if($rij['adviesprijsIncl'] !== null)
                                            <span class="block text-xs text-gray-500">{{ $geld($rij['adviesprijsIncl']) }} incl.</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} font-medium">{{ $geld($rij['tekort']) }}</td>
                                    <td class="px-3 py-1 text-right whitespace-nowrap">
                                        <a href="{{ $rij['url'] }}" class="{{ $link }}">Marges →</a>
                                        <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="negeer(@js($rij['sleutel']))">Negeren</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($punten['marge']['rest'] > 0)
                    <p class="mt-1 text-xs text-gray-500">En nog {{ $punten['marge']['rest'] }} {{ $punten['marge']['rest'] === 1 ? 'groep' : 'groepen' }} × maat onder de doelmarge (samen {{ $geld($punten['marge']['restTekort']) }} tekort).</p>
                @endif
                <p class="mt-1 text-xs text-gray-500">Marge na orderkosten: de orderkosten van de groep uit het rapport, naar omzetaandeel over de maten verdeeld. Adviesprijs: kostprijs per stuk bij de doelmarge, afgerond op ,95 incl. btw.</p>
            @else
                <p class="text-gray-500">Niets gevonden.</p>
            @endif

            @if(count($punten['kanalen']))
                <p class="mt-3 mb-1 font-medium">Verkoopkanalen met verlies (marge na orderkosten)</p>
                <table class="w-full text-sm">
                    <thead class="text-right text-gray-500">
                        <tr>
                            <th class="{{ $th }} text-left">Kanaal</th>
                            <th class="{{ $th }}">Orders</th>
                            <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                            <th class="{{ $th }}">Marge na orderkosten{!! $ex !!}</th>
                            <th class="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($punten['kanalen'] as $rij)
                            <tr wire:key="aandacht-kanaal-{{ md5($rij['kanaal']) }}" class="border-t border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-1 text-left">{{ $rij['kanaal'] }}</td>
                                <td class="{{ $td }}">{{ $rij['orders'] }}</td>
                                <td class="{{ $td }}">{{ $geld($rij['omzet']) }}</td>
                                <td class="{{ $td }} text-danger-600 dark:text-danger-400">{{ $geld($rij['marge']) }}</td>
                                <td class="px-3 py-1 text-right whitespace-nowrap">
                                    <a href="{{ $rij['url'] }}" class="{{ $link }}">Orders →</a>
                                    <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="negeer(@js($rij['sleutel']))">Negeren</button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
            @include('dashed-ecommerce-rendabiliteit::filament.pages.rendabiliteit.partials.aandachtspunten-genegeerd', [
                'rijen' => array_merge($genegeerd['marge'], $genegeerd['kanalen']),
                'label' => fn ($rij) => isset($rij['kanaal']) ? 'Kanaal '.$rij['kanaal'] : $rij['groep'].' · '.$rij['maat'],
            ])
        </section>

        {{-- 2. Wat mist --}}
        <section x-data="{ alles: false }">
            <p class="font-medium">2. Wat mist <span class="font-normal text-gray-500">({{ $aantalMist }})</span></p>
            <p class="mb-2 text-xs text-gray-500">Ontbrekende gegevens die als € 0 of als schatting in het rapport tellen, grootste betrokken omzet eerst.</p>
            @if($aantalMist)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-gray-500">
                            <tr>
                                <th class="{{ $th }} text-left">Wat</th>
                                <th class="{{ $th }}">Betrokken omzet{!! $ex !!}</th>
                                <th class="{{ $th }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($punten['mist'] as $item)
                                <tr wire:key="aandacht-mist-{{ md5($item['sleutel']) }}" class="border-t border-gray-100 dark:border-gray-800" @if($loop->index >= $zichtbaar) x-show="alles" x-cloak @endif>
                                    <td class="px-3 py-1 text-left">
                                        <span class="font-medium">{{ $item['titel'] }}</span>
                                        <span class="block text-xs text-gray-500">
                                            {{ $item['toelichting'] }}@if(count($item['maanden'])) · {{ $maanden($item['maanden']) }}@endif
                                        </span>
                                    </td>
                                    <td class="{{ $td }}">{{ $geld($item['omzet']) }}</td>
                                    <td class="px-3 py-1 text-right whitespace-nowrap">
                                        @if($item['url'])
                                            <a href="{{ $item['url'] }}" class="{{ $link }}">{{ $item['linkLabel'] }} →</a>
                                        @endif
                                        <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="negeer(@js($item['sleutel']))">Negeren</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if($aantalMist > $zichtbaar)
                    <button type="button" class="{{ $link }} mt-1 text-xs" x-on:click="alles = ! alles" x-text="alles ? 'Minder tonen' : 'Alle {{ $aantalMist }} tonen'">Alle {{ $aantalMist }} tonen</button>
                @endif
                @if($mistPerSoort->filter(fn ($n) => $n > 1)->isNotEmpty())
                    <p class="mt-1 text-xs text-gray-500">
                        Alles negeren:
                        @foreach($mistPerSoort->filter(fn ($n) => $n > 1) as $soort => $n)
                            <button type="button" class="{{ $negeerKnop }} ml-1" wire:click="negeerSoort(@js($soort))" wire:key="negeer-soort-{{ $soort }}">{{ $soortLabels[$soort] ?? $soort }} ({{ $n }})</button>@if(! $loop->last) · @endif
                        @endforeach
                    </p>
                @endif
            @else
                <p class="text-gray-500">Niets gevonden.</p>
            @endif
            @if($punten['outlet'])
                <p class="mt-2 text-xs text-gray-500">
                    Ter info: {{ $punten['outlet']['groep'] }} — {{ $punten['outlet']['regels'] }} {{ $punten['outlet']['regels'] === 1 ? 'regel' : 'regels' }} zonder kostprijs ({{ $geld($punten['outlet']['omzet']) }} omzet excl. btw). Bewust: deze groepen tellen niet mee in kostprijs en marge.
                    @if($punten['outlet']['genegeerd'] ?? false)
                        <span class="text-xs text-gray-400">(genegeerd in de waarschuwing)</span>
                        <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="nietMeerNegeren(@js($punten['outlet']['sleutel']))">Niet meer negeren</button>
                    @else
                        <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="negeer(@js($punten['outlet']['sleutel']))">Negeren</button>
                    @endif
                </p>
            @endif
            @include('dashed-ecommerce-rendabiliteit::filament.pages.rendabiliteit.partials.aandachtspunten-genegeerd', [
                'rijen' => $genegeerd['mist'],
                'label' => fn ($item) => $item['titel'].(count($item['maanden']) ? ' · '.$maanden($item['maanden']) : ''),
            ])
        </section>

        {{-- 3. Advertenties onder break-even --}}
        <section>
            <p class="font-medium">3. Advertenties onder break-even <span class="font-normal text-gray-500">({{ $aantalAdvertenties }})</span></p>
            <p class="mb-2 text-xs text-gray-500">
                Maand × marketingkanaal waar de ROAS onder de break-even-ROAS ligt: de advertenties kosten meer dan de marge na orderkosten van dat kanaal. Tekort = advertenties − marge.
                Last-click; maanden vóór {{ $pagina::maandLabel(\Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Aandachtspunten::ATTRIBUTIE_VANAF) }} tellen niet mee (nog geen klik-ID's op de orders).
            </p>
            @if($aantalAdvertenties)
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-gray-500">
                            <tr>
                                <th class="{{ $th }} text-left">Maand · kanaal</th>
                                <th class="{{ $th }}">Advertenties{!! $ex !!}</th>
                                <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                                <th class="{{ $th }}">Marge na orderkosten{!! $ex !!}</th>
                                <th class="{{ $th }}">ROAS</th>
                                <th class="{{ $th }}">Break-even ROAS</th>
                                <th class="{{ $th }}">Tekort{!! $ex !!}</th>
                                <th class="{{ $th }}"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($punten['advertenties'] as $rij)
                                <tr wire:key="aandacht-adv-{{ md5($rij['sleutel']) }}" class="border-t border-gray-100 dark:border-gray-800">
                                    <td class="px-3 py-1 text-left">{{ $pagina::maandLabel($rij['maand']) }} <span class="text-gray-500">· {{ $rij['kanaal'] }}</span></td>
                                    <td class="{{ $td }}">
                                        {{ $geld($rij['advertenties']) }}
                                        @if($rij['deelsGeschat'])
                                            <span class="block text-xs text-gray-500">(deels geschat)</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }}">{{ $geld($rij['omzet']) }}</td>
                                    <td class="{{ $td }}">{{ $geld($rij['marge']) }}</td>
                                    <td class="{{ $td }} text-danger-600 dark:text-danger-400">{{ $getal($rij['roas']) }}</td>
                                    <td class="{{ $td }}">{{ $getal($rij['breakEvenRoas']) }}</td>
                                    <td class="{{ $td }} font-medium">{{ $geld($rij['tekort']) }}</td>
                                    <td class="px-3 py-1 text-right whitespace-nowrap">
                                        <a href="{{ $rij['url'] }}" class="{{ $link }}">Advertentie-uitgaven →</a>
                                        <button type="button" class="{{ $negeerKnop }} ml-2" wire:click="negeer(@js($rij['sleutel']))">Negeren</button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-gray-500">Niets gevonden.</p>
            @endif
            @include('dashed-ecommerce-rendabiliteit::filament.pages.rendabiliteit.partials.aandachtspunten-genegeerd', [
                'rijen' => $genegeerd['advertenties'],
                'label' => fn ($rij) => $pagina::maandLabel($rij['maand']).' · '.$rij['kanaal'],
            ])
        </section>
    </div>
</details>
