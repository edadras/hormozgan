<?php

namespace App\Museum\Ai;

/** Used when no LLM is configured: AI features report themselves unavailable instead of guessing. */
class NullLlmClient implements LlmClient
{
    public function available(): bool
    {
        return false;
    }

    public function json(string $system, string $user, array $schema, array $options = []): LlmResult
    {
        throw new LlmException('No LLM configured (MUSEUM_LLM_DRIVER=null or ANTHROPIC_API_KEY missing).');
    }

    public function text(string $system, string $user, array $options = []): LlmResult
    {
        throw new LlmException('No LLM configured (MUSEUM_LLM_DRIVER=null or ANTHROPIC_API_KEY missing).');
    }
}
