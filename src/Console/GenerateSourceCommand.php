<?php

namespace Kargnas\LaravelAiTranslator\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generate source language files from translation keys.
 *
 * Some packages (like Mailcoach) use the English text as keys,
 * so there's no 'en' directory. This command generates it by
 * creating files where key = value.
 */
class GenerateSourceCommand extends Command
{
    protected $signature = 'ai-translator:generate-source
                          {--vendor=* : Specific vendor packages to process (e.g., --vendor=mailcoach)}
                          {--source=en : Source locale to generate}
                          {--dry-run : Show what would be generated without writing files}
                          {--force : Overwrite existing source files}';

    protected $description = 'Generate source language files from translation keys (for packages where keys are English text)';

    public function handle(): int
    {
        $sourceLocale = $this->option('source');
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');
        $specificPackages = $this->option('vendor');

        $vendorDir = base_path(config('ai-translator.source_directory', 'lang')).'/vendor';

        if (! is_dir($vendorDir)) {
            $this->error("Vendor directory not found: {$vendorDir}");
            return 1;
        }

        $this->info('Scanning vendor packages for missing source locale...');
        $this->newLine();

        $packageDirs = array_filter(glob("{$vendorDir}/*"), 'is_dir');
        $generatedCount = 0;

        foreach ($packageDirs as $packageDir) {
            $packageName = basename($packageDir);

            // Filter by specific packages if provided
            if (! empty($specificPackages) && ! in_array($packageName, $specificPackages)) {
                continue;
            }

            $sourceLocaleDir = "{$packageDir}/{$sourceLocale}";

            // Check if source locale already exists
            if (is_dir($sourceLocaleDir) && ! $force) {
                $this->line("<comment>{$packageName}</comment>: Source locale '{$sourceLocale}' already exists. Use --force to overwrite.");
                continue;
            }

            // Find any existing locale to use as reference
            $existingLocaleDirs = array_filter(glob("{$packageDir}/*"), 'is_dir');

            if (empty($existingLocaleDirs)) {
                $this->warn("{$packageName}: No locale directories found. Skipping.");
                continue;
            }

            // Use first available locale as reference
            $referenceLocaleDir = $existingLocaleDirs[0];
            $referenceLocale = basename($referenceLocaleDir);

            $this->info("<comment>{$packageName}</comment>: Generating '{$sourceLocale}' from '{$referenceLocale}' keys...");

            // Get all PHP files from reference locale
            $phpFiles = glob("{$referenceLocaleDir}/*.php");

            if (empty($phpFiles)) {
                $this->warn("  No PHP files found in {$referenceLocale}. Skipping.");
                continue;
            }

            foreach ($phpFiles as $phpFile) {
                $fileName = basename($phpFile);
                $targetFile = "{$sourceLocaleDir}/{$fileName}";

                $result = $this->generateSourceFile($phpFile, $targetFile, $dryRun);

                if ($result['success']) {
                    $generatedCount++;
                    $this->line("  <info>✓</info> {$fileName}: {$result['count']} keys");
                } else {
                    $this->error("  ✗ {$fileName}: {$result['error']}");
                }
            }
        }

        $this->newLine();

        if ($dryRun) {
            $this->warn('Dry run mode - no files were written.');
        } else {
            $this->info("Generated {$generatedCount} source file(s).");
        }

        return 0;
    }

    /**
     * Generate source file where key = value
     */
    protected function generateSourceFile(string $referenceFile, string $targetFile, bool $dryRun): array
    {
        try {
            // Load reference file to get keys
            $translations = require $referenceFile;

            if (! is_array($translations)) {
                return ['success' => false, 'error' => 'Invalid translation file format'];
            }

            // Generate source translations (key = key)
            $sourceTranslations = $this->generateKeyEqualsValue($translations);

            if ($dryRun) {
                return ['success' => true, 'count' => count($sourceTranslations)];
            }

            // Create directory if needed
            $targetDir = dirname($targetFile);
            if (! is_dir($targetDir)) {
                mkdir($targetDir, 0755, true);
            }

            // Write the file
            $content = $this->generatePhpFileContent($sourceTranslations);
            File::put($targetFile, $content);

            return ['success' => true, 'count' => count($sourceTranslations)];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Generate array where key = value (recursively for nested arrays)
     */
    protected function generateKeyEqualsValue(array $translations): array
    {
        $result = [];

        foreach ($translations as $key => $value) {
            if (is_array($value)) {
                // Recursively process nested arrays
                $result[$key] = $this->generateKeyEqualsValue($value);
            } else {
                // Key becomes the value (key is already English text)
                $result[$key] = $key;
            }
        }

        return $result;
    }

    /**
     * Generate PHP file content
     */
    protected function generatePhpFileContent(array $translations): string
    {
        $exported = $this->varExport($translations, 0);

        return "<?php\n\n/**\n * Auto-generated source file.\n * Keys are used as values (English source text).\n * Generated by: php artisan ai-translator:generate-source\n */\n\nreturn {$exported};\n";
    }

    /**
     * Custom var_export with proper formatting
     */
    protected function varExport(array $array, int $indent): string
    {
        $indentStr = str_repeat('    ', $indent);
        $innerIndent = str_repeat('    ', $indent + 1);

        $items = [];
        foreach ($array as $key => $value) {
            $exportedKey = var_export($key, true);

            if (is_array($value)) {
                $exportedValue = $this->varExport($value, $indent + 1);
            } else {
                $exportedValue = var_export($value, true);
            }

            $items[] = "{$innerIndent}{$exportedKey} => {$exportedValue}";
        }

        if (empty($items)) {
            return '[]';
        }

        return "[\n".implode(",\n", $items).",\n{$indentStr}]";
    }
}
