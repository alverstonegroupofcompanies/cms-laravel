<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoyalPointTransaction extends Model
{
    protected $fillable = [
        'patient_id',
        'visit_bill_id',
        'points',
        'type',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visitBill(): BelongsTo
    {
        return $this->belongsTo(VisitBill::class);
    }
}

