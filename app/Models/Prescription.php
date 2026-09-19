<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Prescription extends Model
{
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'queue_token_id',
        'worksheet_id',
        'notes',
        'status',
        'clinic_prescription_id',
        'clinic_prescription_number',
        'clinic_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'clinic_prescription_id' => 'integer',
            'clinic_synced_at' => 'datetime',
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

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }

    public function dispense(): HasOne
    {
        return $this->hasOne(Dispense::class);
    }
}
