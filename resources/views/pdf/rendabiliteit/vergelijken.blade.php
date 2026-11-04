@extends('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.layout')

@section('inhoud')
    <?php
        $waarde = fn (array $regel, $bedrag) => match ($regel['soort']) {
            'aantal' => number_format($bedrag, 0, ',', '.'),
            'punten' => $f->procent($bedrag),
            default => $f->geld($bedrag),
        };
    $verschil = fn (array $regel) => match ($regel['soort']) {
        'aantal' => ($regel['verschil'] > 0 ? '+' : ($regel['verschil'] < 0 ? '−' : '')).number_format(abs($regel['verschil']), 0, ',', '.'),
        'punten' => $f->verschilPunten($regel['verschil']),
        default => $f->verschilGeld($regel['verschil']),
    };
    $maanden = fn (int $n) => $n.' '.($n === 1 ? 'maand' : 'maanden');
    ?>
    <h1>Vergelijking</h1>
    <p class="ondertitel">
        <strong>A</strong> = {{ $a['label'] }} ({{ $maanden($a['maanden']) }}) · <strong>B</strong> = {{ $b['label'] }} ({{ $maanden($b['maanden']) }}) · verschil = B − A, procent t.o.v. A · alle bedragen excl. btw
    </p>
    @if($a['maanden'] !== $b['maanden'])
        <div class="melding info"><p style="margin: 0;">A en B zijn niet even lang ({{ $maanden($a['maanden']) }} tegenover {{ $maanden($b['maanden']) }}): de bedragen zijn totalen, geen maandgemiddelden.</p></div>
    @endif

    @include('dashed-ecommerce-rendabiliteit::pdf.rendabiliteit.partials.waarschuwingen')

    <h3>Kerncijfers</h3>
    <table class="tabel heel">
        <thead>
            <tr><th class="l">Post</th><th>A</th><th>B</th><th>Verschil</th><th>Verschil %</th></tr>
        </thead>
        <tbody>
            @foreach($regels as $veld => $regel)
                <tr class="{{ in_array($veld, ['marge', 'winst'], true) ? 'tussen' : '' }}">
                    <td class="l">{{ $regel['label'] }}</td>
                    <td class="{{ $regel['a'] < 0 ? 'neg' : '' }}">{{ $waarde($regel, $regel['a']) }}</td>
                    <td class="{{ $regel['b'] < 0 ? 'neg' : '' }}">{{ $waarde($regel, $regel['b']) }}</td>
                    <td class="{{ $f->gunstigKlasse($regel['gunstig']) }}">{{ $verschil($regel) }}</td>
                    <td class="{{ $f->gunstigKlasse($regel['gunstig']) }}">{{ $f->verschilProcent($regel['procent']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="klein">Groen = gunstig (meer omzet, marge of winst; minder kosten), rood = ongunstig. Marge % verschilt in procentpunten (pp). Een procent t.o.v. een negatief bedrag (bijv. verlies) is over de absolute waarde berekend, dus minder verlies is positief.</p>

    @foreach(['perKanaal' => ['Per kanaal', 'Kanaal'], 'perProductgroep' => ['Per productgroep', 'Productgroep']] as $sleutel => [$kop, $kolom])
        <h3>{{ $kop }}</h3>
        <table class="tabel">
            <thead>
                <tr>
                    <th class="l">{{ $kolom }}</th>
                    <th>Omzet A</th><th>Omzet B</th><th>Verschil</th><th>%</th>
                    <th>Marge A</th><th>Marge B</th><th>Verschil</th><th>%</th>
                </tr>
            </thead>
            <tbody>
                @forelse($$sleutel as $rij)
                    <tr>
                        <td class="l naam">{{ $rij['naam'] }}</td>
                        <td>{{ $f->geld($rij['omzetA']) }}</td>
                        <td>{{ $f->geld($rij['omzetB']) }}</td>
                        <td class="{{ $rij['omzet']['bedrag'] > 0 ? 'goed' : ($rij['omzet']['bedrag'] < 0 ? 'slecht' : '') }}">{{ $f->verschilGeld($rij['omzet']['bedrag']) }}</td>
                        <td class="{{ $rij['omzet']['bedrag'] > 0 ? 'goed' : ($rij['omzet']['bedrag'] < 0 ? 'slecht' : '') }}">{{ $f->verschilProcent($rij['omzet']['procent']) }}</td>
                        <td class="{{ $rij['margeA'] < 0 ? 'neg' : '' }}">{{ $f->geld($rij['margeA']) }}</td>
                        <td class="{{ $rij['margeB'] < 0 ? 'neg' : '' }}">{{ $f->geld($rij['margeB']) }}</td>
                        <td class="{{ $rij['marge']['bedrag'] > 0 ? 'goed' : ($rij['marge']['bedrag'] < 0 ? 'slecht' : '') }}">{{ $f->verschilGeld($rij['marge']['bedrag']) }}</td>
                        <td class="{{ $rij['marge']['bedrag'] > 0 ? 'goed' : ($rij['marge']['bedrag'] < 0 ? 'slecht' : '') }}">{{ $f->verschilProcent($rij['marge']['procent']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="9" class="l gedempt">Geen gegevens.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endforeach
    <p class="klein">Marge per kanaal na kostprijs en orderkosten; marge per productgroep na kostprijs en het verdeelde aandeel van de orderkosten (zoals op de rapportpagina).</p>
@endsection
