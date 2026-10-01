<?php

namespace App\Museum\Ai;

use Anthropic\Beta\AnthropicBeta;
use Anthropic\Client;
use App\Models\Museum\AiJob;
use Throwable;

/**
 * Claude via the official Anthropic PHP SDK.
 *
 * - Structured outputs (`outputConfig.format = json_schema`) for extraction, so responses parse.
 * - The stable system prompt carries a cache breakpoint (prompt caching across chunks).
 * - Effort is set explicitly (config museum.llm.effort).
 * - Server-side refusal fallback (`fallbacks: 'default'`) is enabled; a refusal that survives
 *   the fallback chain is raised as an exception, never turned into invented content.
 * - Every call is recorded in museum_ai_jobs with the verbatim raw output (preservation).
 */
class AnthropicLlmClient implements LlmClient
{
    private ?Client $client = null;

    public function __construct(private array $config) {}

    public function available(): bool
    {
        return ! empty($this->config['api_key']) || ! empty(getenv('ANTHROPIC_AUTH_TOKEN'));
    }

    public function json(string $system, string $user, array $schema, array $options = []): LlmResult
    {
        $format = ['type' => 'json_schema', 'schema' => $schema];

        return $this->call($system, $user, $format, $options);
    }

    public function text(string $system, string $user, array $options = []): LlmResult
    {
        return $this->call($system, $user, null, $options);
    }

    private function call(string $system, string $user, ?array $format, array $options): LlmResult
    {
        if (! $this->available()) {
            throw new LlmException('ANTHROPIC_API_KEY is not configured.');
        }
        $model = $options['model'] ?? $this->config['model'];
        $job = AiJob::create([
            'job_type' => $options['job_type'] ?? ($format ? 'extract' : 'answer'),
            'provider' => 'anthropic',
            'model' => $model,
            'status' => 'running',
            'subject_type' => isset($options['subject']) ? $options['subject']->getMorphClass() : null,
            'subject_id' => isset($options['subject']) ? $options['subject']->getKey() : null,
            'input_hash' => hash('sha256', $model.$system.$user.json_encode($format)),
            'attempts' => 1,
            'started_at' => now(),
        ]);
        $started = microtime(true);

        try {
            $outputConfig = ['effort' => $options['effort'] ?? $this->config['effort'] ?? 'medium'];
            if ($format) {
                $outputConfig['format'] = $format;
            }
            $message = $this->client()->beta->messages->create(
                model: $model,
                maxTokens: $options['max_tokens'] ?? $this->config['max_tokens'] ?? 4096,
                system: [['type' => 'text', 'text' => $system, 'cacheControl' => ['type' => 'ephemeral']]],
                messages: [['role' => 'user', 'content' => $user]],
                outputConfig: $outputConfig,
                fallbacks: 'default',
                betas: [AnthropicBeta::SERVER_SIDE_FALLBACK_2026_07_01],
            );

            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }
            $job->forceFill([
                'raw_output' => $text,
                'input_tokens' => $message->usage->inputTokens ?? null,
                'output_tokens' => $message->usage->outputTokens ?? null,
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
                'model' => $message->model ?? $model,
            ]);

            if ($message->stopReason === 'refusal') {
                throw new LlmException('Model declined the request (refusal).');
            }
            if ($message->stopReason === 'max_tokens') {
                throw new LlmException('Output truncated at max_tokens; increase museum.llm.max_tokens or reduce chunk size.');
            }

            $data = null;
            if ($format) {
                $data = json_decode($text, true);
                if (! is_array($data)) {
                    throw new LlmException('Structured output was not valid JSON.');
                }
            }
            $job->forceFill(['status' => 'succeeded', 'finished_at' => now()])->save();

            return new LlmResult($text, $data, (string) ($message->model ?? $model), $message->stopReason,
                (int) ($message->usage->inputTokens ?? 0), (int) ($message->usage->outputTokens ?? 0), $job->id);
        } catch (Throwable $e) {
            $job->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
            throw $e instanceof LlmException ? $e : new LlmException($e->getMessage(), 0, $e);
        }
    }

    private function client(): Client
    {
        return $this->client ??= new Client(
            apiKey: $this->config['api_key'] ?: null,
            baseUrl: $this->config['base_url'] ?? null,
        );
    }
}
