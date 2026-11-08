<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Désactivation par le gérant lui-même (salon fermé, pause…) : distincte de la
// suspension par l'administrateur (is_active), que le gérant ne peut pas lever.
// Le salon n'est plus visible, mais toutes ses données sont conservées.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('is_verified');
        });
    }

    public function down(): void
    {
        Schema::table('salons', function (Blueprint $table) {
            $table->dropColumn('deactivated_at');
        });
    }
};
