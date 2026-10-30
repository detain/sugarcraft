<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Code\Lang;

/**
 * The `chat.` / `host.` catalogue (audit 15b-14, step 15b-14-4a) agrees with
 * its call sites in `src/Chat.php`, `src/Host/**`, the grant scopes
 * `src/Permissions/SessionPermissionMemo.php` names for Chat's modal, the prompt
 * titles `src/Permissions/PermissionPrompt.php` builds for it (W5-A), and —
 * since ISS-070 — `src/Backend/EngineBackend.php`, which records one
 * catalogue row of its own onto the notice seam the host drains, joined for
 * VIS-6 by `src/Runtime.php`, whose recall row rides that same seam. EffortGate
 * joins for C-PRV-1.a: the /effort verdict's shape phrases emit their own
 * `chat.effort.shape.*` rows from `src/Providers/`. Widened for W27-m2 over the
 * full stranded-key census (wave review MAJOR-2, option (a)): the dial rows
 * `src/Commands/EffortDial.php` asks, the incognito band `src/Renderer.php`
 * paints, and the ask-home of every remaining stranded row — `src/Theme.php`,
 * `src/Diagnostics/RuntimeNoticeSink.php`, `src/Support/StartupSummary.php`,
 * `src/Attachments/FileMentions.php`, `src/Permissions/SavedRules.php`,
 * `src/Cli/LaunchContext.php`.
 *
 * LangParityTest already proves every literal key src asks for exists. What it
 * cannot see is the other half of a call: the parameters. A call that passes
 * `['count' => …]` to an entry spelling `{n}` renders the placeholder
 * literally — in English as much as in any translation — and nothing else in
 * the suite fails for a notice no test happens to print. So the census reads
 * every `Lang::t(` in the two areas with the tokenizer, collects each key its
 * first argument can name (a ternary names two) and the literal keys of its
 * params array, and requires the two sets to match exactly. It also requires
 * every key of the block to be asked for somewhere, so a moved string cannot
 * leave a dead entry behind for translators to keep translating.
 */
final class ChatHostLangCatalogueTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    private string $locale;

    protected function setUp(): void
    {
        $this->locale = T::locale();
        T::setLocale('en');
    }

    protected function tearDown(): void
    {
        T::setLocale($this->locale);
    }

    public function testEveryCallPassesExactlyThePlaceholdersItsEntrySpells(): void
    {
        $en = $this->catalogue();
        $problems = [];
        $seen = 0;

        foreach ($this->calls() as [$where, $keys, $params]) {
            foreach ($keys as $key) {
                $seen++;
                if (!\array_key_exists($key, $en)) {
                    $problems[] = "{$where}: {$key} has no lang/en.php entry";

                    continue;
                }
                preg_match_all('/\{(\w+)\}/', $en[$key], $m);
                $expected = array_values(array_unique($m[1]));
                sort($expected);
                if ($params !== null && $expected !== $params) {
                    $problems[] = "{$where}: {$key} spells {" . implode('}, {', $expected) . '} but is passed ['
                        . implode(', ', $params) . ']';
                }
            }
        }

        // Known-positive: an instrument that matched nothing would pass vacuously.
        self::assertGreaterThan(100, $seen, 'the census found too few chat./host. Lang::t() calls to be trusted');
        self::assertSame([], $problems);
    }

    public function testEveryCatalogueKeyIsAskedForByChatOrHost(): void
    {
        $asked = [];
        foreach ($this->sources() as $source) {
            preg_match_all("/'((?:chat|host)\\.[a-zA-Z0-9_.-]+)'/", $source, $m);
            foreach ($m[1] as $key) {
                $asked[$key] = true;
            }
        }

        $unused = array_values(array_diff(array_keys($this->catalogue()), array_keys($asked)));
        self::assertSame([], $unused, 'chat./host. entries nothing in the sources() scan — Chat, Host, the Permissions/Backend/Runtime/Goal/Providers/Context rows, the shared EffortDial, the Renderer band, Theme, the notice sink, and the startup/attach/launch ask-homes — asks for');
    }

    public function testTheEnglishRenderingIsTheTextTheLiteralsUsedToSpell(): void
    {
        // A sample across the shapes the conversion used — a plain row, an
        // interpolated row, a plural pair and a fragment spliced into another
        // row — pinned to the bytes the literals produced before 15b-14-4a.
        self::assertSame('_Request cancelled._', Lang::t('chat.request.cancelled'));
        self::assertSame('_Resumed session demo._', Lang::t('chat.session.resumed', ['session' => 'demo']));
        self::assertSame('Saved 1 setting', Lang::t('chat.settings.saved.one', ['count' => 1]));
        self::assertSame('Saved 3 settings', Lang::t('chat.settings.saved.other', ['count' => 3]));
        self::assertSame(' (pid 42)', Lang::t('chat.session.lock_holder', ['pid' => 42]));
        self::assertSame(
            "Switched to provider 'openai', model 'gpt' (saved as models.openai in /x).",
            Lang::t('chat.provider.switched_model_saved', [
                'provider' => 'openai',
                'model' => 'gpt',
                'saved' => Lang::t('chat.provider.model_saved', ['provider' => 'openai', 'path' => '/x']),
            ]),
        );
    }

    /** @return array<string, string> the `chat.` and `host.` entries of lang/en.php */
    private function catalogue(): array
    {
        $all = require __DIR__ . '/../../lang/en.php';

        return array_filter(
            $all,
            static fn (string $key): bool => str_starts_with($key, 'chat.') || str_starts_with($key, 'host.'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /** @return array<string, string> path => source */
    private function sources(): array
    {
        // SessionPermissionMemo names what a session grant remembers, in the
        // words Chat's permission modal shows (`this exact command`), and
        // PendingAsk why a question cannot be remembered (`asked by hook …`).
        // EngineBackend joins for ISS-070: its memory-flush notice lands on
        // the seam the host drains into the same transcript. Runtime joins for
        // VIS-6: its recall row rides that same sink from the priming door.
        // GoalLoop joins for W7a-3: both hosts' goal decision table emits its
        // own chat.goal.* sentences from the one shared file.
        // W7-S4: route()'s family arms live in src/Chat/Handlers/ traits —
        // the census now spans both files, so the whole directory is walked
        // (unlisted files strand their keys as unused here).
        // C-PRV-1.a: EffortGate.php joins — the /effort verdict emits its own
        // chat.effort.shape.* rows from the src/Providers/ directory.
        // C-CTX-2.f: the two src/Context instruction files join — the nested
        // load and the mid-session change each emit their own visibility row
        // (chat.instructions.loaded / chat.instructions.updated) through the
        // same RuntimeNoticeSink the refusal rows already ride from there.
        // W27-m2 (wave review MAJOR-2, lead option (a)): the scan covers the
        // ask-home of every key the catalogue still ships, each leg attributed
        // to the move that stranded it —
        // C-WEB-2.f 75d2d19f5: /effort lifted out of Chat.php into the shared
        // dial, its seven chat.effort rows (plus the launch_unshaped pair
        // C-SES-8.g/Cli LaunchContext carries) asked from src/Commands/.
        // C-SES-7.c: the incognito band paints chat.incognito.status from
        // src/Renderer.php.
        // Foreign legs adjudicated to their lanes' accounts at the review,
        // scanned here so the guard is green at master rather than ledger-only:
        // C-TUI-4.a 83353667a emits the chat.theme.file_* quartet from
        // src/Theme.php; C-CTX-6.e/c 9c59bc8d1 + 16266186d the recalled_notice
        // pair from src/Diagnostics/RuntimeNoticeSink.php (the seam's own row);
        // src/Support/StartupSummary.php, src/Attachments/FileMentions.php,
        // src/Permissions/SavedRules.php and src/Cli/LaunchContext.php carry
        // the remaining startup/attach/permission/launch rows.
        $files = [self::SRC . '/Chat.php', self::SRC . '/Permissions/SessionPermissionMemo.php', self::SRC . '/Permissions/PermissionPrompt.php', self::SRC . '/Backend/PendingAsk.php', self::SRC . '/Backend/EngineBackend.php', self::SRC . '/Runtime.php', self::SRC . '/Goal/GoalLoop.php', self::SRC . '/Providers/EffortGate.php', self::SRC . '/Context/InstructionFileLoader.php', self::SRC . '/Context/InstructionDiffTracker.php', self::SRC . '/Commands/EffortDial.php', self::SRC . '/Renderer.php', self::SRC . '/Theme.php', self::SRC . '/Diagnostics/RuntimeNoticeSink.php', self::SRC . '/Support/StartupSummary.php', self::SRC . '/Attachments/FileMentions.php', self::SRC . '/Permissions/SavedRules.php', self::SRC . '/Cli/LaunchContext.php'];
        foreach (new \RecursiveDirectoryIterator(self::SRC . '/Chat/Handlers', \FilesystemIterator::SKIP_DOTS) as $handlerFile) {
            if ($handlerFile->isFile() && $handlerFile->getExtension() === 'php') {
                $files[] = $handlerFile->getPathname();
            }
        }
        $walk = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::SRC . '/Host', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $out = [];
        foreach ($files as $file) {
            $out[substr($file, \strlen(self::SRC) + 1)] = (string) file_get_contents($file);
        }

        return $out;
    }

    /**
     * Every `Lang::t(` call in the two areas: where it is, the catalogue keys its
     * first argument names, and the sorted literal keys of its params array —
     * null when that array is not a literal the tokenizer can read.
     *
     * @return list<array{0: string, 1: list<string>, 2: ?list<string>}>
     */
    private function calls(): array
    {
        $calls = [];
        foreach ($this->sources() as $path => $source) {
            $tokens = array_values(array_filter(
                token_get_all($source),
                static fn (array|string $t): bool => !\is_array($t)
                    || !\in_array($t[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true),
            ));
            $n = \count($tokens);
            for ($i = 0; $i + 3 < $n; $i++) {
                if (!\is_array($tokens[$i]) || $tokens[$i][1] !== 'Lang'
                    || !\is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== \T_DOUBLE_COLON
                    || !\is_array($tokens[$i + 2]) || $tokens[$i + 2][1] !== 't'
                    || $tokens[$i + 3] !== '('
                ) {
                    continue;
                }

                $where = $path . ':' . $tokens[$i][2];
                $keys = [];
                $params = [];
                $arg = 0;
                $depth = 0;
                $arrayDepth = null;
                $literal = true;
                for ($j = $i + 4; $j < $n; $j++) {
                    $t = $tokens[$j];
                    $text = \is_array($t) ? $t[1] : $t;
                    if (\in_array($text, ['(', '['], true)) {
                        if ($arg === 1 && $depth === 0 && $text === '[') {
                            $arrayDepth = 1;
                        } elseif ($arg === 1 && $depth === 0) {
                            $literal = false;
                        }
                        $depth++;

                        continue;
                    }
                    if (\in_array($text, [')', ']'], true)) {
                        if ($depth === 0) {
                            break;
                        }
                        $depth--;

                        continue;
                    }
                    if ($text === ',' && $depth === 0) {
                        $arg++;

                        continue;
                    }
                    if ($arg === 0 && \is_array($t) && $t[0] === \T_CONSTANT_ENCAPSED_STRING) {
                        $value = substr($t[1], 1, -1);
                        if (preg_match('/^(?:chat|host)\.[a-z0-9_.]+$/', $value) === 1) {
                            $keys[] = $value;
                        }
                    }
                    if ($arg === 1 && $depth === 0 && \is_array($t)) {
                        $literal = false;
                    }
                    if ($arg === 1 && $arrayDepth !== null && $depth === 1
                        && \is_array($t) && $t[0] === \T_CONSTANT_ENCAPSED_STRING
                        && \is_array($tokens[$j + 1] ?? null) && $tokens[$j + 1][0] === \T_DOUBLE_ARROW
                    ) {
                        $params[] = substr($t[1], 1, -1);
                    }
                }

                if ($keys === []) {
                    continue;
                }
                $params = array_values(array_unique($params));
                sort($params);
                $calls[] = [$where, $keys, $literal ? $params : null];
            }
        }

        return $calls;
    }
}
