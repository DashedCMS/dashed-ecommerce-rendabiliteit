{{-- Uitsplitsing van één maand, zoals de uitgeklapte maand op de rapportpagina. Verwacht $rapport en $f. --}}
<?php
    $kosten = ['omzet' => 'Omzet', 'kostprijs' => 'Kostprijs', 'verpakking' => 'Verpakking', 'verzending' => 'Verzending', 'betaalkosten' => 'Betaalk.', 'commissie' => 'Commissie'];
?>
<h3>Per kanaal</h3>
<table class="tabel">
    <thead>
        <tr>
            <th class="l">Kanaal</th>
            <th>Orders</th>
            @foreach($kosten as $label)
                <th>{{ $label }}</th>
            @endforeach
            <th>Marge</th>
            <th>Marge %</th>
        </tr>
    </thead>
    <tbody>
        @forelse(collect($rapport->perKanaal)->sortByDesc('omzet') as $kanaal => $rij)
            <tr>
                <td class="l">{{ $kanaal }}</td>
                <td>{{ $rij['aantalOrders'] }}</td>
                @foreach($kosten as $veld => $label)
                    <td>{{ $f->geld($rij[$veld]) }}</td>
                @endforeach
                <td class="{{ $rij['marge'] < 0 ? 'neg' : '' }}">{{ $f->geld($rij['marge']) }}</td>
                <td>{{ $f->margeProcent($rij) }}</td>
            </tr>
        @empty
            <tr><td colspan="10" class="l gedempt">Geen orders in deze maand.</td></tr>
        @endforelse
    </tbody>
</table>

@if(count($rapport->perMarketingkanaal))
    <div class="heel">
        <h3>Per marketingkanaal</h3>
        <table class="tabel">
            <thead>
                <tr>
                    <th class="l">Kanaal</th>
                    <th>Orders</th>
                    <th>Omzet</th>
                    <th>Marge na orderkosten</th>
                    <th>Advertenties</th>
                    <th>ROAS</th>
                    <th>Break-even ROAS</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rapport->perMarketingkanaal as $kanaal => $rij)
                    <tr>
                        <td class="l">{{ $kanaal }}</td>
                        <td>{{ $rij['orders'] }}</td>
                        <td>{{ $f->geld($rij['omzet']) }}</td>
                        <td>{{ $f->geld($rij['marge']) }}</td>
                        <td>{{ $rij['advertenties'] > 0 ? $f->geld($rij['advertenties']) : '—' }}</td>
                        <td class="{{ $f->roasKlasse($rij['roas'], $rij['breakEvenRoas']) }}">{{ $f->getal($rij['roas']) }}</td>
                        <td>{{ $f->getal($rij['breakEvenRoas']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <p class="klein">Last-click op basis van de klik-ID in de order (gclid/fbclid/UTM). Break-even ROAS = omzet ÷ marge: daaronder eet de advertentie-uitgave de marge op. Meta en Google rapporteren zelf hoger (view-through, andere apparaten).</p>
    </div>
@endif

@if(count($rapport->advertentiesDetail))
    <div class="heel">
        <h3>Advertenties per platform</h3>
        <table class="tabel" style="width: 70%;">
            <thead>
                <tr><th class="l">Platform</th><th>Werkelijk</th><th>Geschat</th><th>Dagen geschat</th><th>Totaal</th></tr>
            </thead>
            <tbody>
                @foreach($rapport->advertentiesDetail as $platform => $detail)
                    <tr>
                        <td class="l">{{ $platform }}</td>
                        <td>{{ $f->geld($detail['werkelijk']) }}</td>
                        <td>{{ $detail['geschat'] > 0 ? $f->geld($detail['geschat']) : '—' }}</td>
                        <td>{{ $detail['dagenGeschat'] ?: '—' }}</td>
                        <td>{{ $f->geld($detail['werkelijk'] + $detail['geschat']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td class="l">Totaal</td><td colspan="3"></td><td>{{ $f->geld($rapport->advertenties) }}</td></tr>
            </tfoot>
        </table>
    </div>
@endif

@if(count($rapport->verzendDetail))
    <div class="heel">
        <h3>Verzending per vervoerder</h3>
        <table class="tabel" style="width: 70%;">
            <thead>
                <tr><th class="l">Vervoerder</th><th>Tarief</th><th>Factor</th><th>Verzending</th><th class="l">Bron</th></tr>
            </thead>
            <tbody>
                @foreach($rapport->verzendDetail as $vervoerder => $detail)
                    <tr>
                        <td class="l">{{ $vervoerder }}</td>
                        <td>{{ $f->geld($detail['tarief']) }}</td>
                        <td>{{ $f->getal($detail['factor']) }}</td>
                        <td>{{ $f->geld($detail['verzending']) }}</td>
                        <td class="l gedempt">{{ $f->bronLabel($detail['bron']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

<h3>Per productgroep</h3>
<table class="tabel">
    <thead>
        <tr><th class="l">Productgroep</th><th>Omzet</th><th>Kostprijs</th><th>Orderkosten</th><th>Marge</th><th>Marge %</th></tr>
    </thead>
    <tbody>
        @forelse(collect($rapport->perProductgroep)->sortByDesc('omzet') as $groep)
            <tr>
                <td class="l naam">{{ $groep['naam'] }}</td>
                <td>{{ $f->geld($groep['omzet']) }}</td>
                <td>{{ $f->geld($groep['kostprijs']) }}</td>
                <td>{{ $f->geld($groep['orderkosten']) }}</td>
                <td class="{{ $groep['marge'] < 0 ? 'neg' : '' }}">{{ $f->geld($groep['marge']) }}</td>
                <td>{{ $groep['omzet'] > 0 ? $f->procent($groep['marge'] / $groep['omzet'] * 100) : '—' }}</td>
            </tr>
        @empty
            <tr><td colspan="6" class="l gedempt">Geen productregels in deze maand.</td></tr>
        @endforelse
    </tbody>
</table>
