<?php

namespace App\Museum\Enums;

enum SubmissionStatus: string
{
    case Submitted = 'submitted';
    case AiChecked = 'ai_checked';
    case ModeratorChecked = 'moderator_checked';
    case ExpertVerified = 'expert_verified';
    case Published = 'published';
    case Rejected = 'rejected';

    /** Allowed forward transitions; nothing jumps straight to Published. */
    public function next(): array
    {
        return match ($this) {
            self::Submitted => [self::AiChecked, self::ModeratorChecked, self::Rejected],
            self::AiChecked => [self::ModeratorChecked, self::Rejected],
            self::ModeratorChecked => [self::ExpertVerified, self::Published, self::Rejected],
            self::ExpertVerified => [self::Published, self::Rejected],
            self::Published => [self::Rejected],
            self::Rejected => [self::Submitted],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'ارسال‌شده',
            self::AiChecked => 'بررسی خودکار',
            self::ModeratorChecked => 'بررسی ناظر',
            self::ExpertVerified => 'تأیید کارشناس',
            self::Published => 'منتشرشده',
            self::Rejected => 'ردشده',
        };
    }
}
