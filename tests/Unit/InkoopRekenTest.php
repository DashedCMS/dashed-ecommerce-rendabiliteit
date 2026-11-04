<?php

namespace Dashed\DashedEcommerceRendabiliteit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommerceRendabiliteit\Services\Inkoop\InkoopfactuurVerwerker;

class InkoopRekenTest extends TestCase
{
    public function test_prijs_incl_btw_wordt_excl_btw(): void
    {
        $this->assertSame(10.0, InkoopfactuurVerwerker::nettoStuksprijs(12.10, 0, true, 21));
    }

    public function test_korting_gaat_van_de_stuksprijs_af(): void
    {
        $this->assertSame(8.5, InkoopfactuurVerwerker::nettoStuksprijs(10.00, 1.50, false, 21));
    }

    public function test_korting_op_een_prijs_incl_btw_gaat_er_eerst_af(): void
    {
        $this->assertSame(8.5, InkoopfactuurVerwerker::nettoStuksprijs(12.10, 1.815, true, 21));
    }

    public function test_stuksprijs_wordt_nooit_negatief(): void
    {
        $this->assertSame(0.0, InkoopfactuurVerwerker::nettoStuksprijs(1.00, 2.00, false, 21));
    }

    public function test_kosten_worden_naar_regelwaarde_verdeeld(): void
    {
        // 10 × € 5 en 5 × € 10 (elk € 50 regelwaarde); € 2 kosten = 2% erbij.
        $this->assertSame(
            [5.10, 10.20],
            InkoopfactuurVerwerker::verdeelKosten([[10, 5.00], [5, 10.00]], 2.00),
        );
    }

    public function test_tien_euro_verzendkosten_over_honderd_euro_is_tien_procent(): void
    {
        $this->assertSame(
            [5.50, 11.00],
            InkoopfactuurVerwerker::verdeelKosten([[10, 5.00], [5, 10.00]], 10.00),
        );
    }

    public function test_zonder_kosten_of_regelwaarde_blijven_de_prijzen_gelijk(): void
    {
        $this->assertSame([5.0, 10.0], InkoopfactuurVerwerker::verdeelKosten([[10, 5.00], [5, 10.00]], 0));
        $this->assertSame([0.0], InkoopfactuurVerwerker::verdeelKosten([[3, 0.00]], 5.00));
    }
}
