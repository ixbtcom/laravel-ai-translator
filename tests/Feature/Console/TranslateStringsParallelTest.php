<?php

namespace Tests\Feature\Console;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Kargnas\LaravelAiTranslator\Console\TranslateStringsParallel;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function () {
    config()->set('ai-translator.source_locale', 'en');
    config()->set('ai-translator.skip_locales', []);
    $this->parallelOriginalBasePath = app()->basePath();
    $this->parallelFixturePath = sys_get_temp_dir().'/translator-parallel-'.bin2hex(random_bytes(8));
    File::makeDirectory($this->parallelFixturePath, 0700, true);
    app()->setBasePath($this->parallelFixturePath);
    config()->set('ai-translator.source_directory', $this->parallelFixturePath.'/lang');
    foreach (['en', 'ko', 'ja', 'zh', 'es', 'fr', 'de'] as $locale) {
        File::makeDirectory($this->parallelFixturePath.'/lang/'.$locale, 0700, true);
    }
    file_put_contents($this->parallelFixturePath.'/process-state.json', json_encode([
        'active' => 0, 'maximum' => 0, 'started' => [], 'completed' => [], 'errors' => [],
    ], JSON_THROW_ON_ERROR));
    file_put_contents($this->parallelFixturePath.'/artisan', <<<'PHP'
<?php
$statePath = __DIR__.'/process-state.json';
$locale = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--locale=')) {
        $locale = substr($argument, strlen('--locale='));
    }
}
$update = static function (callable $callback) use ($statePath): array {
    $handle = fopen($statePath, 'r+');
    flock($handle, LOCK_EX);
    $state = json_decode(stream_get_contents($handle), true, flags: JSON_THROW_ON_ERROR);
    $callback($state);
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
    return $state;
};
$state = $update(static function (array &$state) use ($locale, $argv): void {
    $state['active']++;
    $state['maximum'] = max($state['maximum'], $state['active']);
    $state['started'][$locale] = ['pid' => getmypid(), 'arguments' => $argv];
});
$expectedConcurrency = $state['expected_concurrency'];
$deadline = microtime(true) + 5;
do {
    $state = $update(static function (array &$state): void {});
    if (count($state['started']) >= $expectedConcurrency) {
        break;
    }
    usleep(1000);
} while (microtime(true) < $deadline);
if (count($state['started']) < $expectedConcurrency) {
    $update(static function (array &$state) use ($locale): void {
        $state['errors'][] = 'Timed out waiting for concurrent workers: '.$locale;
    });
}
// Keep the first batch alive while the scheduler has a chance to exceed its cap.
usleep(100000);
$update(static function (array &$state) use ($locale): void {
    $state['active']--;
    $state['completed'][] = $locale;
});
echo 'Completed '.$locale.PHP_EOL;
PHP);
});

afterEach(function () {
    app()->setBasePath($this->parallelOriginalBasePath);
    File::deleteDirectory($this->parallelFixturePath);
});

test('command exists', function () {
    expect(class_exists(TranslateStringsParallel::class))->toBeTrue();
});

test('buildLocaleCommand constructs correct command array', function () {
    $command = new ParallelTranslationCommand;
    $command->prepare([
        '--force-big-files' => true, '--show-prompt' => true, '--reference' => 'ja,fr',
    ]);
    $arguments = (new ReflectionMethod(TranslateStringsParallel::class, 'buildLocaleCommand'))
        ->invoke($command, 'ko', 1000);
    expect($arguments)->toBe([
        'php', 'artisan', 'ai-translator:translate', '--source=en', '--locale=ko',
        '--chunk=100', '--max-context=1000', '--non-interactive',
        '--reference=ja,fr', '--force-big-files', '--show-prompt',
    ]);
});

test('skips source locale and skip locales', function () {
    config()->set('ai-translator.skip_locales', ['es', 'zh', 'fr', 'de']);
    $statePath = $this->parallelFixturePath.'/process-state.json';
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    $state['expected_concurrency'] = 2;
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    $command = new ParallelTranslationCommand;
    $command->prepare();
    $command->runTranslation();
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    expect($state['errors'])->toBe([]);
    expect(array_keys($state['started']))->toEqualCanonicalizing(['ko', 'ja']);
    expect($state['completed'])->toEqualCanonicalizing(['ko', 'ja']);
    expect($state['active'])->toBe(0);
});

test('command accepts max-processes option', function () {
    $command = new TranslateStringsParallel;
    expect($command->getDefinition()->hasOption('max-processes'))->toBeTrue();
});

test('actually runs multiple processes in parallel within the configured limit', function (?string $limit, int $expectedConcurrency) {
    $statePath = $this->parallelFixturePath.'/process-state.json';
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    $state['expected_concurrency'] = $expectedConcurrency;
    file_put_contents($statePath, json_encode($state, JSON_THROW_ON_ERROR));
    $command = new ParallelTranslationCommand;
    $command->prepare($limit === null ? [] : ['--max-processes' => $limit]);
    $command->runTranslation(1000);
    $state = json_decode(file_get_contents($statePath), true, flags: JSON_THROW_ON_ERROR);
    expect($state['errors'])->toBe([]);
    expect(array_keys($state['started']))->toEqualCanonicalizing(['ko', 'ja', 'zh', 'es', 'fr', 'de']);
    expect($state['completed'])->toEqualCanonicalizing(['ko', 'ja', 'zh', 'es', 'fr', 'de']);
    expect($state['active'])->toBe(0);
    expect($state['maximum'])->toBe($expectedConcurrency);
    expect(array_unique(array_column($state['started'], 'pid')))->toHaveCount(6);
    foreach ($state['started'] as $worker) {
        expect($worker['pid'])->not->toBe(getmypid());
        expect($worker['arguments'])->toContain('--max-context=1000', '--non-interactive');
    }
})->with([
    'default limit' => [null, 5],
    'zero uses default limit' => ['0', 5],
    'explicit limit' => ['3', 3],
]);

final class ParallelTranslationCommand extends TranslateStringsParallel
{
    public function runTranslation(int $maxContextItems = 100): void
    {
        $previousAlarm = pcntl_alarm(0);
        $previousHandler = pcntl_signal_get_handler(SIGALRM);
        $previousAsyncSignals = pcntl_async_signals(true);
        $startedAt = microtime(true);

        try {
            pcntl_signal(SIGALRM, static function (): void {
                throw new RuntimeException('Parallel scheduler exceeded the 30-second test deadline.');
            });
            pcntl_alarm(30);
            parent::translate($maxContextItems);
        } finally {
            pcntl_alarm(0);
            pcntl_signal(SIGALRM, $previousHandler);
            pcntl_async_signals($previousAsyncSignals);
            if ($previousAlarm > 0) {
                pcntl_alarm(max(1, $previousAlarm - (int) (microtime(true) - $startedAt)));
            }
        }
    }

    public function prepare(array $options = []): void
    {
        $this->setLaravel(app());
        $this->input = new ArrayInput($options, $this->getDefinition());
        $this->output = new OutputStyle($this->input, new BufferedOutput);
        $this->sourceLocale = 'en';
        $this->sourceDirectory = config('ai-translator.source_directory');
        $this->chunkSize = 100;
        $this->referenceLocales = empty($options['--reference'])
            ? []
            : explode(',', $options['--reference']);
    }
}
