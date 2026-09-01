<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use App\Models\LabTest;
use App\Models\Medicine;
use App\Models\Patient;
use App\Models\User;
use App\Services\PatientCodeGenerator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@clinic.com',
            'phone' => '9999999999',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'status' => 'active',
        ]);

        $receptionist = User::create([
            'name' => 'Receptionist',
            'email' => 'reception@clinic.com',
            'phone' => '9999999998',
            'password' => Hash::make('password'),
            'role' => UserRole::Receptionist,
            'status' => 'active',
        ]);

        $doctorUser = User::create([
            'name' => 'Dr. Sarah Johnson',
            'email' => 'doctor@clinic.com',
            'phone' => '9999999997',
            'password' => Hash::make('password'),
            'role' => UserRole::Doctor,
            'status' => 'active',
        ]);

        $doctor = Doctor::create([
            'user_id' => $doctorUser->id,
            'name' => 'Dr. Sarah Johnson',
            'specialization' => 'General Physician',
            'phone' => '9999999997',
            'email' => 'doctor@clinic.com',
            'status' => DoctorStatus::Active,
            'consultation_fee' => 500,
        ]);

        foreach ([1, 2, 3, 4, 5] as $day) {
            DoctorAvailability::create([
                'doctor_id' => $doctor->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'slot_duration_minutes' => 10,
            ]);
        }

        Patient::create([
            'patient_code' => app(PatientCodeGenerator::class)->generate(),
            'name' => 'John Doe',
            'phone' => '9876543210',
            'email' => 'john@example.com',
            'gender' => 'male',
            'dob' => '1990-01-15',
        ]);

        Medicine::insert([
            ['name' => 'Paracetamol 500mg', 'generic_name' => 'Paracetamol', 'unit' => 'tablet', 'stock_quantity' => 500, 'unit_price' => 2.50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Amoxicillin 250mg', 'generic_name' => 'Amoxicillin', 'unit' => 'capsule', 'stock_quantity' => 200, 'unit_price' => 5.00, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Cetirizine 10mg', 'generic_name' => 'Cetirizine', 'unit' => 'tablet', 'stock_quantity' => 300, 'unit_price' => 3.00, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        LabTest::insert([
            ['name' => 'Complete Blood Count', 'code' => 'CBC', 'description' => 'Full blood count test', 'price' => 350, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Blood Sugar Fasting', 'code' => 'BSF', 'description' => 'Fasting blood glucose', 'price' => 150, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Lipid Profile', 'code' => 'LIPID', 'description' => 'Cholesterol panel', 'price' => 600, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
