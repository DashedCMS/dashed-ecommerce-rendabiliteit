{{-- Zelfde meldingen als op de rapportpagina; geschatte advertentiedagen apart. Verwacht $waarschuwingen en $f. --}}
<?php
    $alle = collect($waarschuwingen);
$geschatteAdvertenties = $alle->filter(fn ($w, $code) => str_starts_with($code, 'advertenties_geschat:'));
$overige = $alle->diffKeys($geschatteAdvertenties);
?>
@if($overige->isNotEmpty())
    <div class="melding heel">
        <p><strong>Let op: deze posten tellen als € 0, dus de marge is mogelijk te rooskleurig.</strong></p>
        <ul>
            @foreach($overige as $w)
                <li>{{ number_format($w['aantal'], 0, ',', '.') }} × {{ \Illuminate\Support\Str::lcfirst($w['omschrijving']) }} ({{ $f->geld($w['omzet']) }} omzet)</li>
            @endforeach
        </ul>
    </div>
@endif
@if($geschatteAdvertenties->isNotEmpty())
    <div class="melding info heel">
        <p><strong>Advertenties deels geschat met het daggemiddelde uit Tarieven (dagen zonder werkelijke uitgave):</strong></p>
        <p style="margin: 0;">
            @foreach($geschatteAdvertenties as $code => $w)
                {{ \Illuminate\Support\Str::after($code, 'advertenties_geschat:') }}: {{ number_format($w['aantal'], 0, ',', '.') }} {{ $w['aantal'] === 1 ? 'dag' : 'dagen' }}@if(! $loop->last) · @endif
            @endforeach
        </p>
    </div>
@endif
