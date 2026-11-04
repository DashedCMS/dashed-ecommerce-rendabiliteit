<?php

namespace Dashed\DashedEcommerceRendabiliteit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Illuminate\Support\Collection;
use Dashed\DashedEcommerceRendabiliteit\Rendabiliteit;
use Dashed\DashedEcommerceRendabiliteit\Contracts\KostprijsBron;
use Dashed\DashedEcommerceRendabiliteit\Kostprijs\InkoopprijsBron;

class RendabiliteitRegistryTest extends TestCase
{
    public function test_standaard_bron_is_inkoopprijsbron(): void
    {
        $this->assertInstanceOf(InkoopprijsBron::class, (new Rendabiliteit())->kostprijsBron());
    }

    public function test_bron_kan_worden_vervangen(): void
    {
        $r = new Rendabiliteit();
        $bron = $this->createMock(KostprijsBron::class);

        $this->assertSame($bron, $r->kostprijsBron($bron));
        $this->assertSame($bron, $r->kostprijsBron());
    }

    public function test_uitgesloten_groepen_worden_samengevoegd_zonder_dubbelen(): void
    {
        $r = new Rendabiliteit();
        $this->assertSame([], $r->uitgeslotenGroepIds());

        $r->sluitGroepenUit(fn () => [1, 2]);
        $r->sluitGroepenUit(fn () => [2, 3]);

        $this->assertSame([1, 2, 3], $r->uitgeslotenGroepIds());
    }

    public function test_link_voor_geeft_null_zonder_registratie_en_anders_het_resultaat(): void
    {
        $r = new Rendabiliteit();
        $this->assertNull($r->linkVoor(5, 'x'));

        $r->kostprijsLink(fn (int $g, string $s) => ['url' => "/{$g}/{$s}"]);
        $this->assertSame(['url' => '/5/x'], $r->linkVoor(5, 'x'));
    }

    public function test_extra_signalen_voegt_alle_closures_samen(): void
    {
        $r = new Rendabiliteit();
        $this->assertSame([], $r->signalen(new Collection()));

        $r->extraSignalen(fn (Collection $p) => [['a' => $p->count()]]);
        $r->extraSignalen(fn () => [['b' => 1], ['c' => 2]]);

        $this->assertSame([['a' => 2], ['b' => 1], ['c' => 2]], $r->signalen(new Collection([1, 2])));
    }
}
