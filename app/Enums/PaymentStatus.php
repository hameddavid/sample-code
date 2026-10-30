<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case SUCCESS = 'success';

    public function statusCode(): string
    {
        return match($this) {
            self::PENDING => '025',
            self::SUCCESS => '00',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'Pending',
            self::SUCCESS => 'Successful',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PENDING => 'yellow',
            self::SUCCESS => 'green',
        };
    }
}
