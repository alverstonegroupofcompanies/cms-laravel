<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\Department;
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
        User::create([
            'name' => 'Admin User',
            'email' => 'admin@clinic.com',
            'phone' => '9999999999',
            'password' => Hash::make('password'),
            'role' => UserRole::Admin,
            'status' => 'active',
        ]);

        User::create([
            'name' => 'Receptionist',
            'email' => 'reception@clinic.com',
            'phone' => '9999999998',
            'password' => Hash::make('password'),
            'role' => UserRole::Receptionist,
            'status' => 'active',
        ]);

        $clinic = Clinic::create([
            'name' => 'Alverstone Medcity',
            'code' => 'AMC-MAIN',
            'address' => 'Technopark, Trivandrum',
            'is_active' => true,
        ]);

        $departments = collect([
            ['name' => 'General Medicine', 'code' => 'GEN'],
            ['name' => 'Cardiology', 'code' => 'CARD'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO'],
            ['name' => 'Dental', 'code' => 'DENT'],
        ])->map(fn ($d) => Department::create([...$d, 'clinic_id' => $clinic->id, 'is_active' => true]));

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
            'clinic_id' => $clinic->id,
            'department_id' => $departments[0]->id,
            'name' => 'Dr. Sarah Johnson',
            'specialization' => 'General Physician',
            'phone' => '9999999997',
            'email' => 'doctor@clinic.com',
            'status' => DoctorStatus::Active,
            'consultation_fee' => 500,
            'lunch_start' => '13:00:00',
            'lunch_end' => '14:00:00',
        ]);

        foreach ([1, 2, 3, 4, 5] as $day) {
            DoctorAvailability::create([
                'doctor_id' => $doctor->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'slot_duration_minutes' => 30,
            ]);
        }

        $this->seedDummyDoctors($clinic, $departments);

        Patient::create([
            'patient_code' => app(PatientCodeGenerator::class)->generate(),
            'registration_source' => 'walk_in',
            'name' => 'John Doe',
            'first_name' => 'John',
            'last_name' => 'Doe',
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

    private function seedDummyDoctors(Clinic $clinic, $departments): void
    {
        $byName = $departments->keyBy('name');

        $dummyDoctors = [
            'General Medicine' => [
                ['name' => 'Dr. Ananya Nair', 'specialization' => 'Family Medicine', 'fee' => 450, 'days' => [1, 3, 5], 'email' => 'ananya.nair@clinic.com'],
                ['name' => 'Dr. Rajesh Menon', 'specialization' => 'Internal Medicine', 'fee' => 600, 'days' => [2, 4, 6], 'email' => 'rajesh.menon@clinic.com'],
            ],
            'Cardiology' => [
                ['name' => 'Dr. Priya Sharma', 'specialization' => 'Cardiologist', 'fee' => 800, 'days' => [1, 2, 4], 'email' => 'priya.sharma@clinic.com'],
                ['name' => 'Dr. Arjun Pillai', 'specialization' => 'Interventional Cardiology', 'fee' => 950, 'days' => [3, 5, 6], 'email' => 'arjun.pillai@clinic.com'],
            ],
            'Orthopedics' => [
                ['name' => 'Dr. Meera Krishnan', 'specialization' => 'Orthopedic Surgeon', 'fee' => 750, 'days' => [1, 3, 4, 5], 'email' => 'meera.krishnan@clinic.com'],
                ['name' => 'Dr. Vikram Singh', 'specialization' => 'Sports Medicine', 'fee' => 700, 'days' => [2, 4, 6], 'email' => 'vikram.singh@clinic.com'],
            ],
            'Dental' => [
                ['name' => 'Dr. Lisa Thomas', 'specialization' => 'Dental Surgeon', 'fee' => 550, 'days' => [1, 2, 3, 5], 'email' => 'lisa.thomas@clinic.com'],
                ['name' => 'Dr. Suresh Babu', 'specialization' => 'Orthodontist', 'fee' => 650, 'days' => [2, 4, 6], 'email' => 'suresh.babu@clinic.com'],
            ],
        ];

        foreach ($dummyDoctors as $deptName => $doctors) {
            $department = $byName->get($deptName);
            if (! $department) {
                continue;
            }

            foreach ($doctors as $doc) {
                $doctor = Doctor::create([
                    'clinic_id' => $clinic->id,
                    'department_id' => $department->id,
                    'name' => $doc['name'],
                    'specialization' => $doc['specialization'],
                    'phone' => '9'.str_pad((string) random_int(100000000, 999999999), 9, '0'),
                    'email' => $doc['email'],
                    'status' => DoctorStatus::Active,
                    'consultation_fee' => $doc['fee'],
                    'lunch_start' => '13:00:00',
                    'lunch_end' => '14:00:00',
                ]);

                foreach ($doc['days'] as $day) {
                    DoctorAvailability::create([
                        'doctor_id' => $doctor->id,
                        'day_of_week' => $day,
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'slot_duration_minutes' => 30,
                    ]);
                }
            }
        }
    }
}
