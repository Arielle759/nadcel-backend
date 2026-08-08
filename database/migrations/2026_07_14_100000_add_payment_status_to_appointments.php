<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->string('payment_status')->default('unpaid')->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $t) {
            $t->dropColumn('payment_status');
        });
    }
};
