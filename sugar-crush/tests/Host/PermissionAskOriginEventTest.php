<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Host;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Backend\PendingAsk;
use SugarCraft\Crush\Events\PermissionAsked;
use SugarCraft\Crush\Host\SessionEvent;
use SugarCraft\Crush\Host\TranscriptProjector;
use SugarCraft\Crush\Permissions\AskOrigin;
use SugarCraft\Crush\Protocol\EventType;
use SugarCraft\Crush\Protocol\Schema\EventSchemas;
use SugarCraft\Crush\Protocol\Schema\ProtocolSchema;

/**
 * W9 integration (W9-f handoff, P-E2 remainder): a question a delegated run
 * put carries its origin over the wire and into the session's event log, so a
 * web client's approvals drawer can say which sub-agent is asking, as the
 * TUI's agent view does.
 */
final class PermissionAskOriginEventTest extends TestCase
{
    public function testARelayedQuestionCarriesItsOriginAndTheTurnsOwnCarriesNone(): void
    {
        $projector = TranscriptProjector::new();

        $relayed = $projector->backendEvent(new PermissionAsked(self::ask(new AskOrigin('a7', 'reviewer', 'call_task_1'))), 's', 't');
        self::assertNotNull($relayed);
        self::assertSame(SessionEvent::PERMISSION_REQUESTED, $relayed->type);
        self::assertSame(['agentId' => 'a7', 'agentName' => 'reviewer', 'parentCallId' => 'call_task_1'], $relayed->data['origin']);

        $own = $projector->backendEvent(new PermissionAsked(self::ask(null)), 's', 't');
        self::assertNotNull($own);
        self::assertArrayNotHasKey('origin', $own->data);

        foreach ([$relayed, $own] as $event) {
            $schema = EventSchemas::data(SessionEvent::PERMISSION_REQUESTED);
            self::assertNotNull($schema);
            self::assertSame([], ProtocolSchema::errors($event->data, $schema), 'the payload fits permission.requested');
            $server = EventSchemas::data(EventType::PERMISSION_ASKED);
            self::assertNotNull($server);
            self::assertSame([], ProtocolSchema::errors(['sessionId' => 's', ...$event->data], $server), 'and permission.asked');
        }

        $back = PendingAsk::fromFrame([...$relayed->data, 'suggestions' => $relayed->data['options']], static function (): void {
        });
        self::assertSame('reviewer', $back?->origin?->agentName, 'a remote terminal reads it back');
    }

    private static function ask(?AskOrigin $origin): PendingAsk
    {
        return new PendingAsk(
            PendingAsk::askId('c1', 'Bash', ['command' => 'rm x']),
            'c1',
            'Bash',
            ['command' => 'rm x'],
            'Bash wants to run rm x',
            'gate',
            'default',
            ['once', 'always', 'reject'],
            ['tool' => 'Bash'],
            static function (): void {
            },
            $origin,
        );
    }
}
