<?php

namespace App\Modules\Reports\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * OPTIONAL AI layer: turns the deterministic insights (already restricted to the user's branches)
 * into a short Arabic/English business summary using Claude.
 *
 * Safety by design:
 *  - only aggregated figures are sent (product names/SKUs, no customer names, phones or emails);
 *  - the model only writes text: it has no tools and the application never acts on its output;
 *  - disabled unless ERP_AI_ENABLED=true and ANTHROPIC_API_KEY is set; failures degrade to a message.
 */
class AiNarrator
{
    public function enabled(): bool
    {
        return (bool) config('erp.ai.enabled') && (string) config('erp.ai.api_key') !== '';
    }

    /** @return array{ok:bool, text:string} */
    public function summarize(array $insights, array $headline, string $scopeLabel): array
    {
        if (! $this->enabled()) {
            return ['ok' => false, 'text' => __('AI analysis is not configured. Set ERP_AI_ENABLED and ANTHROPIC_API_KEY in the server environment.')];
        }

        $payload = json_encode([
            'scope' => $scopeLabel,
            'currency' => config('erp.currency.code'),
            'money_unit' => 'minor units (divide by 10^'.config('erp.currency.decimals').')',
            'headline' => $headline,
            'insights' => $this->strip($insights),
        ], JSON_UNESCAPED_UNICODE);

        $cacheKey = 'ai-summary:'.app()->getLocale().':'.sha1($payload);

        return Cache::remember($cacheKey, now()->addHour(), fn () => $this->call($payload));
    }

    private function call(string $payload): array
    {
        $language = app()->getLocale() === 'ar' ? 'Arabic' : 'English';
        $system = <<<TXT
        You are a retail business analyst for an eyewear (optical) store chain. You receive pre-computed, aggregated
        analytics as JSON. Write a concise management summary in {$language} with these sections: overall performance,
        declining sales, unusual inventory movements, slow-moving stock, reorder priorities, demand outlook, and
        products worth promoting. Base every statement only on the data provided; if a section has no data, say so in
        one line. Present suggestions as recommendations for a human to review. Never state that any change has been
        or will be made automatically. Convert money from minor units before showing it.
        TXT;

        try {
            $client = new Client(apiKey: (string) config('erp.ai.api_key'));
            $message = $client->beta->messages->create(
                model: (string) config('erp.ai.model', 'claude-opus-5-5'),
                maxTokens: 8000,
                system: $system,
                messages: [['role' => 'user', 'content' => $payload]],
                outputConfig: ['effort' => 'medium'],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
                requestOptions: ['timeout' => 90.0, 'maxRetries' => 2],
            );

            if ($message->stopReason === 'refusal') {
                return ['ok' => false, 'text' => __('The AI service declined to analyze this data.')];
            }
            $text = '';
            foreach ($message->content as $block) {
                if ($block->type === 'text') {
                    $text .= $block->text;
                }
            }

            return ['ok' => $text !== '', 'text' => $text !== '' ? $text : __('The AI service returned no text.')];
        } catch (AuthenticationException $e) {
            Log::warning('AI narrator authentication failed', ['type' => $e->type?->value]);

            return ['ok' => false, 'text' => __('The AI service rejected the API key.')];
        } catch (RateLimitException $e) {
            return ['ok' => false, 'text' => __('The AI service is busy. Please try again in a minute.')];
        } catch (BadRequestException $e) {
            Log::warning('AI narrator bad request', ['message' => $e->getMessage()]);

            return ['ok' => false, 'text' => __('The AI request was rejected.')];
        } catch (APIStatusException $e) {
            Log::warning('AI narrator API error', ['type' => $e->type?->value]);

            return ['ok' => false, 'text' => __('The AI service is temporarily unavailable.')];
        } catch (APIConnectionException $e) {
            return ['ok' => false, 'text' => __('Could not reach the AI service.')];
        }
    }

    /** Keep the payload small and free of identifiers the model does not need. */
    private function strip(array $insights): array
    {
        $drop = ['product_id', 'supplier_id', 'branch_id'];
        array_walk_recursive($insights, function (&$v, $k) use ($drop) {
            if (in_array($k, $drop, true)) {
                $v = null;
            }
        });

        return $insights;
    }
}
