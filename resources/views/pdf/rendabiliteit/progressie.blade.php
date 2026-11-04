@extends('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.layout')

@section('inhoud')
    <?php
        $wijzigingKlasse = fn (?array $w) => $w === null || $w['bedrag'] === null ? '' : ($w['bedrag'] > 0 ? 'goed' : ($w['bedrag'] < 0 ? 'slecht' : ''));
    $totaalRoas = $totaal['advertenties'] > 0 ? round($totaal['omzet'] / $totaal['advertenties'], 2) : null;
    ?>
    <h1>Progressie {{ $periode }}</h1>
    <p class="ondertitel">Omzet, marge, winst en ROAS per maand · alle bedragen excl. btw</p>

    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.waarschuwingen')

    <table class="grafieken">
        <tr>
            <td>@include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.grafiek', ['grafiek' => $grafieken['omzet']])</td>
            <td>@include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.grafiek', ['grafiek' => $grafieken['marge']])</td>
        </tr>
        <tr>
            <td>@include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.grafiek', ['grafiek' => $grafieken['winst']])</td>
            <td>@include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.grafiek', ['grafiek' => $grafieken['roas']])</td>
        </tr>
    </table>
    <p class="klein">Negatieve waarden (verlies) staan in rood onder de nullijn. ROAS = omzet ÷ alle advertenties van die maand (alle kanalen, dus inclusief Bol, kassa en organisch); "—" zonder advertenties.</p>

    <div class="nieuwe-pagina">
        <h2 style="margin-top: 0;">Wijziging ten opzichte van de vorige maand</h2>
        <table class="tabel">
            <thead>
                <tr>
                    <th class="l">Maand</th>
                    <th>Omzet</th><th>Δ</th><th>Δ %</th>
                    <th>Marge</th><th>Δ</th><th>Δ %</th>
                    <th>Winst</th><th>Δ</th><th>Δ %</th>
                    <th>Advertenties</th>
                    <th>ROAS</th><th>Δ</th><th>Δ %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($maanden as $rij)
                    <?php $w = $rij['wijziging']; ?>
                    <tr>
                        <td class="l">{{ $f->maand($rij['maand']) }}</td>
                        @foreach(['omzet', 'marge', 'winst'] as $veld)
                            <td class="{{ $rij[$veld] < 0 ? 'neg' : '' }}">{{ $f->geld($rij[$veld]) }}</td>
                            <td class="{{ $wijzigingKlasse($w[$veld] ?? null) }}">{{ $w === null ? '' : $f->verschilGeld($w[$veld]['bedrag']) }}</td>
                            <td class="{{ $wijzigingKlasse($w[$veld] ?? null) }}">{{ $w === null ? '' : $f->verschilProcent($w[$veld]['procent']) }}</td>
                        @endforeach
                        <td>{{ $f->geld($rij['advertenties']) }}</td>
                        <td>{{ $f->getal($rij['roas']) }}</td>
                        <td class="{{ $wijzigingKlasse($w['roas'] ?? null) }}">{{ $w === null ? '' : $f->verschilGetal($w['roas']['bedrag']) }}</td>
                        <td class="{{ $wijzigingKlasse($w['roas'] ?? null) }}">{{ $w === null ? '' : $f->verschilProcent($w['roas']['procent']) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td class="l">Totaal</td>
                    <td>{{ $f->geld($totaal['omzet']) }}</td><td></td><td></td>
                    <td>{{ $f->geld($totaal['marge']) }}</td><td></td><td></td>
                    <td class="{{ $totaal['winst'] < 0 ? 'neg' : '' }}">{{ $f->geld($totaal['winst']) }}</td><td></td><td></td>
                    <td>{{ $f->geld($totaal['advertenties']) }}</td>
                    <td>{{ $f->getal($totaalRoas) }}</td><td></td><td></td>
                </tr>
            </tfoot>
        </table>
        <p class="klein">Δ = verschil met de maand ervoor; Δ % t.o.v. de vorige maand (over de absolute waarde, dus minder verlies is positief). De lopende maand is nog niet compleet.</p>
        @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.uitleg')
    </div>
@endsection
