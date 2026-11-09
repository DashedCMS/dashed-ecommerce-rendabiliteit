<x-filament-panels::page>
    @php
        $pagina = \Dashed\DashedEcommerceRendabiliteit\Filament\Pages\Rendabiliteit\RendabiliteitPage::class;
        $geld = fn (?float $b) => $pagina::geld($b);
        $procent = fn (float $p) => $pagina::procent($p);
        // Een margepercentage op nul of negatieve omzet (bijv. een grote creditnota) zegt niets: "—".
        $margeProcent = fn (array $rij) => $rij['omzet'] > 0 ? $pagina::procent($rij['margePercentage']) : '—';
        $rapporten = $this->rapporten;
        $totaal = $this->totaal;
        // Geschatte advertentiedagen zijn geen posten die als € 0 tellen: apart tonen.
        $alleWaarschuwingen = collect($this->waarschuwingen);
        $geschatteAdvertenties = $alleWaarschuwingen->filter(fn ($w, $code) => str_starts_with($code, 'advertenties_geschat:'));
        $waarschuwingen = $alleWaarschuwingen->diffKeys($geschatteAdvertenties)->all();
        $geschat = fn ($rapport) => round(array_sum(array_column($rapport->advertentiesDetail, 'geschat')), 2);
        $totaalGeschat = round($rapporten->sum(fn ($rapport) => $geschat($rapport)), 2);
        $kostenKolommen = [
            'omzet' => 'Omzet',
            'kostprijs' => 'Kostprijs',
            'verpakking' => 'Verpakking',
            'verzending' => 'Verzending',
            'betaalkosten' => 'Betaalkosten',
            'commissie' => 'Commissie',
        ];
        // Alle bedragen zijn excl. btw; dat staat ook onder elke geldkolom.
        $ex = new \Illuminate\Support\HtmlString('<span class="block font-normal text-gray-400" style="font-size: 0.7rem;">excl. btw</span>');
        // Verzending: werkelijk (factuur), geschat met factuurfactor of alleen tarief.
        $factorTekst = fn (?float $f) => $f === null ? '—' : number_format($f, 2, ',', '.');
        $roas = fn (?float $r) => $r === null ? '—' : number_format($r, 2, ',', '.');
        $verzendNotitie = function ($rapport) use ($factorTekst) {
            $samen = $rapport->verzendBron();

            return match ($samen['bron'] ?? null) {
                'factuur' => '(werkelijk)',
                'tarief' => '(tarief)',
                'gemiddelde', 'gemengd' => '(geschat, factor '.$factorTekst($samen['factor']).')',
                default => null,
            };
        };
        $bronLabels = ['factuur' => 'Factuur', 'gemiddelde' => 'Factuurgemiddelde', 'tarief' => 'Tarief', 'gemengd' => 'Gemengd'];
        $th = 'px-3 py-2 font-medium whitespace-nowrap';
        $td = 'px-3 py-2 tabular-nums whitespace-nowrap text-right';
    @endphp

    <div class="flex flex-wrap items-end gap-4">
        <label class="grid gap-1 text-sm">
            <span class="font-medium">Van</span>
            <input type="month" wire:model.live="van" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700">
        </label>
        <label class="grid gap-1 text-sm">
            <span class="font-medium">Tot en met</span>
            <input type="month" wire:model.live="tot" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700">
        </label>
        <p class="text-sm text-gray-500">
            {{ $pagina::maandLabel($this->periode[0]->format('Y-m')) }} t/m {{ $pagina::maandLabel($this->periode[1]->format('Y-m')) }} · alle bedragen excl. btw
        </p>
    </div>

    @if(count($waarschuwingen))
        @php
            // Elke waarschuwing is open te klikken: eronder staan de rijen uit "Wat mist" (Aandachtspunten) die
            // haar verklaren. Een rij is te negeren (zelfde tabel rendabiliteit_genegeerd als de aandachtspunten,
            // bijv. maatwerk dat per order anders is); de kopregel telt genegeerde regels dan niet meer mee.
            // De uitgesloten groepen hebben bewust geen kostprijs en staan als eigen rij met eigen negeer-sleutel.
            $punten = $this->aandachtspunten;
            $outlet = $punten['outlet'] ?? null;
            $outletRij = $outlet ? [
                'sleutel' => $outlet['sleutel'], 'soort' => 'kostprijs', 'titel' => $outlet['groep'],
                'toelichting' => $outlet['regels'].' '.($outlet['regels'] === 1 ? 'regel' : 'regels').'; deze groepen tellen bewust niet mee in kostprijs en marge',
                'omzet' => $outlet['omzet'], 'regels' => $outlet['regels'], 'url' => null, 'linkLabel' => null, 'genegeerd' => $outlet['genegeerd'],
            ] : null;
            $mistRijen = collect($punten['mist'])->map(fn ($r) => $r + ['genegeerd' => false])
                ->concat(collect($punten['genegeerd']['mist'] ?? [])->map(fn ($r) => $r + ['genegeerd' => true]))
                ->when($outletRij, fn ($c) => $c->push($outletRij));
            $uitleg = function (string $code) use ($mistRijen) {
                [$soort, $sleutel] = array_pad(explode(':', $code, 2), 2, null);

                return match ($soort) {
                    'kostprijs_onbekend' => $mistRijen->where('soort', 'kostprijs')->sortByDesc('omzet')->values(),
                    'geen_betaaltarief' => $mistRijen->where('sleutel', "betaaltarief:{$sleutel}")->values(),
                    'geen_verzendtarief' => $mistRijen->where('sleutel', "verzendtarief:{$sleutel}")->values(),
                    'geen_verpakkingstarief' => $mistRijen->where('sleutel', 'verpakkingstarief')->values(),
                    'geen_etsytarief' => $mistRijen->where('sleutel', 'commissie:etsy')->values(),
                    default => $mistRijen->where('sleutel', "overig:{$code}")->values(),
                };
            };
            $linkKlasse = 'font-medium text-primary-600 hover:underline dark:text-primary-400';
            $negeerKnopKlasse = 'text-xs text-gray-400 hover:text-gray-600 hover:underline dark:hover:text-gray-300';
        @endphp
        <div class="rounded-xl border border-warning-300 bg-warning-50 p-4 text-sm dark:border-warning-700 dark:bg-warning-950">
            <p class="mb-2 font-medium">Let op: deze posten tellen als € 0, dus de marge is mogelijk te rooskleurig. Klik een post open om te zien waar het aan ligt; wat je negeert telt hier niet meer mee.</p>
            <div class="space-y-1">
                @foreach($waarschuwingen as $code => $w)
                    @php
                        $alleRijen = $uitleg($code);
                        $rijen = $alleRijen->where('genegeerd', false)->values();
                        $genegeerdeRijen = $alleRijen->where('genegeerd', true)->values();
                        // Alleen als elke rij haar regels kent (kostprijs) is de kopregel uit de rijen op te bouwen.
                        $telbaar = $alleRijen->isNotEmpty() && $alleRijen->every(fn ($r) => isset($r['regels']));
                        $aantal = $telbaar ? $rijen->sum('regels') : $w['aantal'];
                        $omzet = $telbaar ? round($rijen->sum('omzet'), 2) : $w['omzet'];
                        $genegeerdAantal = $telbaar ? $genegeerdeRijen->sum('regels') : $genegeerdeRijen->count();
                    @endphp
                    {{-- open-staat in Alpine: na een Livewire-rerender (Negeren) zou het blok anders dichtklappen. --}}
                    <details wire:key="waarschuwing-{{ md5($code) }}" class="group" x-data="{ open: false, genegeerd: false }" x-bind:open="open" x-on:toggle="open = $event.target.open">
                        <summary class="cursor-pointer list-none">
                            <span class="inline-block w-3 text-gray-500 transition group-open:rotate-90">▸</span>
                            @if($telbaar && $aantal === 0)
                                <span class="text-gray-500">0 × {{ \Illuminate\Support\Str::lcfirst($w['omschrijving']) }}</span>
                            @else
                                {{ number_format($aantal, 0, ',', '.') }} × {{ \Illuminate\Support\Str::lcfirst($w['omschrijving']) }} ({{ $geld($omzet) }} omzet)
                            @endif
                            @if($genegeerdeRijen->isNotEmpty())
                                <span class="text-xs text-gray-500">· {{ $genegeerdAantal }} {{ $telbaar ? ($genegeerdAantal === 1 ? 'regel' : 'regels') : ($genegeerdAantal === 1 ? 'post' : 'posten') }} genegeerd</span>
                            @endif
                        </summary>
                        <div class="ml-3 mt-1 mb-2 rounded-lg border border-warning-200 bg-white p-3 dark:border-warning-800 dark:bg-gray-900">
                            @if($alleRijen->isNotEmpty())
                                <table class="w-full text-sm">
                                    <thead class="text-right text-gray-500">
                                        <tr>
                                            <th class="{{ $th }} text-left">Waar</th>
                                            <th class="{{ $th }} text-left">Waarom</th>
                                            @if($telbaar)<th class="{{ $th }}">Regels</th>@endif
                                            <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                                            <th class="{{ $th }}"></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach($rijen->concat($genegeerdeRijen) as $rij)
                                            <tr wire:key="waarschuwing-{{ md5($code) }}-{{ md5($rij['sleutel']) }}" class="border-t border-gray-100 dark:border-gray-800 {{ $rij['genegeerd'] ? 'text-gray-400' : '' }}" @if($rij['genegeerd']) x-show="genegeerd" x-cloak @endif>
                                                <td class="px-3 py-1 text-left">{{ $rij['titel'] }}</td>
                                                <td class="px-3 py-1 text-left {{ $rij['genegeerd'] ? '' : 'text-gray-600 dark:text-gray-400' }}">{{ $rij['toelichting'] }}</td>
                                                @if($telbaar)<td class="{{ $td }} py-1">{{ $rij['regels'] }}</td>@endif
                                                <td class="{{ $td }} py-1">{{ $geld($rij['omzet']) }}</td>
                                                <td class="px-3 py-1 text-right whitespace-nowrap">
                                                    @if($rij['url'])
                                                        <a href="{{ $rij['url'] }}" class="{{ $linkKlasse }}">{{ $rij['linkLabel'] }} →</a>
                                                    @endif
                                                    @if($rij['genegeerd'])
                                                        <button type="button" class="{{ $negeerKnopKlasse }} ml-2" wire:click="nietMeerNegeren(@js($rij['sleutel']))">Niet meer negeren</button>
                                                    @else
                                                        <button type="button" class="{{ $negeerKnopKlasse }} ml-2" wire:click="negeer(@js($rij['sleutel']))">Negeren</button>
                                                    @endif
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                                @if($genegeerdeRijen->isNotEmpty())
                                    <button type="button" class="mt-2 text-xs text-gray-500 hover:underline" x-on:click="genegeerd = ! genegeerd">
                                        <span x-show="! genegeerd">Toon genegeerde ({{ $genegeerdeRijen->count() }})</span>
                                        <span x-show="genegeerd" x-cloak>Verberg genegeerde</span>
                                    </button>
                                @endif
                                @if($code === 'kostprijs_onbekend')
                                    <p class="mt-2 text-xs text-gray-500">Per productgroep × maat; een verkochte regel telt zonder kostprijs als er geen rij in orderregel_kostprijzen staat. Negeer wat per order anders is (maatwerk) of bewust geen kostprijs heeft; vul anders de kostprijs aan en reken de regels door met rendabiliteit:vul-orderregel-kostprijzen.</p>
                                @endif
                            @else
                                <p class="text-gray-500">Geen verdere uitsplitsing beschikbaar.</p>
                            @endif
                        </div>
                    </details>
                @endforeach
            </div>
        </div>
    @endif

    @if($geschatteAdvertenties->isNotEmpty())
        <div class="rounded-xl border border-info-300 bg-info-50 p-4 text-sm dark:border-info-700 dark:bg-info-950">
            <p class="font-medium">Advertenties deels geschat met het daggemiddelde uit Tarieven (dagen zonder werkelijke uitgave):</p>
            <p class="mt-1">
                @foreach($geschatteAdvertenties as $code => $w)
                    <span wire:key="geschat-{{ md5($code) }}">{{ \Illuminate\Support\Str::after($code, 'advertenties_geschat:') }}: {{ number_format($w['aantal'], 0, ',', '.') }} {{ $w['aantal'] === 1 ? 'dag' : 'dagen' }}</span>@if(! $loop->last) · @endif
                @endforeach
            </p>
            <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">Importeer de werkelijke bedragen onder Rendabiliteit → Advertentie-uitgaven om de schatting te vervangen.</p>
        </div>
    @endif

    @include('dashed-ecommerce-rendabiliteit::filament.pages.rendabiliteit.partials.aandachtspunten', ['punten' => $this->aandachtspunten])

    <div class="overflow-x-auto rounded-xl border border-gray-200 bg-white dark:border-gray-800 dark:bg-gray-900">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-right dark:bg-gray-800">
                <tr>
                    <th class="{{ $th }} text-left">Maand</th>
                    @foreach($kostenKolommen as $label)
                        <th class="{{ $th }}">{{ $label }}{!! $ex !!}</th>
                    @endforeach
                    <th class="{{ $th }}">Marge{!! $ex !!}</th>
                    <th class="{{ $th }}">Marge %</th>
                    <th class="{{ $th }}">Advertenties{!! $ex !!}</th>
                    <th class="{{ $th }}">Vaste lasten{!! $ex !!}</th>
                    <th class="{{ $th }}">Winst{!! $ex !!}</th>
                </tr>
            </thead>
            @foreach($rapporten as $rapport)
                <?php $notitie = $verzendNotitie($rapport); ?>
                <tbody x-data="{ open: false }" wire:key="maand-{{ $rapport->maand }}" class="border-t border-gray-100 dark:border-gray-800">
                    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800" x-on:click="open = ! open">
                        <td class="px-3 py-2 whitespace-nowrap text-left">
                            <span class="inline-block w-3" x-text="open ? '▾' : '▸'">▸</span>
                            {{ $pagina::maandLabel($rapport->maand) }}
                            <span class="text-xs text-gray-500">({{ $rapport->totaal['aantalOrders'] }} orders)</span>
                        </td>
                        @foreach($kostenKolommen as $veld => $label)
                            <td class="{{ $td }}">
                                {{ $geld($rapport->totaal[$veld]) }}
                                @if($veld === 'verzending' && $notitie)
                                    <span class="block text-xs text-gray-500">{{ $notitie }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="{{ $td }}">{{ $geld($rapport->totaal['marge']) }}</td>
                        <td class="{{ $td }}">{{ $margeProcent($rapport->totaal) }}</td>
                        <td class="{{ $td }}">
                            {{ $geld($rapport->advertenties) }}
                            @if($geschat($rapport) > 0)
                                <span class="block text-xs text-gray-500">(waarvan {{ $geld($geschat($rapport)) }} geschat)</span>
                            @endif
                        </td>
                        <td class="{{ $td }}">{{ $geld($rapport->vasteLasten) }}</td>
                        <td @class([$td, 'font-medium', 'text-danger-600 dark:text-danger-400' => $rapport->winst < 0])>{{ $geld($rapport->winst) }}</td>
                    </tr>
                    <tr x-show="open" x-cloak>
                        <td colspan="12" class="bg-gray-50 px-3 py-3 dark:bg-gray-950">
                            <div class="grid gap-4">
                                <div>
                                    <p class="mb-1 font-medium">Per kanaal</p>
                                    <table class="w-full text-sm">
                                        <thead class="text-right text-gray-500">
                                            <tr>
                                                <th class="{{ $th }} text-left">Kanaal</th>
                                                <th class="{{ $th }}">Orders</th>
                                                @foreach($kostenKolommen as $label)
                                                    <th class="{{ $th }}">{{ $label }}{!! $ex !!}</th>
                                                @endforeach
                                                <th class="{{ $th }}">Marge{!! $ex !!}</th>
                                                <th class="{{ $th }}">Marge %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse(collect($rapport->perKanaal)->sortByDesc('omzet') as $kanaal => $rij)
                                                <tr>
                                                    <td class="px-3 py-1 text-left">{{ $kanaal }}</td>
                                                    <td class="{{ $td }}">{{ $rij['aantalOrders'] }}</td>
                                                    @foreach($kostenKolommen as $veld => $label)
                                                        <td class="{{ $td }}">{{ $geld($rij[$veld]) }}</td>
                                                    @endforeach
                                                    <td class="{{ $td }}">{{ $geld($rij['marge']) }}</td>
                                                    <td class="{{ $td }}">{{ $margeProcent($rij) }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="10" class="px-3 py-2 text-gray-500">Geen orders in deze maand.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @if(count($rapport->perMarketingkanaal))
                                    <div>
                                        <p class="mb-1 font-medium">Per marketingkanaal</p>
                                        <table class="w-full text-sm">
                                            <thead class="text-right text-gray-500">
                                                <tr>
                                                    <th class="{{ $th }} text-left">Kanaal</th>
                                                    <th class="{{ $th }}">Orders</th>
                                                    <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                                                    <th class="{{ $th }}">Marge na orderkosten{!! $ex !!}</th>
                                                    <th class="{{ $th }}">Advertenties{!! $ex !!}</th>
                                                    <th class="{{ $th }}">ROAS</th>
                                                    <th class="{{ $th }}">Break-even ROAS</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($rapport->perMarketingkanaal as $kanaal => $rij)
                                                    <tr wire:key="marketingkanaal-{{ $rapport->maand }}-{{ md5($kanaal) }}">
                                                        <td class="px-3 py-1 text-left">{{ $kanaal }}</td>
                                                        <td class="{{ $td }}">{{ $rij['orders'] }}</td>
                                                        <td class="{{ $td }}">{{ $geld($rij['omzet']) }}</td>
                                                        <td class="{{ $td }}">{{ $geld($rij['marge']) }}</td>
                                                        <td class="{{ $td }}">{{ $rij['advertenties'] > 0 ? $geld($rij['advertenties']) : '—' }}</td>
                                                        <td @class([
                                                            $td,
                                                            'text-danger-600 dark:text-danger-400' => $rij['roas'] !== null && $rij['breakEvenRoas'] !== null && $rij['roas'] < $rij['breakEvenRoas'],
                                                            'text-success-600 dark:text-success-400' => $rij['roas'] !== null && $rij['breakEvenRoas'] !== null && $rij['roas'] > $rij['breakEvenRoas'],
                                                        ])>{{ $roas($rij['roas']) }}</td>
                                                        <td class="{{ $td }}">{{ $roas($rij['breakEvenRoas']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        <p class="mt-1 text-xs text-gray-500">
                                            Last-click op basis van de klik-ID in de order (gclid/fbclid/UTM). Meta en Google rapporteren zelf hoger (view-through, andere apparaten). Herkomst wordt vastgelegd sinds mei 2026.
                                        </p>
                                    </div>
                                @endif
                                @if(count($rapport->verzendDetail))
                                    <div>
                                        <p class="mb-1 font-medium">Verzending per vervoerder</p>
                                        <table class="w-full text-sm">
                                            <thead class="text-right text-gray-500">
                                                <tr>
                                                    <th class="{{ $th }} text-left">Vervoerder</th>
                                                    <th class="{{ $th }}">Tarief{!! $ex !!}</th>
                                                    <th class="{{ $th }}">Factor</th>
                                                    <th class="{{ $th }}">Verzending{!! $ex !!}</th>
                                                    <th class="{{ $th }} text-left">Bron</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($rapport->verzendDetail as $vervoerder => $detail)
                                                    <tr wire:key="verzending-{{ $rapport->maand }}-{{ md5($vervoerder) }}">
                                                        <td class="px-3 py-1 text-left">{{ $vervoerder }}</td>
                                                        <td class="{{ $td }}">{{ $geld($detail['tarief']) }}</td>
                                                        <td class="{{ $td }}">{{ $factorTekst($detail['factor']) }}</td>
                                                        <td class="{{ $td }}">{{ $geld($detail['verzending']) }}</td>
                                                        <td class="px-3 py-1 text-left text-gray-500">{{ $bronLabels[$detail['bron']] ?? $detail['bron'] }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                @if(count($rapport->advertentiesDetail))
                                    <div>
                                        <p class="mb-1 font-medium">Advertenties per platform</p>
                                        <table class="w-full text-sm">
                                            <thead class="text-right text-gray-500">
                                                <tr>
                                                    <th class="{{ $th }} text-left">Platform</th>
                                                    <th class="{{ $th }}">Werkelijk{!! $ex !!}</th>
                                                    <th class="{{ $th }}">Geschat{!! $ex !!}</th>
                                                    <th class="{{ $th }}">Dagen geschat</th>
                                                    <th class="{{ $th }}">Totaal{!! $ex !!}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($rapport->advertentiesDetail as $platform => $detail)
                                                    <tr wire:key="advertenties-{{ $rapport->maand }}-{{ md5($platform) }}">
                                                        <td class="px-3 py-1 text-left">{{ $platform }}</td>
                                                        <td class="{{ $td }}">{{ $geld($detail['werkelijk']) }}</td>
                                                        <td class="{{ $td }}">{{ $detail['geschat'] > 0 ? $geld($detail['geschat']) : '—' }}</td>
                                                        <td class="{{ $td }}">{{ $detail['dagenGeschat'] ?: '—' }}</td>
                                                        <td class="{{ $td }}">{{ $geld($detail['werkelijk'] + $detail['geschat']) }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                                <div>
                                    <p class="mb-1 font-medium">Per productgroep</p>
                                    <table class="w-full text-sm">
                                        <thead class="text-right text-gray-500">
                                            <tr>
                                                <th class="{{ $th }} text-left">Productgroep</th>
                                                <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                                                <th class="{{ $th }}">Kostprijs{!! $ex !!}</th>
                                                <th class="{{ $th }}">Orderkosten{!! $ex !!}</th>
                                                <th class="{{ $th }}">Marge{!! $ex !!}</th>
                                                <th class="{{ $th }}">Marge %</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @forelse(collect($rapport->perProductgroep)->sortByDesc('omzet') as $groepId => $groep)
                                                <tr>
                                                    <td class="px-3 py-1 text-left">{{ $groep['naam'] }}</td>
                                                    <td class="{{ $td }}">{{ $geld($groep['omzet']) }}</td>
                                                    <td class="{{ $td }}">{{ $geld($groep['kostprijs']) }}</td>
                                                    <td class="{{ $td }}">{{ $geld($groep['orderkosten']) }}</td>
                                                    <td class="{{ $td }}">{{ $geld($groep['marge']) }}</td>
                                                    <td class="{{ $td }}">{{ $groep['omzet'] > 0 ? $procent($groep['marge'] / $groep['omzet'] * 100) : '—' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="6" class="px-3 py-2 text-gray-500">Geen productregels in deze maand.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            @endforeach
            <tfoot class="border-t-2 border-gray-200 bg-gray-50 font-medium dark:border-gray-700 dark:bg-gray-800">
                <tr>
                    <td class="px-3 py-2 text-left">Totaal</td>
                    @foreach($kostenKolommen as $veld => $label)
                        <td class="{{ $td }}">{{ $geld($totaal[$veld]) }}</td>
                    @endforeach
                    <td class="{{ $td }}">{{ $geld($totaal['marge']) }}</td>
                    <td class="{{ $td }}">{{ $margeProcent($totaal) }}</td>
                    <td class="{{ $td }}">
                        {{ $geld($totaal['advertenties']) }}
                        @if($totaalGeschat > 0)
                            <span class="block text-xs font-normal text-gray-500">(waarvan {{ $geld($totaalGeschat) }} geschat)</span>
                        @endif
                    </td>
                    <td class="{{ $td }}">{{ $geld($totaal['vasteLasten']) }}</td>
                    <td @class([$td, 'text-danger-600 dark:text-danger-400' => $totaal['winst'] < 0])>{{ $geld($totaal['winst']) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>

    <?php
        $reeks = $this->reeks;
        $reeksTotaal = \Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RapportReeks::totaal($reeks);
        $reeksPer = $this->reeksGroepering;
        $reeksProcent = fn (?float $p) => $p === null ? '—' : $procent($p);
    ?>
    <div class="grid gap-3 rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
        <div class="flex flex-wrap items-end gap-4">
            <p class="mr-auto font-medium">Verloop: advertenties, omzet, marge en winst</p>
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Van</span>
                <input type="date" wire:model.live="reeksVan" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700">
            </label>
            <label class="grid gap-1 text-sm">
                <span class="font-medium">Tot en met</span>
                <input type="date" wire:model.live="reeksTot" class="fi-input rounded-lg border-gray-300 dark:bg-gray-900 dark:border-gray-700">
            </label>
            <div class="flex overflow-hidden rounded-lg border border-gray-300 text-sm dark:border-gray-700">
                @foreach(\Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RapportReeks::PER as $sleutel => $label)
                    <button type="button" wire:click="$set('reeksPer', '{{ $sleutel }}')" @class([
                        'px-3 py-2',
                        'bg-primary-600 text-white' => $reeksPer === $sleutel,
                        'hover:bg-gray-50 dark:hover:bg-gray-800' => $reeksPer !== $sleutel,
                    ])>{{ $label }}</button>
                @endforeach
            </div>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-right dark:bg-gray-800">
                    <tr>
                        <th class="{{ $th }} text-left">{{ ['dag' => 'Dag', 'week' => 'Week', 'maand' => 'Maand'][$reeksPer] }}</th>
                        <th class="{{ $th }}">Advertenties{!! $ex !!}</th>
                        <th class="{{ $th }}">Omzet{!! $ex !!}</th>
                        <th class="{{ $th }}">Orders</th>
                        <th class="{{ $th }}">Marge{!! $ex !!}</th>
                        <th class="{{ $th }}">Marge %</th>
                        <th class="{{ $th }}">Vaste lasten{!! $ex !!}</th>
                        <th class="{{ $th }}">Winst{!! $ex !!}</th>
                        <th class="{{ $th }}">MER</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(array_reverse($reeks) as $rij)
                        <tr wire:key="reeks-{{ $reeksPer }}-{{ $rij['van'] }}" class="border-t border-gray-100 dark:border-gray-800">
                            <td class="px-3 py-2 whitespace-nowrap text-left">{{ $rij['label'] }}</td>
                            <td class="{{ $td }}">
                                {{ $geld($rij['advertenties']) }}
                                @if($rij['geschat'] > 0)
                                    <span class="block text-xs text-gray-500">(waarvan {{ $geld($rij['geschat']) }} geschat)</span>
                                @endif
                            </td>
                            <td class="{{ $td }}">{{ $geld($rij['omzet']) }}</td>
                            <td class="{{ $td }}">{{ $rij['orders'] }}</td>
                            <td class="{{ $td }}">{{ $geld($rij['marge']) }}</td>
                            <td class="{{ $td }}">{{ $reeksProcent($rij['margePercentage']) }}</td>
                            <td class="{{ $td }}">{{ $geld($rij['vasteLasten']) }}</td>
                            <td @class([$td, 'font-medium', 'text-danger-600 dark:text-danger-400' => $rij['winst'] < 0, 'text-success-600 dark:text-success-400' => $rij['winst'] > 0])>{{ $geld($rij['winst']) }}</td>
                            <td class="{{ $td }}">{{ $rij['advertenties'] > 0 ? $roas($rij['omzet'] / $rij['advertenties']) : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t-2 border-gray-200 font-medium dark:border-gray-700">
                    <tr>
                        <td class="px-3 py-2 text-left">Totaal</td>
                        <td class="{{ $td }}">{{ $geld($reeksTotaal['advertenties']) }}</td>
                        <td class="{{ $td }}">{{ $geld($reeksTotaal['omzet']) }}</td>
                        <td class="{{ $td }}">{{ $reeksTotaal['orders'] }}</td>
                        <td class="{{ $td }}">{{ $geld($reeksTotaal['marge']) }}</td>
                        <td class="{{ $td }}">{{ $reeksProcent($reeksTotaal['margePercentage']) }}</td>
                        <td class="{{ $td }}">{{ $geld($reeksTotaal['vasteLasten']) }}</td>
                        <td @class([$td, 'text-danger-600 dark:text-danger-400' => $reeksTotaal['winst'] < 0])>{{ $geld($reeksTotaal['winst']) }}</td>
                        <td class="{{ $td }}">{{ $reeksTotaal['advertenties'] > 0 ? $roas($reeksTotaal['omzet'] / $reeksTotaal['advertenties']) : '—' }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <p class="text-xs text-gray-500">
            Alle kanalen en alle advertentieplatformen, zoals de maandtabel hierboven, nieuwste bovenaan. Winst = marge min advertenties min vaste lasten; vaste lasten van een maand zijn gelijk over de dagen van die maand verdeeld. MER = omzet ÷ advertenties.
        </p>
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-800 dark:bg-gray-900">
        <p class="mb-2 font-medium">Break-even</p>
        <dl class="grid gap-1 sm:grid-cols-2">
            <div>
                <dt class="text-gray-500">
                    Laatste volledige maand{{ $this->breakEvenLaatsteMaand ? ' ('.$pagina::maandLabel($this->breakEvenLaatsteMaand['maand']).')' : '' }}
                </dt>
                <dd class="text-lg tabular-nums">{{ $this->breakEvenLaatsteMaand && ! $this->breakEvenLaatsteMaand['zonderKosten'] ? $geld($this->breakEvenLaatsteMaand['bedrag']) : '—' }}</dd>
            </div>
            <div>
                <dt class="text-gray-500">Gemiddeld over de periode</dt>
                <dd class="text-lg tabular-nums">{{ $this->breakEvenZonderKosten ? '—' : $geld($this->breakEvenGemiddeld) }}</dd>
            </div>
        </dl>
        @if($this->breakEvenZonderKosten || ($this->breakEvenLaatsteMaand['zonderKosten'] ?? false))
            <p class="mt-2 text-sm text-warning-600 dark:text-warning-400">Vul advertenties en vaste lasten in om een break-even te zien.</p>
        @endif
        <p class="mt-2 text-xs text-gray-500">
            Omzet die je per maand minimaal nodig hebt om advertenties en vaste lasten terug te verdienen.
            Berekend als (advertenties + vaste lasten) ÷ margepercentage; "—" als de marge nul of negatief is.
        </p>
    </div>

    <p class="text-xs text-gray-500">
        Omzet = orderbedrag min btw van betaalde orders (ook betaalde proforma-orders) en retouren, maand op besteldatum. Marge = omzet min kostprijs en orderkosten (verpakking, verzending, betaalkosten, commissie). Verzending = tarief per label maal de factor uit de verzendfactuur van die vervoerder (werkelijk), of het gemiddelde van de laatste drie facturen als er nog geen factuur is (geschat); zonder facturen alleen het tarief. Tarieven, advertenties en vaste lasten beheer je onder Rendabiliteit; advertenties tellen de werkelijke dagbedragen plus het daggemiddelde voor dagen zonder bedrag (t/m vandaag); het rapport wordt per maand gecachet en ververst direct bij wijziging van tarieven, advertenties of vaste lasten; ordergegevens van afgelopen maanden hoogstens één keer per dag.
    </p>
</x-filament-panels::page>
