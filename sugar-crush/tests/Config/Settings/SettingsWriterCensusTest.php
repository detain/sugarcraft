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
 *    (`chat()`), the shell's layout hook (`app()`), the launch's settings
 *    write door (`settingsWriter()`), and the project-MCP trust grant
 *    (`trustProjectMcp()`);
 *  - that {@see \SugarCraft\Crush\Config\Settings\SettingsWriter} is built in
 *    one place, `Bootstrap::settingsWriter()`, which `workspace()` registers
 *    for `/model` and `app()` hands on to the settings editor;
 *  - the project-local file a writer may publish to is asked for only by the
 *    writer, and the writer is the only `Config/Settings` class that touches
 *    `AtomicJsonFile`;
 *  - who SAVES through the writer (`write()`, `grantTrust()`, `revokeTrust()`):
 *    the settings view's save and its trust action (`App`), `/model`'s model
 *    choice (`ModelChoice::persist()`), and the server's `settings.set`
 *    (`SettingsMethods::set()`, the web UI's settings form — roadmap O-6b).
 *    `docs/SETTINGS.md` "Saving from the settings view" names the same three.
 *
 * A new caller of any of these reds here by EXISTING, which is the point: a
 * fifth door into `config.json` has to be written down, with its docs.
 */
final class SettingsWriterCensusTest extends TestCase
{
    private const EXPECTED_USER_CONFIG_WRITERS = [
        'Cli/Bootstrap.php::app' => 1,
        'Cli/Bootstrap.php::chat' => 1,
        'Cli/Bootstrap.php::settingsWriter' => 1,
        'Cli/Bootstrap.php::trustProjectMcp' => 1,
    ];

    public function testConfigJsonIsWrittenOnlyThroughItsKnownDoors(): void
    {
        self::assertSame(self::EXPECTED_USER_CONFIG_WRITERS, $this->callSites('writeUserConfig', '::'));
    }

    public function testTheSettingsWriterIsBuiltOnlyByTheLaunch(): void
    {
        $sites = $this->callSites('SettingsWriter', '', 'new');

        self::assertSame(['Cli/Bootstrap.php::settingsWriter' => 1], $sites);
    }

    public function testOnlyTheWriterAsksForTheProjectFileItWrites(): void
    {
        self::assertSame(
            ['Config/Settings/SettingsWriter.php::targetPath' => 1],
            $this->callSites('projectLocalPath', '::'),
        );
    }

    /**
     * The writer's producers. A token walk cannot type a receiver, so this is
     * scoped to the files that NAME `SettingsWriter` (a producer has to get a
     * writer from somewhere typed), and counts the writer's three saving
     * methods called through `->` there; the writer's own file is left out (its
     * `write()` calls `AtomicJsonFile`'s).
     */
    public function testTheWriterIsSavedThroughOnlyByItsKnownProducers(): void
    {
        $sites = [];
        foreach ($this->sourceFiles() as $relative => $path) {
            if ($relative === 'Config/Settings/SettingsWriter.php') {
                continue;
            }

            $tokens = $this->codeTokens($path);
            $namesWriter = false;
            foreach ($tokens as $token) {
                if (\is_array($token) && \in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                    && ($token[1] === 'SettingsWriter' || str_ends_with($token[1], '\\SettingsWriter'))) {
                    $namesWriter = true;
                    break;
                }
            }
            if (!$namesWriter) {
                continue;
            }

            $function = '';
            foreach ($tokens as $i => $token) {
                if (!\is_array($token)) {
                    continue;
                }
                if ($token[0] === T_FUNCTION && isset($tokens[$i + 1]) && \is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) {
                    $function = $tokens[$i + 1][1];
                    continue;
                }
                if ($token[0] === T_STRING && \in_array($token[1], ['write', 'grantTrust', 'revokeTrust'], true)
                    && isset($tokens[$i - 1], $tokens[$i + 1]) && \is_array($tokens[$i - 1])
                    && \in_array($tokens[$i - 1][0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    && $tokens[$i + 1] === '(') {
                    $key = $relative . '::' . $function . '->' . $token[1];
                    $sites[$key] = ($sites[$key] ?? 0) + 1;
                }
            }
        }

        ksort($sites);

        self::assertSame([
            'App/App.php::confirmSettingsSave->write' => 1,
            'App/App.php::grantProjectTrust->grantTrust' => 1,
            'Config/Settings/ModelChoice.php::persist->write' => 1,
            'Protocol/Methods/SettingsMethods.php::set->write' => 1,
            // N-P5: a settings PROFILE, not a settings file — its write is
            // AtomicJsonFile's, it names SettingsWriter only to ask which keys
            // a tier takes, and it refuses any settings file's name.
            'Tui/Settings/SettingsProfile.php::write->write' => 1,
        ], $sites);
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

        // SettingsProfile writes a profile file the user names (N-P5), never a
        // settings layer: it refuses config.json / settings.json /
        // settings.local.json by name (SettingsFilesAndProfilesTest).
        self::assertSame(['Config/Settings/SettingsWriter.php', 'Tui/Settings/SettingsProfile.php'], array_keys($files));
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
