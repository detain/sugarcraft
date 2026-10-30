<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\StatusLineSession;

/**
 * THE SHAPE OF THE DOCUMENT A `statusLine` COMMAND FINDS ON ITS STDIN
 * (GAP-UI-23). {@see StatusLineSession} is pure, so everything here is
 * byte-level: the pinned literal is the contract Claude Code's statusline
 * scripts parse, and the candy_code block is this app's named superset.
 *
 * WHAT THIS DOES NOT RE-TEST: that the bytes ever REACH a child — that is
 * {@see StatusLineCommandTest}'s `cat` round-trip, its early-exit pin, and
 * its structural write-before-close pin. This file proves the document is
 * well-formed, complete, and small; that file proves the pipe honors it.
 */
final class StatusLineSessionTest extends TestCase
{
    private static function session(
        ?string $sessionId = 'ses_1',
        ?string $cwd = '/home/u/project',
        ?string $modelId = 'claude-sonnet-4-5',
        ?string $projectDir = '/home/u',
        string $version = '0.1.0',
        ?float $totalCostUsd = 1.25,
        ?string $provider = 'anthropic',
        ?string $permissionMode = 'default',
        int $contextTokens = 12345,
        int $contextPercent = 42,
        ?string $branch = 'main',
    ): StatusLineSession {
        return new StatusLineSession(
            sessionId: $sessionId,
            cwd: $cwd,
            modelId: $modelId,
            projectDir: $projectDir,
            version: $version,
            totalCostUsd: $totalCostUsd,
            provider: $provider,
            permissionMode: $permissionMode,
            contextTokens: $contextTokens,
            contextPercent: $contextPercent,
            branch: $branch,
        );
    }

    // =====================================================================
    // THE CONTRACT — one literal, so a renamed key cannot survive
    // =====================================================================

    public function testTheWholeDocumentIsThePinnedLiteral(): void
    {
        self::assertSame(
            '{"session_id":"ses_1","cwd":"/home/u/project",'
            . '"model":{"id":"claude-sonnet-4-5","display_name":"claude-sonnet-4-5"},'
            . '"workspace":{"current_dir":"/home/u/project","project_dir":"/home/u"},'
            . '"version":"0.1.0","cost":{"total_cost_usd":1.25},'
            . '"candy_code":{"provider":"anthropic","permission_mode":"default",'
            . '"context_tokens":12345,"context_percent":42,"branch":"main"}}',
            self::session()->toJson(),
        );
    }

    public function testTheTwoModelFieldsCarryTheSameString(): void
    {
        // Pinned on purpose, disclosed on purpose: display_name is not a
        // second label — this app has one name per model, and the class says
        // so. A future WITH-display-name gathering must decide here, where
        // the decision is visible, not silently in one of the two slots.
        $decoded = json_decode(self::session()->toJson(), true);

        self::assertSame('claude-sonnet-4-5', $decoded['model']['id']);
        self::assertSame($decoded['model']['id'], $decoded['model']['display_name']);
    }

    // =====================================================================
    // PRE-SESSION — every key present, every unknown null, never an []
    // =====================================================================

    public function testAnUnknownSessionStillAnswersACompleteDocument(): void
    {
        $json = self::session(
            sessionId: null,
            cwd: null,
            modelId: null,
            projectDir: null,
            totalCostUsd: null,
            provider: null,
            permissionMode: null,
            branch: null,
        )->toJson();

        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        self::assertSame(
            ['session_id', 'cwd', 'model', 'workspace', 'version', 'cost', 'candy_code'],
            array_keys($decoded),
        );
        self::assertNull($decoded['session_id']);
        self::assertNull($decoded['cwd']);
        self::assertSame(['id', 'display_name'], array_keys($decoded['model']));
        self::assertNull($decoded['model']['id']);
        self::assertNull($decoded['model']['display_name'], 'display_name must stay null, not become an empty string dressed as data');
        self::assertSame(['current_dir', 'project_dir'], array_keys($decoded['workspace']));
        self::assertNull($decoded['workspace']['project_dir']);
        self::assertSame(['total_cost_usd'], array_keys($decoded['cost']));
        self::assertNull($decoded['cost']['total_cost_usd'], 'an unmeasured spend must not serialise as a number a script can bank on');
        self::assertStringContainsString('"cost":' . chr(123) . '"total_cost_usd":null' . chr(125), $json);
        self::assertSame(
            ['provider', 'permission_mode', 'context_tokens', 'context_percent', 'branch'],
            array_keys($decoded['candy_code']),
        );
        self::assertNull($decoded['candy_code']['provider']);

        // THE [] CLASS OF BUG, pinned in the raw bytes: an empty PHP array
        // serialises as `[]`, and a script doing `.model.id` would read the
        // pair as a list. Every object above is built from fixed literal
        // keys, so no path can produce one — but a future refactor to a
        // conditional nest would, and this leg is what reddens it. The
        // needles are assembled from chr() legs on purpose: a bare
        // bracket-bearing literal is glob-shaped and would drift the
        // PathGlob corpus census (GlobDialect law).
        self::assertStringNotContainsString(':' . chr(91) . chr(93), $json);
        self::assertStringNotContainsString(':' . chr(91), $json);
    }

    // =====================================================================
    // THE FLAGS — paths read as paths, unicode stays readable, one bad
    // byte cannot poison the document
    // =====================================================================

    public function testSlashesAndUnicodeAreNotEscaped(): void
    {
        $json = self::session(
            modelId: 'glm-4.6:日本',
            branch: 'feature/x',
        )->toJson();

        self::assertStringContainsString('"branch":"feature/x"', $json);
        self::assertStringContainsString('glm-4.6:日本', $json);
        self::assertStringNotContainsString('\\/', $json);
        self::assertStringNotContainsString('\u65e5', $json);
    }

    public function testAByteThatIsNotValidUtf8BecomesTheReplacementCharacter(): void
    {
        // A branch or directory name carrying a non-UTF-8 byte is external
        // data this app does not control. WITHOUT
        // JSON_INVALID_UTF8_SUBSTITUTE json_encode() returns FALSE here and
        // the command reads an empty stdin — the poison-the-whole-document
        // failure the flag exists to refuse.
        $json = self::session(branch: "bad\xffbranch")->toJson();

        self::assertNotFalse(json_decode($json));
        self::assertStringContainsString("\u{fffd}", $json);
        self::assertStringContainsString('bad', $json);
        self::assertStringContainsString('branch', $json);
    }

    // =====================================================================
    // THE NUMBERS — the cost must survive the round trip
    // =====================================================================

    public function testTheCostSurvivesTheRoundTripInBothExtremes(): void
    {
        $spent = json_decode(self::session(totalCostUsd: 12.345)->toJson(), true);
        self::assertSame(12.345, $spent['cost']['total_cost_usd']);

        // PHP's shortest round-trip serialisation writes whole floats as bare
        // integers (`0.0` arrives as `0`, measured) — still a JSON number,
        // still numerically zero. What the script consumes is the VALUE, so
        // the value is what is pinned; the spelling is serialize_precision's
        // business, not this contract's.
        $zero = json_decode(self::session(totalCostUsd: 0.0)->toJson(), true);
        self::assertSame(0, $zero['cost']['total_cost_usd']);
    }

    public function testASpendNothingBilledIsAbsentFromTheDocumentAsANumber(): void
    {
        // THE MONEY LAW, in the payload's own shape. `0.0` and null are the
        // same two bytes wide to a script that adds them up, and only one of
        // them is honest about a backend that reports tokens but has no
        // pricing — the case `Renderer::spendIndicator()` refuses to paint as
        // `$0.0000`. The pinned raw needle is what reddens a revert to a
        // non-nullable float: with the field non-nullable the document says
        // `"total_cost_usd":0` and neither leg below can hold.
        $json = self::session(totalCostUsd: null)->toJson();
        $decoded = json_decode($json, true);

        self::assertNull($decoded['cost']['total_cost_usd']);
        self::assertStringNotContainsString('"total_cost_usd":0', $json);
    }

    // =====================================================================
    // THE SIZE — the write is one step BECAUSE the document is bounded
    // =====================================================================

    public function testTheDocumentStaysFarInsideTheKernelPipeBuffer(): void
    {
        // StatusLineCommand::run() writes the whole payload in ONE fwrite()
        // before the deadline is armed, which cannot stall only while the
        // document stays far under the ~64 KiB pipe buffer. The absurd
        // fields below are the practical ceilings of what can be gathered:
        // PATH_MAX paths, the filesystem's longest single name, and a git
        // refname-sized branch. A real session is a few hundred bytes.
        $path = '/home/u/' . str_repeat('d', 255);
        $deep = $path . '/' . str_repeat('x', 4000);

        $json = self::session(
            sessionId: str_repeat('s', 255),
            cwd: $deep,
            modelId: str_repeat('m', 255),
            projectDir: $deep,
            version: str_repeat('v', 255),
            provider: str_repeat('p', 255),
            permissionMode: str_repeat('a', 255),
            branch: str_repeat('b', 255),
        )->toJson();

        self::assertLessThan(16384, strlen($json));
    }

    public function testToJsonIsDeterministicForTheSameSnapshot(): void
    {
        // The same figures must answer the same bytes twice: cmd() records
        // the document as the snapshot the sync refresh() path replays, and a
        // key order that moved between calls would make the cached line's
        // provenance untraceable.
        $session = self::session();

        self::assertSame($session->toJson(), $session->toJson());
    }
}
