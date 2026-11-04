<?php

namespace Dashed\DashedEcommerceRendabiliteit\Commands;

use Illuminate\Console\Command;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\RendabiliteitCache;
use Dashed\DashedEcommerceRendabiliteit\Services\Kostprijzen\OrderregelKostprijsVastlegger;

/**
 * Vult orderregels zonder kostprijs aan met de kostprijs van nu (bron
 * aangevuld). Veilig om opnieuw te draaien.
 */
class VulOrderregelKostprijzen extends Command
{
    protected $signature = 'rendabiliteit:vul-orderregel-kostprijzen
        {--opnieuw : Herbereken ook de al aangevulde regels}
        {--dry-run : Tel alleen, schrijf niets}';

    /** De oude naam uit Lovora blijft werken. */
    protected $aliases = ['lovora:vul-orderregel-kostprijzen'];

    protected $description = 'Vult de kostprijs aan op orderregels die er nog geen hebben';

    public function handle(OrderregelKostprijsVastlegger $vastlegger): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $aantal = $vastlegger->vulAan((bool) $this->option('opnieuw'), $dryRun);

        $this->info($dryRun ? "Dry-run: {$aantal} orderregels komen in aanmerking" : "Aangevuld: {$aantal} orderregels");

        // Kostprijzen voeden het rendabiliteitsrapport; wissen is goedkoop, dus altijd.
        RendabiliteitCache::wis();

        return self::SUCCESS;
    }
}
