<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Tests\Support\CheckpointedRepoTrait;

/**
 * Item 3.A-2: `/rewind [n] --chat|--files|--both` — the scope words, the
 * file restore offered only when it would change something, the refusal
 * once HEAD has moved, and the home-directory refusal.
 *
 * @see Chat::handleRewindCommand()
 */
final class RewindFilesTest extends TestCase
{
    use CheckpointedRepoTrait;

    protected function setUp(): void
    {
        $this->setUpCheckpointedRepo();
    }

    protected function tearDown(): void
    {
        $this->tearDownCheckpointedRepo();
    }

    public function testAConversationRewindLeavesTheFilesAndOffersThemOnlyWhenTheyDiffer(): void
    {
        $chat = $this->oneTurn("edited by the turn\n");

        $rewound = $this->send('/rewind', $chat);

        self::assertSame([], self::conversation($rewound));
        self::assertSame('first', $rewound->inputBuf);
        self::assertSame("edited by the turn\n", $this->file('file.txt'), '--chat is the default and touches no file');
        self::assertStringContainsString('1 file differs from that checkpoint — `/rewind --files` puts it back too', self::reply($rewound));

        // The offered command: right after the rewind, --files counts from
        // the checkpoint the conversation is at.
        $restored = $this->send('/rewind --files', $rewound);
        self::assertSame("base\n", $this->file('file.txt'));
        self::assertSame([], self::conversation($restored), 'the conversation is left as it is');
        self::assertStringStartsWith('Restored the files to checkpoint 0: 1 rewritten, 0 deleted.', self::reply($restored));
    }

    public function testNoOfferWhenTheFilesAlreadyMatch(): void
    {
        $chat = $this->oneTurn("base\n");

        $reply = self::reply($this->send('/rewind', $chat));

        self::assertStringStartsWith('Rewound', $reply);
        self::assertStringNotContainsString('--files', $reply);
    }

    public function testBothRestoresTheConversationAndTheFilesInEitherWordOrder(): void
    {
        $chat = $this->oneTurn("edited\n");

        $rewound = $this->send('/rewind --both 1', $chat);

        self::assertSame([], self::conversation($rewound));
        self::assertSame("base\n", $this->file('file.txt'));
        self::assertStringContainsString('Restored the files: 1 rewritten, 0 deleted.', self::reply($rewound));
    }

    public function testFilesAloneTouchesNoCheckpointRow(): void
    {
        $chat = $this->oneTurn("edited\n");

        $restored = $this->send('/rewind --files', $chat);

        self::assertSame("base\n", $this->file('file.txt'));
        self::assertSame(['first', 'one'], self::conversation($restored));
        self::assertCount(1, $this->store->listCheckpoints('undo-session'));
        self::assertSame([], $this->store->redoStack('undo-session'));

        self::assertSame(
            'The files already match checkpoint 0; nothing was restored.',
            self::reply($this->send('/rewind --files', $restored)),
        );
    }

    public function testAMovedHeadRefusesAFileRewindAndChangesNothing(): void
    {
        $chat = $this->oneTurn("committed by the turn\n");
        $this->gitAt('commit', '-q', '-am', 'the turn committed');

        foreach (['/rewind --both', '/undo'] as $command) {
            $refused = $this->send($command, $chat);

            self::assertStringStartsWith('Nothing was rewound: the files cannot be restored to checkpoint 0 — HEAD has moved', self::reply($refused), $command);
            self::assertSame(['first', 'one'], self::conversation($refused), $command);
            self::assertSame("committed by the turn\n", $this->file('file.txt'), $command);
            self::assertSame([], $this->store->redoStack('undo-session'), $command . ' set nothing aside');
        }

        self::assertStringStartsWith('Nothing was restored: HEAD has moved', self::reply($this->send('/rewind --files', $chat)));

        // The conversation alone still rewinds, and offers nothing it could not do.
        $rewound = $this->send('/rewind --chat', $chat);
        self::assertSame([], self::conversation($rewound));
        self::assertStringNotContainsString('--files', self::reply($rewound));
    }

    public function testAProjectInTheHomeDirectoryHasNoFilesToRestore(): void
    {
        $home = (string) getenv('HOME');
        file_put_contents($home . '/notes.txt', "mine\n");
        $index = $this->store->saveCheckpoint('undo-session', ['messages' => [], 'messagesPrecedePrompt' => true, 'inputBuf' => 'first']);
        $workspace = $this->store->captureWorkspace('undo-session', $index, $home);
        self::assertSame('refused', $workspace['status']);

        $reply = self::reply($this->send('/rewind --files', [Message::user('first')]));

        self::assertSame('Nothing was restored: checkpoint 0 has no file snapshot — no snapshot was taken for that checkpoint (checkpoints are not taken in the home directory).', $reply);
        self::assertSame("mine\n", file_get_contents($home . '/notes.txt'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badArguments(): iterable
    {
        yield 'two scopes' => ['/rewind --files --chat'];
        yield 'two counts' => ['/rewind 2 3'];
        yield 'unknown flag' => ['/rewind --all'];
        yield 'zero' => ['/rewind 0 --files'];
        yield 'word' => ['/rewind last'];
    }

    /**
     * @dataProvider badArguments
     */
    public function testAnythingElseIsUsageAndRewindsNothing(string $command): void
    {
        $chat = $this->oneTurn("edited\n");

        $answered = $this->send($command, $chat);

        self::assertStringStartsWith('Usage: /rewind [n] [--chat|--files|--both]', self::reply($answered));
        self::assertSame(['first', 'one'], self::conversation($answered));
        self::assertSame("edited\n", $this->file('file.txt'));
    }

    /** One turn that left file.txt as $after. */
    private function oneTurn(string $after): Chat
    {
        $this->turnCheckpoint([], 'first');
        file_put_contents($this->repo . '/file.txt', $after);

        return $this->chatAt('', [Message::user('first'), Message::assistant('one')]);
    }
}
