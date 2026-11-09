<?php

namespace Dashed\DashedEcommerceRendabiliteit\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Dashed\DashedEcommerceRendabiliteit\Instellingen\KoppelingInstellingen;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Koppelingen\KoppelingPrompt;

class KoppelingPromptTest extends TestCase
{
    public function test_elke_koppeling_heeft_stappen_en_velden(): void
    {
        foreach ([KoppelingInstellingen::META, KoppelingInstellingen::GOOGLE, KoppelingInstellingen::BOL] as $koppeling) {
            $this->assertNotEmpty(KoppelingPrompt::stappen($koppeling));
            $this->assertNotEmpty(KoppelingPrompt::VELDEN[$koppeling]);
        }
    }

    public function test_prompt_noemt_pagina_stand_en_laatste_fout(): void
    {
        $prompt = KoppelingPrompt::voor(
            KoppelingInstellingen::GOOGLE,
            'https://shop.test/dashed/rendabiliteit/advertentie-koppelingen',
            ['google_customer_id' => true, 'google_refresh_token' => false],
            ['at' => '2026-11-09T05:20:00+00:00', 'gelukt' => false, 'fout' => 'Google gaf status 400: invalid_grant'],
            true,
        );

        $this->assertStringContainsString('https://shop.test/dashed/rendabiliteit/advertentie-koppelingen', $prompt);
        $this->assertStringContainsString('Klant-ID: ingevuld', $prompt);
        $this->assertStringContainsString('Refresh token: leeg', $prompt);
        $this->assertStringContainsString('invalid_grant', $prompt);
        $this->assertStringContainsString('Elke nacht ophalen: aan', $prompt);
    }

    public function test_zonder_stand_nog_nooit_opgehaald(): void
    {
        $prompt = KoppelingPrompt::voor(KoppelingInstellingen::BOL, 'https://x', [], null, false);

        $this->assertStringContainsString('nog nooit opgehaald', $prompt);
        $this->assertStringContainsString('Advertising API', $prompt);
    }
}
