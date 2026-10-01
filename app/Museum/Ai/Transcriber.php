<?php

namespace App\Museum\Ai;

use App\Models\Museum\AiJob;
use App\Models\Museum\Interview;
use App\Models\Museum\InterviewSegment;
use App\Models\Museum\Media;
use App\Museum\Services\MentionLinker;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Speech-to-text for oral history. Uses any server implementing the OpenAI-compatible
 * `/audio/transcriptions` API with `verbose_json` segments (e.g. a self-hosted Whisper
 * server, so recordings never leave project infrastructure). Output is stored as AI draft
 * segments with timestamps; text_original is preserved, corrections go to text_corrected.
 */
class Transcriber
{
    public function available(): bool
    {
        return (bool) config('museum.transcription.url');
    }

    public function transcribe(Interview $interview): int
    {
        $media = $interview->audio ?? $interview->video;
        if (! $media instanceof Media) {
            throw new \RuntimeException('Interview has no audio or video media.');
        }
        if (! $this->available()) {
            throw new \RuntimeException('No transcription service configured (MUSEUM_TRANSCRIBE_URL).');
        }
        $job = AiJob::create(['job_type' => 'transcribe', 'provider' => 'whisper_compatible', 'model' => config('museum.transcription.model'),
            'status' => 'running', 'subject_type' => $interview->getMorphClass(), 'subject_id' => $interview->getKey(), 'started_at' => now()]);
        try {
            $stream = Storage::disk($media->disk)->readStream($media->path);
            $res = Http::withToken((string) config('museum.transcription.api_key'))
                ->timeout(1800)
                ->attach('file', $stream, basename($media->path))
                ->post(rtrim(config('museum.transcription.url'), '/').'/audio/transcriptions', [
                    'model' => config('museum.transcription.model'),
                    'response_format' => 'verbose_json',
                    'language' => config('museum.transcription.language', 'fa'),
                    'timestamp_granularities[]' => 'segment',
                ])->throw();
            $job->forceFill(['raw_output' => $res->body()])->save();
            $segments = $res->json('segments') ?? [];
            $seq = (int) InterviewSegment::where('interview_id', $interview->entity_id)->max('seq');
            $linker = app(MentionLinker::class);
            foreach ($segments as $s) {
                $seg = InterviewSegment::create([
                    'interview_id' => $interview->entity_id,
                    'seq' => ++$seq,
                    'start_ms' => (int) round(($s['start'] ?? 0) * 1000),
                    'end_ms' => (int) round(($s['end'] ?? 0) * 1000),
                    'text_original' => trim((string) ($s['text'] ?? '')),
                    'is_ai_generated' => true,
                    'verification_status' => 'ai_extracted',
                ]);
                $linker->link($seg, $seg->text_original);
            }
            $interview->forceFill(['transcript_status' => 'ai_draft'])->save();
            $job->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();

            return count($segments);
        } catch (\Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
            throw $e;
        }
    }
}
