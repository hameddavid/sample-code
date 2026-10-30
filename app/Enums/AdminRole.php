<?php

namespace App\Enums;

enum AdminRole: string
{
    case RECOMMENDER = '200';
    case APPROVER = '300';
    case SUPER_ADMIN = '400';

    public function label(): string
    {
        return match($this) {
            self::RECOMMENDER => 'Recommender',
            self::APPROVER => 'Approver',
            self::SUPER_ADMIN => 'Super Admin',
        };
    }

    public function canRecommend(): bool
    {
        return $this === self::RECOMMENDER;
    }

    public function canApprove(): bool
    {
        return $this === self::APPROVER || $this === self::SUPER_ADMIN;
    }

    public function canManageUsers(): bool
    {
        return $this === self::SUPER_ADMIN;
    }
}
