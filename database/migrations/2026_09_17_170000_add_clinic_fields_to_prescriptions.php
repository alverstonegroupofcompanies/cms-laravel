<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('clinic_prescription_id')->nullable()->unique()->after('status');
            $table->string('clinic_prescription_number')->nullable()->after('clinic_prescription_id');
            $table->timestamp('clinic_synced_at')->nullable()->after('clinic_prescription_number');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropColumn([
                'clinic_prescription_id',
                'clinic_prescription_number',
                'clinic_synced_at',
            ]);
        });
    }
};
