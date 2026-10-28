<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paiement sur place : le gérant note, à la clôture du rendez-vous, le montant
 * réellement encaissé (le prix affiché n'est qu'indicatif) et le moyen utilisé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->decimal('amount_paid', 10, 2)->nullable()->after('payment_status');
            $table->string('payment_method', 20)->nullable()->after('amount_paid');
            $table->timestamp('paid_at')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['amount_paid', 'payment_method', 'paid_at']);
        });
    }
};
