<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Who writes a settings file, derived from `src/`'s TOKEN STREAM (code, never
 * comments) — the census the settings editor's door was promised (N-P2,
 * Appendix N §4.4), beside the `onConfigChange` census in
 * {@see \SugarCraft\Crush\Tests\Config\ConfigWriteProducerDocumentationDriftTest}
 * that stays exactly `provider` + `theme`.
 *
 *  - `config.json` is written only through `Bootstrap::writeUserConfig()`, and
 *    that is called from exactly four places: Chat's config-change closure
 *    (`chat()`), the shell's layout hook and the settings editor's door (both
 *    in `app()`), and the project-MCP trust grant (`trustProjectMcp()`);
 *  - the editor's {@see \SugarCraft\Crush\Config\Settings\SettingsWriter} is
 *    built in one place, `Bootstrap::app()`;
 *  - the project-local file a writer may publish to is asked for only by the
 *    writer, and the writer is the only `Config/Settings` class that touches
 *    `AtomicJsonFile`.
 *
 * A new caller of any of these reds here by EXISTING, which is the point: a
 * fifth door into `config.json` has to be written down, with its docs.
 */
final class SettingsWriterCensusTest extends TestCase
{
    private const EXPECTED_USER_CONFIG_WRITERS = [
        'Cli/Bootstrap.php::app' => 2,
        'Cli/Bootstrap.php::chat' => 1,
        'Cli/Bootstrap.php::trustProjectMcp' => 1,
    ];

    public function testConfigJsonIsWrittenOnlyThroughItsKnownDoors(): void
    {
        self::assertSame(self::EXPECTED_USER_CONFIG_WRITERS, $this->callSites('writeUserConfig', '::'));
    }

    public function testTheSettingsWriterIsBuiltOnlyByTheLaunch(): void
    {
        $sites = $this->callSites('SettingsWriter', '', 'new');

        self::assertSame(['Cli/Bootstrap.php::app' => 1], $sites);
    }

    public function testOnlyTheWriterAsksForTheProjectFileItWrites(): void
    {
        self::assertSame(
            ['Config/Settings/SettingsWriter.php::targetPath' => 1],
            $this->callSites('projectLocalPath', '::'),
        );
    }

    public function testOnlyTheWriterTouchesAtomicJsonFileAmongTheSettingsClasses(): void
    {
        $files = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            if (!str_starts_with($relative, 'Config/') && !str_starts_with($relative, 'Tui/Settings/')) {
                continue;
            }

            foreach ($this->codeTokens($path) as $token) {
                if (\is_array($token) && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && str_ends_with($token[1], 'AtomicJsonFile')) {
                    $files[$relative] = true;
                }
            }
        }

        self::assertSame(['Config/Settings/SettingsWriter.php'], array_keys($files));
    }

    /**
     * Call sites of `$name`, as `file::enclosing-method` => count.
     *
     * `$before` is the token that must precede the name (`::` for a static
     * call, '' for none) and `$after` the name that must follow it after a
     * `::` (for `SettingsWriter::new`), else the name must be followed by `(`.
     *
     * @return array<string, int>
     */
    private function callSites(string $name, string $before, string $after = ''): array
    {
        $sites = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            $tokens = array_values($this->codeTokens($path));
            $function = '';
            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }

                if ($token[0] === T_FUNCTION) {
                    $next = $this->nextSignificant($tokens, $i);
                    if ($next !== null && \is_array($tokens[$next]) && $tokens[$next][0] === T_STRING) {
                        $function = $tokens[$next][1];
                    }

                    continue;
                }

                $isName = ($token[0] === T_STRING && $token[1] === $name)
                    || (\in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true) && str_ends_with($token[1], '\\' . $name));
                if (!$isName) {
                    continue;
                }

                $prev = $this->prevSignificant($tokens, $i);
                $next = $this->nextSignificant($tokens, $i);
                if ($prev !== null && \is_array($tokens[$prev]) && $tokens[$prev][0] === T_FUNCTION) {
                    continue; // the declaration itself
                }

                if ($before === '::' && ($prev === null || !\is_array($tokens[$prev]) || $tokens[$prev][0] !== T_DOUBLE_COLON)) {
                    continue;
                }

                if ($after !== '') {
                    $method = $next === null ? null : $this->nextSignificant($tokens, $next);
                    if ($next === null || !\is_array($tokens[$next]) || $tokens[$next][0] !== T_DOUBLE_COLON
                        || $method === null || !\is_array($tokens[$method]) || $tokens[$method][1] !== $after) {
                        continue;
                    }
                } elseif ($next === null || $tokens[$next] !== '(') {
                    continue;
                }

                $key = $relative . '::' . $function;
                $sites[$key] = ($sites[$key] ?? 0) + 1;
            }
        }

        ksort($sites);

        return $sites;
    }

    /** @return array<string, string> relative path => absolute path, every PHP file under src/ */
    private function sourceFiles(): array
    {
        $root = realpath(__DIR__ . '/../../../src');
        self::assertIsString($root);

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = $file->getPathname();
            }
        }

        ksort($files);
        self::assertNotEmpty($files);

        return $files;
    }

    /** @return list<array{0: int, 1: string, 2: int}|string> */
    private function codeTokens(string $path): array
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "{$path} is unreadable");

        return array_values(array_filter(
            token_get_all($source),
            static fn (array|string $t): bool => !\is_array($t) || !\in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function nextSignificant(array $tokens, int $i): ?int
    {
        return isset($tokens[$i + 1]) ? $i + 1 : null;
    }

    /** @param list<array{0: int, 1: string, 2: int}|string> $tokens */
    private function prevSignificant(array $tokens, int $i): ?int
    {
        return $i > 0 ? $i - 1 : null;
    }
}
