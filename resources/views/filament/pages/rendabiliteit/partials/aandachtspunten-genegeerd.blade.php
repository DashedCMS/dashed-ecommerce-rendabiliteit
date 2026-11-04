{{-- Genegeerde aandachtspunten van één blok, achter "Toon genegeerde (n)"; verwacht $rijen (met 'sleutel') en $label (rij → tekst). --}}
@if(count($rijen))
    <div x-data="{ toon: false }" class="mt-2 text-xs">
        <button type="button" class="text-gray-400 hover:text-gray-600 hover:underline dark:hover:text-gray-300" x-on:click="toon = ! toon">
            <span x-text="toon ? 'Verberg genegeerde ({{ count($rijen) }})' : 'Toon genegeerde ({{ count($rijen) }})'">Toon genegeerde ({{ count($rijen) }})</span>
        </button>
        <ul x-show="toon" x-cloak class="mt-1 space-y-1 text-gray-400">
            @foreach($rijen as $rij)
                <li wire:key="genegeerd-{{ md5($rij['sleutel']) }}">
                    {{ $label($rij) }}
                    <button type="button" class="ml-2 text-primary-600 hover:underline dark:text-primary-400" wire:click="nietMeerNegeren(@js($rij['sleutel']))">Niet meer negeren</button>
                </li>
            @endforeach
        </ul>
    </div>
@endif
