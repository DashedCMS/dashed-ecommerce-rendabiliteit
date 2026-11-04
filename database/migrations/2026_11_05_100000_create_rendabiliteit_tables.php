<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;
use Dashed\DashedEcommerceRendabiliteit\Services\Rendabiliteit\Startwaarden;

/**
 * Alle tabellen van het rendabiliteitsrapport. Elke tabel alleen als hij nog
 * niet bestaat: in een shop die deze code al had (Lovora) doet dit niets.
 */
return new class () extends Migration {
    public function up(): void
    {
        $nieuweTarieven = false;

        if (! Schema::hasTable('kostentarieven')) {
            Schema::create('kostentarieven', function (Blueprint $table) {
                $table->id();
                $table->string('soort', 32)->index();
                $table->string('sleutel');
                $table->decimal('vast_bedrag', 8, 2)->default(0);
                $table->decimal('percentage', 6, 3)->default(0);
                $table->date('geldig_vanaf');
                $table->timestamps();
                $table->unique(['soort', 'sleutel', 'geldig_vanaf']);
            });
            $nieuweTarieven = true;
        }

        if (! Schema::hasTable('vaste_lasten')) {
            Schema::create('vaste_lasten', function (Blueprint $table) {
                $table->id();
                $table->string('omschrijving');
                $table->decimal('bedrag_per_maand', 10, 2);
                $table->date('vanaf');
                $table->date('tot')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('advertentie_uitgaven')) {
            Schema::create('advertentie_uitgaven', function (Blueprint $table) {
                $table->id();
                $table->date('datum');
                $table->string('platform');
                $table->decimal('bedrag', 10, 2);
                $table->string('bron', 16)->default('import');
                $table->timestamps();
                $table->unique(['datum', 'platform']);
            });
        }

        if (! Schema::hasTable('verzendfacturen')) {
            Schema::create('verzendfacturen', function (Blueprint $table) {
                $table->id();
                $table->string('vervoerder');
                $table->string('factuurnummer')->nullable();
                $table->date('factuurdatum')->nullable();
                $table->date('periode_van');
                $table->date('periode_tot');
                $table->decimal('bedrag', 10, 2);
                $table->decimal('btw_bedrag', 10, 2)->nullable();
                $table->unsignedInteger('aantal_zendingen')->nullable();
                $table->string('bestand')->nullable();
                $table->string('bron', 16);
                $table->string('notitie')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('rendabiliteit_genegeerd')) {
            Schema::create('rendabiliteit_genegeerd', function (Blueprint $table) {
                $table->id();
                $table->string('sleutel')->unique();
                $table->string('omschrijving')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('orderregel_kostprijzen')) {
            Schema::create('orderregel_kostprijzen', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_product_id')->unique();
                $table->foreignId('order_id')->index();
                $table->foreignId('product_id')->index();
                $table->decimal('kostprijs', 10, 4);
                $table->json('opbouw')->nullable();
                $table->string('bron', 16);
                $table->dateTime('berekend_op');
                $table->timestamps();
            });
        }

        if ($nieuweTarieven) {
            Startwaarden::zet();
        }
    }

    public function down(): void
    {
        // Bewust leeg: de tabellen kunnen data bevatten die van vóór dit package is.
    }
};
