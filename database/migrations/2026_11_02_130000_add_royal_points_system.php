<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->unsignedInteger('royal_points')->default(0)->after('emergency_contact_phone');
        });

        Schema::table('visit_bills', function (Blueprint $table) {
            $table->unsignedInteger('points_earned')->default(0)->after('grand_total');
            $table->unsignedInteger('points_redeemed')->default(0)->after('points_earned');
            $table->decimal('points_discount', 10, 2)->default(0.00)->after('points_redeemed');
        });

        Schema::create('royal_point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->foreignId('visit_bill_id')->nullable()->constrained('visit_bills')->nullOnDelete();
            $table->integer('points'); // e.g. +36 (earned), -400 (redeemed)
            $table->string('type'); // earned | redeemed | adjusted | refund
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('royal_point_transactions');

        Schema::table('visit_bills', function (Blueprint $table) {
            $table->dropColumn(['points_earned', 'points_redeemed', 'points_discount']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn('royal_points');
        });
    }
};
