<?php

namespace Dashed\DashedEcommerceRendabiliteit\Commands;

use Throwable;
use Illuminate\Console\Command;
use Dashed\DashedCore\Models\BackgroundTask;
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

        $opnieuw = (bool) $this->option('opnieuw');

        // In het takenicoon van dashed-core, met een balk over de regels; een
        // ronde zonder werk laat geen regel achter. Het tellen loopt door
        // rescue(): de administratie mag het aanvullen nooit tegenhouden.
        $taak = null;

        if (! $dryRun && class_exists(BackgroundTask::class)) {
            $teVullen = (int) rescue(fn () => $vastlegger->aantalTeVullen($opnieuw), 0, report: false);

            $taak = $teVullen > 0
                ? BackgroundTask::begin('rendabiliteit-orderregel-kostprijzen', __('Kostprijzen van orderregels aanvullen'), $teVullen, notify: false)
                : null;
        }

        try {
            $aantal = $vastlegger->vulAan($opnieuw, $dryRun, fn (int $regels) => $taak?->advance($regels));
        } catch (Throwable $e) {
            $taak?->fail($e);

            throw $e;
        }

        $taak?->finish(__(':aantal orderregels aangevuld.', ['aantal' => $aantal]));

        $this->info($dryRun ? "Dry-run: {$aantal} orderregels komen in aanmerking" : "Aangevuld: {$aantal} orderregels");

        // Kostprijzen voeden het rendabiliteitsrapport; wissen is goedkoop, dus altijd.
        RendabiliteitCache::wis();

        return self::SUCCESS;
    }
}
