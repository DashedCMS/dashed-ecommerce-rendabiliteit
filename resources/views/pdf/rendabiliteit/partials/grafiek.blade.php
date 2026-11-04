{{-- Staafgrafiek in pure HTML-tabellen (dompdf kent geen JS). Verwacht $grafiek en $f. --}}
<?php
    $label = fn (?float $waarde) => $waarde === null ? '—' : ($grafiek['soort'] === 'geld' ? $f->geldKort($waarde) : $f->getal($waarde));
$toonWaarden = count($grafiek['balken']) <= 14;
?>
<div class="heel">
    <h3>{{ $grafiek['titel'] }}</h3>
    <table class="grafiek">
        @if($grafiek['hoogtePositief'] > 0)
            <tr>
                @foreach($grafiek['balken'] as $balk)
                    <td class="pos" style="height: {{ $grafiek['hoogtePositief'] }}px;">
                        @if($toonWaarden && $balk['negatief'] === 0)
                            <div class="balk-waarde">{{ $label($balk['waarde']) }}</div>
                        @endif
                        @if($balk['positief'] > 0)
                            <div class="balk" style="height: {{ $balk['positief'] }}px;"></div>
                        @endif
                    </td>
                @endforeach
            </tr>
        @endif
        @if($grafiek['hoogteNegatief'] > 0)
            <tr>
                @foreach($grafiek['balken'] as $balk)
                    <td class="min {{ $grafiek['hoogtePositief'] > 0 ? '' : 'zonder-pos' }}" style="height: {{ $grafiek['hoogteNegatief'] }}px;">
                        @if($balk['negatief'] > 0)
                            <div class="balk negatief" style="height: {{ $balk['negatief'] }}px;"></div>
                            @if($toonWaarden)
                                <div class="balk-waarde neg">{{ $label($balk['waarde']) }}</div>
                            @endif
                        @endif
                    </td>
                @endforeach
            </tr>
        @endif
        <tr>
            @foreach($grafiek['balken'] as $balk)
                <td class="as">{{ $f->maandKort($balk['maand']) }}</td>
            @endforeach
        </tr>
    </table>
</div>
