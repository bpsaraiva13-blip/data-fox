<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origem de peças enviadas de outro acervo e caixas vinculadas: a mesma caixa
 * (e as peças dela) aparece em outros acervos, sempre igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table) {
            $table->jsonb('transferred_from')->nullable();
        });

        Schema::create('box_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('box_id')->constrained()->cascadeOnDelete();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();   // acervo onde a caixa também aparece
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['box_id', 'acervo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('box_links');
        Schema::table('items', fn (Blueprint $table) => $table->dropColumn('transferred_from'));
    }
};
