{{-- Resultatenrekening van één maand: omzet − kosten = marge − advertenties − vaste lasten = winst. Verwacht $rapport en $f. --}}
<?php
    $t = $rapport->totaal;
$aandeel = fn (float $bedrag) => $t['omzet'] > 0 ? $f->procent($bedrag / $t['omzet'] * 100) : '—';
$geschat = round(array_sum(array_column($rapport->advertentiesDetail, 'geschat')), 2);
$verzendNotitie = $f->verzendNotitie($rapport);
?>
<table class="kaarten">
    <tr>
        <td><div class="kaart"><div class="kaart-label">Omzet ({{ $t['aantalOrders'] }} orders)</div><div class="kaart-waarde">{{ $f->geld($t['omzet']) }}</div></div></td>
        <td><div class="kaart"><div class="kaart-label">Marge ({{ $f->margeProcent($t) }})</div><div class="kaart-waarde">{{ $f->geld($t['marge']) }}</div></div></td>
        <td><div class="kaart"><div class="kaart-label">Winst</div><div class="kaart-waarde {{ $rapport->winst < 0 ? 'neg' : '' }}">{{ $f->geld($rapport->winst) }}</div></div></td>
        <td><div class="kaart"><div class="kaart-label">Break-even omzet</div><div class="kaart-waarde">{{ $rapport->advertenties + $rapport->vasteLasten == 0 ? '—' : $f->geld($rapport->breakEven) }}</div></div></td>
    </tr>
</table>
<table class="tabel heel" style="width: 62%;">
    <thead>
        <tr><th class="l">Post</th><th>Bedrag</th><th>% van omzet</th></tr>
    </thead>
    <tbody>
        <tr><td class="l">Omzet</td><td>{{ $f->geld($t['omzet']) }}</td><td></td></tr>
        @foreach(['kostprijs' => 'Kostprijs', 'verpakking' => 'Verpakking', 'verzending' => 'Verzending', 'betaalkosten' => 'Betaalkosten', 'commissie' => 'Commissie'] as $veld => $label)
            <tr>
                <td class="l">− {{ $label }}@if($veld === 'verzending' && $verzendNotitie) <span class="klein">{{ $verzendNotitie }}</span>@endif</td>
                <td>{{ $f->geld($t[$veld]) }}</td>
                <td>{{ $aandeel($t[$veld]) }}</td>
            </tr>
        @endforeach
        <tr class="tussen"><td class="l">Marge</td><td>{{ $f->geld($t['marge']) }}</td><td>{{ $f->margeProcent($t) }}</td></tr>
        <tr>
            <td class="l">− Advertenties @if($geschat > 0)<span class="klein">(waarvan {{ $f->geld($geschat) }} geschat)</span>@endif</td>
            <td>{{ $f->geld($rapport->advertenties) }}</td>
            <td>{{ $aandeel($rapport->advertenties) }}</td>
        </tr>
        <tr><td class="l">− Vaste lasten</td><td>{{ $f->geld($rapport->vasteLasten) }}</td><td>{{ $aandeel($rapport->vasteLasten) }}</td></tr>
        <tr class="eind"><td class="l">Winst</td><td class="{{ $rapport->winst < 0 ? 'neg' : '' }}">{{ $f->geld($rapport->winst) }}</td><td>{{ $aandeel($rapport->winst) }}</td></tr>
    </tbody>
</table>
<p class="klein">Break-even = (advertenties + vaste lasten) ÷ margepercentage: de omzet die nodig is om advertenties en vaste lasten terug te verdienen; "—" zonder advertenties en vaste lasten of bij een marge van nul of minder.</p>
