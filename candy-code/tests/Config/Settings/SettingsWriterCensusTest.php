<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Who writes a settings file, derived from `src/`'s TOKEN STREAM (code, never
 * comments) — the census the settings editor's door was promised (N-P2,
 * Appendix N §4.4), beside the `onConfigChange` census in
 * {@see \SugarCraft\Code\Tests\Config\ConfigWriteProducerDocumentationDriftTest}
 * that stays exactly `provider` + `theme`.
 *
 *  - `config.json` is written only through `Bootstrap::writeUserConfig()`, and
 *    that is called from exactly four places: Chat's config-change closure
 *    (`buildChat()` — the body `chat()` carried before P-TABS.d), the shell's
 *    layout hook (`app()`), the launch's settings
 *    write door (`settingsWriter()`), and the project-MCP trust grant
 *    (`McpDecision::trustProjectMcp()` since the w4a launch split —
 *    `Bootstrap::trustProjectMcp()` is a delegating shim and writes nothing
 *    itself);
 *  - that `candy-code login` persists a typed provider key through the same
 *    door (`Subcommands::loginStoreKey()`, plan_comparison W4 PRV-4);
 *  - and the first-run wizard's host persists its typed key through the same
 *    door as well (`Config/ProviderKeyStore::store()` — the C-TUI-5.d
 *    extraction of `loginStoreKey`'s persist half, credited as its own door
 *    while `login` still carries the inline copy);
 *  - that {@see \SugarCraft\Code\Config\Settings\SettingsWriter} is built in
 *    one place, `LaunchContext::settingsWriter()` (body moved there in the
 *    S1b composition split, W7-E C5; `Bootstrap::settingsWriter()` is its
 *    private door), which `workspace()` registers
 *    for `/model` and `app()` hands on to the settings editor;
 *  - the project-local file a writer may publish to is asked for only by the
 *    writer, and the writer is the only `Config/Settings` class that touches
 *    `AtomicJsonFile`;
 *  - who SAVES through the writer (`write()`, `grantTrust()`, `revokeTrust()`):
 *    the settings view's save and its trust action (`App`), `/model`'s model
 *    choice (`ModelChoice::persist()`), the server's `settings.set`
 *    (`SettingsMethods::set()`, the web UI's settings form — roadmap O-6b),
 *    and the `/rendermode`, `/skills enable|disable`, and `/effort` host
 *    commands (the render door is described in `docs/MEDIA.md`;
 *    `docs/SETTINGS.md` "Saving from the settings view" names the
 *    settings-file doors).
 *
 * A new caller of any of these reds here by EXISTING, which is the point: a
 * fifth door into `config.json` has to be written down, with its docs.
 */
final class SettingsWriterCensusTest extends TestCase
{
    private const EXPECTED_USER_CONFIG_WRITERS = [
        // 2026-10-27: chat()'s body lives in buildChat() since the tab-factory
        // extraction (P-TABS.d); the writer credit follows the body.
        'Cli/Bootstrap.php::buildChat' => 1,
        // The `app()` and `settingsWriter()` bodies moved to LaunchContext in
        // the S1b composition split (W7-E C5); their writeUserConfig calls
        // ride along, so these two rows re-key to the new owner file.
        'Cli/LaunchContext.php::app' => 1,
        'Cli/LaunchContext.php::settingsWriter' => 1,
        'Cli/McpDecision.php::trustProjectMcp' => 1,
        'Cli/Subcommands.php::loginStoreKey' => 1,
        // C-MCP-4.b (round-2 review): /mcp enable|disable --persist mirrors
        // ONE exact name into the user disabledMcpServers list through this
        // same door. Credited 3 because the method carries three write
        // shapes, each its own call site: the enable arm's kept-list
        // rewrite, the --persist clear/empty path that unsets the key
        // instead of persisting a dead `[]`, and the disable arm's append.
        // A glob-shaped name is refused before any of the three is reached.
        'Commands/McpAuthCommand.php::persistSessionToggle' => 3,
        // C-TUI-5.d: the first-run wizard's persist door, extracted from
        // loginStoreKey's write half — its own door until a wiring step
        // re-points `login` itself here (both are credited meanwhile).
        'Config/ProviderKeyStore.php::store' => 1,
    ];

    public function testConfigJsonIsWrittenOnlyThroughItsKnownDoors(): void
    {
        self::assertSame(self::EXPECTED_USER_CONFIG_WRITERS, $this->callSites('writeUserConfig', '::'));
    }

    public function testTheSettingsWriterIsBuiltOnlyByTheLaunch(): void
    {
        $sites = $this->callSites('SettingsWriter', '', 'new');

        self::assertSame(['Cli/LaunchContext.php::settingsWriter' => 1], $sites);
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
            // crush-media W4.1 (lane A carried seam): the media form's preset
            // persist door is minted here — one write of exactly the
            // sd.presets key, the sanctioned SettingsWriter path, the form
            // itself never names the writer (the W3.9 separation law holds).
            'App/App.php::openMediaForm->write' => 1,
            // The permission modal's `p`: the same scope `a` remembers, also
            // written to the user's `permissionRules` so it outlives the
            // session. One write of exactly that key, through the sanctioned
            // SettingsWriter path — the session grant it rides on is already
            // recorded, so a refused save is reported, never fatal.
            // PRV-2 (w5b): /effort remembers the chosen shape across launches.
            // Two token-sites — the set branch and the clear-to-default branch —
            // and any one invocation writes exactly once, user tier, the
            // reasoningEffort key alone; a failed save rides the note, never
            // fatal.
            'Chat.php::persistEffort->write' => 2,
            // C-PRV-3.b (carried by the W21 wave loop): /fast remembers the
            // chosen service lane across launches — the `serviceTier` key
            // pairs with persistEffort's `reasoningEffort`. One token-site:
            // every accepting invocation writes exactly once through the
            // sanctioned writer, user tier, the one key alone. There is no
            // delete arm by design ('default' IS an affirmative word in the
            // user tier), and a failed save rides the note, never fatal.
            'Chat.php::persistFast->write' => 1,
            // C-SEC-5.a: the census's one grant door. The list/settings targets
            // go through the sanctioned writer exactly as the settings editor's
            // own confirmed action does — one site, the writer still built only
            // by the launch.
            'Cli/Bootstrap.php::grantProjectTrust->grantTrust' => 1,
            // C-EXT-4.d (W21): the launch-recommendation STAMP — a notice's
            // seen-pins, not a settings key. One site through AtomicJsonFile
            // at 0600, rewriting the marketplace registry's own envelope only
            // over a file the registry already minted (it never creates the
            // stamp), fail-open: the row rides anyway and a lost stamp costs
            // one re-shown line next launch. Listed because this census walks
            // EVERY `->write(` token-site in src/ — an unannounced writer into
            // a JSON file is exactly what it exists to catch.
            'Cli/Bootstrap.php::stampBundleRecommendations->write' => 1,
            'Config/Settings/ModelChoice.php::persist->write' => 1,
            // C-WEB-2.f: the served `/effort` saves the tier through the
            // workspace's writer — the same two sites (set and the auto/clear
            // delete) as Chat's persistEffort row above, reached from the host
            // surface over the shared EffortDial. A refusal or a bare read
            // never opens the door; that is pinned dial-side.
            'Host/Commands/EffortHostCommand.php::persist->write' => 2,
            // crush-media W5.7: /rendermode writes the chosen render mode
            // through the sanctioned writer — the one command that mutates a
            // settings key, which is why it takes the host command's writer
            // rather than touching AtomicJsonFile itself.
            'Host/Commands/RenderModeHostCommand.php::run->write' => 1,
            // C-EXT-2.d: `/skills enable|disable` writes the toggled
            // disabledSkills list through the sanctioned writer — the second
            // command door into a settings key, and it takes the host
            // command's writer rather than touching the file itself for the
            // same reason /rendermode's row does.
            'Host/Commands/SkillsHostCommand.php::toggleSkill->write' => 1,
            // C-SEC-6.a: the `p` save moved from PermissionPrompt into the
            // SavedRules service that also lists and forgets the key — one
            // write of `permissionRules` in append() (the modal's save, same
            // notice and door as before the move) and one in remove() (the
            // `/permissions forget` write-back, which refuses loudly rather
            // than report a half-landed removal). PermissionPrompt now only
            // opens the door and delegates, so it holds no write site.
            'Permissions/SavedRules.php::append->write' => 1,
            'Permissions/SavedRules.php::remove->write' => 1,
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
        // Config/PolicySnapshot.php (f-app-launch 2026-10-14, rv-sec-3) writes
        // its OWN 0600 freshness state file through the same atomic door; it
        // reads and writes no settings layer — the writer census stays honest
        // because the roster names every toucher, not only the layer writer.
        self::assertSame(['Config/PolicySnapshot.php', 'Config/Settings/SettingsWriter.php', 'Tui/Settings/SettingsProfile.php'], array_keys($files));
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
