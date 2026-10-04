<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Workspace\CommitMessageWriter;

/**
 * Step 3.G: the auto-commit subject — Aider's commit prompt for the title
 * model, a model answer reduced to one safe Conventional-Commits line, and the
 * plain subjects used when there is no model to ask.
 *
 * @see CommitMessageWriter
 */
final class CommitMessageWriterTest extends TestCase
{
    public function testTheRequestIsAidersPromptThenTheContextAndTheDiff(): void
    {
        [$system, $user] = CommitMessageWriter::request("+added\n", 'make it so');

        self::assertSame(Role::System, $system->role);
        self::assertStringContainsString('<type>: <description>', $system->content);
        self::assertStringContainsString('fix, feat, build, chore, ci, docs, style, refactor, perf, test', $system->content);
        self::assertStringContainsString('Does not exceed 72 characters.', $system->content);
        self::assertSame(Role::User, $user->role);
        self::assertSame("<context>\nmake it so\n</context>\n\n<diff>\n+added\n\n</diff>", $user->content);

        [, $cut] = CommitMessageWriter::request(str_repeat('x', CommitMessageWriter::MAX_DIFF_BYTES + 500));
        self::assertStringContainsString('(diff cut at', $cut->content);
        self::assertLessThan(CommitMessageWriter::MAX_DIFF_BYTES + 200, \strlen($cut->content));
    }

    public function testAModelReplyBecomesOneSafeTypedLine(): void
    {
        self::assertSame('feat: add retry policy', CommitMessageWriter::subjectFrom('feat: add retry policy'));
        self::assertSame('fix(api): handle 404', CommitMessageWriter::subjectFrom("<think>hmm</think>\n\n`fix(api): handle 404`\nmore text"));
        self::assertSame('refactor: split the loader', CommitMessageWriter::subjectFrom('Commit message: "refactor: split the loader"'));
        self::assertSame('chore: tidy things up', CommitMessageWriter::subjectFrom('tidy things up'), 'an untyped reply is typed chore');
        self::assertSame('feat: x', CommitMessageWriter::subjectFrom("feat:\e]52;c;evil\x07 x"));
        self::assertNull(CommitMessageWriter::subjectFrom("  \n```\n"));

        $long = CommitMessageWriter::subjectFrom('feat: ' . str_repeat('word ', 40));
        self::assertNotNull($long);
        self::assertLessThanOrEqual(CommitMessageWriter::MAX_SUBJECT_CHARS, mb_strlen($long));
        self::assertStringEndsWith('…', $long);
    }

    public function testThePlainSubjectsAreTypedByThePathsTheyTouch(): void
    {
        self::assertSame('chore: update src/App.php', CommitMessageWriter::fallback(['src/App.php']));
        self::assertSame('chore: update 3 files', CommitMessageWriter::fallback(['a.php', 'b.php', 'c.php']));
        self::assertSame('test: update tests/AppTest.php', CommitMessageWriter::fallback(['tests/AppTest.php']));
        self::assertSame('docs: update README.md', CommitMessageWriter::fallback(['README.md']));

        self::assertSame('chore: rename the legacy config helper', CommitMessageWriter::fromDescription('Rename the legacy config helper.', ['src/Config.php']));
        self::assertSame('test: cover the empty case', CommitMessageWriter::fromDescription('Cover the empty case', ['tests/EmptyTest.php']));
        self::assertSame('fix: keep a typed description', CommitMessageWriter::fromDescription('fix: keep a typed description', ['a.php']));
        self::assertSame('chore: update a.php', CommitMessageWriter::fromDescription('  ', ['a.php']));
    }
}
