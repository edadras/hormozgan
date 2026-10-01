<?php

namespace Tests\Support;

use App\Museum\Ai\LlmClient;
use App\Museum\Ai\LlmResult;

/** Scripted LLM for tests: returns queued responses and records prompts. */
class FakeLlmClient implements LlmClient
{
    public array $calls = [];

    public function __construct(public array $responses = []) {}

    public function available(): bool
    {
        return true;
    }

    public function json(string $system, string $user, array $schema, array $options = []): LlmResult
    {
        $this->calls[] = compact('system', 'user', 'schema', 'options');
        $data = array_shift($this->responses) ?? [];

        return new LlmResult(json_encode($data, JSON_UNESCAPED_UNICODE), $data, 'fake-model', 'end_turn');
    }

    public function text(string $system, string $user, array $options = []): LlmResult
    {
        $this->calls[] = compact('system', 'user', 'options');
        $text = (string) (array_shift($this->responses) ?? '');

        return new LlmResult($text, null, 'fake-model', 'end_turn');
    }
}
