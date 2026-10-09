<?php

namespace Database\Seeders;

use App\Enums\DoctorStatus;
use App\Enums\QueueTokenStatus;
use App\Enums\UserRole;
use App\Models\Clinic;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\QueueToken;
use App\Models\User;
use App\Models\Worksheet;
use App\Services\PatientCodeGenerator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class TodayQueueSeeder extends Seeder
{
    public function run(): void
    {
        $today = now()->toDateString();
        $now = Carbon::now();
        $codeGen = app(PatientCodeGenerator::class);
        $password = Hash::make('password');

        // 1. Ensure Clinic exists
        $clinic = Clinic::first();

        // 2. Room number assignments for all doctors
        $roomAssignments = [
            'doctor@clinic.com' => 'OPD 101',      // Dr. Sarah Johnson
            'priya.sharma@clinic.com' => 'OPD 102', // Dr. Priya Sharma
            'ananya.nair@clinic.com' => 'OPD 103',  // Dr. Ananya Nair
            'rajesh.menon@clinic.com' => 'OPD 104', // Dr. Rajesh Menon
            'lisa.thomas@clinic.com' => 'OPD 201',  // Dr. Lisa Thomas
            'meera.krishnan@clinic.com' => 'OPD 202', // Dr. Meera Krishnan
            'arjun.pillai@clinic.com' => 'OPD 203', // Dr. Arjun Pillai
            'vikram.singh@clinic.com' => 'OPD 204', // Dr. Vikram Singh
            'suresh.babu@clinic.com' => 'OPD 205',  // Dr. Suresh Babu
        ];

        foreach ($roomAssignments as $email => $room) {
            Doctor::where('email', $email)->whereNull('room_number')->update(['room_number' => $room]);
            // Also ensure active status
            Doctor::where('email', $email)->update(['status' => DoctorStatus::Active]);
        }

        // 3. Ensure a large pool of demo patients (35 patients)
        $patientPoolData = [
            ['first' => 'Arjun', 'last' => 'Kumar', 'email' => 'arjun.kumar@gmail.com', 'phone' => '9995956770', 'gender' => 'male', 'dob' => '1992-05-14'],
            ['first' => 'Meera', 'last' => 'Nair', 'email' => 'meera.nair@gmail.com', 'phone' => '9876501001', 'gender' => 'female', 'dob' => '1988-11-02'],
            ['first' => 'Rahul', 'last' => 'Menon', 'email' => 'rahul.menon@gmail.com', 'phone' => '9876501002', 'gender' => 'male', 'dob' => '1995-03-21'],
            ['first' => 'Sneha', 'last' => 'Pillai', 'email' => 'sneha.pillai@gmail.com', 'phone' => '9876501003', 'gender' => 'female', 'dob' => '1990-07-09'],
            ['first' => 'Vishnu', 'last' => 'Das', 'email' => 'vishnu.das@gmail.com', 'phone' => '9876501004', 'gender' => 'male', 'dob' => '1985-01-30'],
            ['first' => 'Anitha', 'last' => 'Rajan', 'email' => 'anitha.rajan@gmail.com', 'phone' => '9876501005', 'gender' => 'female', 'dob' => '1998-09-18'],
            ['first' => 'John', 'last' => 'Doe', 'email' => 'john.doe@gmail.com', 'phone' => '9876543210', 'gender' => 'male', 'dob' => '1990-01-15'],
            ['first' => 'Priya', 'last' => 'Joseph', 'email' => 'priya.joseph@gmail.com', 'phone' => '9876501006', 'gender' => 'female', 'dob' => '1993-12-05'],
            ['first' => 'Kavitha', 'last' => 'Nair', 'email' => 'kavitha.nair@gmail.com', 'phone' => '9876501007', 'gender' => 'female', 'dob' => '1989-04-12'],
            ['first' => 'Suresh', 'last' => 'Kumar', 'email' => 'suresh.kumar@gmail.com', 'phone' => '9876501008', 'gender' => 'male', 'dob' => '1981-10-25'],
            ['first' => 'Deepa', 'last' => 'Mohan', 'email' => 'deepa.mohan@gmail.com', 'phone' => '9876501009', 'gender' => 'female', 'dob' => '1996-06-19'],
            ['first' => 'Manoj', 'last' => 'Varma', 'email' => 'manoj.varma@gmail.com', 'phone' => '9876501010', 'gender' => 'male', 'dob' => '1978-02-14'],
            ['first' => 'Divya', 'last' => 'Pillai', 'email' => 'divya.pillai@gmail.com', 'phone' => '9876501011', 'gender' => 'female', 'dob' => '1994-08-30'],
            ['first' => 'Harish', 'last' => 'Menon', 'email' => 'harish.menon@gmail.com', 'phone' => '9876501012', 'gender' => 'male', 'dob' => '1987-11-05'],
            ['first' => 'Sunitha', 'last' => 'George', 'email' => 'sunitha.george@gmail.com', 'phone' => '9876501013', 'gender' => 'female', 'dob' => '1991-03-27'],
            ['first' => 'Akhil', 'last' => 'Raj', 'email' => 'akhil.raj@gmail.com', 'phone' => '9876501014', 'gender' => 'male', 'dob' => '1997-07-22'],
            ['first' => 'Reshma', 'last' => 'Sreedhar', 'email' => 'reshma.s@gmail.com', 'phone' => '9876501015', 'gender' => 'female', 'dob' => '1993-09-15'],
            ['first' => 'Vinod', 'last' => 'Kartha', 'email' => 'vinod.k@gmail.com', 'phone' => '9876501016', 'gender' => 'male', 'dob' => '1983-05-18'],
            ['first' => 'Anandhu', 'last' => 'Prasad', 'email' => 'anandhu.p@gmail.com', 'phone' => '9876501017', 'gender' => 'male', 'dob' => '1999-12-01'],
            ['first' => 'Aparna', 'last' => 'Suresh', 'email' => 'aparna.suresh@gmail.com', 'phone' => '9876501018', 'gender' => 'female', 'dob' => '1995-04-08'],
            ['first' => 'Biju', 'last' => 'Mathew', 'email' => 'biju.mathew@gmail.com', 'phone' => '9876501019', 'gender' => 'male', 'dob' => '1976-08-11'],
            ['first' => 'Chithra', 'last' => 'Devi', 'email' => 'chithra.devi@gmail.com', 'phone' => '9876501020', 'gender' => 'female', 'dob' => '1984-01-29'],
            ['first' => 'Gopakumar', 'last' => 'N', 'email' => 'gopakumar.n@gmail.com', 'phone' => '9876501021', 'gender' => 'male', 'dob' => '1972-11-17'],
            ['first' => 'Indu', 'last' => 'Lekha', 'email' => 'indu.lekha@gmail.com', 'phone' => '9876501022', 'gender' => 'female', 'dob' => '1992-10-03'],
            ['first' => 'Jayesh', 'last' => 'Babu', 'email' => 'jayesh.babu@gmail.com', 'phone' => '9876501023', 'gender' => 'male', 'dob' => '1988-06-25'],
            ['first' => 'Lakshmi', 'last' => 'Priya', 'email' => 'lakshmi.priya@gmail.com', 'phone' => '9876501024', 'gender' => 'female', 'dob' => '1997-01-14'],
            ['first' => 'Mohanlal', 'last' => 'V', 'email' => 'mohanlal.v@gmail.com', 'phone' => '9876501025', 'gender' => 'male', 'dob' => '1965-05-21'],
            ['first' => 'Nandhana', 'last' => 'M', 'email' => 'nandhana.m@gmail.com', 'phone' => '9876501026', 'gender' => 'female', 'dob' => '2001-09-09'],
            ['first' => 'Pradeep', 'last' => 'Chandran', 'email' => 'pradeep.c@gmail.com', 'phone' => '9876501027', 'gender' => 'male', 'dob' => '1980-03-04'],
            ['first' => 'Remya', 'last' => 'Krishnan', 'email' => 'remya.k@gmail.com', 'phone' => '9876501028', 'gender' => 'female', 'dob' => '1991-07-16'],
            ['first' => 'Sajeev', 'last' => 'Kumar', 'email' => 'sajeev.k@gmail.com', 'phone' => '9876501029', 'gender' => 'male', 'dob' => '1975-12-19'],
            ['first' => 'Thara', 'last' => 'Kalyan', 'email' => 'thara.k@gmail.com', 'phone' => '9876501030', 'gender' => 'female', 'dob' => '1986-04-02'],
            ['first' => 'Unnikrishnan', 'last' => 'P', 'email' => 'unni.p@gmail.com', 'phone' => '9876501031', 'gender' => 'male', 'dob' => '1982-08-28'],
            ['first' => 'Varsha', 'last' => 'Ramesh', 'email' => 'varsha.r@gmail.com', 'phone' => '9876501032', 'gender' => 'female', 'dob' => '1998-11-23'],
            ['first' => 'Zacharia', 'last' => 'Thomas', 'email' => 'zacharia.t@gmail.com', 'phone' => '9876501033', 'gender' => 'male', 'dob' => '1970-06-07'],
        ];

        $patients = [];
        foreach ($patientPoolData as $p) {
            $fullName = trim($p['first'].' '.$p['last']);

            $user = User::where('email', $p['email'])->orWhere('phone', $p['phone'])->first();
            if (! $user) {
                $user = User::create([
                    'name' => $fullName,
                    'email' => $p['email'],
                    'phone' => $p['phone'],
                    'password' => $password,
                    'role' => UserRole::Patient,
                    'status' => 'active',
                ]);
            }

            $patient = Patient::where('phone', $p['phone'])->orWhere('email', $p['email'])->first();
            if (! $patient) {
                $patient = Patient::create([
                    'user_id' => $user->id,
                    'patient_code' => $codeGen->generate(),
                    'registration_source' => 'walk_in',
                    'name' => $fullName,
                    'first_name' => $p['first'],
                    'last_name' => $p['last'],
                    'phone' => $p['phone'],
                    'email' => $p['email'],
                    'gender' => $p['gender'],
                    'dob' => $p['dob'],
                    'city' => 'Trivandrum',
                    'state' => 'Kerala',
                ]);
            } else {
                if (! $patient->user_id) {
                    $patient->update(['user_id' => $user->id]);
                }
            }

            $patients[] = $patient;
        }

        // 4. Clean existing tokens and worksheets for today
        $todayTokenIds = QueueToken::whereDate('queue_date', $today)->pluck('id');
        Worksheet::whereIn('queue_token_id', $todayTokenIds)->delete();
        QueueToken::whereDate('queue_date', $today)->delete();

        // 5. Select on-duty doctors for today's high-traffic demo
        $docSarah = Doctor::where('email', 'doctor@clinic.com')->first();
        $docPriya = Doctor::where('email', 'priya.sharma@clinic.com')->first();
        $docLisa = Doctor::where('email', 'lisa.thomas@clinic.com')->first();
        $docMeera = Doctor::where('email', 'meera.krishnan@clinic.com')->first();
        $docAnanya = Doctor::where('email', 'ananya.nair@clinic.com')->first();

        $activeDoctors = array_values(array_filter([$docSarah, $docPriya, $docLisa, $docMeera, $docAnanya]));

        if (empty($activeDoctors)) {
            return;
        }

        $tokenNum = 1;
        $patientIndex = 0;
        $worksheetSeq = 1;

        // Plan: Generate a realistic clinic day with 30 walk-in tokens across active doctors
        //
        // Doctor 1 (Sarah Johnson - General Medicine, Room OPD 101):
        //   - 2 Completed tokens (arrived 1.5h ago, completed)
        //   - 1 In Consultation (arrived 30m ago, called 7m ago)
        //   - 6 Waiting walk-ins (arrived between 45m and 5m ago)
        //
        // Doctor 2 (Priya Sharma - Cardiology, Room OPD 102):
        //   - 1 Completed token
        //   - 1 In Consultation (called 4m ago)
        //   - 5 Waiting walk-ins
        //
        // Doctor 3 (Lisa Thomas - Dental, Room OPD 201):
        //   - 1 Completed token
        //   - 1 In Consultation (called 11m ago)
        //   - 5 Waiting walk-ins
        //
        // Doctor 4 (Meera Krishnan - Orthopedics, Room OPD 202):
        //   - 1 Completed token
        //   - 1 In Consultation (called 3m ago)
        //   - 4 Waiting walk-ins
        //
        // Doctor 5 (Ananya Nair - Family Medicine, Room OPD 103):
        //   - 1 In Consultation (called 5m ago)
        //   - 3 Waiting walk-ins

        $doctorPlans = [
            [
                'doctor' => $docSarah,
                'completed' => 2,
                'in_consult' => 1,
                'waiting' => 6,
                'consult_elapsed' => 7,
            ],
            [
                'doctor' => $docPriya,
                'completed' => 1,
                'in_consult' => 1,
                'waiting' => 5,
                'consult_elapsed' => 4,
            ],
            [
                'doctor' => $docLisa,
                'completed' => 1,
                'in_consult' => 1,
                'waiting' => 5,
                'consult_elapsed' => 11,
            ],
            [
                'doctor' => $docMeera,
                'completed' => 1,
                'in_consult' => 1,
                'waiting' => 4,
                'consult_elapsed' => 3,
            ],
            [
                'doctor' => $docAnanya,
                'completed' => 0,
                'in_consult' => 1,
                'waiting' => 3,
                'consult_elapsed' => 5,
            ],
        ];

        foreach ($doctorPlans as $plan) {
            $doc = $plan['doctor'];
            if (! $doc) {
                continue;
            }

            // A. Completed tokens earlier today
            for ($c = 0; $c < $plan['completed']; $c++) {
                if (! isset($patients[$patientIndex])) {
                    break;
                }
                $pat = $patients[$patientIndex++];
                $createdMinutesAgo = 90 + ($c * 20);
                $calledMinutesAgo = 60 + ($c * 15);
                $completedMinutesAgo = 40 + ($c * 10);

                $t = QueueToken::create([
                    'patient_id' => $pat->id,
                    'doctor_id' => $doc->id,
                    'queue_date' => $today,
                    'token_number' => $tokenNum,
                    'display_code' => 'AMC'.str_pad((string) $tokenNum, 2, '0', STR_PAD_LEFT),
                    'status' => QueueTokenStatus::Completed,
                    'end_of_line' => false,
                    'appointment_id' => null,
                    'called_at' => $now->copy()->subMinutes($calledMinutesAgo),
                    'completed_at' => $now->copy()->subMinutes($completedMinutesAgo),
                    'created_at' => $now->copy()->subMinutes($createdMinutesAgo),
                    'updated_at' => $now->copy()->subMinutes($completedMinutesAgo),
                ]);

                // Create closed worksheet
                Worksheet::create([
                    'worksheet_code' => 'WS-'.now()->format('Ymd').'-'.str_pad((string) $worksheetSeq++, 4, '0', STR_PAD_LEFT),
                    'patient_id' => $pat->id,
                    'doctor_id' => $doc->id,
                    'queue_token_id' => $t->id,
                    'appointment_id' => null,
                    'status' => 'completed',
                    'opened_at' => $now->copy()->subMinutes($calledMinutesAgo),
                    'closed_at' => $now->copy()->subMinutes($completedMinutesAgo),
                ]);

                $tokenNum++;
            }

            // B. In Consultation token right now
            if ($plan['in_consult'] > 0 && isset($patients[$patientIndex])) {
                $pat = $patients[$patientIndex++];
                $consultElapsed = $plan['consult_elapsed'];
                $createdMinutesAgo = $consultElapsed + 25;

                $t = QueueToken::create([
                    'patient_id' => $pat->id,
                    'doctor_id' => $doc->id,
                    'queue_date' => $today,
                    'token_number' => $tokenNum,
                    'display_code' => 'AMC'.str_pad((string) $tokenNum, 2, '0', STR_PAD_LEFT),
                    'status' => QueueTokenStatus::InConsultation,
                    'end_of_line' => false,
                    'appointment_id' => null,
                    'called_at' => $now->copy()->subMinutes($consultElapsed),
                    'created_at' => $now->copy()->subMinutes($createdMinutesAgo),
                    'updated_at' => $now->copy()->subMinutes($consultElapsed),
                ]);

                // Create open active clinical worksheet
                Worksheet::create([
                    'worksheet_code' => 'WS-'.now()->format('Ymd').'-'.str_pad((string) $worksheetSeq++, 4, '0', STR_PAD_LEFT),
                    'patient_id' => $pat->id,
                    'doctor_id' => $doc->id,
                    'queue_token_id' => $t->id,
                    'appointment_id' => null,
                    'status' => 'open',
                    'opened_at' => $now->copy()->subMinutes($consultElapsed),
                ]);

                $tokenNum++;
            }

            // C. Waiting Walk-in tokens
            for ($w = 0; $w < $plan['waiting']; $w++) {
                if (! isset($patients[$patientIndex])) {
                    break;
                }
                $pat = $patients[$patientIndex++];
                // Stagger wait times from 45m down to 4m
                $waitMinutes = max(3, 48 - ($w * 7));

                QueueToken::create([
                    'patient_id' => $pat->id,
                    'doctor_id' => $doc->id,
                    'queue_date' => $today,
                    'token_number' => $tokenNum,
                    'display_code' => 'AMC'.str_pad((string) $tokenNum, 2, '0', STR_PAD_LEFT),
                    'status' => QueueTokenStatus::Waiting,
                    'end_of_line' => false,
                    'appointment_id' => null,
                    'created_at' => $now->copy()->subMinutes($waitMinutes),
                    'updated_at' => $now->copy()->subMinutes($waitMinutes),
                ]);

                $tokenNum++;
            }
        }
    }
}
