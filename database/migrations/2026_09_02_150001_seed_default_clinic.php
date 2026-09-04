<?php

use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (Clinic::exists()) {
            return;
        }

        $clinic = Clinic::create([
            'name' => 'Alverstone Medcity',
            'code' => 'AMC-MAIN',
            'address' => 'Technopark, Trivandrum',
            'is_active' => true,
        ]);

        $department = Department::create([
            'clinic_id' => $clinic->id,
            'name' => 'General Medicine',
            'code' => 'GEN',
            'is_active' => true,
        ]);

        Department::insert([
            ['clinic_id' => $clinic->id, 'name' => 'Cardiology', 'code' => 'CARD', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['clinic_id' => $clinic->id, 'name' => 'Orthopedics', 'code' => 'ORTHO', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['clinic_id' => $clinic->id, 'name' => 'Dental', 'code' => 'DENT', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Doctor::query()->update([
            'clinic_id' => $clinic->id,
            'department_id' => $department->id,
            'lunch_start' => '13:00:00',
            'lunch_end' => '14:00:00',
        ]);

        DoctorAvailability::query()->update(['slot_duration_minutes' => 30]);
    }

    public function down(): void
    {
        // Data migration — no rollback
    }
};
