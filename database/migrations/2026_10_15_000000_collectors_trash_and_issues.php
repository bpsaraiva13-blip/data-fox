<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colecionadores (contatos ligados às movimentações), lixeira de 30 dias para
 * caixas, fotos e colecionadores, e pendências dispensadas por peça.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('collectors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('nick')->nullable();
            $table->string('kind', 12)->default('collector');      // collector | buyer | seller | dealer | other
            $table->string('doc', 20)->nullable();                  // CPF / CNPJ
            $table->date('birth')->nullable();
            $table->jsonb('interests')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('phone2', 30)->nullable();
            $table->string('instagram', 60)->nullable();
            $table->string('email')->nullable();
            $table->string('cep', 9)->nullable();
            $table->string('street')->nullable();
            $table->string('number', 20)->nullable();
            $table->string('complement')->nullable();
            $table->string('district')->nullable();
            $table->string('city')->nullable();
            $table->string('uf', 2)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['acervo_id', 'name']);
        });

        Schema::table('movements', function (Blueprint $table) {
            $table->foreignId('collector_id')->nullable()->after('person')->constrained()->nullOnDelete();
        });

        Schema::table('items', function (Blueprint $table) {
            $table->jsonb('issues_dismissed')->nullable();          // pendências dispensadas: ["nophoto", ...]
        });

        Schema::table('item_photos', function (Blueprint $table) {
            $table->softDeletes();                                  // foto removida vai para a lixeira
        });

        Schema::table('boxes', function (Blueprint $table) {
            $table->softDeletes();
            $table->jsonb('trashed_item_ids')->nullable();          // peças que estavam nela, para restaurar junto
            $table->dropUnique(['acervo_id', 'name']);
        });
        // o nome só precisa ser único entre as caixas que não estão na lixeira
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create unique index boxes_acervo_name_live on boxes (acervo_id, name) where deleted_at is null');
        } else {
            Schema::table('boxes', fn (Blueprint $t) => $t->index(['acervo_id', 'name']));
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop index if exists boxes_acervo_name_live');
        }
        Schema::table('boxes', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn('trashed_item_ids');
            $table->unique(['acervo_id', 'name']);
        });
        Schema::table('item_photos', fn (Blueprint $t) => $t->dropSoftDeletes());
        Schema::table('items', fn (Blueprint $t) => $t->dropColumn('issues_dismissed'));
        Schema::table('movements', fn (Blueprint $t) => $t->dropConstrainedForeignId('collector_id'));
        Schema::dropIfExists('collectors');
    }
};
