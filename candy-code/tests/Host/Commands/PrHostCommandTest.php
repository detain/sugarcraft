<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Commands\PrCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandEffectKind;
use SugarCraft\Code\Host\Commands\PrHostCommand;

/**
 * C-AGT-6.d: `/pr` on a served session. The body is the two pieces a headless
 * driver applies — the command's echo row and the submit-prompt effect that
 * carries the canned prompt — and the read-only refusal, because `/pr`
 * pushes and posts and a read-locked window must not see either.
 */
final class PrHostCommandTest extends TestCase
{
    public function testTheEchoRowRidesBeforeThePromptEffect(): void
    {
        $result = (new PrHostCommand())->run(CommandContext::new(history: []), '/pr watch the migrations');

        self::assertFalse($result->isRefused());
        self::assertCount(1, $result->rows, 'the command posts only its own echo row');
        self::assertSame('/pr watch the migrations', $result->rows[0]->content);

        $effect = $result->effect(CommandEffectKind::SubmitPrompt);
        self::assertNotNull($effect, 'the canned prompt rides the submit effect');
        self::assertSame(
            PrCommand::prompt('watch the migrations'),
            $effect->promptText(),
            'the served prompt is the same text the terminal handler submits'
        );
    }

    public function testABareSlashPrCarriesThePromptWithNoFocus(): void
    {
        $result = (new PrHostCommand())->run(CommandContext::new(history: []), '/pr');

        $effect = $result->effect(CommandEffectKind::SubmitPrompt);
        self::assertNotNull($effect);
        self::assertSame(PrCommand::prompt(), $effect->promptText());
    }

    public function testAReadOnlySessionRefusesTheCommand(): void
    {
        $context = CommandContext::new(history: [], readOnly: true);

        $result = (new PrHostCommand())->run($context, '/pr');

        self::assertTrue($result->isRefused(), '/pr pushes and posts; a read-locked window refuses');
        self::assertStringContainsString('/pr was not run', (string) $result->error);
        self::assertNull($result->effect(CommandEffectKind::SubmitPrompt), 'the refusal starts no turn');
    }
}
