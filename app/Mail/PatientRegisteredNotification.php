<?php

namespace App\Mail;

use App\Models\Patient;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PatientRegisteredNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Patient $patient) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New patient registration — '.$this->patient->name,
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
        $p = $this->patient;
        $dob = $p->dob?->format('d M Y') ?? '—';
        $gender = e($p->gender ?? '—');
        $bloodGroup = e($p->blood_group ?? '—');
        $maritalStatus = e($p->marital_status ?? '—');
        $symptoms = e($p->symptoms ?? 'None reported');
        $medications = e($p->current_medications ?? 'None reported');
        $name = e($p->name);
        $code = e($p->patient_code);
        $phone = e($p->phone);
        $email = e($p->email ?? '—');
        $address = e($p->address ?? '—');

        $emergencyName = trim(($p->emergency_contact_first_name ?? '').' '.($p->emergency_contact_last_name ?? ''));
        $emergencyName = $emergencyName !== '' ? e($emergencyName) : '—';
        $emergencyRel = e($p->emergency_contact_relationship ?? '—');
        $emergencyPhone = e($p->emergency_contact_phone ?? '—');
        $takingMeds = $p->taking_medications === null ? '—' : ($p->taking_medications ? 'Yes' : 'No');
        $street = e(collect([$p->address_street, $p->address_line2, $p->city, $p->state, $p->postal_code])->filter()->implode(', ') ?: ($p->address ?? '—'));

        return <<<HTML
        <div style="font-family: Arial, sans-serif; max-width: 560px; margin: 0 auto; padding: 24px;">
            <h2 style="color: #0d9488;">New Patient Registration</h2>
            <table style="width: 100%; border-collapse: collapse; font-size: 14px; color: #334155;">
                <tr><td style="padding: 6px 0; font-weight: bold;">Patient ID</td><td>{$code}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Name</td><td>{$name}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Date of Birth</td><td>{$dob}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Gender</td><td>{$gender}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Blood Group</td><td>{$bloodGroup}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Marital Status</td><td>{$maritalStatus}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Phone</td><td>{$phone}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Email</td><td>{$email}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Address</td><td>{$street}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Symptoms</td><td>{$symptoms}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Taking Medications</td><td>{$takingMeds}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Current Medications</td><td>{$medications}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Emergency Contact</td><td>{$emergencyName}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Relationship</td><td>{$emergencyRel}</td></tr>
                <tr><td style="padding: 6px 0; font-weight: bold;">Emergency Phone</td><td>{$emergencyPhone}</td></tr>
            </table>
        </div>
        HTML;
    }
}
