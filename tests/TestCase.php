<?php

namespace Kargnas\LaravelAiTranslator\Tests;

use Illuminate\Support\Facades\Http;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicMessages;
use Kargnas\LaravelAiTranslator\AI\Clients\GeminiClient;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\ServiceProvider;
use Mockery;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    public array $translationRequests = [];

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config()->set('ai-translator.ai.api_key', 'offline-test-key');
        config()->set('ai-translator.ai.provider', 'anthropic');
        config()->set('ai-translator.ai.model', 'offline-test-model');
        config()->set('ai-translator.ai.retries', 1);
        config()->set('ai-translator.ai.disable_stream', false);
        $this->fakeTranslationClients();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void {}

    protected function fakeTranslationClients(): void
    {
        $xmlFor = static function (string $prompt): string {
            preg_match_all('/^  - `([^`]+)`: """(.*?)"""$/m', $prompt, $matches, PREG_SET_ORDER);
            expect($matches)->not->toBeEmpty();

            $xml = '<translations>';
            foreach ($matches as $match) {
                $xml .= '<item><key>'.htmlspecialchars($match[1], ENT_XML1).'</key><trx><![CDATA[Translated '.$match[2].']]></trx></item>';
            }

            return $xml.'</translations>';
        };

        $this->app->bind(OpenAIClient::class, function ($app, array $parameters) use ($xmlFor) {
            expect($parameters)->toBe([
                'apiKey' => config('ai-translator.ai.api_key'),
                'baseUrl' => config('ai-translator.ai.base_url'),
            ]);
            $client = Mockery::mock(OpenAIClient::class);
            $client->shouldReceive('createChatStream')->andReturnUsing(function (array $request, ?callable $callback) use ($parameters, $xmlFor): array {
                $this->translationRequests[] = ['client' => OpenAIClient::class, 'parameters' => $parameters, 'request' => $request];
                $xml = $xmlFor($request['messages'][1]['content']);
                foreach (str_split($xml, 17) as $part) {
                    if ($callback !== null) {
                        $callback('', ['choices' => [['delta' => ['content' => $part]]]]);
                    }
                }

                return ['choices' => [['message' => ['content' => $xml]]]];
            });

            return $client;
        });

        $this->app->bind(AnthropicClient::class, function ($app, array $parameters) use ($xmlFor) {
            expect($parameters)->toBe(['apiKey' => config('ai-translator.ai.api_key')]);
            $client = Mockery::mock(AnthropicClient::class);
            $client->shouldReceive('messages')->andReturn(new AnthropicMessages($client));
            $client->shouldReceive('createMessageStream')->andReturnUsing(function (array $request, callable $callback) use ($parameters, $xmlFor): array {
                $this->translationRequests[] = ['client' => AnthropicClient::class, 'parameters' => $parameters, 'request' => $request];
                $xml = $xmlFor($request['messages'][0]['content']);
                foreach (str_split($xml, 17) as $part) {
                    $callback('', ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => $part]]);
                }

                return ['content' => [['text' => $xml]], 'usage' => ['input_tokens' => 2, 'output_tokens' => 3]];
            });

            return $client;
        });

        $this->app->bind(GeminiClient::class, function ($app, array $parameters) use ($xmlFor) {
            expect($parameters)->toBe(['apiKey' => config('ai-translator.ai.api_key')]);
            $client = Mockery::mock(GeminiClient::class);
            $client->shouldReceive('request')->andReturnUsing(function (string $model, array $contents) use ($parameters, $xmlFor): array {
                $this->translationRequests[] = ['client' => GeminiClient::class, 'parameters' => $parameters, 'request' => ['model' => $model, 'contents' => $contents]];
                $xml = $xmlFor($contents[0]['parts'][0]['text']);

                return ['candidates' => [['content' => ['parts' => [['text' => $xml]]]]]];
            });

            return $client;
        });
    }
}
