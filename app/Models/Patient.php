<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Patient extends Model
{
    protected $appends = ['photo_url'];

    protected $fillable = [
        'user_id',
        'patient_code',
        'registration_source',
        'name',
        'first_name',
        'last_name',
        'dob',
        'gender',
        'blood_group',
        'marital_status',
        'symptoms',
        'current_medications',
        'taking_medications',
        'phone',
        'email',
        'address',
        'photo_path',
        'emergency_contact',
        'address_street',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'emergency_contact_first_name',
        'emergency_contact_last_name',
        'emergency_contact_relationship',
        'emergency_contact_phone',
    ];

    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'taking_medications' => 'boolean',
        ];
    }

    public function getPhotoUrlAttribute(): ?string
    {
        if (! $this->photo_path) {
            return null;
        }

        return Storage::disk('public')->url($this->photo_path);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function queueTokens(): HasMany
    {
        return $this->hasMany(QueueToken::class);
    }

    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    public function labOrders(): HasMany
    {
        return $this->hasMany(LabOrder::class);
    }
}
