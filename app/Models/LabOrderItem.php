<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabOrderItem extends Model
{
    protected $fillable = [
        'lab_order_id',
        'lab_test_id',
        'results',
    ];

    protected function casts(): array
    {
        return [
            'results' => 'array',
        ];
    }

    public function labOrder(): BelongsTo
    {
        return $this->belongsTo(LabOrder::class);
    }

    public function labTest(): BelongsTo
    {
        return $this->belongsTo(LabTest::class);
    }
}
