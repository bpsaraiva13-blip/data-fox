<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Login com Google e Apple, último acesso e verificação em duas etapas. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique();
            $table->string('apple_id')->nullable()->unique();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_via', 10)->nullable();   // password | google | apple
            // verificação em duas etapas (app autenticador: Google Authenticator, 1Password…)
            $table->text('app_authentication_secret')->nullable();
            $table->text('app_authentication_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['google_id', 'apple_id', 'last_login_at', 'last_login_via', 'app_authentication_secret', 'app_authentication_recovery_codes']));
    }
};
