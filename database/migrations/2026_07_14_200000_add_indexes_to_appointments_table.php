<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->index('scheduled_at');
            $table->index('salon_id');
            $table->index('status');
            $table->index('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropIndex(['scheduled_at']);
            $table->dropIndex(['salon_id']);
            $table->dropIndex(['status']);
            $table->dropIndex(['employee_id']);
        });
    }
};
