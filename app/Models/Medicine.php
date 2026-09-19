<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Medicine extends Model
{
    protected $fillable = [
        'clinic_product_id',
        'clinic_external_id',
        'name',
        'generic_name',
        'sku',
        'strength',
        'manufacturer',
        'unit',
        'stock_quantity',
        'unit_price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'clinic_product_id' => 'integer',
            'unit_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function prescriptionItems(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class);
    }
}
