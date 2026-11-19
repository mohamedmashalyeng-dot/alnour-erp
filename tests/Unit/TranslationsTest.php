<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Arabic is the primary language: every translatable string in the code must have an Arabic entry.
 */
class TranslationsTest extends TestCase
{
    public function test_every_ui_string_has_an_arabic_translation(): void
    {
        $root = dirname(__DIR__, 2);
        $ar = json_decode(file_get_contents($root.'/lang/ar.json'), true, flags: JSON_THROW_ON_ERROR);

        $missing = [];
        foreach (['app', 'resources/views'] as $dir) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir));
            foreach ($it as $file) {
                if (! str_ends_with((string) $file, '.php')) {
                    continue;
                }
                $source = file_get_contents((string) $file);
                preg_match_all('/__\(\s*\'([^\']+)\'/', $source, $m);
                preg_match_all('/#\[Title\(\'([^\']+)\'/', $source, $t);
                foreach ([...$m[1], ...$t[1]] as $key) {
                    // Skip dynamic prefixes such as __('role.'.$name) and framework file keys, but NOT sentences ending with ".".
                    if (preg_match('/^[a-z_.]+\.$/', $key) || str_starts_with($key, 'validation.')) {
                        continue; // dynamic prefixes / framework files
                    }
                    if (! array_key_exists($key, $ar)) {
                        $missing[$key] = str_replace($root, '', (string) $file);
                    }
                }
                if (str_contains((string) $file, 'Enums') && preg_match('/LABELS = \[(.*?)\];/s', $source, $block)) {
                    preg_match_all('/=> \'([^\']+)\'/', $block[1], $labels);
                    foreach ($labels[1] as $key) {
                        if (! array_key_exists($key, $ar)) {
                            $missing[$key] = str_replace($root, '', (string) $file);
                        }
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'Missing Arabic translations');
    }
}
