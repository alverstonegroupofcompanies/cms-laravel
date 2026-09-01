<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';
}
