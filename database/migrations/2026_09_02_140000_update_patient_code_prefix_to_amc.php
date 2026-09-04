<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('patients')->where('patient_code', 'like', 'PAT-%')->get() as $patient) {
            DB::table('patients')
                ->where('id', $patient->id)
                ->update(['patient_code' => 'AMC-'.substr($patient->patient_code, 4)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('patients')->where('patient_code', 'like', 'AMC-%')->get() as $patient) {
            DB::table('patients')
                ->where('id', $patient->id)
                ->update(['patient_code' => 'PAT-'.substr($patient->patient_code, 4)]);
        }
    }
};
