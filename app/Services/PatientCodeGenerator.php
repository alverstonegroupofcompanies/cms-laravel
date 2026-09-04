<?php

namespace App\Services;

use App\Models\Patient;

class PatientCodeGenerator
{
    private const PREFIX = 'AMC';

    public function generate(): string
    {
        $maxNumber = Patient::query()
            ->pluck('patient_code')
            ->map(fn (string $code) => $this->extractNumber($code))
            ->max() ?? 0;

        return self::PREFIX.'-'.str_pad((string) ($maxNumber + 1), 6, '0', STR_PAD_LEFT);
    }

    private function extractNumber(string $code): int
    {
        if (preg_match('/(\d+)$/', $code, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }
}
