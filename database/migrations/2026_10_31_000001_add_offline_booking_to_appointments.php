<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Rendez-vous saisis par le salon (WhatsApp, téléphone, sur place) : la cliente
// n'a pas forcément de compte, on garde alors son nom et son téléphone.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
            $table->string('client_name', 120)->nullable()->after('client_id');
            $table->string('client_phone', 30)->nullable()->after('client_name');
            $table->string('source', 20)->default('online')->after('client_phone');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['client_name', 'client_phone', 'source']);
        });
        // client_id n'est pas remis en NOT NULL : des rendez-vous hors ligne
        // existants l'empêcheraient.
    }
};
