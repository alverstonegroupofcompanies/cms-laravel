<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinics', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clinic_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('doctors', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('clinic_id')->constrained()->nullOnDelete();
            $table->time('lunch_start')->nullable()->after('consultation_fee');
            $table->time('lunch_end')->nullable()->after('lunch_start');
        });

        Schema::create('doctor_leaves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->date('leave_date');
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['doctor_id', 'leave_date']);
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->string('registration_source')->default('online')->after('patient_code');
            $table->string('photo_path')->nullable()->after('address');
            $table->string('emergency_contact')->nullable()->after('photo_path');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_reset_password')->default(false)->after('status');
        });

        Schema::table('appointments', function (Blueprint $table) {
            $table->foreignId('clinic_id')->nullable()->after('doctor_id')->constrained()->nullOnDelete();
            $table->foreignId('department_id')->nullable()->after('clinic_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('clinic_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_reset_password');
        });

        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['registration_source', 'photo_path', 'emergency_contact']);
        });

        Schema::dropIfExists('doctor_leaves');

        Schema::table('doctors', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
            $table->dropConstrainedForeignId('clinic_id');
            $table->dropColumn(['lunch_start', 'lunch_end']);
        });

        Schema::dropIfExists('departments');
        Schema::dropIfExists('clinics');
    }
};
