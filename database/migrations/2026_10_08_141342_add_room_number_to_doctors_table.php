<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->string('room_number')->nullable()->after('specialization');
        });

        // Assign default room numbers to existing doctors if not set
        $doctors = \App\Models\Doctor::all();
        $roomIndex = 1;
        foreach ($doctors as $doctor) {
            $doctor->update(['room_number' => 'OPD ' . $roomIndex]);
            $roomIndex++;
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->dropColumn('room_number');
        });
    }
};
