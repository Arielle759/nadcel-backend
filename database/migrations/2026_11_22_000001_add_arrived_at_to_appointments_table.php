<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Heure à laquelle le salon a signalé l'arrivée de la cliente (null tant qu'elle n'est pas arrivée).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('arrived_at')->nullable()->after('no_show_detected_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('arrived_at');
        });
    }
};
