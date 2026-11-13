<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\Tests\Fixture\FaceHarness;
use PHPUnit\Framework\TestCase;

/**
 * `groups.rows.<conversationId>.allowFrom`: a per-user list inside one served
 * group. Listed users reach the router, unlisted users are dropped before any
 * command or prompt is read, and an unset (or empty) list changes nothing.
 * The router is over a dead serve, so "reached the router" shows up as the
 * session-open failure the conversation hears.
 */
final class GroupAllowFromTest extends TestCase
{
    use FaceHarness;

    private const SESSION_OPEN_FAILURE = 'Could not open a session';

    protected function setUp(): void
    {
        $this->bootFixtures();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixtures();
    }

    public function testAListedUserMayPromptTheGroup(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist', 'allowFrom' => ['u1']]]]);
        $this->face->handleActivity($this->mention('u1', '<at>candy</at> hello'));
        $this->settle(600);

        self::assertTrue($this->postedContaining(self::SESSION_OPEN_FAILURE), 'a listed user reached the router');
    }

    public function testAnUnlistedUserIsDroppedAndTheirCommandsToo(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist', 'allowFrom' => ['u1']]]]);
        $this->face->handleActivity($this->mention('u2', '<at>candy</at> hello'));
        $this->face->handleActivity($this->mention('u2', '<at>candy</at> /help'));
        $this->settle(600);

        self::assertSame([], $this->sentTexts(), 'nothing from an unlisted user, prompt or command');
    }

    public function testAnUnsetListChangesNothing(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist']]]);
        $this->face->handleActivity($this->mention('u2', '<at>candy</at> hello'));
        $this->settle(600);

        self::assertTrue($this->postedContaining(self::SESSION_OPEN_FAILURE), 'with no allowFrom, any peer may prompt as before');
    }

    public function testAnEmptyListIsTheSameAsUnset(): void
    {
        $this->buildFace(['groups' => ['conv-1' => ['policy' => 'allowlist', 'allowFrom' => []]]]);
        $this->face->handleActivity($this->mention('u2', '<at>candy</at> hello'));
        $this->settle(600);

        self::assertTrue($this->postedContaining(self::SESSION_OPEN_FAILURE));
    }

    public function testTheListIsPerGroupNotGlobal(): void
    {
        $this->buildFace(['groups' => [
            'conv-1' => ['policy' => 'allowlist', 'allowFrom' => ['u1']],
            'conv-2' => ['policy' => 'allowlist'],
        ]]);
        $other = $this->mention('u2', '<at>candy</at> hello');
        $other['conversation']['id'] = 'conv-2';
        $this->face->handleActivity($other);
        $this->settle(600);

        self::assertTrue($this->postedContaining(self::SESSION_OPEN_FAILURE), 'the restriction names conv-1 only');
    }

    /** A group message that mentions the bot, the only shape the group door admits by default. */
    private function mention(string $fromId, string $text): array
    {
        return $this->activity($fromId, $text, 'groupChat', [
            ['type' => 'mention', 'mentioned' => ['user' => ['id' => '28:' . self::HARNESS_APP_ID]]],
        ]);
    }

    private function postedContaining(string $needle): bool
    {
        foreach ($this->sentTexts() as $text) {
            if (\str_contains($text, $needle)) {
                return true;
            }
        }

        return false;
    }
}
