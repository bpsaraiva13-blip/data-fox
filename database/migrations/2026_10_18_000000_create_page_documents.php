<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Interface do Data Fox (a mesma da versão web): os dados de cada acervo ficam em documentos
 * JSON por coleção (peças, caixas, histórico, colecionadores…), lidos e gravados pela API /api/db
 * com as permissões conferidas no servidor. Fotos e anexos vão para a tabela assets (bucket).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('docs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->string('col', 40);                      // items, boxes, activity…
            $table->string('doc_id', 80);
            $table->jsonb('data')->nullable();              // null = apagado (fica para a sincronização)
            $table->unsignedBigInteger('rev')->index();     // ordem global das mudanças
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['acervo_id', 'col', 'doc_id']);
            $table->index(['acervo_id', 'col', 'rev']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('create sequence if not exists docs_rev_seq');
        }

        Schema::create('assets', function (Blueprint $table) {
            $table->string('id', 80)->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('acervo_id')->nullable()->constrained()->nullOnDelete();
            $table->string('path');
            $table->string('mime', 100)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->timestamps();
        });

        Schema::table('acervos', fn (Blueprint $t) => $t->jsonb('page')->nullable());   // ajustes da interface (PIN obrigatório, nomes antigos…)
        Schema::table('users', fn (Blueprint $t) => $t->jsonb('profile')->nullable());  // perfil da interface (nome de exibição, foto, PIN, cor…)
        Schema::table('invitations', fn (Blueprint $t) => $t->string('email')->nullable()->change());  // convite por link, sem e-mail
    }

    public function down(): void
    {
        Schema::table('invitations', fn (Blueprint $t) => $t->string('email')->nullable(false)->change());
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('profile'));
        Schema::table('acervos', fn (Blueprint $t) => $t->dropColumn('page'));
        Schema::dropIfExists('assets');
        Schema::dropIfExists('docs');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('drop sequence if exists docs_rev_seq');
        }
    }
};
