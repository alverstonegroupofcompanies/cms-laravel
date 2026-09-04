<?php

namespace App\Mail;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Appointment $appointment) {}

    public function envelope(): Envelope
    {
        $time = substr((string) $this->appointment->slot_time, 0, 5);

        return new Envelope(
            subject: "Reminder: appointment in 2 hours ({$time})",
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: $this->buildHtml(),
        );
    }

    private function buildHtml(): string
    {
        $a = $this->appointment->loadMissing(['patient', 'doctor', 'clinic']);
        $patientName = e($a->patient?->name ?? 'Patient');
        $doctorName = e($a->doctor?->name ?? 'Doctor');
        $spec = e($a->doctor?->specialization ?? 'Consultation');
        $clinic = e($a->clinic?->name ?? 'Alverstone Medcity');
        $date = e($a->appointment_date?->format('l, d F Y') ?? '—');
        $time = e(substr((string) $a->slot_time, 0, 5));
        $portal = e(rtrim((string) config('clinic.frontend_url', config('app.url')), '/').'/patient/dashboard');

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 520px; margin: 0 auto; padding: 24px; color: #334155;">
            <h2 style="color: #9f1239; margin-bottom: 8px;">Alverstone Medcity</h2>
            <p style="font-size: 15px;">Hi {$patientName},</p>
            <p style="font-size: 15px;">This is a reminder that your appointment is in about <strong>2 hours</strong>.</p>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px; margin: 20px 0; background: #f8fafc; border-radius: 8px;">
                <tr><td style="padding: 10px 14px; font-weight: bold;">Date</td><td style="padding: 10px 14px;">{$date}</td></tr>
                <tr><td style="padding: 10px 14px; font-weight: bold;">Time</td><td style="padding: 10px 14px;">{$time}</td></tr>
                <tr><td style="padding: 10px 14px; font-weight: bold;">Doctor</td><td style="padding: 10px 14px;">{$doctorName} · {$spec}</td></tr>
                <tr><td style="padding: 10px 14px; font-weight: bold;">Clinic</td><td style="padding: 10px 14px;">{$clinic}</td></tr>
            </table>
            <p style="font-size: 14px; color: #64748b;">Please arrive a few minutes early. If you cannot attend, reschedule or cancel from your patient portal.</p>
            <p style="margin-top: 20px;">
                <a href="{$portal}" style="display: inline-block; background: #9f1239; color: #fff; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: bold;">Open dashboard</a>
            </p>
        </div>
        HTML;
    }
}
