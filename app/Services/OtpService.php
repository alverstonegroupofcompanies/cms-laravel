<?php

namespace App\Services;

use App\Models\OtpCode;
use Illuminate\Support\Facades\Log;

class OtpService
{
    public function send(string $phone): array
    {
        $code = app()->environment('local', 'testing') ? '123456' : (string) random_int(100000, 999999);

        OtpCode::where('phone', $phone)->delete();

        OtpCode::create([
            'phone' => $phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        Log::info("OTP for {$phone}: {$code}");

        return [
            'message' => 'OTP sent successfully',
            'dev_otp' => app()->environment('local', 'testing') ? $code : null,
        ];
    }

    public function verify(string $phone, string $code): bool
    {
        $otp = OtpCode::where('phone', $phone)
            ->where('code', $code)
            ->where('expires_at', '>', now())
            ->where('verified', false)
            ->first();

        if (! $otp) {
            return false;
        }

        $otp->update(['verified' => true]);

        return true;
    }
}
