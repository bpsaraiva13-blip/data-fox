<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Id de origem (versão web) para importar colecionadores, movimentações e coleções sem duplicar. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['collectors', 'movements', 'collections'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->string('import_ref')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (['collectors', 'movements', 'collections'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropColumn('import_ref'));
        }
    }
};
