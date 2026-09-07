<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitBillItem extends Model
{
    protected $fillable = [
        'visit_bill_id',
        'section',
        'name',
        'detail',
        'qty',
        'unit_price',
        'amount',
        'status',
        'included',
        'purchased',
        'prescription_id',
        'prescription_item_id',
        'lab_order_id',
        'lab_order_item_id',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'float',
            'amount' => 'float',
            'included' => 'boolean',
            'purchased' => 'boolean',
        ];
    }

    public function visitBill(): BelongsTo
    {
        return $this->belongsTo(VisitBill::class);
    }
}
