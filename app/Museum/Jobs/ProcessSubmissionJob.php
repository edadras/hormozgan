<?php

namespace App\Museum\Jobs;

use App\Models\Museum\CommunitySubmission;
use App\Museum\Services\SubmissionService;

class ProcessSubmissionJob extends MuseumJob
{
    public function __construct(public int $submissionId)
    {
        $this->onMuseumQueue('ai');
    }

    public function handle(SubmissionService $service): void
    {
        if ($s = CommunitySubmission::find($this->submissionId)) {
            $service->aiCheck($s);
        }
    }
}
