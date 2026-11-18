<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Retrait des options de service (renvoyées en perspective dans le mémoire).
// Les rendez-vous gardent leur prix et leur durée : seuls le lien vers l'option
// et son libellé disparaissent.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_option_id');
            $table->dropColumn('option_label');
        });
        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('option_label');
        });
        Schema::dropIfExists('service_options');
    }

    public function down(): void
    {
        Schema::create('service_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('label', 60);
            $table->decimal('price', 10, 2);
            $table->unsignedSmallInteger('duration');
            $table->unsignedTinyInteger('position')->default(0);
            $table->timestamps();
        });
        Schema::table('services', function (Blueprint $table) {
            $table->string('option_label', 40)->nullable()->after('category');
        });
        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('service_option_id')->nullable()->after('service_id')
                ->constrained('service_options')->nullOnDelete();
            $table->string('option_label', 60)->nullable()->after('service_option_id');
        });
    }
};
