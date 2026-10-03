<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolResult;

/**
 * Audit 0.7: the compactor's file-read (stage 4) and navigation (stage 5)
 * stages key on the tool row — the `tool` key {@see Chat::compactionWire()}
 * stamps from a row's {@see ToolResult} — and never on a guess from the text.
 *
 * The regexes this replaces were unanchored and multi-line, so a user prompt
 * with a line starting `ls` or `cd` was deleted from the history, and any row
 * that looked like code was rewritten into a `[file: …]` line.
 */
final class CompactorToolRowKeyingTest extends TestCase
{
    private function compactor(int $preserve = 10): ContextCompactor
    {
        return new ContextCompactor(new CompactorConfig(
            reminderThreshold: 70,
            backgroundCompactionThreshold: 85,
            foregroundBlockingThreshold: 95,
            recentPreserveCount: $preserve,
        ));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{role:string,content:string,tool:array{name:string,arguments:array<string,mixed>,error:bool}}
     */
    private function toolRow(string $name, array $arguments, string $content, bool $error = false): array
    {
        return [
            'role' => 'assistant',
            'content' => $content,
            'tool' => ['name' => $name, 'arguments' => $arguments, 'error' => $error],
        ];
    }

    // ─── stage 5: navigation ─────────────────────────────────────

    public function testAUserRowThatStartsALineWithANavCommandIsNeverDropped(): void
    {
        $messages = [
            ['role' => 'user', 'content' => "ls the files\ncd later"],
            ['role' => 'user', 'content' => 'pwd'],
            ['role' => 'user', 'content' => 'rm the old build please'],
        ];

        $this->assertSame($messages, $this->compactor()->removeNavigationSteps($messages));
    }

    public function testAnAssistantReplyShowingACommandIsNotNavigation(): void
    {
        $messages = [
            ['role' => 'assistant', 'content' => "Run this:\ncd src\nls -la"],
            ['role' => 'assistant', 'content' => 'cd /tmp'],
        ];

        $this->assertSame($messages, $this->compactor()->removeNavigationSteps($messages));
    }

    public function testOnlyALoneCdLsOrPwdBashCallIsDropped(): void
    {
        $kept = [
            $this->toolRow('Bash', ['command' => 'cd src && rm -rf build'], ''),
            $this->toolRow('Bash', ['command' => 'ls; make'], ''),
            $this->toolRow('Bash', ['command' => "ls\nrm -rf x"], ''),
            $this->toolRow('Bash', ['command' => 'ls $(cat list)'], ''),
            $this->toolRow('Bash', ['command' => 'ls > out.txt'], ''),
            $this->toolRow('Bash', ['command' => 'mkdir build'], ''),
            $this->toolRow('Bash', ['command' => 'rm -rf build'], ''),
            $this->toolRow('Bash', ['command' => 'mv a b'], ''),
            $this->toolRow('Bash', ['command' => 'cp a b'], ''),
            $this->toolRow('Bash', ['command' => 'lsof -i'], ''),
            $this->toolRow('Glob', ['pattern' => 'ls'], 'ls'),
        ];
        $dropped = [
            $this->toolRow('Bash', ['command' => 'cd /var/www'], ''),
            $this->toolRow('Bash', ['command' => '  ls -la src/  '], "a\nb"),
            $this->toolRow('Bash', ['command' => 'pwd'], '/home/x'),
            $this->toolRow('Bash', ['command' => 'cd'], ''),
        ];

        $result = $this->compactor()->removeNavigationSteps([...$kept, ...$dropped]);

        $this->assertSame($kept, $result);
    }

    public function testAFailedNavigationCallIsKept(): void
    {
        $row = $this->toolRow('Bash', ['command' => 'cd nowhere'], 'Tool error: no such directory', true);

        $this->assertSame([$row], $this->compactor()->removeNavigationSteps([$row]));
    }

    public function testDroppingANavigationRowLeavesItsNeighboursInPlace(): void
    {
        $messages = [
            ['role' => 'user', 'content' => 'where are we?'],
            $this->toolRow('Bash', ['command' => 'pwd'], '/repo'),
            ['role' => 'assistant', 'content' => 'In the repo root.'],
        ];

        $this->assertSame(
            [$messages[0], $messages[2]],
            $this->compactor()->removeNavigationSteps($messages),
        );
    }

    // ─── stage 4: file reads ─────────────────────────────────────

    public function testCodeInAUserOrAssistantRowIsNotAFileRead(): void
    {
        $code = "<?php\n\ndeclare(strict_types=1);\n\nfinal class Foo\n{\n    private int \$x;\n}\n";
        $messages = [
            ['role' => 'user', 'content' => "Why does this fail?\n" . $code],
            ['role' => 'assistant', 'content' => "src/Foo.php\n" . $code],
            $this->toolRow('Grep', ['pattern' => 'Foo'], "src/Foo.php\n" . $code),
        ];

        $this->assertSame($messages, $this->compactor()->compactFileReferences($messages));
    }

    public function testAReadRowIsCompactedWhateverItsContentLooksLike(): void
    {
        $messages = [
            $this->toolRow('Read', ['file_path' => 'notes/plain.txt'], "just\nsome\nwords"),
        ];

        $result = $this->compactor()->compactFileReferences($messages);

        $this->assertSame(
            [['role' => 'assistant', 'content' => '[file: notes/plain.txt, 3 lines]']],
            $result,
        );
    }

    public function testAFailedReadIsNotCompacted(): void
    {
        $row = $this->toolRow('Read', ['file_path' => 'missing.php'], 'Tool error: no such file', true);

        $this->assertSame([$row], $this->compactor()->compactFileReferences([$row]));
    }

    public function testAReadRowWithNoRecordedPathIsNamedGenerically(): void
    {
        $result = $this->compactor()->compactFileReferences([$this->toolRow('Read', [], 'x')]);

        $this->assertSame('[file: file, 1 lines]', $result[0]['content']);
    }

    // ─── through compact(): the key survives pairing ─────────────

    public function testTheToolKeySurvivesPairingInEveryPosition(): void
    {
        $messages = [
            // Assistant half of a pair.
            ['role' => 'user', 'content' => 'read a'],
            $this->toolRow('Read', ['file_path' => 'a.php'], 'AAA-CONTENT'),
            // Standalone (the pair above is already answered).
            $this->toolRow('Read', ['file_path' => 'b.php'], 'BBB-CONTENT'),
            $this->toolRow('Bash', ['command' => 'ls'], 'LS-CONTENT'),
            ['role' => 'assistant', 'content' => 'done reading'],
            // Interleaved rider (a system row after an unanswered prompt).
            ['role' => 'user', 'content' => 'and c'],
            ['role' => 'system', 'content' => 'rider', 'tool' => ['name' => 'Read', 'arguments' => ['file_path' => 'c.php'], 'error' => false]],
            ['role' => 'assistant', 'content' => 'ok'],
            // Preserved tail.
            ['role' => 'user', 'content' => 'recent'],
            ['role' => 'assistant', 'content' => 'recent answer'],
        ];

        $result = $this->compactor(preserve: 1)->compact($messages);
        $all = implode("\n", array_column($result, 'content'));

        $this->assertStringNotContainsString('AAA-CONTENT', $all);
        $this->assertStringNotContainsString('BBB-CONTENT', $all);
        $this->assertStringNotContainsString('LS-CONTENT', $all);
        $this->assertStringContainsString('read a', $all);
        $this->assertStringContainsString('and c', $all);
        $this->assertStringContainsString('recent answer', $all);
    }

    public function testExchangesOfferedForSummaryKeepTheUsersNavLookingPrompt(): void
    {
        $messages = [
            ['role' => 'user', 'content' => "ls the files\ncd later"],
            ['role' => 'assistant', 'content' => 'Sure.'],
            ['role' => 'user', 'content' => 'recent'],
            ['role' => 'assistant', 'content' => 'recent answer'],
        ];

        $offered = $this->compactor(preserve: 1)->exchangesToSummarize($messages);

        $this->assertCount(1, $offered);
        $this->assertSame("ls the files\ncd later", $offered[0]['user']);
        $this->assertSame(ContextCompactor::exchangeKey("ls the files\ncd later", 'Sure.'), $offered[0]['key']);
    }

    // ─── the stamp itself ────────────────────────────────────────

    public function testCompactionWireStampsTheToolKeyOnToolRowsOnly(): void
    {
        $read = (new ToolResult('Read', '<?php echo 1;'))->withArguments(['file_path' => 'x.php']);
        $failed = new ToolResult('Bash', '', 'boom');
        $history = [
            Message::user('show x'),
            Message::assistant('<?php echo 1;')->withToolResults([$read]),
            Message::assistant('Tool error: boom')->withToolResults([$failed]),
            Message::assistant('That prints 1.'),
        ];

        $method = new \ReflectionMethod(Chat::class, 'compactionWire');
        $wire = $method->invoke(null, $history);

        $this->assertArrayNotHasKey('tool', $wire[0]);
        $this->assertSame(['name' => 'Read', 'arguments' => ['file_path' => 'x.php'], 'error' => false], $wire[1]['tool']);
        $this->assertSame(['name' => 'Bash', 'arguments' => [], 'error' => true], $wire[2]['tool']);
        $this->assertArrayNotHasKey('tool', $wire[3]);

        // Content is untouched, so exchange keys are what they were.
        $this->assertSame('<?php echo 1;', $wire[1]['content']);
    }

    public function testTheProviderWireCarriesNoToolKey(): void
    {
        $read = (new ToolResult('Read', 'body'))->withArguments(['file_path' => 'x.php']);

        $this->assertArrayNotHasKey('tool', Message::assistant('body')->withToolResults([$read])->toWire());
    }
}
