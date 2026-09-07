<?php

namespace App\Models;

use App\Enums\QueueTokenStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class QueueToken extends Model
{
    protected $fillable = [
        'patient_id',
        'doctor_id',
        'queue_date',
        'token_number',
        'display_code',
        'status',
        'end_of_line',
        'appointment_id',
        'called_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'queue_date' => 'date',
            'status' => QueueTokenStatus::class,
            'end_of_line' => 'boolean',
            'called_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function worksheet(): HasOne
    {
        return $this->hasOne(Worksheet::class);
    }
}
