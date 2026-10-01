<?php

namespace App\Museum\Ai;

use Illuminate\Database\Eloquent\Model;

interface LlmClient
{
    public function available(): bool;

    /**
     * Returns JSON constrained to $schema (structured outputs).
     *
     * @param  array{model?: string, max_tokens?: int, effort?: string, subject?: Model|null, job_type?: string}  $options
     */
    public function json(string $system, string $user, array $schema, array $options = []): LlmResult;

    /** Free-text completion. */
    public function text(string $system, string $user, array $options = []): LlmResult;
}
