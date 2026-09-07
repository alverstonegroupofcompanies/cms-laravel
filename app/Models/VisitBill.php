<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VisitBill extends Model
{
    protected $fillable = [
        'bill_code',
        'worksheet_id',
        'patient_id',
        'doctor_id',
        'queue_token_id',
        'consultation_fee',
        'pharmacy_subtotal',
        'lab_subtotal',
        'grand_total',
        'status',
        'pharmacy_status',
        'lab_status',
    ];

    protected function casts(): array
    {
        return [
            'consultation_fee' => 'float',
            'pharmacy_subtotal' => 'float',
            'lab_subtotal' => 'float',
            'grand_total' => 'float',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(VisitBillItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function worksheet(): BelongsTo
    {
        return $this->belongsTo(Worksheet::class);
    }

    public function queueToken(): BelongsTo
    {
        return $this->belongsTo(QueueToken::class);
    }
}
