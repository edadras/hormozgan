<?php

namespace App\Http\Controllers\Museum\Api;

use App\Museum\Jobs\ProcessSubmissionJob;
use App\Museum\Services\MediaService;
use App\Museum\Services\SubmissionService;
use Illuminate\Http\Request;

class SubmissionController extends ApiController
{
    public function store(Request $r, SubmissionService $service, MediaService $media)
    {
        $data = $r->validate([
            'submission_type' => 'required|in:'.implode(',', SubmissionService::TYPES),
            'title' => 'nullable|string|max:300',
            'payload' => 'required|array',
            'payload.*' => 'nullable|string|max:20000',
            'submitter_name' => 'nullable|string|max:200',
            'submitter_contact' => 'nullable|string|max:300',
            'place_text' => 'nullable|string|max:200',
            'dialect_text' => 'nullable|string|max:200',
            'consent_publish' => 'accepted',
            'license_offered' => 'nullable|in:cc_by,cc_by_sa,cc0,permission_granted',
            'is_own_work' => 'boolean',
            'file' => 'nullable|file|max:'.(config('museum.media.max_upload_mb') * 1024).'|mimetypes:image/jpeg,image/png,image/webp,audio/mpeg,audio/mp4,audio/wav,audio/x-wav,audio/ogg,video/mp4',
            'website' => 'prohibited', // honeypot
        ]);
        $submission = $service->submit($data, $r->user()?->id, $r->ip());
        if ($r->hasFile('file')) {
            $m = $media->store($r->file('file'), ['visibility' => 'draft', 'license' => 'unknown', 'title' => $data['title'] ?? null]);
            $payload = $submission->payload;
            $payload['media_uuid'] = $m->uuid;
            $submission->forceFill(['payload' => $payload])->save();
        }
        ProcessSubmissionJob::dispatch($submission->id);

        return response()->json([
            'id' => $submission->uuid,
            'status' => $submission->status,
            'message' => 'سپاسگزاریم. مشارکت شما ثبت شد و پس از بررسی ناظر و کارشناس منتشر می‌شود.',
        ], 201);
    }
}
