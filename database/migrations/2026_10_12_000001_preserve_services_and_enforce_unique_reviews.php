<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not silently discard existing reviews when upgrading a live database.
        if (DB::table('reviews')->select('appointment_id')->groupBy('appointment_id')
            ->havingRaw('COUNT(*) > 1')->exists()) {
            throw new RuntimeException('Duplicate appointment reviews exist. Review and reconcile them before migrating.');
        }

        Schema::table('reviews', function (Blueprint $table) {
            $table->unique('appointment_id');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['appointment_id']);
        });
    }
};
