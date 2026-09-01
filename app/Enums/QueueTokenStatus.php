<?php

namespace App\Enums;

enum QueueTokenStatus: string
{
    case Waiting = 'waiting';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case Skipped = 'skipped';
    case Cancelled = 'cancelled';
}
