<?php

namespace App\Services;

use App\Mail\OtpMail;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function send(string $email): array
    {
        $code = app()->environment('local', 'testing') ? '123456' : (string) random_int(100000, 999999);

        OtpCode::where('email', $email)->delete();

        OtpCode::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        if (app()->environment('local', 'testing')) {
            Log::info("OTP for {$email}: {$code}");
        } else {
            Mail::to($email)->send(new OtpMail($code));
        }

        return [
            'message' => 'OTP sent successfully',
            'dev_otp' => app()->environment('local', 'testing') ? $code : null,
        ];
    }

    public function verify(string $email, string $code): bool
    {
        $otp = OtpCode::where('email', $email)
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
