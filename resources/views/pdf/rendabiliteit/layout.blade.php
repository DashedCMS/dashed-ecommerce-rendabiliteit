{{-- Gedeelde opmaak van de rendabiliteits-PDF's (dompdf: geen JS, geen flex/grid; tabellen en vaste kop/voet). --}}
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="utf-8">
    <title>{{ config('app.name') }} rendabiliteit – {{ $titel }} – {{ $periode }}</title>
    <style>
        @page { margin: 25mm 12mm 16mm 12mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 7.5pt; color: #1f2933; line-height: 1.3; }
        header { position: fixed; top: -18mm; left: 0; right: 0; height: 13mm; border-bottom: 0.8pt solid #30545B; }
        header table { width: 100%; border-collapse: collapse; }
        header td { vertical-align: bottom; padding: 0 0 2mm 0; }
        .merk { font-size: 14pt; font-weight: bold; color: #30545B; letter-spacing: 0.5pt; }
        .kop-titel { font-size: 9pt; color: #30545B; padding-left: 2mm; }
        .kop-rechts { text-align: right; font-size: 7.5pt; color: #52606d; }
        .kop-rechts strong { color: #1f2933; }
        footer { position: fixed; bottom: -11mm; left: 0; right: 0; height: 6mm; font-size: 7pt; color: #737373; }

        h1 { font-size: 15pt; color: #30545B; margin: 0 0 1mm 0; }
        h2 { font-size: 11pt; color: #30545B; margin: 5mm 0 2mm 0; }
        h3 { font-size: 8.5pt; color: #30545B; margin: 4mm 0 1.2mm 0; }
        .ondertitel { color: #52606d; margin: 0 0 3mm 0; }
        .klein { font-size: 6.5pt; color: #737373; }
        .nieuwe-pagina { page-break-before: always; }
        .heel { page-break-inside: avoid; }

        table.tabel { width: 100%; border-collapse: collapse; margin-bottom: 1mm; }
        table.tabel th { background: #eef4f2; color: #30545B; font-weight: bold; text-align: right; padding: 1.3mm 1.2mm; border-bottom: 0.6pt solid #b8cdc6; white-space: nowrap; }
        table.tabel td { padding: 1mm 1.2mm; border-bottom: 0.3pt solid #e4e7eb; text-align: right; white-space: nowrap; }
        table.tabel th.l, table.tabel td.l { text-align: left; }
        table.tabel td.naam { white-space: normal; }
        table.tabel tfoot td, table.tabel tr.totaal td { font-weight: bold; background: #f5f7f7; border-top: 0.8pt solid #30545B; border-bottom: none; }
        table.tabel tr.tussen td { font-weight: bold; border-top: 0.6pt solid #9aa5b1; }
        table.tabel tr.eind td { font-weight: bold; font-size: 8.5pt; border-top: 0.8pt solid #30545B; background: #f5f7f7; }
        .notitie { display: block; font-size: 6pt; color: #737373; font-weight: normal; }
        .neg { color: #b42318; }
        .goed { color: #1f7a4d; }
        .slecht { color: #b42318; }
        .gedempt { color: #9aa5b1; }

        .melding { border: 0.6pt solid #e6b34a; background: #fdf6e7; padding: 2mm 3mm; margin: 0 0 3mm 0; }
        .melding.info { border-color: #7cb4c4; background: #eef6f8; }
        .melding p { margin: 0 0 1mm 0; }
        .melding ul { margin: 0; padding-left: 4mm; }

        table.kaarten { width: 100%; border-collapse: collapse; margin: 0 0 3mm 0; }
        table.kaarten td { padding: 0 1mm; vertical-align: top; }
        table.kaarten td:first-child { padding-left: 0; }
        table.kaarten td:last-child { padding-right: 0; }
        .kaart { border: 0.6pt solid #d9e2df; background: #fafcfb; padding: 2mm 2.5mm; }
        .kaart-label { color: #52606d; font-size: 6.8pt; }
        .kaart-waarde { font-size: 11pt; font-weight: bold; color: #1f2933; }

        table.grafieken { width: 100%; border-collapse: separate; border-spacing: 3mm 0; margin: 0 -3mm; }
        table.grafieken > tbody > tr > td { width: 50%; vertical-align: top; padding-bottom: 4mm; }
        table.grafiek { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grafiek td { text-align: center; padding: 0 0.6mm; }
        table.grafiek td.pos { vertical-align: bottom; border-bottom: 0.8pt solid #52606d; }
        table.grafiek td.min { vertical-align: top; }
        table.grafiek td.min.zonder-pos { border-top: 0.8pt solid #52606d; }
        table.grafiek td.as { font-size: 6pt; color: #52606d; padding-top: 1mm; }
        .balk { width: 70%; margin: 0 auto; background: #4cb78d; }
        .balk.negatief { background: #d9534f; }
        .balk-waarde { font-size: 5.6pt; color: #3e4c59; white-space: nowrap; height: 10px; line-height: 10px; padding-bottom: 2px; }
        .min .balk-waarde { padding: 2px 0 0 0; }
    </style>
</head>
<body>
    <header>
        <table>
            <tr>
                <td><span class="merk">{{ config('app.name') }}</span><span class="kop-titel">Rendabiliteit · {{ $titel }}</span></td>
                <td class="kop-rechts"><strong>{{ $periode }}</strong><br>Alle bedragen excl. btw</td>
            </tr>
        </table>
    </header>
    <footer>Gegenereerd op {{ $gegenereerd->translatedFormat('j F Y \o\m H:i') }}</footer>

    <main>
        @yield('inhoud')
    </main>
</body>
</html>
