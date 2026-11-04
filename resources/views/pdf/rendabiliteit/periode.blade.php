@extends('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.layout')

@section('inhoud')
    <?php
        $kosten = ['omzet' => 'Omzet', 'kostprijs' => 'Kostprijs', 'verpakking' => 'Verpakking', 'verzending' => 'Verzending', 'betaalkosten' => 'Betaalkosten', 'commissie' => 'Commissie'];
    $geschat = fn ($rapport) => round(array_sum(array_column($rapport->advertentiesDetail, 'geschat')), 2);
    $totaalGeschat = \Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Pdf\RendabiliteitPdf::geschatteAdvertenties($rapporten);
    $zonderKosten = $totaal['advertenties'] + $totaal['vasteLasten'] == 0;
    ?>
    <h1>Rendabiliteit {{ $periode }}</h1>
    <p class="ondertitel">{{ $rapporten->count() }} {{ $rapporten->count() === 1 ? 'maand' : 'maanden' }} · {{ number_format($totaal['aantalOrders'], 0, ',', '.') }} orders · alle bedragen excl. btw</p>

    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.waarschuwingen')

    <table class="kaarten">
        <tr>
            <td><div class="kaart"><div class="kaart-label">Omzet</div><div class="kaart-waarde">{{ $f->geld($totaal['omzet']) }}</div></div></td>
            <td><div class="kaart"><div class="kaart-label">Marge ({{ $f->margeProcent($totaal) }})</div><div class="kaart-waarde">{{ $f->geld($totaal['marge']) }}</div></div></td>
            <td><div class="kaart"><div class="kaart-label">Advertenties + vaste lasten</div><div class="kaart-waarde">{{ $f->geld($totaal['advertenties'] + $totaal['vasteLasten']) }}</div></div></td>
            <td><div class="kaart"><div class="kaart-label">Winst</div><div class="kaart-waarde {{ $totaal['winst'] < 0 ? 'neg' : '' }}">{{ $f->geld($totaal['winst']) }}</div></div></td>
            <td><div class="kaart"><div class="kaart-label">Gemiddelde break-even per maand</div><div class="kaart-waarde">{{ $zonderKosten ? '—' : $f->geld($breakEvenGemiddeld) }}</div></div></td>
        </tr>
    </table>

    <table class="tabel">
        <thead>
            <tr>
                <th class="l">Maand</th>
                <th>Orders</th>
                @foreach($kosten as $label)
                    <th>{{ $label }}</th>
                @endforeach
                <th>Marge</th>
                <th>Marge %</th>
                <th>Advertenties</th>
                <th>Vaste lasten</th>
                <th>Winst</th>
            </tr>
        </thead>
        <tbody>
            @foreach($rapporten as $rapport)
                <?php $notitie = $f->verzendNotitie($rapport); ?>
                <tr>
                    <td class="l">{{ $f->maand($rapport->maand) }}</td>
                    <td>{{ $rapport->totaal['aantalOrders'] }}</td>
                    @foreach($kosten as $veld => $label)
                        <td>
                            {{ $f->geld($rapport->totaal[$veld]) }}
                            @if($veld === 'verzending' && $notitie)<span class="notitie">{{ $notitie }}</span>@endif
                        </td>
                    @endforeach
                    <td>{{ $f->geld($rapport->totaal['marge']) }}</td>
                    <td>{{ $f->margeProcent($rapport->totaal) }}</td>
                    <td>
                        {{ $f->geld($rapport->advertenties) }}
                        @if($geschat($rapport) > 0)<span class="notitie">(waarvan {{ $f->geld($geschat($rapport)) }} geschat)</span>@endif
                    </td>
                    <td>{{ $f->geld($rapport->vasteLasten) }}</td>
                    <td class="{{ $rapport->winst < 0 ? 'neg' : '' }}"><strong>{{ $f->geld($rapport->winst) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td class="l">Totaal</td>
                <td>{{ number_format($totaal['aantalOrders'], 0, ',', '.') }}</td>
                @foreach($kosten as $veld => $label)
                    <td>{{ $f->geld($totaal[$veld]) }}</td>
                @endforeach
                <td>{{ $f->geld($totaal['marge']) }}</td>
                <td>{{ $f->margeProcent($totaal) }}</td>
                <td>
                    {{ $f->geld($totaal['advertenties']) }}
                    @if($totaalGeschat > 0)<span class="notitie">(waarvan {{ $f->geld($totaalGeschat) }} geschat)</span>@endif
                </td>
                <td>{{ $f->geld($totaal['vasteLasten']) }}</td>
                <td class="{{ $totaal['winst'] < 0 ? 'neg' : '' }}">{{ $f->geld($totaal['winst']) }}</td>
            </tr>
        </tfoot>
    </table>
    <p class="klein">Marge % over de opgetelde omzet van de periode, niet het gemiddelde van de maanden. Gemiddelde break-even = gemiddelde advertenties + vaste lasten per maand ÷ margepercentage over de periode.</p>
    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.uitleg')

    @if($metDetail)
        @foreach($rapporten as $rapport)
            <div class="nieuwe-pagina">
                <h1>{{ $f->maand($rapport->maand) }}</h1>
                <p class="ondertitel">Detail van de maand · alle bedragen excl. btw</p>
                @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.waarschuwingen', ['waarschuwingen' => $rapport->waarschuwingen])
                @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.kerncijfers')
                @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.maand-detail')
            </div>
        @endforeach
    @endif
@endsection
