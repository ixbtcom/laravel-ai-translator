<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Export @locked markers from translation files to config.
 *
 * This command scans translation files for @locked markers and exports them
 * to the locked_keys config option, ensuring they won't be overwritten
 * during translation or clean operations.
 */
class ExportLockedCommand extends Command
{
    protected $signature = 'ai-translator:export-locked
                          {--dry-run : Show what would be exported without modifying config}
                          {--format=php : Output format: php (config file) or json}
                          {--lock-vendor : Lock all vendor package translations (prevent overwriting)}';

    protected $description = 'Export @locked markers from translation files to config';

    protected array $lockedKeys = [];

    protected array $existingLockedKeys = [];

    public function handle(): int
    {
        $sourceDir = base_path(config('ai-translator.source_directory', 'lang'));
        $dryRun = $this->option('dry-run');
        $format = $this->option('format');
        $lockVendor = $this->option('lock-vendor');

        // Load existing locked keys from config
        $this->existingLockedKeys = config('ai-translator.locked_keys', []);

        $this->info('Scanning translation files for @locked markers...');
        $this->newLine();

        if (! is_dir($sourceDir)) {
            $this->error("Source directory not found: {$sourceDir}");

            return 1;
        }

        // Scan all locale directories
        $localeDirs = array_filter(glob("{$sourceDir}/*"), 'is_dir');

        foreach ($localeDirs as $localeDir) {
            $locale = basename($localeDir);

            // Skip backup directories
            if (in_array($locale, ['backup', '.backup', '_backup'])) {
                continue;
            }

            // Handle vendor directory specially
            if ($locale === 'vendor') {
                if ($lockVendor) {
                    $this->scanVendorDirectory($localeDir);
                }
                continue;
            }

            $this->scanLocaleDirectory($localeDir, $locale);
        }

        if (empty($this->lockedKeys)) {
            if ($lockVendor) {
                $this->warn('No vendor translations found to lock.');
            } else {
                $this->warn('No @locked markers found in files.');
            }

            if (! empty($this->existingLockedKeys)) {
                $this->info('Existing locked keys in config: '.count($this->existingLockedKeys));
            }

            return 0;
        }

        // Count vendor keys for reporting
        $vendorKeyCount = 0;
        $markerKeyCount = 0;
        foreach ($this->lockedKeys as $key => $locales) {
            if (str_starts_with($key, 'vendor/')) {
                $vendorKeyCount++;
            } else {
                $markerKeyCount++;
            }
        }

        // Merge with existing locked keys (existing keys take precedence, we only add new ones)
        $newKeys = [];
        $mergedKeys = $this->existingLockedKeys;

        foreach ($this->lockedKeys as $key => $locales) {
            if (! isset($mergedKeys[$key])) {
                // Completely new key
                $mergedKeys[$key] = $locales;
                $newKeys[$key] = $locales;
            } else {
                // Key exists, merge locales
                $existingLocales = is_array($mergedKeys[$key]) ? $mergedKeys[$key] : [$mergedKeys[$key]];
                $newLocales = is_array($locales) ? $locales : [$locales];

                foreach ($newLocales as $locale) {
                    if (! in_array($locale, $existingLocales)) {
                        $existingLocales[] = $locale;
                        if (! isset($newKeys[$key])) {
                            $newKeys[$key] = [];
                        }
                        $newKeys[$key][] = $locale;
                    }
                }

                $mergedKeys[$key] = count($existingLocales) === 1 ? $existingLocales[0] : $existingLocales;
            }
        }

        // Display results
        if (! empty($this->existingLockedKeys)) {
            $this->info('Existing locked keys in config: '.count($this->existingLockedKeys));
        }

        if ($markerKeyCount > 0) {
            $this->info("Found @locked markers in files: {$markerKeyCount}");
        }
        if ($vendorKeyCount > 0) {
            $this->info("Found vendor keys to lock: {$vendorKeyCount}");
        }

        if (empty($newKeys)) {
            $this->newLine();
            $this->info('All markers already exist in config. Nothing to add.');

            return 0;
        }

        $this->newLine();
        $this->info('New keys to add:');
        $this->newLine();

        foreach ($newKeys as $key => $locales) {
            $localeStr = is_array($locales) ? implode(', ', $locales) : $locales;
            $this->line("  <comment>{$key}</comment> => <info>{$localeStr}</info>");
        }

        $this->newLine();
        $this->info('New keys: '.count($newKeys).', Total after merge: '.count($mergedKeys));

        // Use merged keys for export
        $this->lockedKeys = $mergedKeys;

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run mode - no changes made.');

            return 0;
        }

        // Export to config file
        if ($format === 'json') {
            $this->exportToJson();
        } else {
            $this->exportToConfig();
        }

        return 0;
    }

    protected function scanLocaleDirectory(string $localeDir, string $locale): void
    {
        $files = glob("{$localeDir}/*.php");

        foreach ($files as $file) {
            $this->scanPhpFile($file, $locale);
        }
    }

    /**
     * Scan vendor directory for package translations
     * Structure: lang/vendor/{package}/{locale}/*.php
     */
    protected function scanVendorDirectory(string $vendorDir): void
    {
        $this->info('Scanning vendor translations...');

        // Get all package directories
        $packageDirs = array_filter(glob("{$vendorDir}/*"), 'is_dir');

        foreach ($packageDirs as $packageDir) {
            $packageName = basename($packageDir);

            // Get all locale directories within package
            $localeDirs = array_filter(glob("{$packageDir}/*"), 'is_dir');

            foreach ($localeDirs as $localeDir) {
                $locale = basename($localeDir);

                // Get all PHP files in locale directory
                $files = glob("{$localeDir}/*.php");

                foreach ($files as $file) {
                    $this->scanVendorPhpFile($file, $locale, $packageName);
                }
            }
        }
    }

    /**
     * Scan vendor PHP file and lock all keys
     */
    protected function scanVendorPhpFile(string $file, string $locale, string $packageName): void
    {
        $filename = pathinfo($file, PATHINFO_FILENAME);

        // Load the translations
        if (! file_exists($file)) {
            return;
        }

        $content = require $file;
        if (! is_array($content)) {
            return;
        }

        // Flatten and add all keys as locked
        $flat = $this->flattenArray($content);

        foreach (array_keys($flat) as $key) {
            $fullKey = "vendor/{$packageName}/{$filename}.{$key}";
            $this->addLockedKey($fullKey, $locale);
        }
    }

    /**
     * Flatten nested array to dot notation
     */
    protected function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $newKey = $prefix ? "{$prefix}.{$key}" : $key;

            if (is_array($value)) {
                $result += $this->flattenArray($value, $newKey);
            } else {
                $result[$newKey] = $value;
            }
        }

        return $result;
    }

    protected function scanPhpFile(string $file, string $locale): void
    {
        $content = file_get_contents($file);
        $filename = pathinfo($file, PATHINFO_FILENAME);

        // Match patterns like: 'key' => 'value', // @locked
        // or: 'key' => 'value', /* @locked */
        $pattern = '/[\'"]([^\'"]+)[\'"]\s*=>\s*[\'"](?:[^\'"]|\\\\[\'"])*[\'"]\s*,?\s*(?:\/\/|\/\*)\s*@locked/i';

        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $key) {
                $fullKey = "{$filename}.{$key}";
                $this->addLockedKey($fullKey, $locale);
            }
        }

        // Also check for nested keys with dot notation in comments
        $patternDot = '/\/\/\s*@locked\s+([a-zA-Z0-9_.]+)/i';
        if (preg_match_all($patternDot, $content, $matches)) {
            foreach ($matches[1] as $key) {
                $fullKey = str_contains($key, '.') ? $key : "{$filename}.{$key}";
                $this->addLockedKey($fullKey, $locale);
            }
        }
    }

    protected function addLockedKey(string $key, string $locale): void
    {
        if (! isset($this->lockedKeys[$key])) {
            $this->lockedKeys[$key] = $locale;
        } elseif (is_string($this->lockedKeys[$key])) {
            if ($this->lockedKeys[$key] !== $locale) {
                $this->lockedKeys[$key] = [$this->lockedKeys[$key], $locale];
            }
        } elseif (is_array($this->lockedKeys[$key]) && ! in_array($locale, $this->lockedKeys[$key])) {
            $this->lockedKeys[$key][] = $locale;
        }
    }

    protected function exportToJson(): void
    {
        $outputFile = base_path('locked-translations.json');
        $json = json_encode($this->lockedKeys, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        File::put($outputFile, $json);

        $this->newLine();
        $this->info("Exported to: {$outputFile}");
        $this->line('Add this to your ai-translator.php config manually.');
    }

    protected function exportToConfig(): void
    {
        $configFile = config_path('ai-translator.php');

        if (! file_exists($configFile)) {
            $this->error('Config file not found. Run: php artisan vendor:publish --provider="Kargnas\LaravelAiTranslator\ServiceProvider"');

            return;
        }

        $content = file_get_contents($configFile);

        // Generate locked_keys array
        $lockedKeysPhp = $this->generatePhpArray($this->lockedKeys);

        // Check if locked_keys already exists
        if (preg_match("/['\"]locked_keys['\"]\s*=>/", $content)) {
            // Replace existing locked_keys
            $pattern = "/(['\"]locked_keys['\"]\s*=>\s*)\[[^\]]*\]/s";
            $replacement = "'locked_keys' => {$lockedKeysPhp}";
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            // Add locked_keys after skip_files
            $pattern = "/(\/\/\s*'skip_files'\s*=>\s*\[\],?)/";
            $replacement = "$1\n\n    'locked_keys' => {$lockedKeysPhp},";
            $content = preg_replace($pattern, $replacement, $content);

            // If skip_files not found, try adding after skip_locales
            if (! str_contains($content, "'locked_keys'")) {
                $pattern = "/(\/\/\s*'skip_locales'\s*=>\s*\[\],?)/";
                $replacement = "$1\n    // 'skip_files' => [],\n\n    'locked_keys' => {$lockedKeysPhp},";
                $content = preg_replace($pattern, $replacement, $content);
            }
        }

        File::put($configFile, $content);

        $this->newLine();
        $this->info("Updated config: {$configFile}");
    }

    protected function generatePhpArray(array $array): string
    {
        $items = [];

        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $valueStr = "['" . implode("', '", $value) . "']";
            } else {
                $valueStr = "'{$value}'";
            }
            $items[] = "        '{$key}' => {$valueStr}";
        }

        if (empty($items)) {
            return '[]';
        }

        return "[\n" . implode(",\n", $items) . ",\n    ]";
    }
}
