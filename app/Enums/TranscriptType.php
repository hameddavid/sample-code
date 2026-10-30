<?php

namespace App\Enums;

use App\Models\PaymentItem;

enum TranscriptType: string
{
    case OFFICIAL = 'OFFICIAL';
    case STUDENT = 'STUDENT';
    case PROFICIENCY = 'PROFICIENCY';

    public function label(): string
    {
        return match($this) {
            self::OFFICIAL => 'Official Transcript',
            self::STUDENT => 'Student Copy',
            self::PROFICIENCY => 'Proficiency Letter',
        };
    }

    public function amount(): int
    {
        return (int) PaymentItem::getAmount($this->value, match($this) {
            self::OFFICIAL => 15000,
            self::STUDENT => 5000,
            self::PROFICIENCY => 3000,
        });
    }
}
