<?php

namespace App\Museum\Jobs;

use App\Models\Museum\Media;
use App\Museum\Services\MediaService;

class ProcessMediaJob extends MuseumJob
{
    public int $timeout = 1800;

    public function __construct(public int $mediaId)
    {
        $this->onMuseumQueue('media');
    }

    public function handle(MediaService $media): void
    {
        if ($m = Media::find($this->mediaId)) {
            $media->process($m);
        }
    }
}
