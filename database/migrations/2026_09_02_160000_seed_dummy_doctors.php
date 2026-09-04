<?php

use App\Enums\DoctorStatus;
use App\Models\Clinic;
use App\Models\Department;
use App\Models\Doctor;
use App\Models\DoctorAvailability;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    private array $dummyDoctors = [
        'General Medicine' => [
            ['name' => 'Dr. Sarah Johnson', 'specialization' => 'General Physician', 'fee' => 500, 'days' => [1, 2, 3, 4, 5], 'email' => 'doctor@clinic.com'],
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

    public function up(): void
    {
        $clinic = Clinic::first();
        if (! $clinic) {
            return;
        }

        $departments = Department::where('clinic_id', $clinic->id)->get()->keyBy('name');

        foreach ($this->dummyDoctors as $deptName => $doctors) {
            $department = $departments->get($deptName);
            if (! $department) {
                continue;
            }

            foreach ($doctors as $doc) {
                if (Doctor::where('email', $doc['email'])->exists()) {
                    $existing = Doctor::where('email', $doc['email'])->first();
                    $existing->availability()->delete();
                    $this->seedAvailability($existing, $doc['days']);

                    continue;
                }

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

                $this->seedAvailability($doctor, $doc['days']);
            }
        }
    }

    private function seedAvailability(Doctor $doctor, array $days): void
    {
        foreach ($days as $day) {
            DoctorAvailability::create([
                'doctor_id' => $doctor->id,
                'day_of_week' => $day,
                'start_time' => '09:00:00',
                'end_time' => '17:00:00',
                'slot_duration_minutes' => 30,
            ]);
        }
    }

    public function down(): void
    {
        $emails = collect($this->dummyDoctors)->flatten(1)->pluck('email')->filter(fn ($e) => $e !== 'doctor@clinic.com');
        Doctor::whereIn('email', $emails)->each(fn (Doctor $d) => $d->availability()->delete());
        Doctor::whereIn('email', $emails)->delete();
    }
};
