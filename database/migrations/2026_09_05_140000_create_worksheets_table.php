<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheets', function (Blueprint $table) {
            $table->id();
            $table->string('worksheet_code')->unique();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('queue_token_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('appointment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open'); // open | closed
            $table->text('chief_complaint')->nullable();
            $table->text('clinical_notes')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['doctor_id', 'status']);
            $table->index(['patient_id', 'created_at']);
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->foreignId('worksheet_id')->nullable()->after('queue_token_id')->constrained()->nullOnDelete();
        });

        Schema::table('lab_orders', function (Blueprint $table) {
            $table->foreignId('worksheet_id')->nullable()->after('queue_token_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('lab_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worksheet_id');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('worksheet_id');
        });

        Schema::dropIfExists('worksheets');
    }
};
