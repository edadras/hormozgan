<?php

namespace App\Museum\Ai;

final class LlmResult
{
    public function __construct(
        public readonly ?string $text,
        public readonly ?array $data,
        public readonly string $model,
        public readonly ?string $stopReason,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly ?int $aiJobId = null,
    ) {}
}
