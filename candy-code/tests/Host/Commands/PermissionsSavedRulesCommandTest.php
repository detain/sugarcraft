<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\PermissionsCommand;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Role;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `/permissions saved` and `/permissions forget <n>|<pattern>` — the reader
 * and remover C-SEC-6.a gave the list `p` writes. The pins take the command
 * through the SAME door the settings editor uses (a real SettingsWriter over
 * a real file in a sandbox HOME), because the whole point of the step is
 * that the transcript now shows and edits the file the launch actually
 * reads: the numbering must be the numbering `forget <n>` takes, a pattern
 * with spaces must survive the verb's argument split, and every failure
 * shape (no door, unreadable file, throwing door, no match) must refuse
 * visibly and change nothing. The `forgot` answer also has to carry the
 * next-launch truth — the live gate loaded its rules at start, so this
 * session is tightened by `mode`, not by forgetting.
 */
final class PermissionsSavedRulesCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';
    private string $home = '';
    private string $config = '';
    private string $project = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-perm-saved-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        mkdir($this->home . '/.candy-code', 0700, true);
        $this->config = $this->home . '/.candy-code/config.json';
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testSavedNumbersTheStoredRulesAndNamesTheirFile(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'deny'],
        ]);

        [$result, $body] = $this->ask($this->context(), '/permissions saved');
        self::assertFalse($result->isRefused());
        self::assertStringContainsString('Saved rules (2)', $body);
        self::assertStringContainsString('1. allow Bash(git status *)', $body, 'the number here is the number forget <n> takes');
        self::assertStringContainsString('2. deny  Bash(npm test *)', $body, 'stored order rides, first-match-wins and all, in the report\'s own action column');
        self::assertStringContainsString('config.json', $body);
        self::assertStringContainsString('/permissions forget', $body, 'the list says how to take one out');
        self::assertStringContainsString('NEXT launch', $body, 'the removal truth is in the hint, not only the answer');
    }

    public function testSavedOnAFreshConfigSaysNoneAndPointsAtTheKey(): void
    {
        [, $body] = $this->ask($this->context(), '/permissions saved');
        self::assertStringContainsString('Saved rules: none', $body);
        self::assertStringContainsString('permissionRules', $body);
        self::assertStringContainsString('`p`', $body, 'the empty list teaches how one gets made');
    }

    public function testForgetByIndexTakesTheEntryAndCarriesTheNextLaunchTruth(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'allow'],
        ]);

        [$result, $body] = $this->ask($this->context(), '/permissions forget 1');
        self::assertFalse($result->isRefused());
        self::assertStringContainsString('Forgot 1 saved rule', $body);
        self::assertStringContainsString('allow Bash(git status *)', $body, 'the answer names exactly what left');
        self::assertStringContainsString('next launch', $body);
        self::assertStringContainsString('/permissions mode', $body, 'and points at the lever that tightens THIS session');

        [, $again] = $this->ask($this->context(), '/permissions saved');
        self::assertStringContainsString('Saved rules (1)', $again);
        self::assertStringContainsString('Bash(npm test *)', $again, 'the untouched entry rides, still in order');
    }

    public function testForgetByPatternSurvivesTheSpacesAHousePatternCarries(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'deny'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'allow'],
        ]);

        [$result, $body] = $this->ask($this->context(), '/permissions forget Bash(git status *)');
        self::assertFalse($result->isRefused());
        self::assertStringContainsString('Forgot 1 saved rule', $body);

        $stored = $this->stored();
        self::assertSame([['pattern' => 'Bash(npm test *)', 'action' => 'allow']], $stored['permissionRules'] ?? [], 'the verb took the REST of the argument, not just its first word');
    }

    public function testForgetWithoutATargetAnswersUsageAboveTheList(): void
    {
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);

        [$result, $body] = $this->ask($this->context(), '/permissions forget');
        self::assertFalse($result->isRefused(), 'a bare verb is a teaching answer, not a failure');
        self::assertStringContainsString('Usage: `/permissions forget', $body);
        self::assertStringContainsString('1. allow Bash(git status *)', $body, 'usage comes with the list it needs');
        self::assertFileExists($this->config, 'nothing moved');
    }

    public function testForgetOfNoMatchRefusesNothingAndShowsTheList(): void
    {
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);
        $before = (string) file_get_contents($this->config);

        [$result, $body] = $this->ask($this->context(), '/permissions forget Bash(rm -rf *)');
        self::assertFalse($result->isRefused(), 'a miss is an answer, not a failure — but it writes nothing');
        self::assertStringContainsString('No saved rule matches', $body);
        self::assertStringContainsString('Bash(rm -rf *)', $body, 'the miss names the target back');
        self::assertStringContainsString('1. allow', $body, 'with the numbered list beneath');
        self::assertSame($before, (string) file_get_contents($this->config));
    }

    public function testBothVerbsRefuseWhenTheSessionHasNoWriteDoor(): void
    {
        $context = CommandContext::new(root: $this->project, workspace: WorkspaceContext::new(root: $this->project));

        foreach (['/permissions saved', '/permissions forget 1'] as $text) {
            [$result, $body, $errored] = $this->ask($context, $text);
            self::assertFalse($result->isRefused(), $text.' — the command ran its check and answered');
            self::assertTrue($errored, $text);
            self::assertStringContainsString('no settings write door', $body, $text);
        }
        self::assertFileDoesNotExist($this->config);
    }

    public function testAFailingDoorRefusesTheForgetVisiblyAndWritesNothing(): void
    {
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);
        $before = (string) file_get_contents($this->config);
        $writer = SettingsWriter::new($this->config, static function (array $set, array $unset): void {
            throw new \RuntimeException('disk on fire');
        });
        $context = CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project)->withService(SettingsWriter::class, $writer),
        );

        [$result, $body, $errored] = $this->ask($context, '/permissions forget 1');
        self::assertTrue($errored, 'a half-landed forget must never be heard as success');
        self::assertStringContainsString('Could not change the saved rules', $body);
        self::assertStringContainsString('disk on fire', $body, 'the failure names what threw');
        self::assertSame($before, (string) file_get_contents($this->config));
    }

    public function testSavedRefusesAnUnreadableListInsteadOfShowingEmpty(): void
    {
        file_put_contents($this->config, (string) json_encode([['pattern' => 'x', 'action' => 'allow']]));

        [, $body, $errored] = $this->ask($this->context(), '/permissions saved');
        self::assertTrue($errored);
        self::assertStringContainsString('Could not read the saved rules', $body);
        self::assertStringNotContainsString('Saved rules (', $body, 'no fake table above the failure — the read failed, and that is the whole answer');
    }

    public function testBareForgetOnAnUnreadableConfigRefusesExactlyLikeSaved(): void
    {
        // Review r1 MAJOR: the usage branch used to call savedBody()
        // UNGUARDED, so the bare verb on the very corrupt config that
        // taught `saved` to refuse raised RuntimeException out of run()
        // into the program loop — a typed slash command crashing instead
        // of answering. Both verbs must now speak the one refusal.
        file_put_contents($this->config, (string) json_encode([['pattern' => 'x', 'action' => 'allow']]));

        [, $savedBody, $savedErrored] = $this->ask($this->context(), '/permissions saved');
        [$result, $body, $errored] = $this->ask($this->context(), '/permissions forget');

        self::assertTrue($savedErrored, 'the reference shape: saved refuses the unreadable file');
        self::assertTrue($errored, 'bare forget refuses the same file the same way — run() never throws');
        self::assertFalse($result->isRefused(), 'the command ran and answered visibly, it did not abort');
        self::assertStringContainsString('Could not read the saved rules', $body);
        self::assertStringNotContainsString('Usage:', $body, 'no usage text (or any table) above a failed read');
        self::assertSame($savedBody, $body, 'one refusal sentence for one failure, whichever verb knocked');
    }

    /**
     * @return array{0: \SugarCraft\Code\Host\Commands\CommandResult, 1: string, 2: bool} the result, the answer row's text, and its error flag — the flag errorReply carries (isRefused() means "the command did not run", which is not the shape of a visible refusal with an effect-free answer)
     */
    private function ask(CommandContext $context, string $text): array
    {
        $result = (new PermissionsCommand())->run($context, $text);
        $last = $result->rows[\count($result->rows) - 1];
        self::assertSame(Role::Assistant, $last->role, $text);

        return [$result, (string) $last->content, $last->error];
    }

    private function context(): CommandContext
    {
        return CommandContext::new(
            root: $this->project,
            workspace: WorkspaceContext::new(root: $this->project)
                ->withService(SettingsWriter::class, $this->writer()),
        );
    }

    private function writer(): SettingsWriter
    {
        $config = $this->config;

        return SettingsWriter::new($config, static function (array $set, array $unset) use ($config): void {
            $current = \is_file($config) ? (array) json_decode((string) file_get_contents($config), true) : [];
            file_put_contents($config, (string) json_encode(SettingsWriter::patched($current, $set, $unset)));
        });
    }

    /**
     * @param list<array{pattern: string, action: string}> $rules
     */
    private function seedConfig(array $rules): void
    {
        file_put_contents($this->config, (string) json_encode(['permissionRules' => $rules]));
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return \is_file($this->config)
            ? (array) json_decode((string) file_get_contents($this->config), true)
            : [];
    }
}
