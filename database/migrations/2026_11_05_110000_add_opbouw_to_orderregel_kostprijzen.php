<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Oude vorm (Lovora): vier vaste kolommen filament/print/handwerk/extra. Die
 * worden nullable met default 0 en de waarden gaan naar `opbouw`; het package
 * schrijft daarna alleen nog `kostprijs` en `opbouw`.
 */
return new class () extends Migration {
    private const ONDERDELEN = ['filament', 'print', 'handwerk', 'extra'];

    public function up(): void
    {
        if (! Schema::hasTable('orderregel_kostprijzen')
            || ! Schema::hasColumn('orderregel_kostprijzen', 'filament')
            || Schema::hasColumn('orderregel_kostprijzen', 'opbouw')) {
            return;
        }

        Schema::table('orderregel_kostprijzen', function (Blueprint $table) {
            $table->json('opbouw')->nullable()->after('kostprijs');

            foreach (self::ONDERDELEN as $kolom) {
                $table->decimal($kolom, 10, 4)->nullable()->default(0)->change();
            }
        });

        DB::table('orderregel_kostprijzen')
            ->select(['id', ...self::ONDERDELEN])
            ->orderBy('id')
            ->chunkById(1000, function ($rijen) {
                foreach ($rijen as $rij) {
                    $opbouw = [];
                    foreach (self::ONDERDELEN as $kolom) {
                        $opbouw[$kolom] = (float) $rij->{$kolom};
                    }

                    DB::table('orderregel_kostprijzen')->where('id', $rij->id)->update(['opbouw' => json_encode($opbouw)]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('orderregel_kostprijzen', 'opbouw')) {
            Schema::table('orderregel_kostprijzen', fn (Blueprint $table) => $table->dropColumn('opbouw'));
        }
    }
};
