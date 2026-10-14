<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vários acervos por instalação (cada pessoa cria o seu ou compartilha um),
 * equipe com perfis e permissão por caixa, convites, movimentações, coleções,
 * vitrine, conferências, anexos, registro de acesso e duplicatas ignoradas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable()->after('email');
            $table->string('phone', 30)->nullable()->after('avatar_path');
        });

        Schema::create('acervos', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('showcase_public')->default(false);   // vitrine aberta sem login
            $table->text('showcase_intro')->nullable();
            $table->timestamp('last_backup_at')->nullable();
            $table->timestamps();
        });

        Schema::create('acervo_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('editor');        // admin | editor | viewer | showcase
            $table->string('status', 10)->default('active');      // active | blocked
            $table->jsonb('boxes')->nullable();                   // null = todas; [ids] (+ "none" = sem caixa)
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['acervo_id', 'user_id']);
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('role', 10)->default('editor');
            $table->jsonb('boxes')->nullable();
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        // acervo_id nas tabelas existentes
        foreach (['boxes', 'items', 'tags', 'activity_logs'] as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->foreignId('acervo_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            });
        }

        // Registros que já existiam vão para um "Acervo principal"
        if (DB::table('items')->exists() || DB::table('boxes')->exists() || DB::table('tags')->exists()) {
            $owner = DB::table('users')->where('role', 'admin')->orderBy('id')->value('id') ?? DB::table('users')->orderBy('id')->value('id');
            $id = DB::table('acervos')->insertGetId(['name' => 'Acervo principal', 'slug' => 'acervo-principal', 'owner_id' => $owner, 'created_at' => now(), 'updated_at' => now()]);
            foreach (['boxes', 'items', 'tags', 'activity_logs'] as $t) {
                DB::table($t)->whereNull('acervo_id')->update(['acervo_id' => $id]);
            }
            foreach (DB::table('users')->pluck('role', 'id') as $uid => $role) {
                DB::table('acervo_user')->insert(['acervo_id' => $id, 'user_id' => $uid, 'role' => $role === 'admin' ? 'admin' : ($role ?: 'editor'), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        // Unicidade passa a ser por acervo
        Schema::table('boxes', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['acervo_id', 'name']);
        });
        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['acervo_id', 'name']);
        });
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->unique(['acervo_id', 'code']);
            $table->string('status', 10)->default('available')->index();   // available | loaned | sold
            $table->string('status_person')->nullable();
            $table->date('status_date')->nullable();
            $table->boolean('showcase')->default(false)->index();
            $table->string('split_from')->nullable();
        });

        Schema::create('movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);                             // loan | sale | return | undo
            $table->date('date');
            $table->string('person');                               // responsável
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['acervo_id', 'date']);
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['acervo_id', 'name']);
        });
        Schema::create('collection_item', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->primary(['collection_id', 'item_id']);
        });

        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('box_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 10)->default('open');          // open | closed
            $table->jsonb('found')->nullable();                     // ids encontrados na caixa
            $table->jsonb('extra')->nullable();                     // ids de outras caixas achados aqui
            $table->jsonb('summary')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('item_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 40);                             // Nota fiscal, Certificado…
            $table->string('name');
            $table->string('path');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 12);                             // view | pdf | labels | share | export | backup | catalog
            $table->foreignId('item_id')->nullable()->index();
            $table->string('label')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });

        Schema::create('dup_ignores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('acervo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_a')->constrained('items')->cascadeOnDelete();
            $table->foreignId('item_b')->constrained('items')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['item_a', 'item_b']);
        });
    }

    public function down(): void
    {
        foreach (['dup_ignores', 'access_logs', 'item_attachments', 'inventories', 'collection_item', 'collections', 'movements'] as $t) {
            Schema::dropIfExists($t);
        }
        Schema::table('items', function (Blueprint $table) {
            $table->dropUnique(['acervo_id', 'code']);
            $table->dropColumn(['status', 'status_person', 'status_date', 'showcase', 'split_from']);
            $table->unique('code');
        });
        Schema::table('tags', fn (Blueprint $t) => $t->dropUnique(['acervo_id', 'name']));
        Schema::table('boxes', fn (Blueprint $t) => $t->dropUnique(['acervo_id', 'name']));
        foreach (['boxes', 'items', 'tags', 'activity_logs'] as $t) {
            Schema::table($t, fn (Blueprint $table) => $table->dropConstrainedForeignId('acervo_id'));
        }
        Schema::table('tags', fn (Blueprint $t) => $t->unique('name'));
        Schema::table('boxes', fn (Blueprint $t) => $t->unique('name'));
        Schema::dropIfExists('invitations');
        Schema::dropIfExists('acervo_user');
        Schema::dropIfExists('acervos');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['avatar_path', 'phone']));
    }
};
