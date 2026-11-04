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

    /** @param Closure(int, string): ?array $closure */
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

    /** @param Closure(Collection): list<array> $closure */
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
