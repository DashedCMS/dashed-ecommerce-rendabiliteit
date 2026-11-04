<?php

namespace Dashed\DashedEcommerceRendabiliteit;

use Closure;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceCore\Models\Product;
use Dashed\DashedEcommerceRendabiliteit\Contracts\KostprijsBron;
use Dashed\DashedEcommerceRendabiliteit\Kostprijs\InkoopprijsBron;

/** Uitbreidingspunten voor shops; als singleton gebonden en bereikbaar via rendabiliteit(). */
class Rendabiliteit
{
    private ?KostprijsBron $bron = null;

    /** @var list<Closure> */
    private array $uitsluiters = [];

    private ?Closure $link = null;

    private ?Closure $maatsleutel = null;

    private ?Closure $maatsleutels = null;

    /** @var list<Closure> */
    private array $signalen = [];

    /** Zet de bron (met argument) of geeft de huidige terug, standaard InkoopprijsBron. */
    public function kostprijsBron(?KostprijsBron $bron = null): KostprijsBron
    {
        if ($bron !== null) {
            $this->bron = $bron;
        }

        return $this->bron ??= new InkoopprijsBron();
    }

    /** @param Closure(): array<int> $closure */
    public function sluitGroepenUit(Closure $closure): void
    {
        $this->uitsluiters[] = $closure;
    }

    /** @return array<int> */
    public function uitgeslotenGroepIds(): array
    {
        $ids = [];
        foreach ($this->uitsluiters as $uitsluiter) {
            foreach ($uitsluiter() as $id) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * De link bij "mist kostprijs" per groep × maatsleutel. Het resultaat is
     * ['url' => string, 'label' => string] met optioneel 'reden' (waarom de
     * kostprijs ontbreekt) en 'maat' (leesbare naam van de maat).
     *
     * @param Closure(int, string): ?array $closure
     */
    public function kostprijsLink(Closure $closure): void
    {
        $this->link = $closure;
    }

    public function linkVoor(int $groepId, string $sleutel): ?array
    {
        return $this->link ? ($this->link)($groepId, $sleutel) : null;
    }

    /** @param Closure(Product): string $closure */
    public function maatsleutel(Closure $closure): void
    {
        $this->maatsleutel = $closure;
    }

    public function maatsleutelVoor(Product $product): string
    {
        return $this->maatsleutel ? (string) ($this->maatsleutel)($product) : '';
    }

    /**
     * Snellere variant van maatsleutel(): sleutels voor veel producten tegelijk.
     *
     * @param  Closure(iterable<Product>): array<int, string>  $closure  product-id → sleutel
     */
    public function maatsleutels(Closure $closure): void
    {
        $this->maatsleutels = $closure;
    }

    /**
     * @param  iterable<Product>  $producten
     * @return array<int, string> product-id → maatsleutel ('' = één maat)
     */
    public function maatsleutelsVoor(iterable $producten): array
    {
        $producten = collect($producten);

        if ($this->maatsleutels) {
            $sleutels = [];
            foreach (($this->maatsleutels)($producten) as $id => $sleutel) {
                $sleutels[(int) $id] = (string) $sleutel;
            }

            foreach ($producten as $product) {
                $sleutels[(int) $product->id] ??= '';
            }

            return $sleutels;
        }

        $sleutels = [];
        foreach ($producten as $product) {
            $sleutels[(int) $product->id] = $this->maatsleutelVoor($product);
        }

        return $sleutels;
    }

    /**
     * Signalen voor de Marges-pagina. Bekende soort: ['soort' => 'verwacht_duurder',
     * 'product_id' => int] (zo'n variant telt niet als prijsafwijking).
     *
     * @param Closure(Collection): list<array> $closure
     */
    public function extraSignalen(Closure $closure): void
    {
        $this->signalen[] = $closure;
    }

    /** @return list<array> */
    public function signalen(Collection $producten): array
    {
        $uit = [];
        foreach ($this->signalen as $closure) {
            foreach ($closure($producten) as $signaal) {
                $uit[] = $signaal;
            }
        }

        return $uit;
    }
}
