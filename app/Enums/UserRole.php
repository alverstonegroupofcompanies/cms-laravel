<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Doctor = 'doctor';
    case Receptionist = 'receptionist';
    case Pharmacy = 'pharmacy';
    case Lab = 'lab';
    case Patient = 'patient';
}
