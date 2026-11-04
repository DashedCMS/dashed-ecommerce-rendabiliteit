<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Inkoopfacturen van leveranciers: de regels zetten purchase_price op het
 * gekoppelde product. leverancier_artikelen onthoudt welke artikelcode van een
 * leverancier bij welk product hoort.
 */
return new class () extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('inkoopfacturen')) {
            Schema::create('inkoopfacturen', function (Blueprint $table) {
                $table->id();
                $table->string('leverancier');
                $table->string('factuurnummer');
                $table->date('factuurdatum')->nullable();
                $table->string('bestand')->nullable();
                $table->string('bron', 20)->default('handmatig');
                $table->boolean('prijzen_incl_btw')->default(false);
                $table->boolean('kosten_verdelen')->default(true);
                $table->string('status', 20)->default('concept');
                $table->text('notitie')->nullable();
                $table->timestamps();

                $table->unique(['leverancier', 'factuurnummer']);
            });
        }

        if (! Schema::hasTable('inkoopfactuur_regels')) {
            Schema::create('inkoopfactuur_regels', function (Blueprint $table) {
                $table->id();
                $table->foreignId('inkoopfactuur_id')->constrained('inkoopfacturen')->cascadeOnDelete();
                $table->string('artikelcode')->nullable();
                $table->string('ean')->nullable();
                $table->string('omschrijving')->nullable();
                $table->decimal('aantal', 12, 3)->default(1);
                $table->decimal('stuksprijs', 12, 4)->default(0);
                $table->decimal('korting', 12, 4)->default(0);
                $table->string('soort', 20)->default('product');
                $table->unsignedBigInteger('product_id')->nullable()->index();
                $table->string('koppeling', 20)->nullable();
                $table->timestamp('toegepast_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('leverancier_artikelen')) {
            Schema::create('leverancier_artikelen', function (Blueprint $table) {
                $table->id();
                $table->string('leverancier');
                $table->string('artikelcode');
                $table->unsignedBigInteger('product_id')->index();
                $table->timestamps();

                $table->unique(['leverancier', 'artikelcode']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inkoopfactuur_regels');
        Schema::dropIfExists('inkoopfacturen');
        Schema::dropIfExists('leverancier_artikelen');
    }
};
