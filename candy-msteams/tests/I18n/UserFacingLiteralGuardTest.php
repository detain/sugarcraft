<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every string a Teams user reads rides `Lang::t` over the bundle's own
 * `channels.msteams.*` catalog in `lang/` (plugin-owned under O4, plan_plugin.md
 * 4.11), the same guard candy-discord carries (its UserFacingLiteralGuardTest).
 *
 * The check is token based. A string literal that contains a three-letter word
 * and a space is prose, and prose in these files is user text, so it is flagged
 * unless it sits in a `->log->` call, is the message of a NoSessionIdException (the
 * one exception class the router's failureReason() maps to catalog copy), is the
 * RuntimeException message of a HOST_DIAGNOSTIC method (a rejection that never reaches
 * the chat), or is an array key. Event codes, separators and identifiers carry no
 * space and pass.
 * Two single-word shapes are flagged too: a sentence such as `'Done.'`, and a bare
 * word that is a ternary branch (`? 'attachment' :`), the usual shape of a display
 * fallback. A bare word elsewhere is not separable from an identifier by tokens
 * alone, so it stays a review matter.
 * A `Lang::t` key is a single token with no space, so a routed string never trips
 * the check.
 */
final class UserFacingLiteralGuardTest extends TestCase
{
    private const SRC = __DIR__ . '/../../src';

    /** Files whose text reaches a Teams user, relative to src/. */
    private const USER_FACING = [
        'MsTeams/MsTeamsAccount.php',
        'Bridge/ConversationRouter.php',
    ];

    /**
     * Methods whose rejection text never reaches a Teams chat. `deliver` rejections
     * are logged by the router's `deliver_failed` line; `notifyConversation` rejections
     * return to the host's own caller. Their English stays readable in the log and
     * the host, so the RuntimeException literal is exempt only inside these methods.
     *
     * @var array<string, list<string>>
     */
    private const HOST_DIAGNOSTIC = [
        'MsTeams/MsTeamsAccount.php' => ['deliver', 'notifyConversation'],
    ];

    public function testUserFacingFilesCarryNoBareEnglish(): void
    {
        foreach (self::USER_FACING as $file) {
            $source = (string) \file_get_contents(self::SRC . '/' . $file);
            $found = \array_map(
                static fn (array $hit): string => "{$file}:{$hit['line']} {$hit['literal']}",
                self::bareEnglishIn($source, self::HOST_DIAGNOSTIC[$file] ?? []),
            );
            self::assertSame([], $found, 'bare English literal reaches a user; move it into lang/');
        }
    }

    public function testHostDiagnosticRejectionIsExemptInItsNamedMethod(): void
    {
        $code = "public function deliver(): void { throw new \\RuntimeException('msteams deliver without a conversationReference ref'); }";

        self::assertSame([], self::bareEnglishIn("<?php\n{$code}\n", ['deliver']));
    }

    public function testHostDiagnosticExemptionDoesNotCoverTheSameLiteralElsewhere(): void
    {
        $code = "public function sendRaw(): void { throw new \\RuntimeException('msteams deliver without a conversationReference ref'); }";

        self::assertCount(1, self::bareEnglishIn("<?php\n{$code}\n", ['deliver']));
    }

    public function testHostDiagnosticExemptionDoesNotCoverOtherProseInItsMethod(): void
    {
        $code = "public function deliver(): void { \$this->sendRaw(\$s, \$c, 'Only an approver can manage pairings.'); }";

        self::assertCount(1, self::bareEnglishIn("<?php\n{$code}\n", ['deliver']));
    }

    /** @return iterable<string, array{string}> */
    public static function bareEnglishFixtureProvider(): iterable
    {
        yield 'pairing reply in a send call' => ["\$this->sendRaw(\$s, \$c, 'Only an approver can manage pairings.');"];
        yield 'interpolated media note' => ["\$notes[] = \"{\$name}: beyond the 4-image cap\";"];
        yield 'skipped prefix in a concatenation' => ["\$disclosed = '[skipped: ' . \\implode('; ', \$notes) . ']';"];
        yield 'multi-line notice' => ["\$text = \"This conversation is not enabled\\n\\n\" . \$id;"];
        yield 'refused prompt in deliver' => ["\$this->deliver(\$ch, \$ref, \"⛔ Your message never reached the agent.\");"];
        yield 'exception message the core shows in chat' => ["throw new \\RuntimeException('Session is busy, try again.');"];
        yield 'exception class other than the mapped one' => ["throw new \\CandyCode\\Bundles\\MsTeams\\Bridge\\SessionBusyException('Session is busy, try again.');"];
        yield 'default tool name' => ["\$tool = (string) (\$ask['tool'] ?? 'a tool');"];
        yield 'method name ending in Error is not diagnostic' => ["\$this->sendError('Approval expired, answer again.');"];
        yield 'single-word sentence' => ["\$text = 'Done.';"];
        yield 'bare word as a ternary fallback' => ["\$name = \$name === '' ? 'attachment' : \$name;"];
    }

    #[DataProvider('bareEnglishFixtureProvider')]
    public function testFixtureWithBareEnglishIsFlagged(string $code): void
    {
        $hits = self::bareEnglishIn("<?php\ndeclare(strict_types=1);\n{$code}\n");
        self::assertCount(1, $hits, "expected one hit for: {$code}");
        self::assertSame(3, $hits[0]['line'], 'the literal sits on the third line of the fixture');
    }

    /** @return iterable<string, array{string}> */
    public static function passingFixtureProvider(): iterable
    {
        yield 'routed through Lang::t' => ["\$this->sendRaw(\$s, \$c, Lang::t('channels.msteams.pair.use_dm'));"];
        yield 'log call carries prose' => ["\$this->log->info('msteams_media_downselect', ['note' => 'skipped some items']);"];
        yield 'array key with spaces' => ["\$row = ['the key name' => 1];"];
        yield 'event code and identifiers' => ["\$row = ['text' => 'message', 'type' => 'msteams_media'];"];
        yield 'separator only' => ["\$sep = \\implode('; ', \$notes);"];
        yield 'routed ternary fallback' => ["\$name = \$name === '' ? Lang::t('channels.msteams.media.unnamed_attachment') : \$name;"];
        yield 'bare word compared, not shown' => ["\$first = \$name === 'help';"];
        yield 'mapped exception class is never shown' => ["throw new NoSessionIdException('session.create returned no id');"];
        yield 'qualified mapped exception class is never shown' => ["throw new \\CandyCode\\Bundles\\MsTeams\\Bridge\\NoSessionIdException('session.create returned no id');"];
    }

    #[DataProvider('passingFixtureProvider')]
    public function testFixtureRoutedOrDiagnosticIsNotFlagged(string $code): void
    {
        self::assertSame([], self::bareEnglishIn("<?php\n{$code}\n"));
    }

    /**
     * Token scan. Returns each prose literal with its source line.
     *
     * @param list<string> $hostMethods methods whose RuntimeException message is host-facing (HOST_DIAGNOSTIC)
     *
     * @return list<array{line: int, literal: string}>
     */
    public static function bareEnglishIn(string $source, array $hostMethods = []): array
    {
        $toks = [];
        foreach (\token_get_all($source) as $tok) {
            if (\is_string($tok)) {
                $toks[] = ['id' => $tok, 'text' => $tok, 'line' => 0];
                continue;
            }
            if (\in_array($tok[0], [\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $toks[] = ['id' => $tok[0], 'text' => $tok[1], 'line' => $tok[2]];
        }

        $hits = [];
        foreach ($toks as $i => $tok) {
            if (!\in_array($tok['id'], [\T_CONSTANT_ENCAPSED_STRING, \T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }
            $prose = self::isProse($tok['text']) || self::isTernaryFallback($toks, $i);
            if (!$prose || self::isArrayKey($toks, $i) || self::insideDiagnosticCall($toks, $i)) {
                continue;
            }
            if (self::isHostDiagnostic($toks, $i, $hostMethods)) {
                continue;
            }
            $hits[] = ['line' => $tok['line'], 'literal' => $tok['text']];
        }

        return $hits;
    }

    /**
     * True when token $i is the first argument of `new \RuntimeException(` inside one of
     * the named methods. The enclosing method is the nearest named `function` above the
     * literal: methods do not nest and closures are unnamed, so the named function found
     * is the method whose body holds the literal.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     * @param list<string> $hostMethods
     */
    private static function isHostDiagnostic(array $toks, int $i, array $hostMethods): bool
    {
        if ($hostMethods === [] || ($toks[$i - 1]['id'] ?? null) !== '(') {
            return false;
        }
        // PHP 8 tokenizes `\RuntimeException` as one name token, so the callee is matched whole.
        $callee = $toks[$i - 2]['text'] ?? '';
        $isNew = ($toks[$i - 3]['id'] ?? null) === \T_NEW;
        if (\preg_match('/(^|\\\\)RuntimeException$/', $callee) !== 1 || !$isNew) {
            return false;
        }
        for ($k = $i - 1; $k >= 0; $k--) {
            if ($toks[$k]['id'] === \T_FUNCTION && ($toks[$k + 1]['id'] ?? null) === \T_STRING) {
                return \in_array($toks[$k + 1]['text'], $hostMethods, true);
            }
        }

        return false;
    }

    private static function isProse(string $text): bool
    {
        $bare = \preg_replace('/\\\\u\{[0-9A-Fa-f]+\}/', '', $text) ?? $text;
        $sentence = \preg_match('/^[\'"][A-Za-z]{3,}[.!?][\'"]$/', $bare) === 1;

        return $sentence || (\preg_match('/[A-Za-z]{3,}/', $bare) === 1 && \preg_match('/\s/', $bare) === 1);
    }

    /**
     * A bare word between `?` and `:`, such as the `'attachment'` in
     * `$name === '' ? 'attachment' : $name`, is a display fallback in these files.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function isTernaryFallback(array $toks, int $i): bool
    {
        $word = $toks[$i]['text'];

        return ($toks[$i - 1]['id'] ?? null) === '?'
            && ($toks[$i + 1]['id'] ?? null) === ':'
            && \preg_match('/^[\'"][A-Za-z]{3,}[\'"]$/', $word) === 1;
    }

    /** @param list<array{id: int|string, text: string, line: int}> $toks */
    private static function isArrayKey(array $toks, int $i): bool
    {
        return ($toks[$i + 1]['id'] ?? null) === \T_DOUBLE_ARROW;
    }

    /**
     * True when a call enclosing token $i is a diagnostic one. Walks outward
     * through every unmatched open parenthesis.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function insideDiagnosticCall(array $toks, int $i): bool
    {
        $depth = 0;
        for ($k = $i - 1; $k >= 0; $k--) {
            $id = $toks[$k]['id'];
            if ($id === ')') {
                $depth++;
                continue;
            }
            if ($id !== '(') {
                continue;
            }
            if ($depth > 0) {
                $depth--;
                continue;
            }
            if (self::isDiagnosticCall($toks, $k)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `->log->info(`, or the constructor of `new NoSessionIdException(`. Any other
     * exception message reaches the chat: the core's failureReason() returns
     * getMessage(), and the router maps only NoSessionIdException to catalog copy.
     * A method whose name merely ends in `Error` or `Exception` (`$this->sendError(`)
     * is user-facing and is not exempt.
     *
     * @param list<array{id: int|string, text: string, line: int}> $toks
     */
    private static function isDiagnosticCall(array $toks, int $open): bool
    {
        $callee = $toks[$open - 1]['text'] ?? '';
        $isNew = ($toks[$open - 2]['id'] ?? null) === \T_NEW;
        $isMappedException = $isNew && \preg_match('/(^|\\\\)NoSessionIdException$/', $callee) === 1;
        $isLog = ($toks[$open - 1]['id'] ?? null) === \T_STRING
            && ($toks[$open - 2]['id'] ?? null) === \T_OBJECT_OPERATOR
            && ($toks[$open - 3]['id'] ?? null) === \T_STRING
            && ($toks[$open - 3]['text'] ?? '') === 'log';

        return $isMappedException || $isLog;
    }
}
