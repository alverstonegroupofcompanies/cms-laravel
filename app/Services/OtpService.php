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
        $email = strtolower(trim($email));
        $skipMail = $this->shouldSkipMail();
        $code = $skipMail && app()->environment('local', 'testing')
            ? '123456'
            : (string) random_int(100000, 999999);

        OtpCode::where('email', $email)->delete();

        OtpCode::create([
            'email' => $email,
            'code' => $code,
            'expires_at' => now()->addMinutes(10),
        ]);

        $mailSent = false;

        if ($skipMail) {
            try {
                Log::info("OTP for {$email}: {$code} (mail skipped)");
            } catch (\Throwable) {
                // Ignore logger misconfiguration
            }
        } else {
            try {
                Mail::to($email)->send(new OtpMail($code));
                $mailSent = true;
            } catch (\Throwable $e) {
                try {
                    Log::error('OTP mail failed', [
                        'email' => $email,
                        'error' => $e->getMessage(),
                    ]);
                } catch (\Throwable) {
                    // Ignore logger misconfiguration
                }

                // Keep the code usable in local/dev so registration is not blocked by SMTP.
                if (app()->environment('local', 'testing')) {
                    try {
                        Log::info("OTP for {$email}: {$code} (mail failed; use this code)");
                    } catch (\Throwable) {
                        // Ignore logger misconfiguration
                    }
                } else {
                    throw new \RuntimeException('Failed to send OTP email. Please try again later.');
                }
            }

            if ($mailSent) {
                try {
                    Log::info("OTP mailed to {$email}");
                } catch (\Throwable) {
                    // Ignore logger misconfiguration so a sent email never fails
                }
            }
        }

        $exposeDevOtp = ! $mailSent && app()->environment('local', 'testing');

        return [
            'message' => $mailSent
                ? 'OTP sent successfully'
                : 'OTP generated. Check your inbox, or use the on-screen code if email was skipped.',
            'mail_sent' => $mailSent,
            'dev_otp' => $exposeDevOtp ? $code : null,
        ];
    }

    /**
     * Check whether a code is valid without consuming it.
     */
    public function isValid(string $email, string $code): bool
    {
        return $this->findValid($email, $code) !== null;
    }

    /**
     * Validate and mark the OTP as used.
     */
    public function verify(string $email, string $code): bool
    {
        $otp = $this->findValid($email, $code);

        if (! $otp) {
            return false;
        }

        $otp->update(['verified' => true]);

        return true;
    }

    private function findValid(string $email, string $code): ?OtpCode
    {
        $email = strtolower(trim($email));

        return OtpCode::where('email', $email)
            ->where('code', $code)
            ->where('expires_at', '>', now())
            ->where('verified', false)
            ->first();
    }

    private function shouldSkipMail(): bool
    {
        if (filter_var(env('OTP_SKIP_MAIL', false), FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }

        $mailer = (string) config('mail.default');

        return in_array($mailer, ['array', 'log'], true);
    }
}
