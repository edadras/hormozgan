<?php

namespace App\Museum\Services;

use App\Models\Museum\CommunitySubmission;
use App\Models\Museum\Entity;
use App\Models\Museum\Sentence;
use App\Models\Museum\Source;
use App\Models\User;
use App\Museum\Ai\LlmClient;
use App\Museum\Enums\SubmissionStatus;
use App\Museum\Support\TextNormalizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * «شما هم به موزه کمک کنید» — community contributions.
 * submitted → ai_checked → moderator_checked → (expert_verified) → published; rejected is kept.
 * Publishing turns the submission into a cited record: the submission itself becomes a
 * `community_submission` source (with contributor name only if they consented).
 */
class SubmissionService
{
    public const TYPES = ['word', 'pronunciation', 'proverb', 'story', 'photo', 'video', 'old_name', 'food', 'recipe',
        'historical_info', 'memory', 'music', 'sentence'];

    public function __construct(
        private LlmClient $llm,
        private EntityResolver $resolver,
        private EntityService $entities,
        private SourceService $sources,
        private VerificationService $verification,
    ) {}

    public function submit(array $data, ?int $userId, ?string $ip): CommunitySubmission
    {
        return CommunitySubmission::create([
            'submission_type' => $data['submission_type'],
            'title' => $data['title'] ?? null,
            'payload' => $data['payload'] ?? [],
            'user_id' => $userId,
            'submitter_name' => $data['submitter_name'] ?? null,
            'submitter_contact' => $data['submitter_contact'] ?? null,
            'place_text' => $data['place_text'] ?? null,
            'dialect_text' => $data['dialect_text'] ?? null,
            'consent_publish' => (bool) ($data['consent_publish'] ?? false),
            'license_offered' => $data['license_offered'] ?? null,
            'is_own_work' => (bool) ($data['is_own_work'] ?? false),
            'status' => SubmissionStatus::Submitted->value,
            'ip_hash' => $ip ? hash('sha256', $ip.config('app.key')) : null,
        ]);
    }

    /**
     * Automated checks: place resolution, duplicates against existing records, completeness,
     * and (if an LLM is configured) a content-safety/relevance screen. Advisory only.
     */
    public function aiCheck(CommunitySubmission $s): CommunitySubmission
    {
        $checks = ['issues' => []];
        if ($s->place_text) {
            $r = $this->resolver->resolve($s->place_text, ['types' => ['place']]);
            $checks['place'] = ['decision' => $r['decision'], 'entity_id' => $r['entity']?->id, 'score' => $r['score']];
            if ($r['decision'] === EntityResolver::MATCHED) {
                $s->place_id = $r['entity']->id;
            }
        }
        $main = (string) ($s->payload['word'] ?? $s->payload['text'] ?? $s->payload['name'] ?? $s->title ?? '');
        if ($main !== '') {
            $dups = $this->resolver->candidates($main, [], 3)->map(fn ($c) => ['entity_id' => $c['entity']->id, 'name' => $c['entity']->canonical_name, 'score' => $c['score']]);
            $checks['possible_duplicates'] = $dups->values()->all();
        } else {
            $checks['issues'][] = 'no main text';
        }
        if (! $s->consent_publish) {
            $checks['issues'][] = 'contributor did not consent to publication';
        }
        if (in_array($s->submission_type, ['photo', 'video', 'music'], true) && ! $s->is_own_work && ! $s->license_offered) {
            $checks['issues'][] = 'media rights unclear';
        }
        if ($this->llm->available() && $main !== '') {
            try {
                $res = $this->llm->json(
                    'You screen community contributions to a cultural archive about Hormozgan (Iran). Do not judge whether '
                    .'the cultural content is true — experts do that. Only flag: spam, abuse, personal data about private '
                    .'individuals, or content clearly unrelated to Hormozgan culture, language, history or nature.',
                    json_encode(['type' => $s->submission_type, 'title' => $s->title, 'payload' => $s->payload], JSON_UNESCAPED_UNICODE),
                    ['type' => 'object', 'properties' => [
                        'flags' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'contains_private_personal_data' => ['type' => 'boolean'],
                        'relevant' => ['type' => 'boolean'],
                    ], 'required' => ['flags', 'contains_private_personal_data', 'relevant'], 'additionalProperties' => false],
                    ['job_type' => 'submission_screen', 'subject' => $s, 'max_tokens' => 1000, 'effort' => 'low']
                );
                $checks['screen'] = $res->data;
            } catch (\Throwable $e) {
                $checks['screen_error'] = $e->getMessage();
            }
        }
        $s->ai_check = $checks;
        if ($s->status === SubmissionStatus::Submitted->value) {
            $s->status = SubmissionStatus::AiChecked->value;
        }
        $s->save();

        return $s;
    }

    public function transition(CommunitySubmission $s, SubmissionStatus $to, User $user, ?string $notes = null): CommunitySubmission
    {
        $from = SubmissionStatus::from($s->status);
        if (! $from->canTransitionTo($to)) {
            throw new \InvalidArgumentException("Cannot move submission from {$from->value} to {$to->value}.");
        }
        $needed = match ($to) {
            SubmissionStatus::ExpertVerified => 'submissions.expert',
            default => 'submissions.moderate',
        };
        if (! $user->hasMuseumPermission($needed)) {
            throw new AuthorizationException('Missing permission '.$needed);
        }
        if ($to === SubmissionStatus::Published && ! $s->consent_publish) {
            throw new \InvalidArgumentException('Cannot publish without the contributor\'s consent.');
        }

        return DB::transaction(function () use ($s, $from, $to, $user, $notes) {
            if ($to === SubmissionStatus::ModeratorChecked) {
                $s->moderator_id = $user->id;
            }
            if ($to === SubmissionStatus::ExpertVerified) {
                $s->expert_id = $user->id;
            }
            if ($to === SubmissionStatus::Published) {
                $this->materialize($s, $from === SubmissionStatus::ExpertVerified ? 'expert_verified' : 'community_verified');
            }
            $s->status = $to->value;
            $s->review_notes = trim(($s->review_notes ? $s->review_notes."\n" : '').($notes ?? ''));
            $s->save();
            $this->verification->log($s, 'submission_'.$to->value, $from->value, $to->value, $user->id, $notes);

            return $s;
        });
    }

    /** Creates the knowledge record for a published submission, cited to the submission source. */
    private function materialize(CommunitySubmission $s, string $status): void
    {
        $source = $s->source ?? Source::create([
            'source_type' => 'community_submission',
            'title' => 'مشارکت مردمی: '.($s->title ?: $s->submission_type).' ('.$s->uuid.')',
            'author' => $s->consent_publish && $s->submitter_name ? $s->submitter_name : 'مشارکت‌کننده ناشناس',
            'publication_date' => $s->created_at->format('Y-m-d'),
            'license' => $s->license_offered ?: 'permission_granted',
            'copyright_status' => 'contributor_consent',
            'reliability_tier' => 2,
            'crawl_policy' => 'forbidden',
            'verification_status' => $status,
            'metadata' => ['submission_uuid' => $s->uuid],
        ]);
        $s->source_id = $source->id;
        $p = $s->payload;
        $quote = (string) ($p['text'] ?? $p['word'] ?? $p['description'] ?? $s->title ?? '');
        $extract = $quote !== '' ? $this->sources->extract($source, $quote, ['locator' => 'submission:'.$s->uuid, 'extracted_by' => 'human']) : null;
        $facts = app(FactService::class);

        $result = match ($s->submission_type) {
            'word' => tap($this->entities->create('word', [
                'canonical_name' => $p['word'], 'name_local' => $p['word'], 'verification_status' => $status, 'primary_place_id' => $s->place_id,
            ], [
                'headword' => $p['word'], 'normalized_headword' => TextNormalizer::normalize($p['word']),
                'transcription_fa' => $p['transcription_fa'] ?? null, 'place_id' => $s->place_id, 'usage_notes' => $p['usage'] ?? null,
            ]), function (Entity $e) use ($p, $source, $status, $extract) {
                $e->word->meanings()->create(['sense_no' => 1, 'meaning_fa' => $p['meaning_fa'] ?? 'UNKNOWN', 'is_primary' => true,
                    'source_id' => $source->id, 'verification_status' => $status]);
                $this->sources->cite($e->word, $source, ['extract' => $extract]);
            }),
            'sentence' => tap(Sentence::create([
                'text_local' => $p['text'], 'normalized_text' => TextNormalizer::normalize($p['text']), 'text_fa' => $p['text_fa'] ?? null,
                'place_id' => $s->place_id, 'verification_status' => $status,
            ]), fn ($sent) => $this->sources->cite($sent, $source, ['extract' => $extract])),
            'old_name' => tap(Entity::findOrFail($s->place_id), function (Entity $place) use ($p, $source, $status, $extract) {
                $this->entities->addHistoricalName($place, ['name' => $p['name'], 'period_label' => $p['period'] ?? null,
                    'meaning' => $p['meaning'] ?? null, 'source_id' => $source->id, 'source_extract_id' => $extract?->id,
                    'verification_status' => $status]);
            }),
            default => tap($this->entities->create(match ($s->submission_type) {
                'proverb' => 'proverb', 'food', 'recipe' => 'food', 'music' => 'music_work', 'story', 'memory' => 'story',
                default => 'story',
            }, ['canonical_name' => $s->title ?: mb_substr($quote, 0, 120), 'verification_status' => $status, 'primary_place_id' => $s->place_id],
                $s->submission_type === 'proverb' ? ['text_local' => $quote, 'normalized_text' => TextNormalizer::normalize($quote), 'place_id' => $s->place_id] : []),
                function (Entity $e) use ($facts, $quote, $source, $extract, $status) {
                    if ($quote !== '' && $e->type->key !== 'proverb') {
                        $facts->assert($e, 'description', $quote, ['source' => $source, 'extract' => $extract, 'status' => $status, 'method' => 'community']);
                    }
                }),
        };
        $s->result_type = $result->getMorphClass();
        $s->result_id = $result->getKey();
    }
}
