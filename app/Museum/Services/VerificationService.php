<?php

namespace App\Museum\Services;

use App\Models\Museum\Entity;
use App\Models\Museum\Fact;
use App\Models\Museum\VerificationLog;
use App\Models\User;
use App\Museum\Enums\VerificationStatus;
use App\Museum\Enums\Visibility;
use App\Museum\Support\CacheVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Verification workflow, permission checks per target status, publication rules and audit log.
 */
class VerificationService
{
    /** Permission required to move a fact into a status. */
    public const STATUS_PERMISSIONS = [
        'unverified' => 'facts.edit',
        'ai_extracted' => 'facts.edit',
        'source_verified' => 'facts.verify',
        'community_verified' => 'facts.community_verify',
        'expert_verified' => 'facts.expert_verify',
        'disputed' => 'facts.verify',
        'rejected' => 'facts.verify',
    ];

    public function canSetStatus(?User $user, VerificationStatus $status): bool
    {
        return $user !== null && $user->hasMuseumPermission(self::STATUS_PERMISSIONS[$status->value]);
    }

    public function setFactStatus(Fact $fact, VerificationStatus $status, User $user, ?string $notes = null): Fact
    {
        if (! $this->canSetStatus($user, $status)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Not allowed to set status '.$status->value);
        }

        return app(FactService::class)->changeStatus($fact, $status, $user->id, $notes);
    }

    public function log(Model $target, string $action, ?string $from, ?string $to, ?int $userId, ?string $notes = null, ?array $evidence = null): void
    {
        VerificationLog::create([
            'verifiable_type' => $target->getMorphClass(),
            'verifiable_id' => $target->getKey(),
            'action' => $action,
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
            'notes' => $notes,
            'evidence' => $evidence,
            'created_at' => now(),
        ]);
    }

    /**
     * Publication rule: an entity becomes public only if it has at least the configured
     * number of public (sourced and sufficiently verified) facts, or an editor overrides
     * with an explicit reason. Candidates and merged entities are never published.
     */
    public function publishable(Entity $entity): array
    {
        $problems = [];
        if ($entity->is_candidate) {
            $problems[] = 'entity is still a discovery candidate';
        }
        if ($entity->merged_into_id) {
            $problems[] = 'entity was merged into another entity';
        }
        $public = Fact::where('entity_id', $entity->id)->public()->count();
        $min = (int) config('museum.publication.min_public_facts', 1);
        if ($public < $min) {
            $problems[] = "only {$public} sourced+verified facts (minimum {$min})";
        }

        return $problems;
    }

    public function publish(Entity $entity, ?int $userId, bool $force = false, ?string $reason = null): bool
    {
        $problems = $this->publishable($entity);
        if ($problems && ! ($force && $reason)) {
            return false;
        }
        DB::transaction(function () use ($entity, $userId, $problems, $reason) {
            $from = $entity->visibility;
            $entity->forceFill(['visibility' => Visibility::Published->value, 'published_at' => $entity->published_at ?? now()])->save();
            $this->log($entity, 'publish', $from, 'published', $userId, $reason, $problems ? ['overridden' => $problems] : null);
        });
        CacheVersion::bump();

        return true;
    }

    public function unpublish(Entity $entity, ?int $userId, string $reason): void
    {
        $from = $entity->visibility;
        $entity->forceFill(['visibility' => Visibility::Hidden->value])->save();
        $this->log($entity, 'unpublish', $from, 'hidden', $userId, $reason);
        CacheVersion::bump();
    }

    /** Recomputes an entity's aggregate verification level from its facts (best non-disputed level). */
    public function refreshEntityStatus(Entity $entity): void
    {
        $statuses = Fact::where('entity_id', $entity->id)->where('sources_count', '>', 0)->pluck('verification_status')->unique();
        $best = VerificationStatus::Unverified;
        foreach ($statuses as $s) {
            $st = VerificationStatus::tryFrom($s);
            if ($st && $st !== VerificationStatus::Disputed && $st->rank() > $best->rank()) {
                $best = $st;
            }
        }
        if ($entity->verification_status !== $best->value) {
            $entity->forceFill(['verification_status' => $best->value])->save();
        }
    }
}
