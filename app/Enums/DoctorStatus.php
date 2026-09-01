<?php

namespace App\Enums;

enum DoctorStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Inactive = 'inactive';
}
