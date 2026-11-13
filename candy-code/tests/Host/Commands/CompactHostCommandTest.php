<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\CompactHostCommand;
use SugarCraft\Code\Lang;

final class CompactHostCommandTest extends TestCase
{
    public function testAReadOnlySessionRefusesWithTheCommandName(): void
    {
        $result = (new CompactHostCommand())->run(CommandContext::new(readOnly: true), '/compact');

        self::assertTrue($result->isRefused());
        self::assertSame(Lang::t('host.command.read_only', ['name' => '/compact']), $result->error);
        self::assertSame([], $result->effects);
    }

    public function testSelfIsAClientOnlyRouteAnsweredWithAReply(): void
    {
        foreach (['/compact --self', '/compact --SELF'] as $draft) {
            $result = (new CompactHostCommand())->run(CommandContext::new(), $draft);

            self::assertSame(Lang::t('host.compact.self_client_only'), $result->rows[1]->content, $draft);
            self::assertSame([], $result->effects, $draft);
        }
    }

    public function testThePlainFormAsksTheDriverToFoldTheTranscript(): void
    {
        $result = (new CompactHostCommand())->run(CommandContext::new(), '/compact focus on auth');

        self::assertSame([], $result->rows, 'the driver writes the echo and report rows inside the folded history');
        $effect = $result->effect(CommandEffectKind::CompactTranscript);
        self::assertNotNull($effect);
        self::assertSame('/compact focus on auth', $effect->compactText());
    }
}
