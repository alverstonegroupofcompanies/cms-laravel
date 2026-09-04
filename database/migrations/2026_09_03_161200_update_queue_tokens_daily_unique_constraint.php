<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->dropUnique(['doctor_id', 'queue_date', 'token_number']);
            // One token number per clinic day (AMC01, AMC02...) — not per doctor.
            $table->unique(['queue_date', 'token_number']);
        });
    }

    public function down(): void
    {
        Schema::table('queue_tokens', function (Blueprint $table) {
            $table->dropUnique(['queue_date', 'token_number']);
            $table->unique(['doctor_id', 'queue_date', 'token_number']);
        });
    }
};
