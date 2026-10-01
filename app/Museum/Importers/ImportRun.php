<?php

namespace App\Museum\Importers;

use App\Models\Museum\ImportBatch;

/**
 * Tracks an import batch and produces the import report (section 61).
 */
class ImportRun
{
    public const COUNTERS = [
        'sources_found', 'sources_accepted', 'sources_rejected', 'documents_processed', 'facts_extracted',
        'entities_created', 'entities_matched', 'duplicates', 'conflicts', 'low_confidence', 'errors',
    ];

    private array $counts;

    private array $errors = [];

    public function __construct(public ImportBatch $batch)
    {
        $this->counts = array_fill_keys(self::COUNTERS, 0);
    }

    public static function start(string $importer, array $options = [], ?int $sourceId = null, ?int $userId = null, ?string $input = null): self
    {
        return new self(ImportBatch::create([
            'importer' => $importer,
            'status' => 'running',
            'options' => $options,
            'source_id' => $sourceId,
            'input_reference' => $input,
            'started_by' => $userId,
            'started_at' => now(),
        ]));
    }

    public function inc(string $counter, int $by = 1): void
    {
        $this->counts[$counter] += $by;
        if ($by && $this->counts[$counter] % 200 === 0) {
            $this->flush();
        }
    }

    public function error(string $message, array $context = []): void
    {
        $this->counts['errors']++;
        if (count($this->errors) < 500) {
            $this->errors[] = ['message' => mb_substr($message, 0, 1000), 'context' => $context, 'at' => now()->toIso8601String()];
        }
    }

    public function flush(): void
    {
        $this->batch->forceFill($this->counts + ['error_log' => $this->errors])->save();
    }

    public function finish(string $status = 'completed', array $extra = []): ImportBatch
    {
        $this->batch->forceFill($this->counts + [
            'status' => $status,
            'error_log' => $this->errors,
            'finished_at' => now(),
            'report' => array_merge($this->counts, $extra),
        ])->save();

        return $this->batch;
    }

    public function counts(): array
    {
        return $this->counts;
    }

    /** Human-readable report lines (used by console commands and the admin panel). */
    public static function reportLines(ImportBatch $b): array
    {
        $labels = [
            'sources_found' => 'Sources Found', 'sources_accepted' => 'Sources Accepted', 'sources_rejected' => 'Sources Rejected',
            'documents_processed' => 'Documents Processed', 'facts_extracted' => 'Facts Extracted',
            'entities_created' => 'Entities Created', 'entities_matched' => 'Entities Matched', 'duplicates' => 'Duplicates',
            'conflicts' => 'Conflicts', 'low_confidence' => 'Low Confidence Facts', 'errors' => 'Errors',
        ];
        $out = [];
        foreach ($labels as $k => $label) {
            $out[] = [$label, (int) $b->{$k}];
        }

        return $out;
    }
}
