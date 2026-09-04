<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->string('blood_group')->nullable()->after('gender');
            $table->string('marital_status')->nullable()->after('blood_group');
            $table->text('symptoms')->nullable()->after('marital_status');
            $table->text('current_medications')->nullable()->after('symptoms');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['blood_group', 'marital_status', 'symptoms', 'current_medications']);
        });
    }
};
