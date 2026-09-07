<?php

namespace App\Models;

use App\Enums\DoctorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Doctor extends Model
{
    protected $appends = ['photo_url'];

    protected $fillable = [
        'user_id',
        'clinic_id',
        'department_id',
        'name',
        'specialization',
        'phone',
        'phone_secondary',
        'email',
        'photo_path',
        'status',
        'consultation_fee',
        'lunch_start',
        'lunch_end',
    ];

    protected function casts(): array
    {
        return [
            'status' => DoctorStatus::class,
            'consultation_fee' => 'decimal:2',
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

    public function clinic(): BelongsTo
    {
        return $this->belongsTo(Clinic::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function availability(): HasMany
    {
        return $this->hasMany(DoctorAvailability::class);
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(DoctorLeave::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function queueTokens(): HasMany
    {
        return $this->hasMany(QueueToken::class);
    }
}
