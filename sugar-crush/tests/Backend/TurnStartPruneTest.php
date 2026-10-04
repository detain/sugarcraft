<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\Pruning\RefTag;
use SugarCraft\Crush\Context\TurnContextBlock;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Tests\Support\ScriptedProvider;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 3.B-2, in the engine: a turn of an `auto` session starts by
 * pruning what the conversation has already superseded (one batch, worth a
 * cache rewrite), a `manual` session's never does, and the tool results a
 * request sends carry their ref tags unless the session's mode is `off`.
 */
final class TurnStartPruneTest extends TestCase
{
    private ?string $root = null;

    protected function tearDown(): void
    {
        if ($this->root !== null) {
            @rmdir($this->root);
        }
    }

    public function testAnAutoSessionStartsItsTurnWithoutTheSupersededRows(): void
    {
        $provider = self::provider();

        $reply = $this->engine($provider)->withContextLedger(ContextLedger::new()->withDefaultMode(PruningMode::Auto))->complete(self::history());

        $wire = self::wire($provider);
        $this->assertStringNotContainsString('state one', $wire, 'superseded at the turn start');
        $this->assertStringNotContainsString('state two', $wire);
        $this->assertStringContainsString('state three', $wire, 'the newest state the history holds is kept');
        $this->assertTrue($reply->contextLedger?->dropsContextRow(self::contextRow('one')), 'and the next turn starts from that prune');
    }

    public function testAManualSessionPrunesNothingOnItsOwnButShowsRefs(): void
    {
        $provider = self::provider();

        $this->engine($provider)->withContextLedger(ContextLedger::new()->withMode(PruningMode::Manual))->complete(self::history());

        $this->assertStringContainsString('state one', self::wire($provider));
        $this->assertSame(RefTag::appendTo('a', 1), self::sentResult($provider, 'old'));
    }

    public function testAnOffSessionShowsNoRefsAndAHostWithoutALedgerSendsTheRowsAsTheyAre(): void
    {
        $off = self::provider();
        $this->engine($off)->withContextLedger(ContextLedger::new()->withMode(PruningMode::Off))->complete(self::history());
        $this->assertSame('a', self::sentResult($off, 'old'));
        $this->assertStringContainsString('state one', self::wire($off));

        $none = self::provider();
        $this->engine($none)->complete(self::history());
        $this->assertSame('a', self::sentResult($none, 'old'));
        $this->assertStringContainsString('state one', self::wire($none));
    }

    private function engine(ScriptedProvider $provider): EngineBackend
    {
        $this->root ??= sys_get_temp_dir() . '/crush-turn-start-' . bin2hex(random_bytes(6));
        if (!is_dir($this->root)) {
            mkdir($this->root, 0o700, true);
        }

        return EngineBackend::new($provider, 'm')->withoutHooks()->withRoot($this->root);
    }

    private static function provider(): ScriptedProvider
    {
        return new ScriptedProvider([new CompleteResponse(content: 'done')], contextWindow: 10_000_000);
    }

    /** An earlier turn with one read, three stored state rows of ~12k tokens each, then the prompt. */
    private static function history(): array
    {
        return [
            Message::user('read a.php'),
            Message::user(self::contextRow('one'))->withUserVisible(false),
            Message::assistant('')->withToolCalls([new ToolCall('Read', ['file_path' => 'a.php'], 'old')])->withStepId('s_x_1')->withUserVisible(false),
            Message::assistant('a')->withToolResults([new ToolResult('Read', 'a', null, 'old')])->withStepId('s_x_1'),
            Message::user(self::contextRow('two'))->withUserVisible(false),
            Message::assistant('read it')->withStepId('s_x_2'),
            Message::user(self::contextRow('three'))->withUserVisible(false),
            Message::user('next'),
        ];
    }

    private static function contextRow(string $state): string
    {
        return TurnContextBlock::FENCE . "\nstate {$state}" . str_repeat(' word', 12_000) . "\n</turn-context>";
    }

    private static function wire(ScriptedProvider $provider): string
    {
        return implode("\n", array_map(static fn ($m): string => $m->content(), $provider->requests[0]->messages));
    }

    private static function sentResult(ScriptedProvider $provider, string $id): ?string
    {
        foreach ($provider->requests[0]->messages as $message) {
            if ($message instanceof ToolResultMessage && $message->toolCallId() === $id) {
                return $message->content();
            }
        }

        return null;
    }
}
