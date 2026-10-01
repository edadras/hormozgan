<?php

namespace App\Museum\Pipeline;

use App\Models\Museum\TextChunk;
use App\Museum\Support\TextNormalizer;
use Illuminate\Database\Eloquent\Model;

/**
 * Page-aware chunking for extraction and RAG. Chunks never span pages, so every chunk
 * (and every quote found in it) carries an exact page number.
 */
class Chunker
{
    /** @return list<array{page: int, text: string}> */
    public function split(string $pageSeparatedText): array
    {
        $size = (int) config('museum.extraction.chunk_chars', 3500);
        $overlap = (int) config('museum.extraction.chunk_overlap', 300);
        $out = [];
        foreach (explode("\f", $pageSeparatedText) as $i => $page) {
            $page = trim(preg_replace("/[ \t]+/u", ' ', $page));
            if (mb_strlen($page) < 20) {
                continue;
            }
            $paragraphs = preg_split("/\n\s*\n/u", $page);
            $buf = '';
            foreach ($paragraphs as $para) {
                $para = trim($para);
                if ($buf !== '' && mb_strlen($buf) + mb_strlen($para) > $size) {
                    $out[] = ['page' => $i + 1, 'text' => $buf];
                    $buf = mb_substr($buf, -$overlap)."\n\n";
                }
                // Hard-split very long paragraphs.
                while (mb_strlen($para) > $size) {
                    $out[] = ['page' => $i + 1, 'text' => $buf.mb_substr($para, 0, $size)];
                    $para = mb_substr($para, $size - $overlap);
                    $buf = '';
                }
                $buf .= $para."\n\n";
            }
            if (trim($buf) !== '') {
                $out[] = ['page' => $i + 1, 'text' => trim($buf)];
            }
        }

        return $out;
    }

    /** Replaces the chunks of a record (idempotent re-chunking keeps unchanged chunk rows). */
    public function store(Model $chunkable, string $text, array $attrs = []): int
    {
        $parts = $this->split($text);
        $keep = [];
        foreach ($parts as $seq => $part) {
            $hash = hash('sha256', $part['text']);
            $chunk = TextChunk::firstOrCreate([
                'chunkable_type' => $chunkable->getMorphClass(),
                'chunkable_id' => $chunkable->getKey(),
                'text_hash' => $hash,
            ], array_merge($attrs, [
                'seq' => $seq,
                'page_number' => $attrs['page_number'] ?? (string) $part['page'],
                'text' => $part['text'],
                'normalized_text' => TextNormalizer::normalize($part['text']),
                'token_estimate' => (int) ceil(mb_strlen($part['text']) / 3.5),
            ]));
            $keep[] = $chunk->id;
        }
        TextChunk::where('chunkable_type', $chunkable->getMorphClass())->where('chunkable_id', $chunkable->getKey())
            ->whereNotIn('id', $keep)->delete();

        return count($keep);
    }
}
