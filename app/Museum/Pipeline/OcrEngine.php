<?php

namespace App\Museum\Pipeline;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Tesseract OCR (fas+ara+eng by default). If the binary is not installed the engine reports
 * itself unavailable and scanned pages are flagged for OCR later instead of failing.
 * Raw OCR output is always stored as-is; cleanup happens in a separate, preserved field.
 */
class OcrEngine
{
    public function available(): bool
    {
        if (config('museum.ocr.driver') !== 'tesseract') {
            return false;
        }

        return (new ExecutableFinder)->find(config('museum.ocr.tesseract_binary', 'tesseract')) !== null;
    }

    /** @return array{text: string, confidence: ?float, engine: string} */
    public function recognize(string $imagePath): array
    {
        if (! $this->available()) {
            throw new \RuntimeException('OCR engine not available (install tesseract with fas/ara/eng data).');
        }
        $p = new Process([config('museum.ocr.tesseract_binary', 'tesseract'), $imagePath, 'stdout', '-l', config('museum.ocr.languages'), 'tsv']);
        $p->setTimeout(600)->mustRun();

        return $this->fromTsv($p->getOutput());
    }

    public function recognizePdfPage(string $pdf, int $page): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'museum-ocr-');
        $p = new Process([config('museum.ocr.pdftoppm_binary', 'pdftoppm'), '-f', (string) $page, '-l', (string) $page, '-r', '300', '-png', '-singlefile', $pdf, $tmp]);
        $p->setTimeout(300)->mustRun();
        try {
            return $this->recognize($tmp.'.png');
        } finally {
            @unlink($tmp.'.png');
            @unlink($tmp);
        }
    }

    /** Rebuilds lines from Tesseract TSV and averages word confidence. */
    public function fromTsv(string $tsv): array
    {
        $lines = [];
        $confs = [];
        foreach (array_slice(explode("\n", trim($tsv)), 1) as $row) {
            $c = explode("\t", $row);
            if (count($c) < 12 || trim($c[11]) === '') {
                continue;
            }
            $key = $c[2].'-'.$c[3].'-'.$c[4];
            $lines[$key][] = $c[11];
            if ((float) $c[10] >= 0) {
                $confs[] = (float) $c[10];
            }
        }

        return [
            'text' => implode("\n", array_map(fn ($w) => implode(' ', $w), $lines)),
            'confidence' => $confs ? round(array_sum($confs) / count($confs) / 100, 4) : null,
            'engine' => 'tesseract',
        ];
    }
}
