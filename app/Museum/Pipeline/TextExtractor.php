<?php

namespace App\Museum\Pipeline;

use App\Models\Museum\RawDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Turns raw bytes into page-separated text (form feed "\f" between pages).
 * PDF: pdftotext per page; pages with too little text are treated as scans and OCR'd.
 * HTML: main content without scripts/navigation. Images: OCR.
 * The extracted text is stored next to the raw file; the raw file is untouched.
 */
class TextExtractor
{
    public function __construct(private OcrEngine $ocr) {}

    public function extract(RawDocument $raw): RawDocument
    {
        $disk = Storage::disk($raw->disk);
        $ext = strtolower(pathinfo($raw->path, PATHINFO_EXTENSION));
        $ocrPages = 0;
        try {
            [$pages, $ocrPages] = match (true) {
                $ext === 'pdf' => $this->pdf($disk->path($raw->path)),
                in_array($ext, ['html', 'htm', 'xml'], true) => [[$this->html($disk->get($raw->path))], 0],
                in_array($ext, ['jpg', 'jpeg', 'png', 'tif', 'tiff'], true) => [[$this->ocr->recognize($disk->path($raw->path))['text']], 1],
                in_array($ext, ['txt', 'csv', 'json'], true) => [[$disk->get($raw->path)], 0],
                default => throw new \RuntimeException("No text extractor for .$ext"),
            };
        } catch (\Throwable $e) {
            $raw->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            return $raw;
        }
        $textPath = preg_replace('/\.[a-z0-9]+$/i', '', $raw->path).'.txt';
        $disk->put($textPath, implode("\f", $pages));
        $meta = $raw->metadata ?? [];
        $meta['ocr_pages'] = $ocrPages;
        $raw->forceFill([
            'text_path' => $textPath,
            'page_count' => count($pages),
            'status' => 'text_extracted',
            'metadata' => $meta,
            'error' => null,
        ])->save();

        return $raw;
    }

    /** @return array{0: list<string>, 1: int} */
    private function pdf(string $file): array
    {
        $bin = config('museum.ocr.pdftotext_binary', 'pdftotext');
        $p = new Process([$bin, '-layout', '-enc', 'UTF-8', $file, '-']);
        $p->setTimeout(600)->mustRun();
        $pages = explode("\f", rtrim($p->getOutput(), "\f"));
        $min = (int) config('museum.ocr.min_text_chars_per_page', 40);
        $ocrCount = 0;
        if ($this->ocr->available()) {
            foreach ($pages as $i => $text) {
                if (mb_strlen(trim($text)) < $min) {
                    $pages[$i] = $this->ocr->recognizePdfPage($file, $i + 1)['text'];
                    $ocrCount++;
                }
            }
        }

        return [$pages, $ocrCount];
    }

    public function html(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        foreach ($xp->query('//script|//style|//noscript|//nav|//footer|//header|//form|//aside') as $n) {
            $n->parentNode?->removeChild($n);
        }
        $main = $xp->query('//main|//article|//*[@id="content"]|//*[@id="mw-content-text"]')->item(0) ?? $dom->getElementsByTagName('body')->item(0);
        if (! $main) {
            return trim(strip_tags($html));
        }
        $text = '';
        foreach ($xp->query('.//text()', $main) as $t) {
            $parent = $t->parentNode?->nodeName;
            $text .= in_array($parent, ['p', 'li', 'h1', 'h2', 'h3', 'h4', 'td', 'div', 'dd', 'dt'], true) ? $t->nodeValue."\n" : $t->nodeValue;
        }

        return trim(preg_replace("/\n{3,}/", "\n\n", preg_replace('/[ \t]+/', ' ', $text)));
    }
}
