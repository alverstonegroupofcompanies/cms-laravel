<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worksheet extends Model
{
    protected $fillable = [
        'worksheet_code',
        'patient_id',
        'doctor_id',
        'queue_token_id',
        'appointment_id',
        'status',
        'chief_complaint',
        'clinical_notes',
        'clinical_data',
        'diagnosis',
        'advice',
        'follow_up',
        'opened_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'clinical_data' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }

    public function visitBill(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(VisitBill::class);
    }
}
