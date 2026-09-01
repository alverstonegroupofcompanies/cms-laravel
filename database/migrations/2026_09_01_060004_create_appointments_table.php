<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->date('appointment_date');
            $table->time('slot_time');
            $table->string('status')->default('booked');
            $table->string('type')->default('scheduled');
            $table->timestamps();

            $table->index(['doctor_id', 'appointment_date', 'slot_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
