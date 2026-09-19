<?php

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Enums\DoctorStatus;
use App\Enums\UserRole;
use App\Models\Appointment;
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
    /** Common password for every seeded login. */
    private const PASSWORD = 'password';

    public function run(): void
    {
        $password = Hash::make(self::PASSWORD);

        User::firstOrCreate(
            ['email' => 'admin@clinic.com'],
            [
                'name' => 'Admin User',
                'phone' => '9999999999',
                'password' => $password,
                'role' => UserRole::Admin,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'reception@clinic.com'],
            [
                'name' => 'Receptionist',
                'phone' => '9999999998',
                'password' => $password,
                'role' => UserRole::Receptionist,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'pharmacy@clinic.com'],
            [
                'name' => 'Pharmacy Staff',
                'phone' => '9999999995',
                'password' => $password,
                'role' => UserRole::Pharmacy,
                'status' => 'active',
            ]
        );

        User::firstOrCreate(
            ['email' => 'lab@clinic.com'],
            [
                'name' => 'Lab Staff',
                'phone' => '9999999996',
                'password' => $password,
                'role' => UserRole::Lab,
                'status' => 'active',
            ]
        );

        $clinic = Clinic::firstOrCreate(
            ['code' => 'AMC-MAIN'],
            [
                'name' => 'Alverstone Medcity',
                'address' => 'Chandanathope, Kollam, Kerala 691014',
                'is_active' => true,
            ]
        );

        $deptDefs = [
            ['name' => 'General Medicine', 'code' => 'GEN'],
            ['name' => 'Cardiology', 'code' => 'CARD'],
            ['name' => 'Orthopedics', 'code' => 'ORTHO'],
            ['name' => 'Dental', 'code' => 'DENT'],
        ];

        $departments = collect($deptDefs)->map(function (array $d) use ($clinic) {
            return Department::firstOrCreate(
                ['clinic_id' => $clinic->id, 'code' => $d['code']],
                ['name' => $d['name'], 'is_active' => true]
            );
        })->keyBy('name');

        $this->seedDoctorsWithLogins($clinic, $departments, $password);
        $patients = $this->seedPatientsWithLogins($password);
        $this->seedSampleAppointments($clinic, $patients);

        if (Medicine::count() === 0) {
            Medicine::insert([
                ['name' => 'Paracetamol 500mg', 'generic_name' => 'Paracetamol', 'unit' => 'tablet', 'stock_quantity' => 500, 'unit_price' => 2.50, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Amoxicillin 250mg', 'generic_name' => 'Amoxicillin', 'unit' => 'capsule', 'stock_quantity' => 200, 'unit_price' => 5.00, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Cetirizine 10mg', 'generic_name' => 'Cetirizine', 'unit' => 'tablet', 'stock_quantity' => 300, 'unit_price' => 3.00, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }

        if (LabTest::count() === 0) {
            LabTest::insert([
                ['name' => 'Complete Blood Count', 'code' => 'CBC', 'description' => 'Full blood count test', 'price' => 350, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Blood Sugar Fasting', 'code' => 'BSF', 'description' => 'Fasting blood glucose', 'price' => 150, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
                ['name' => 'Lipid Profile', 'code' => 'LIPID', 'description' => 'Cholesterol panel', 'price' => 600, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    private function seedDoctorsWithLogins(Clinic $clinic, $departments, string $password): void
    {
        $doctors = [
            ['name' => 'Dr. Sarah Johnson', 'email' => 'doctor@clinic.com', 'phone' => '9999999997', 'dept' => 'General Medicine', 'specialization' => 'General Physician', 'fee' => 500, 'days' => [1, 2, 3, 4, 5]],
            ['name' => 'Dr. Ananya Nair', 'email' => 'ananya.nair@clinic.com', 'phone' => '9999999901', 'dept' => 'General Medicine', 'specialization' => 'Family Medicine', 'fee' => 450, 'days' => [1, 3, 5]],
            ['name' => 'Dr. Rajesh Menon', 'email' => 'rajesh.menon@clinic.com', 'phone' => '9999999902', 'dept' => 'General Medicine', 'specialization' => 'Internal Medicine', 'fee' => 600, 'days' => [2, 4, 6]],
            ['name' => 'Dr. Priya Sharma', 'email' => 'priya.sharma@clinic.com', 'phone' => '9999999903', 'dept' => 'Cardiology', 'specialization' => 'Cardiologist', 'fee' => 800, 'days' => [1, 2, 4]],
            ['name' => 'Dr. Arjun Pillai', 'email' => 'arjun.pillai@clinic.com', 'phone' => '9999999904', 'dept' => 'Cardiology', 'specialization' => 'Interventional Cardiology', 'fee' => 950, 'days' => [3, 5, 6]],
            ['name' => 'Dr. Meera Krishnan', 'email' => 'meera.krishnan@clinic.com', 'phone' => '9999999905', 'dept' => 'Orthopedics', 'specialization' => 'Orthopedic Surgeon', 'fee' => 750, 'days' => [1, 3, 4, 5]],
            ['name' => 'Dr. Vikram Singh', 'email' => 'vikram.singh@clinic.com', 'phone' => '9999999906', 'dept' => 'Orthopedics', 'specialization' => 'Sports Medicine', 'fee' => 700, 'days' => [2, 4, 6]],
            ['name' => 'Dr. Lisa Thomas', 'email' => 'lisa.thomas@clinic.com', 'phone' => '9999999907', 'dept' => 'Dental', 'specialization' => 'Dental Surgeon', 'fee' => 550, 'days' => [1, 2, 3, 5]],
            ['name' => 'Dr. Suresh Babu', 'email' => 'suresh.babu@clinic.com', 'phone' => '9999999908', 'dept' => 'Dental', 'specialization' => 'Orthodontist', 'fee' => 650, 'days' => [2, 4, 6]],
        ];

        foreach ($doctors as $doc) {
            $department = $departments->get($doc['dept']);
            if (! $department) {
                continue;
            }

            $user = User::firstOrCreate(
                ['email' => $doc['email']],
                [
                    'name' => $doc['name'],
                    'phone' => $doc['phone'],
                    'password' => $password,
                    'role' => UserRole::Doctor,
                    'status' => 'active',
                ]
            );

            $doctor = Doctor::firstOrCreate(
                ['email' => $doc['email']],
                [
                    'user_id' => $user->id,
                    'clinic_id' => $clinic->id,
                    'department_id' => $department->id,
                    'name' => $doc['name'],
                    'specialization' => $doc['specialization'],
                    'phone' => $doc['phone'],
                    'status' => DoctorStatus::Active,
                    'consultation_fee' => $doc['fee'],
                    'lunch_start' => '13:00:00',
                    'lunch_end' => '14:00:00',
                ]
            );

            if (! $doctor->user_id) {
                $doctor->update(['user_id' => $user->id]);
            }

            foreach ($doc['days'] as $day) {
                DoctorAvailability::firstOrCreate(
                    ['doctor_id' => $doctor->id, 'day_of_week' => $day],
                    [
                        'start_time' => '09:00:00',
                        'end_time' => '17:00:00',
                        'slot_duration_minutes' => 30,
                    ]
                );
            }
        }
    }

    /**
     * @return array<int, Patient>
     */
    private function seedPatientsWithLogins(string $password): array
    {
        $codeGen = app(PatientCodeGenerator::class);

        $people = [
            ['first' => 'Arjun', 'last' => 'Kumar', 'email' => 'arjun.kumar@gmail.com', 'phone' => '9995956770', 'gender' => 'male', 'dob' => '1992-05-14', 'source' => 'online'],
            ['first' => 'Meera', 'last' => 'Nair', 'email' => 'meera.nair@gmail.com', 'phone' => '9876501001', 'gender' => 'female', 'dob' => '1988-11-02', 'source' => 'online'],
            ['first' => 'Rahul', 'last' => 'Menon', 'email' => 'rahul.menon@gmail.com', 'phone' => '9876501002', 'gender' => 'male', 'dob' => '1995-03-21', 'source' => 'online'],
            ['first' => 'Sneha', 'last' => 'Pillai', 'email' => 'sneha.pillai@gmail.com', 'phone' => '9876501003', 'gender' => 'female', 'dob' => '1990-07-09', 'source' => 'walk_in'],
            ['first' => 'Vishnu', 'last' => 'Das', 'email' => 'vishnu.das@gmail.com', 'phone' => '9876501004', 'gender' => 'male', 'dob' => '1985-01-30', 'source' => 'online'],
            ['first' => 'Anitha', 'last' => 'Rajan', 'email' => 'anitha.rajan@gmail.com', 'phone' => '9876501005', 'gender' => 'female', 'dob' => '1998-09-18', 'source' => 'walk_in'],
            ['first' => 'John', 'last' => 'Doe', 'email' => 'john.doe@gmail.com', 'phone' => '9876543210', 'gender' => 'male', 'dob' => '1990-01-15', 'source' => 'walk_in'],
            ['first' => 'Priya', 'last' => 'Joseph', 'email' => 'priya.joseph@gmail.com', 'phone' => '9876501006', 'gender' => 'female', 'dob' => '1993-12-05', 'source' => 'online'],
        ];

        $patients = [];

        foreach ($people as $p) {
            $fullName = trim($p['first'].' '.$p['last']);

            $user = User::firstOrCreate(
                ['email' => $p['email']],
                [
                    'name' => $fullName,
                    'phone' => $p['phone'],
                    'password' => $password,
                    'role' => UserRole::Patient,
                    'status' => 'active',
                ]
            );

            $patient = Patient::firstOrCreate(
                ['phone' => $p['phone']],
                [
                    'user_id' => $user->id,
                    'patient_code' => $codeGen->generate(),
                    'registration_source' => $p['source'],
                    'name' => $fullName,
                    'first_name' => $p['first'],
                    'last_name' => $p['last'],
                    'email' => $p['email'],
                    'gender' => $p['gender'],
                    'dob' => $p['dob'],
                    'city' => 'Trivandrum',
                    'state' => 'Kerala',
                ]
            );

            if (! $patient->user_id) {
                $patient->update(['user_id' => $user->id, 'email' => $p['email']]);
            }

            $patients[] = $patient;
        }

        return $patients;
    }

    /**
     * @param  array<int, Patient>  $patients
     */
    private function seedSampleAppointments(Clinic $clinic, array $patients): void
    {
        if (Appointment::count() > 0 || count($patients) < 4) {
            return;
        }

        $sarah = Doctor::where('email', 'doctor@clinic.com')->first();
        $lisa = Doctor::where('email', 'lisa.thomas@clinic.com')->first();
        $priya = Doctor::where('email', 'priya.sharma@clinic.com')->first();

        if (! $sarah || ! $lisa || ! $priya) {
            return;
        }

        $today = now()->toDateString();
        $tomorrow = now()->addDay()->toDateString();

        $samples = [
            ['patient' => $patients[0], 'doctor' => $lisa, 'date' => $today, 'slot' => '09:30:00', 'status' => AppointmentStatus::Booked, 'type' => 'online'],
            ['patient' => $patients[1], 'doctor' => $sarah, 'date' => $today, 'slot' => '10:00:00', 'status' => AppointmentStatus::Booked, 'type' => 'online'],
            ['patient' => $patients[2], 'doctor' => $priya, 'date' => $today, 'slot' => '11:00:00', 'status' => AppointmentStatus::Booked, 'type' => 'online'],
            ['patient' => $patients[3], 'doctor' => $sarah, 'date' => $today, 'slot' => '14:00:00', 'status' => AppointmentStatus::Booked, 'type' => 'walk_in'],
            ['patient' => $patients[4], 'doctor' => $lisa, 'date' => $tomorrow, 'slot' => '09:00:00', 'status' => AppointmentStatus::Booked, 'type' => 'online'],
            ['patient' => $patients[5], 'doctor' => $sarah, 'date' => $tomorrow, 'slot' => '10:30:00', 'status' => AppointmentStatus::Booked, 'type' => 'online'],
        ];

        foreach ($samples as $s) {
            Appointment::create([
                'patient_id' => $s['patient']->id,
                'doctor_id' => $s['doctor']->id,
                'clinic_id' => $clinic->id,
                'department_id' => $s['doctor']->department_id,
                'appointment_date' => $s['date'],
                'slot_time' => $s['slot'],
                'status' => $s['status'],
                'type' => $s['type'],
            ]);
        }
    }
}
