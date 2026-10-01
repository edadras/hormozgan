<?php

namespace App\Museum\Services;

use App\Models\Museum\AudioRecording;
use App\Models\Museum\Media;
use App\Models\Museum\Photo;
use App\Models\Museum\Video;
use App\Models\Museum\WordPronunciation;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Media ingestion: content-addressed storage, metadata probing (ffprobe), derived variants
 * (thumbnails/web versions) generated in the background. Originals are never modified.
 */
class MediaService
{
    public function store(UploadedFile|string $file, array $attrs): Media
    {
        $localPath = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $sha = hash_file('sha256', $localPath);
        if ($existing = Media::where('sha256', $sha)->first()) {
            return $existing;
        }
        $original = $file instanceof UploadedFile ? $file->getClientOriginalName() : basename($file);
        $mime = $file instanceof UploadedFile ? ($file->getMimeType() ?? 'application/octet-stream') : (mime_content_type($localPath) ?: 'application/octet-stream');
        $type = $attrs['media_type'] ?? $this->typeFromMime($mime);
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION)) ?: 'bin';
        $disk = config('museum.storage.media_disk');
        $path = $type.'/'.substr($sha, 0, 2).'/'.$sha.'.'.$ext;
        Storage::disk($disk)->put($path, fopen($localPath, 'r'));

        $media = Media::create(array_merge([
            'license' => 'unknown',
            'copyright_status' => 'unknown',
            'visibility' => 'draft',
        ], $attrs, [
            'media_type' => $type,
            'disk' => $disk,
            'path' => $path,
            'original_filename' => $original,
            'mime_type' => $mime,
            'size_bytes' => filesize($localPath),
            'sha256' => $sha,
            'processing_status' => 'pending',
        ]));
        match ($type) {
            'audio' => AudioRecording::firstOrCreate(['media_id' => $media->id]),
            'image' => Photo::firstOrCreate(['media_id' => $media->id]),
            'video' => Video::firstOrCreate(['media_id' => $media->id]),
            default => null,
        };

        return $media;
    }

    /** Probes duration/dimensions and builds web variants. Runs in the media queue. */
    public function process(Media $media): Media
    {
        $abs = Storage::disk($media->disk)->path($media->path);
        $variants = $media->variants ?? [];
        try {
            if (in_array($media->media_type, ['audio', 'video'], true) && $this->has(config('museum.media.ffprobe'))) {
                $p = new Process([config('museum.media.ffprobe'), '-v', 'quiet', '-print_format', 'json', '-show_format', '-show_streams', $abs]);
                $p->setTimeout(120)->run();
                $info = json_decode($p->getOutput(), true) ?: [];
                $media->duration_ms = isset($info['format']['duration']) ? (int) round($info['format']['duration'] * 1000) : null;
                foreach ($info['streams'] ?? [] as $s) {
                    if (($s['codec_type'] ?? '') === 'video') {
                        $media->width = $s['width'] ?? null;
                        $media->height = $s['height'] ?? null;
                    }
                }
                if ($media->media_type === 'audio' && $this->has(config('museum.media.ffmpeg'))) {
                    $out = preg_replace('/\.[^.]+$/', '', $media->path).'.web.m4a';
                    $tmp = tempnam(sys_get_temp_dir(), 'museum-a').'.m4a';
                    (new Process([config('museum.media.ffmpeg'), '-y', '-i', $abs, '-vn', '-ac', '1', '-b:a', '96k', $tmp]))->setTimeout(600)->run();
                    if (is_file($tmp) && filesize($tmp) > 0) {
                        Storage::disk($media->disk)->put($out, fopen($tmp, 'r'));
                        $variants['web'] = $out;
                    }
                    @unlink($tmp);
                }
            }
            if ($media->media_type === 'image' && function_exists('imagecreatefromstring')) {
                $img = @imagecreatefromstring(Storage::disk($media->disk)->get($media->path));
                if ($img) {
                    $media->width = imagesx($img);
                    $media->height = imagesy($img);
                    $w = (int) config('museum.media.thumbnail_width', 640);
                    if ($media->width > $w) {
                        $thumb = imagescale($img, $w);
                        ob_start();
                        imagewebp($thumb, null, 80);
                        $bytes = ob_get_clean();
                        $out = preg_replace('/\.[^.]+$/', '', $media->path).'.w'.$w.'.webp';
                        Storage::disk($media->disk)->put($out, $bytes);
                        $variants['thumb'] = $out;
                    }
                }
            }
            $media->variants = $variants;
            $media->processing_status = 'processed';
        } catch (\Throwable $e) {
            $media->processing_status = 'failed';
        }
        $media->save();

        return $media;
    }

    /**
     * Section 11: pronunciation evidence must be a real recording by a speaker; synthetic (TTS)
     * audio is refused, and the speaker must have consented to publishing audio for it to be public.
     */
    public function addPronunciation(int $wordId, Media $audio, array $attrs = []): WordPronunciation
    {
        $rec = AudioRecording::find($audio->id);
        if (! $rec || $audio->media_type !== 'audio') {
            throw new \InvalidArgumentException('Pronunciation must be an audio recording.');
        }
        if ($rec->is_synthetic) {
            throw new \InvalidArgumentException('Synthetic (TTS) audio cannot be used as pronunciation evidence.');
        }
        if (! $rec->speaker_id) {
            throw new \InvalidArgumentException('A pronunciation recording must identify its (possibly anonymous) speaker record.');
        }

        return WordPronunciation::create(array_merge($attrs, [
            'word_id' => $wordId,
            'audio_media_id' => $audio->id,
            'speaker_id' => $rec->speaker_id,
            'place_id' => $attrs['place_id'] ?? $rec->place_id,
        ]));
    }

    private function typeFromMime(string $mime): string
    {
        return match (true) {
            str_starts_with($mime, 'image/') => 'image',
            str_starts_with($mime, 'audio/') => 'audio',
            str_starts_with($mime, 'video/') => 'video',
            default => 'document',
        };
    }

    private function has(string $bin): bool
    {
        return (new ExecutableFinder)->find($bin) !== null || is_executable($bin);
    }
}
