<x-filament-panels::page>
    <div id="marges-tabel" class="scroll-mt-24">
        {{ $this->table }}
    </div>

    <?php
        $s = $this->signalen;
    $k = $s['kengetallen'];
    $geld = fn (?float $bedrag) => $bedrag === null ? '—' : '€ '.number_format($bedrag, 2, ',', '.');
    $procent = fn (?float $p) => $p === null ? '—' : number_format($p, 1, ',', '.').'%';
    $th = 'px-3 py-2 font-medium whitespace-nowrap';
    $td = 'px-3 py-2 tabular-nums whitespace-nowrap text-right';
    $naarTabel = "document.getElementById('marges-tabel')?.scrollIntoView({ behavior: 'smooth' })";
    $blokken = [
        'verlies' => [
            'titel' => 'Verlies',
            'uitleg' => 'Prijs excl. btw onder de kostprijs, grootste verlies per stuk eerst.',
            'kolommen' => ['Groep' => 'groep', 'Kostprijs' => 'kostprijs', 'Prijs' => 'prijs', 'Verlies €' => 'verlies'],
            'filter' => 'verlies',
        ],
        'onder_doel' => [
            'titel' => 'Ver onder doelmarge',
            'uitleg' => 'Grootste verschil tussen adviesprijs en prijs (doelmarge '.$procent($s['doelmarge']).').',
            'kolommen' => ['Prijs' => 'prijs', 'Adviesprijs' => 'adviesprijs', 'Verschil €' => 'verschil', 'Marge %' => 'marge_procent'],
            'filter' => 'onder_doel',
        ],
        'boven_advies' => [
            'titel' => 'Ver boven advies',
            'uitleg' => 'Prijs meer dan '.number_format($s['boven_advies_procent'], 0, ',', '.').'% boven de adviesprijs (instelbaar onder Kostprijzen → Instellingen).',
            'kolommen' => ['Prijs' => 'prijs', 'Adviesprijs' => 'adviesprijs', '% boven advies' => 'procent_boven'],
            'filter' => 'boven_advies',
        ],
    ];
    $tegels = [
        ['label' => 'Onder doelmarge', 'waarde' => number_format($k['onder_doel'], 0, ',', '.'), 'filter' => 'onder_doel', 'kleur' => $k['onder_doel'] > 0 ? 'text-danger-600 dark:text-danger-400' : ''],
        ['label' => 'Met verlies', 'waarde' => number_format($k['verlies'], 0, ',', '.'), 'filter' => 'verlies', 'kleur' => $k['verlies'] > 0 ? 'text-danger-600 dark:text-danger-400' : ''],
        ['label' => 'Kostprijs onbekend', 'waarde' => number_format($k['onbekend'], 0, ',', '.'), 'filter' => 'onbekend', 'kleur' => ''],
        ['label' => 'Gemiddelde marge', 'waarde' => $procent($k['gemiddelde_marge']), 'filter' => null, 'kleur' => ''],
    ];
    ?>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach($tegels as $tegel)
            <div wire:key="tegel-{{ $loop->index }}" class="rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-800 dark:bg-gray-900">
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ $tegel['label'] }}</p>
                <p class="mt-1 text-2xl font-semibold tabular-nums {{ $tegel['kleur'] }}">{{ $tegel['waarde'] }}</p>
                @if($tegel['filter'])
                    <button type="button" class="mt-2 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                        x-on:click="$wire.toonInTabel('{{ $tegel['filter'] }}'); {{ $naarTabel }}">Toon in tabel</button>
                @else
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Gemiddelde van de marge % per product met kostprijs</p>
                @endif
            </div>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        @foreach($blokken as $sleutel => $blok)
            <x-filament::section wire:key="blok-{{ $sleutel }}" :heading="$blok['titel'].' ('.count($s[$sleutel]).')'" :description="$blok['uitleg']">
                <x-slot name="afterHeader">
                    <x-filament::button size="xs" color="gray" x-on:click="$wire.toonInTabel('{{ $blok['filter'] }}'); {{ $naarTabel }}">
                        Toon in tabel
                    </x-filament::button>
                </x-slot>

                @if(count($s[$sleutel]) === 0)
                    <p class="text-sm text-gray-500 dark:text-gray-400">Niets gevonden.</p>
                @else
                    <div class="-mx-3 overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-right text-gray-500 dark:text-gray-400">
                                <tr>
                                    <th class="{{ $th }} text-left">Product</th>
                                    @foreach($blok['kolommen'] as $label => $veld)
                                        <th class="{{ $th }} {{ $veld === 'groep' ? 'text-left' : '' }}">{{ $label }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @foreach($s[$sleutel] as $regel)
                                    <tr wire:key="{{ $sleutel }}-{{ $regel['id'] }}">
                                        <td class="px-3 py-2">{{ $regel['naam'] }}</td>
                                        @foreach($blok['kolommen'] as $veld)
                                            @if($veld === 'groep')
                                                <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $regel['groep'] }}</td>
                                            @elseif(in_array($veld, ['marge_procent', 'procent_boven'], true))
                                                <td class="{{ $td }}">{{ $procent($regel[$veld]) }}</td>
                                            @else
                                                <td class="{{ $td }} {{ in_array($veld, ['verlies', 'verschil'], true) ? 'font-medium text-danger-600 dark:text-danger-400' : '' }}">{{ $geld($regel[$veld]) }}</td>
                                            @endif
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-filament::section>
        @endforeach
    </div>

    <x-filament::section heading="Prijsverschillen binnen dezelfde maat ({{ count($s['prijsverschillen']) }})"
        description="Groep × maat waar de openbare varianten niet allemaal dezelfde prijs hebben. Een variant die bewust duurder is (zoals dual-color) is verwacht en telt niet mee.">
        @if(count($s['prijsverschillen']) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">Alle varianten binnen dezelfde groep en maat hebben dezelfde prijs (op bewust duurdere varianten na).</p>
        @else
            <div class="-mx-3 overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-right text-gray-500 dark:text-gray-400">
                        <tr>
                            <th class="{{ $th }} text-left">Groep</th>
                            <th class="{{ $th }} text-left">Maat</th>
                            <th class="{{ $th }}">Laagste</th>
                            <th class="{{ $th }}">Hoogste</th>
                            <th class="{{ $th }}">Varianten</th>
                            <th class="{{ $th }} text-left">Afwijkend van de gangbare prijs</th>
                            <th class="{{ $th }}"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                        @foreach($s['prijsverschillen'] as $regel)
                            <tr wire:key="prijsverschil-{{ $regel['groep_id'] }}-{{ md5($regel['maat']) }}" class="align-top">
                                <td class="px-3 py-2">{{ $regel['groep'] }}</td>
                                <td class="px-3 py-2">{{ $regel['maat'] }}</td>
                                <td class="{{ $td }}">{{ $geld($regel['laagste']) }}</td>
                                <td class="{{ $td }}">{{ $geld($regel['hoogste']) }}</td>
                                <td class="{{ $td }}">{{ $regel['aantal'] }}</td>
                                <td class="px-3 py-2">
                                    <p class="text-gray-500 dark:text-gray-400">Gangbaar: {{ $geld($regel['gangbare_prijs']) }}</p>
                                    <ul class="mt-1 space-y-0.5">
                                        @foreach($regel['afwijkend'] as $afwijking)
                                            <li wire:key="afwijking-{{ $afwijking['id'] }}" @class(['text-gray-500 dark:text-gray-400' => $afwijking['verwacht']])>
                                                {{ $afwijking['naam'] }}: <span class="tabular-nums">{{ $geld($afwijking['prijs']) }}</span>
                                                @if($afwijking['verwacht'])
                                                    <span class="text-xs">(verwacht duurder)</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <x-filament::button size="xs" color="gray" x-on:click="$wire.toonGroep({{ $regel['groep_id'] }}); {{ $naarTabel }}">
                                        Toon groep
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
