<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Crush\Lang;

/**
 * The `chat.` / `host.` catalogue (audit 15b-14, step 15b-14-4a) agrees with
 * its call sites in `src/Chat.php`, `src/Host/**` and the grant scopes
 * `src/Permissions/SessionPermissionMemo.php` names for Chat's modal.
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
            preg_match_all("/'((?:chat|host)\\.[a-z0-9_.]+)'/", $source, $m);
            foreach ($m[1] as $key) {
                $asked[$key] = true;
            }
        }

        $unused = array_values(array_diff(array_keys($this->catalogue()), array_keys($asked)));
        self::assertSame([], $unused, 'chat./host. entries nothing in src/Chat.php, src/Host or SessionPermissionMemo asks for');
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
        // words Chat's permission modal shows (`this exact command`).
        $files = [self::SRC . '/Chat.php', self::SRC . '/Permissions/SessionPermissionMemo.php'];
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
