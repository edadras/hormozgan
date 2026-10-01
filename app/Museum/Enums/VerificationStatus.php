<?php

namespace App\Museum\Enums;

/**
 * Verification state of a claim. Ordered: higher rank = stronger verification.
 * `disputed` and `rejected` are side states and are never deleted.
 */
enum VerificationStatus: string
{
    case Unverified = 'unverified';
    case AiExtracted = 'ai_extracted';
    case SourceVerified = 'source_verified';
    case CommunityVerified = 'community_verified';
    case ExpertVerified = 'expert_verified';
    case Disputed = 'disputed';
    case Rejected = 'rejected';

    public function rank(): int
    {
        return match ($this) {
            self::Rejected => -1,
            self::Unverified, self::Disputed => 0,
            self::AiExtracted => 1,
            self::SourceVerified => 2,
            self::CommunityVerified => 3,
            self::ExpertVerified => 4,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Unverified => 'تأییدنشده',
            self::AiExtracted => 'استخراج‌شده توسط AI',
            self::SourceVerified => 'تأیید با منبع',
            self::CommunityVerified => 'تأیید جامعه',
            self::ExpertVerified => 'تأیید کارشناس',
            self::Disputed => 'محل اختلاف منابع',
            self::Rejected => 'ردشده',
        };
    }

    public function atLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }

    /** Statuses whose rank is at least the given status (for SQL whereIn). */
    public static function atLeastValues(self $min): array
    {
        return array_values(array_map(
            fn (self $s) => $s->value,
            array_filter(self::cases(), fn (self $s) => $s !== self::Disputed && $s->rank() >= $min->rank())
        ));
    }
}
