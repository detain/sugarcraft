<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\I18n;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Discord\Commands;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Plugins\Bridge\ConversationRouterCore;

/**
 * Every string a Discord user or the operator reads at startup rides
 * `Lang::t` over the bundle's own `channels.discord.*` catalog in `lang/`
 * (plugin-owned under O4, plan_plugin.md 4.11). This guard fails
 * on a NEW bare literal in the user-facing slots (slash descriptions, message
 * `content`, thrown endpoint errors) and on a `Lang::t` key that the English
 * catalog does not carry. Diagnostic-only text (log lines, RPC error
 * messages, SQL) is deliberately outside it. The attachment skip path is the
 * one flow where a bare English string reaches a user without a slot name, so
 * DiscordAccount.php is also checked token by token: a letter-bearing literal
 * written into `$notes` (by any assignment or `array_push`), used as the
 * attachment `$name` fallback, or passed as a top-level argument to a send call
 * must go through `Lang::t` instead.
 */
final class UserFacingLiteralGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';
    private const LANG = __DIR__ . '/../../lang';

    /** @return list<string> */
    private static function sources(): array
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::SRC, \FilesystemIterator::SKIP_DOTS));
        $out = [];
        foreach ($it as $f) {
            if ($f->getExtension() === 'php') {
                $out[] = $f->getPathname();
            }
        }
        \sort($out);

        return $out;
    }

    public function testNoBareLiteralInAUserFacingSlot(): void
    {
        $bad = [];
        foreach (self::sources() as $file) {
            foreach (\file($file) as $n => $line) {
                $code = \trim($line);
                if (\str_starts_with($code, '*') || \str_starts_with($code, '//') || \str_starts_with($code, '/*')) {
                    continue;
                }
                // 'description' => '<text>' / 'content' => "<text>" with a letter-bearing literal.
                if (\preg_match('/[\'"](description|content)[\'"]\s*=>\s*([\'"])(?=[^\'"]*[A-Za-z])/', $code)) {
                    $bad[] = \basename($file) . ':' . ($n + 1) . ' ' . $code;
                }
                if (\basename($file) === 'Endpoint.php' && \preg_match('/new RuntimeException\(\s*[\'"]/', $code)) {
                    $bad[] = \basename($file) . ':' . ($n + 1) . ' ' . $code;
                }
            }
        }
        self::assertSame([], $bad, 'route user-facing text through Lang::t with a channels.discord.* key in every lang/ catalog');
    }

    public function testNoBareEnglishReachesNotesOrASendCallInDiscordAccount(): void
    {
        $source = (string) \file_get_contents(self::SRC . '/Discord/DiscordAccount.php');

        self::assertSame(
            [],
            self::bareEnglishInNotesOrSend($source),
            'attachment skip reasons and notices must route through Lang::t with a channels.discord.* key',
        );
    }

    /** @return iterable<string, array{string, int}> fixture and the line its literal sits on */
    public static function bareEnglishFixtureProvider(): iterable
    {
        yield 'double-quoted interpolated reason in $notes' => ["<?php\n\$notes[] = \"{\$name} (unsupported type)\";\n", 2];
        yield 'concatenated reason in $notes' => ["<?php\n\n\$notes[] = 'Skipped ' . \$name;\n", 3];
        yield 'bare literal passed to a send call' => ["<?php\n\$this->rest->send(\$channelId, 'Attachments were skipped', []);\n", 2];
        yield 'multi-line send call with a bare literal' => ["<?php\n\$this->rest->send(\n    \$channelId,\n    'Attachment dropped',\n);\n", 4];
        yield 'indexed assignment into $notes' => ["<?php\n\$notes[\$idx] = 'Attachment dropped';\n", 2];
        yield 'whole-array assignment into $notes' => ["<?php\n\$notes = ['Attachments were skipped'];\n", 2];
        yield 'concatenation-assign into $notes' => ["<?php\n\$notes .= 'Attachment dropped';\n", 2];
        yield 'array_push into $notes' => ["<?php\narray_push(\$notes, 'Attachment dropped');\n", 2];
        yield 'fully qualified array_push into $notes' => ["<?php\n\\array_push(\$notes, 'Attachment dropped');\n", 2];
        yield 'bare English fallback for an unnamed attachment' => ["<?php\n\$name = (string) (\$attachment['filename'] ?? 'attachment');\n", 2];
        yield 'bare English under a qualified non-Lang class' => ["<?php\n\$notes[] = \\Other\\Formatter::t('Attachment dropped');\n", 2];
    }

    #[DataProvider('bareEnglishFixtureProvider')]
    public function testFixtureWithABareEnglishLiteralIsFlagged(string $fixture, int $line): void
    {
        $hits = self::bareEnglishInNotesOrSend($fixture);

        self::assertCount(1, $hits, 'the guard must catch a bare English literal');
        self::assertStringStartsWith($line . ': ', $hits[0]);
    }

    /** @return iterable<string, array{string}> */
    public static function qualifiedLangCallFixtureProvider(): iterable
    {
        yield 'fully qualified Lang::t in $notes' => ["<?php\n\$notes[] = \\CandyCode\\Bundles\\Discord\\Lang::t('channels.discord.attachments.reason.unsupported', ['name' => \$name]);\n"];
        yield 'namespace-qualified Lang::t in $notes' => ["<?php\n\$notes[] = Bundles\\Discord\\Lang::t('channels.discord.attachments.reason.unsupported', ['name' => \$name]);\n"];
        yield 'fully qualified Lang::t as a send argument' => ["<?php\n\$this->rest->send(\$channelId, \\CandyCode\\Bundles\\Discord\\Lang::t('channels.discord.attachments.skipped', []), []);\n"];
    }

    #[DataProvider('qualifiedLangCallFixtureProvider')]
    public function testQualifiedLangCallIsNotFlagged(string $fixture): void
    {
        self::assertSame([], self::bareEnglishInNotesOrSend($fixture));
    }

    public function testFixtureRoutedThroughLangOrAValueTestIsNotFlagged(): void
    {
        $fixture = <<<'PHP'
        <?php
        $notes[] = Lang::t('channels.discord.attachments.reason.unsupported', ['name' => $name]);
        $notes[] = $slot === 'images'
            ? Lang::t('channels.discord.attachments.reason.past_image_limit', ['name' => $name, 'limit' => (string) $cap])
            : Lang::t('channels.discord.attachments.reason.past_document_limit', ['name' => $name, 'limit' => (string) $cap]);
        $name = (string) ($attachment['filename'] ?? Lang::t('channels.discord.attachments.unnamed'));
        $this->rest->send($channelId, $this->safety->outbound($notice), ['allowed_mentions' => ['parse' => []]]);
        PHP;

        self::assertSame([], self::bareEnglishInNotesOrSend($fixture));
    }

    /**
     * Each hit is "line: literal" for a letter-bearing string that reaches
     * `$notes` (any write form), the attachment `$name`, or a top-level argument
     * of a send call without going through `Lang::t`. Works on tokens, so a multi-line call or an interpolated
     * string is read the same way. A literal that is only compared with
     * `===` or `!==` is a value test, not text a user reads.
     *
     * @return list<string>
     */
    public static function bareEnglishInNotesOrSend(string $source): array
    {
        $toks = [];
        $line = 1;
        foreach (\token_get_all($source) as $t) {
            [$id, $text] = \is_array($t) ? [$t[0], $t[1]] : [$t, $t];
            if (!\in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                $toks[] = ['id' => $id, 'text' => $text, 'line' => $line];
            }
            $line += \substr_count($text, "\n");
        }

        $hits = [];
        $n = \count($toks);
        for ($i = 0; $i < $n; ++$i) {
            if (self::is($toks, $i, T_VARIABLE, '$notes') && ($op = self::noteWriteOperator($toks, $i + 1)) !== null) {
                \array_push($hits, ...self::bareLiterals($toks, $op + 1, self::statementEnd($toks, $op + 1), false));
            } elseif (self::is($toks, $i, T_VARIABLE, '$name') && self::is($toks, $i + 1, '=')) {
                \array_push($hits, ...self::bareLiterals($toks, $i + 2, self::statementEnd($toks, $i + 2), false));
            } elseif (self::isCall($toks, $i, 'array_push') && self::is($toks, $i + 1, '(') && self::is($toks, $i + 2, T_VARIABLE, '$notes') && self::is($toks, $i + 3, ',')) {
                \array_push($hits, ...self::bareLiterals($toks, $i + 4, self::closeIndex($toks, $i + 1), false));
            } elseif (self::is($toks, $i, T_OBJECT_OPERATOR) && self::is($toks, $i + 1, T_STRING, 'send') && self::is($toks, $i + 2, '(')) {
                \array_push($hits, ...self::bareLiterals($toks, $i + 3, self::closeIndex($toks, $i + 2), true));
            }
        }

        return $hits;
    }

    /**
     * Index of the assignment operator after `$notes`, `$notes[...]`, or the
     * append form `.=`; null when `$notes` is only read.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function noteWriteOperator(array $toks, int $from): ?int
    {
        $op = $from;
        if (self::is($toks, $op, '[')) {
            $op = self::closeIndex($toks, $op) + 1;
        }

        return self::is($toks, $op, '=') || self::is($toks, $op, T_CONCAT_EQUAL) ? $op : null;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function isCall(array $toks, int $i, string $function): bool
    {
        return self::is($toks, $i, T_STRING, $function) || self::is($toks, $i, T_NAME_FULLY_QUALIFIED, '\\' . $function);
    }

    /**
     * A literal that is the key of a subscript (`$row['filename']`) names data,
     * not text a user reads. An array literal such as `['x']` is not a subscript.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function isSubscriptKey(array $toks, int $i): bool
    {
        return self::is($toks, $i - 1, '[')
            && self::is($toks, $i + 1, ']')
            && isset($toks[$i - 2])
            && \in_array($toks[$i - 2]['id'], [T_VARIABLE, T_STRING, ']', ')'], true);
    }

    /**
     * `Lang` written bare, or qualified (`\CandyCode\Bundles\Discord\Lang`): the
     * lexer emits a name token rather than T_STRING for the qualified forms.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function isLangClass(array $toks, int $i): bool
    {
        if (!isset($toks[$i])) {
            return false;
        }
        if ($toks[$i]['id'] === T_STRING) {
            return $toks[$i]['text'] === 'Lang';
        }

        return \in_array($toks[$i]['id'], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && \preg_match('/(^|\\\\)Lang$/', $toks[$i]['text']) === 1;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function is(array $toks, int $i, int|string $id, ?string $text = null): bool
    {
        if (!isset($toks[$i]) || $toks[$i]['id'] !== $id) {
            return false;
        }

        return $text === null || $toks[$i]['text'] === $text;
    }

    /** @param array{id: int|string, text: string, line: int} $tok */
    private static function opens(array $tok): bool
    {
        return \in_array($tok['id'], ['(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }

    /** @param array{id: int|string, text: string, line: int} $tok */
    private static function closes(array $tok): bool
    {
        return \in_array($tok['id'], [')', ']', '}'], true);
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function statementEnd(array $toks, int $from): int
    {
        $depth = 0;
        for ($i = $from, $n = \count($toks); $i < $n; ++$i) {
            if (self::opens($toks[$i])) {
                ++$depth;
            } elseif (self::closes($toks[$i])) {
                --$depth;
            } elseif ($toks[$i]['id'] === ';' && $depth === 0) {
                return $i;
            }
        }

        return $n;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function closeIndex(array $toks, int $open): int
    {
        $depth = 0;
        for ($i = $open, $n = \count($toks); $i < $n; ++$i) {
            if (self::opens($toks[$i])) {
                ++$depth;
            } elseif (self::closes($toks[$i]) && --$depth === 0) {
                return $i;
            }
        }

        return $n;
    }

    /**
     * @param list<array{id: int|string, text: string, line: int}> $toks
     * @return list<string>
     */
    private static function bareLiterals(array $toks, int $from, int $to, bool $topLevelOnly): array
    {
        $hits = [];
        $depth = 0;
        for ($i = $from; $i < $to; ++$i) {
            if (self::isLangClass($toks, $i) && self::is($toks, $i + 1, T_DOUBLE_COLON) && self::is($toks, $i + 2, T_STRING, 't') && self::is($toks, $i + 3, '(')) {
                $i = self::closeIndex($toks, $i + 3);

                continue;
            }
            if (self::opens($toks[$i])) {
                ++$depth;

                continue;
            }
            if (self::closes($toks[$i])) {
                --$depth;

                continue;
            }
            $literal = $toks[$i]['id'] === T_CONSTANT_ENCAPSED_STRING || $toks[$i]['id'] === T_ENCAPSED_AND_WHITESPACE;
            if (!$literal || \preg_match('/[A-Za-z]/', $toks[$i]['text']) !== 1 || self::isSubscriptKey($toks, $i)) {
                continue;
            }
            if ($topLevelOnly && $depth !== 0) {
                continue;
            }
            if (self::comparedByValue($toks, $i)) {
                continue;
            }
            $hits[] = $toks[$i]['line'] . ': ' . $toks[$i]['text'];
        }

        return $hits;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function comparedByValue(array $toks, int $i): bool
    {
        $compare = [T_IS_IDENTICAL, T_IS_NOT_IDENTICAL, T_IS_EQUAL, T_IS_NOT_EQUAL];
        // Interpolated strings open and close with a bare quote token.
        $prev = $i - 1;
        while ($prev >= 0 && $toks[$prev]['id'] === '"') {
            --$prev;
        }
        $next = $i + 1;
        while (isset($toks[$next]) && $toks[$next]['id'] === '"') {
            ++$next;
        }

        return ($prev >= 0 && \in_array($toks[$prev]['id'], $compare, true))
            || (isset($toks[$next]) && \in_array($toks[$next]['id'], $compare, true));
    }

    public function testEveryLangKeyUsedExistsInEnglishAndEveryLocale(): void
    {
        $keys = [];
        foreach (self::sources() as $file) {
            if (\preg_match_all('/Lang::t\(\s*\'(channels\.discord\.[A-Za-z0-9_.]+)\'/', (string) \file_get_contents($file), $m)) {
                \array_push($keys, ...$m[1]);
            }
        }

        // The shared core asks for core-relative keys ($this->t('turn.eror')) and the
        // router maps each one to its catalog key. Resolve every core key through the
        // router's own catalogKey(), so a typo or an unmapped key reaches the catalog check.
        $core = (string) \file_get_contents((string) (new \ReflectionClass(ConversationRouterCore::class))->getFileName());
        $coreKeys = [];
        \preg_match_all('/\$this->t\(\s*\'([a-z][a-z0-9_.]*)\'/', $core, $coreKeys);
        self::assertNotEmpty($coreKeys[1], 'the core must name its catalog keys as literals');
        $catalogKey = new \ReflectionMethod(ConversationRouter::class, 'catalogKey');
        foreach (\array_unique($coreKeys[1]) as $coreKey) {
            $keys[] = (string) $catalogKey->invoke(null, $coreKey);
        }

        // Every arm the router's map names must resolve too, including arms no core call uses yet.
        $router = (string) \file_get_contents(self::SRC . '/Bridge/ConversationRouter.php');
        \preg_match_all('/\'([a-z][a-z0-9_.]*)\'\s*=>\s*\'(channels\.discord\.[A-Za-z0-9_.]+)\'/', $router, $arms);
        self::assertNotEmpty($arms[2], 'the router must map core keys onto the channels.discord catalog');
        \array_push($keys, ...$arms[2]);

        $keys = \array_values(\array_unique($keys));
        self::assertNotEmpty($keys);
        $missing = [];
        foreach (\glob(self::LANG . '/*.php') as $catalog) {
            $table = require $catalog;
            foreach ($keys as $k) {
                if (!isset($table[$k])) {
                    $missing[] = \basename($catalog) . ' ' . $k;
                }
            }
        }
        self::assertSame([], $missing);
    }

    public function testSlashRosterTextComesFromTheCatalogNotRawKeys(): void
    {
        foreach (Commands::definitions() as $command) {
            self::assertStringNotContainsString('channels.discord.', $command['description'], $command['name']);
            self::assertLessThanOrEqual(100, \mb_strlen($command['description']), 'Discord caps descriptions at 100 chars');
            foreach ($command['options'] ?? [] as $option) {
                self::assertStringNotContainsString('channels.discord.', $option['description']);
                self::assertLessThanOrEqual(100, \mb_strlen($option['description']));
            }
        }
    }

    public function testPairActionOffersGrantAndNotTheDeprecatedApprove(): void
    {
        $pair = \array_values(\array_filter(Commands::definitions(), static fn (array $c): bool => $c['name'] === 'pair'))[0];
        $choices = \array_column($pair['options'][0]['choices'], 'value');
        self::assertSame(['grant', 'list'], $choices);
    }
}
