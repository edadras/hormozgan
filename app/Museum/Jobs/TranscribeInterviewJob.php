<?php

namespace App\Museum\Jobs;

use App\Models\Museum\Interview;
use App\Museum\Ai\Transcriber;

class TranscribeInterviewJob extends MuseumJob
{
    public int $timeout = 3600;

    public int $tries = 2;

    public function __construct(public int $interviewEntityId)
    {
        $this->onMuseumQueue('ai');
    }

    public function handle(Transcriber $transcriber): void
    {
        $interview = Interview::findOrFail($this->interviewEntityId);
        if ($interview->transcript_status === 'none') {
            $transcriber->transcribe($interview);
        }
    }
}
