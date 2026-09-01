<?php

namespace App\Services;

use App\Models\Patient;

class PatientCodeGenerator
{
    public function generate(): string
    {
        $lastPatient = Patient::orderByDesc('id')->first();
        $nextNumber = $lastPatient ? ((int) str_replace('PAT-', '', $lastPatient->patient_code)) + 1 : 1;

        return 'PAT-'.str_pad((string) $nextNumber, 6, '0', STR_PAD_LEFT);
    }
}
