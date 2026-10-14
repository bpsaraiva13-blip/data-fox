<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
        }

        Schema::create('boxes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('location')->nullable();
            $table->string('color', 7)->default('#2A6A5C');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('import_ref')->nullable()->unique();     // id na versão anterior (backup .zip)
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                 // Nº de tombo: ACV-2026-0001
            $table->string('title');
            $table->string('category')->nullable()->index();
            $table->string('era')->nullable();                 // Época / datação
            $table->string('origin')->nullable();
            $table->string('material')->nullable();
            $table->string('dimensions')->nullable();
            $table->string('condition')->nullable()->index();
            $table->decimal('value', 14, 2)->nullable();       // Valor estimado (R$)
            $table->text('description')->nullable();
            $table->string('provenance')->nullable();          // Procedência
            $table->date('acquired_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('box_id')->nullable()->constrained('boxes')->nullOnDelete();
            $table->jsonb('ai_keywords')->nullable();          // termos gerados pela IA para busca
            $table->text('ai_authenticity')->nullable();
            $table->timestamp('ai_analyzed_at')->nullable();
            $table->string('ai_status', 12)->default('none')->index(); // none|pending|running|done|failed
            $table->string('ai_error')->nullable();
            $table->string('batch')->nullable()->index();          // lote de cadastro
            $table->jsonb('merged_from')->nullable();              // tombos unidos a esta peça
            $table->string('import_ref')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('item_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->char('hash', 16)->nullable()->index();     // dHash 64 bits (hex)
            $table->jsonb('color')->nullable();                // [r,g,b] médio
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();                  // slug: art-nouveau
            $table->timestamps();
        });

        Schema::create('item_tag', function (Blueprint $table) {
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->primary(['item_id', 'tag_id']);
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20)->index();               // create|update|move|delete|tag|box
            $table->foreignId('item_id')->nullable()->index();  // sem FK: preserva histórico após exclusão
            $table->string('label');
            $table->jsonb('changes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name')->nullable();               // nome histórico (importação)
            $table->string('import_ref')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('item_tag');
        Schema::dropIfExists('tags');
        Schema::dropIfExists('item_photos');
        Schema::dropIfExists('items');
        Schema::dropIfExists('boxes');
    }
};
