<?php

use Kargnas\LaravelAiTranslator\AI\AIProvider;
use Kargnas\LaravelAiTranslator\AI\Clients\AnthropicClient;
use Kargnas\LaravelAiTranslator\AI\Clients\GeminiClient;
use Kargnas\LaravelAiTranslator\AI\Clients\OpenAIClient;
use Kargnas\LaravelAiTranslator\Enums\TranslationStatus;

test('preserves placeholders and emits completed translations without API credentials', function () {
    expect(config('ai-translator.ai.api_key'))->toBeString()->not->toBeEmpty();
    $events = [];
    $provider = new AIProvider('test.php', ['greeting' => 'Hello :name'], 'en', 'ko');
    $provider->setOnTranslated(function ($item, $status) use (&$events) {
        $events[] = ['key' => $item->key, 'status' => $status];
    });

    $result = $provider->translate();

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('Translated Hello :name')
        ->and(array_column($events, 'status'))->toContain(TranslationStatus::COMPLETED);
});

test('can translate strings using OpenAI', function () {
    config()->set('ai-translator.ai.provider', 'openai');
    config()->set('ai-translator.ai.model', 'gpt-4o-mini');
    config()->set('ai-translator.ai.base_url', 'https://offline.example/v1');
    $provider = new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko');

    $result = $provider->translate();

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('Translated Hello, world!')
        ->and($this->translationRequests[0]['client'])->toBe(OpenAIClient::class)
        ->and($this->translationRequests[0]['parameters']['baseUrl'])->toBe('https://offline.example/v1')
        ->and($this->translationRequests[0]['request']['model'])->toBe('gpt-4o-mini')
        ->and($this->translationRequests[0]['request']['stream'])->toBeTrue();
});

test('can translate strings using Anthropic', function () {
    config()->set('ai-translator.ai.provider', 'anthropic');
    config()->set('ai-translator.ai.model', 'claude-3-haiku-20240307');
    $provider = new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko');

    $result = $provider->translate();

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('Translated Hello, world!')
        ->and($this->translationRequests[0]['client'])->toBe(AnthropicClient::class)
        ->and($this->translationRequests[0]['request']['model'])->toBe('claude-3-haiku-20240307');
});

test('can translate strings using Gemini', function () {
    config()->set('ai-translator.ai.provider', 'gemini');
    config()->set('ai-translator.ai.model', 'gemini-2.5-flash');
    $provider = new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko');

    $result = $provider->translate();

    expect($result)->toBeArray()->toHaveCount(1)
        ->and($result[0]->key)->toBe('greeting')
        ->and($result[0]->translated)->toBe('Translated Hello, world!')
        ->and($this->translationRequests[0]['client'])->toBe(GeminiClient::class)
        ->and($this->translationRequests[0]['request']['model'])->toBe('gemini-2.5-flash');
});

test('throws exception for unsupported provider', function () {
    config()->set('ai-translator.ai.provider', 'unsupported');
    $provider = new AIProvider('test.php', ['greeting' => 'Hello, world!'], 'en', 'ko');
    $method = new ReflectionMethod($provider, 'getTranslatedObjects');

    expect(fn () => $method->invoke($provider))
        ->toThrow(Exception::class, 'Provider unsupported is not supported.');
});
