<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Hooks\BuiltIn\PermissionGateHook;
use SugarCraft\Code\Hooks\BuiltIn\ProtectFilesHook;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\ReadOnlyCommands;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;
use SugarCraft\Code\ToolCall;

/**
 * User decision 2026-10-11, part 1: under `default` (and `accept-edits`) a
 * `Bash` line made ENTIRELY of read-only commands runs without asking —
 * alone, or as a chain or pipeline of them — after the leading in-project
 * `cd` and with a mid-chain `cd` allowed only into the project. The user's
 * own example, `cd <root> && ls -d *\/ | head -80 && echo "---GIT---" &&
 * git log --oneline -3`, was asked about every time the model re-shaped it.
 *
 * Fail-closed: every line below that writes, runs another program, substitutes,
 * leaves the project or names a protected file still asks — and a configured
 * `Deny` rule and the protect-files hook still win.
 */
final class ReadOnlyAutoAllowTest extends TestCase
{
    use ReadOnlyGateTrait;

    use HomeSandboxTrait;

    private string $base = '';

    private string $root = '';

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/roa-' . bin2hex(random_bytes(4));
        mkdir($base . '/project/sub', 0o700, true);
        mkdir($base . '/elsewhere', 0o700, true);
        $this->base = (string) realpath($base);
        $this->root = $this->base . '/project';
        SessionSettings::reset();
        UiSettings::forget();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        Bootstrap::useProjectRootForSettings(null);
        UiSettings::forget();
        $this->restoreHomeSandbox();
        $this->removeTree($this->base);
    }

    private function decide(PermissionGate $gate, string $command): PermissionDecision
    {
        $command = str_replace('{root2}', $this->base . '/elsewhere', $command);

        return $gate->evaluate(new ToolCall('Bash', ['command' => str_replace('{root}', $this->root, $command)]), $this->root);
    }

    /** @return array<string, array{string}> */
    public static function readOnlyLines(): array
    {
        return [
            'the user\'s chain' => ['cd {root} && ls -d */ | head -80 && echo "---GIT---" && git log --oneline -3'],
            'status, then a diffstat through head' => ['git status && git diff --stat | head -50'],
            'a lone listing' => ['ls -la'],
            'stderr to /dev/null' => ['ls missing 2>/dev/null; pwd'],
            'a mid-chain cd into the project' => ['git status && cd sub && ls'],
            'a relative cd' => ['cd sub && cat a.txt | wc -l'],
            'grep and find' => ['grep -rn foo src | sort | uniq -c | sort -rn | head'],
            'find without an action' => ['find . -name "*.php" -type f | head'],
            'a listing git branch / tag / remote' => ['git branch -a && git tag && git remote -v'],
            'git config read' => ['git config --get user.name'],
            'php lint' => ['php -l src/A.php'],
            'composer show / validate' => ['composer show && composer validate'],
            'npm ls / view' => ['npm ls && npm view react version'],
            'tail -f' => ['tail -f storage/app.log'],
            'jq' => ['jq .name composer.json'],
            'a newline list of reads' => ["ls\npwd"],
            'sed printing a range' => ['sed -n 1,5p f'],
            'an inline awk program' => ["awk '{print}' f"],
            'a read-only loop' => ['for f in src/*.php; do wc -l "$f"; done'],
        ];
    }

    #[DataProvider('readOnlyLines')]
    public function testAReadOnlyLineRunsWithoutAsking(string $command): void
    {
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(), $command), $command);
        self::assertSame(PermissionDecision::Allow, $this->decide($this->gate(PermissionMode::AcceptEdits), $command), $command);
    }

    /** @return array<string, array{string}> */
    public static function notReadOnly(): array
    {
        return [
            'piped into a shell' => ['ls | sh'],
            'cat into bash' => ['cat x | bash'],
            'find -exec' => ['find . -exec rm {} +'],
            'find -delete' => ['find . -delete'],
            'find -fprint' => ['find . -fprint out.txt'],
            'git branch -D' => ['git branch -D x'],
            'git branch create' => ['git branch topic'],
            'git commit' => ['git status && git commit -am wip'],
            'git -C elsewhere' => ['git -C /etc status'],
            'sort -o' => ['sort -o out f'],
            'uniq writes its second operand' => ['uniq in out'],
            'echo into a file' => ['echo x > f'],
            'append into a file' => ['ls >> f'],
            'tee' => ['ls | tee f'],
            'a command substitution' => ['cat $(whoami)'],
            'a backtick' => ['ls `x`'],
            'process substitution' => ['diff <(ls) <(ls sub)'],
            'a ${…} expansion' => ['echo ${x:=y}'],
            'env running a command' => ['env rm -rf x'],
            'printenv with a name' => ['printenv HOME'],
            'a protected file' => ['grep x .env'],
            'a key file' => ['cat deploy.pem'],
            'cd out of the project' => ['cd /etc && ls'],
            'cd above the project' => ['cd .. && ls'],
            'cd home' => ['cd && ls'],
            'cd -' => ['cd - && ls'],
            'a relative cd after a cd' => ['cd sub && cd .. && ls'],
            '; with a non-read-only part' => ['ls; rm x'],
            'a newline with a non-read-only part' => ["ls\nrm x"],
            'sed -i' => ['sed -i s/a/b/ f'],
            'awk running a command' => ["awk '{system(\"rm \" \$1)}' f"],
            'xargs rm' => ['ls | xargs rm'],
            'xargs alone' => ['xargs rm'],
            'php running a script' => ['php artisan migrate'],
            'php -r' => ['php -l a.php -r "x"'],
            'composer install' => ['composer install'],
            'npm test' => ['npm test'],
            'rg --pre' => ['rg foo --pre ./x'],
            'a classifier finding' => ['env | grep SECRET'],
            'a background job' => ['ls &'],
            'a glob command name' => ['l? -la'],
            // ISS-010: the environment dump and every machine-anchored read.
            'env and printenv bare' => ['env | sort && printenv'],
            'an absolute read outside the project' => ['cat /etc/hostname'],
            'a home credential store' => ['cat ~/.config/gh/hosts.yml'],
            'a read of the whole home tree' => ['grep -r password ~/'],
            'a dollar-HOME spelling' => ['cat $HOME/.pypirc'],
            'a parent traversal operand' => ['cat ../../etc/passwd'],
            'an absolute path under a different project' => ['head -5 {root2}/README.md'],
            'a machine path as a data file' => ["awk -F: '\$3 > 1000 {print \$1}' /etc/passwd"],
            'a home path as a data file' => ["awk '{print \$1}' ~/.ssh/known_hosts"],
            'an absolute command path' => ['/bin/ls'],
            'an assignment in front' => ['FOO=1 ls'],
            'a here-doc' => ["cat <<EOF\nx\nEOF"],
        ];
    }

    #[DataProvider('notReadOnly')]
    public function testAnythingElseStillAsks(string $command): void
    {
        self::assertSame(PermissionDecision::Ask, $this->decide($this->gate(), $command), $command);
    }

    public function testWithoutAProjectRootNoCdQualifies(): void
    {
        $gate = $this->gate();
        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => 'ls'])));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('Bash', ['command' => 'cd sub && ls'])));
    }

    public function testAConfiguredDenyRuleStillWins(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash(git log *)', PermissionAction::Deny),
            new PermissionRule('Bash(cat *)', PermissionAction::Ask),
        ]))->withReadOnlyAutoAllow(true);

        self::assertSame(PermissionDecision::Deny, $this->decide($gate, 'git status && git log -3'));
        self::assertSame(PermissionDecision::Ask, $this->decide($gate, 'cat README.md'));
        self::assertSame(PermissionDecision::Allow, $this->decide($gate, 'ls'));
    }

    public function testTheProtectFilesHookStillRefusesARead(): void
    {
        self::assertTrue(ProtectFilesHook::namesProtectedFile('grep x .env'));
        self::assertFalse(ProtectFilesHook::namesProtectedFile('grep x .env.example'));

        $manager = new HookManager(new HookRegistry());
        $manager->registerBuiltIns();
        $manager->register(new PermissionGateHook($this->gate()));

        $denied = $manager->preToolUse($this->context(['command' => 'grep API_KEY .env']));
        self::assertTrue($denied->isDenied(), 'protect-files refuses a read of .env whatever the gate would say');

        $allowed = $manager->preToolUse($this->context(['command' => 'grep -rn foo src | head']));
        self::assertTrue($allowed->isAllowed(), 'an ordinary read-only line runs through the whole chain unasked');
    }

    public function testSwitchedOffEveryShellLineAsksAgain(): void
    {
        $gate = (new PermissionGate(PermissionMode::Default))->withReadOnlyAutoAllow(false);
        self::assertSame(PermissionDecision::Ask, $this->decide($gate, 'ls -la'));

        SessionSettings::apply([ReadOnlyCommands::SETTING => false]);
        self::assertFalse(ReadOnlyCommands::autoAllowEnabled());
        self::assertSame(PermissionDecision::Ask, $this->decide(new PermissionGate(PermissionMode::Default), 'ls -la'), 'the gate reads the setting on use');
        SessionSettings::reset();
        self::assertTrue(ReadOnlyCommands::autoAllowEnabled(), 'on by default');
    }

    public function testTheAutoAllowReadsOnlyInsideTheProject(): void
    {
        // ISS-010: direct helper pins. In-project paths ride the auto-run;
        // anything anchored at $HOME, off the root, or escaping with `..`
        // asks. An unjudgeable root fails closed the same way.
        self::assertTrue(ReadOnlyCommands::autoAllows('cat ' . $this->root . '/sub/a.txt', $this->root));
        self::assertTrue(ReadOnlyCommands::autoAllows('head -3 ./sub/../notes.md', $this->root), 'a ./ walk that lands inside stays');
        self::assertTrue(ReadOnlyCommands::autoAllows('wc -l sub/notes.md', $this->root));
        self::assertTrue(ReadOnlyCommands::autoAllows('ls -d ' . $this->root . '/*', $this->root), 'a glob under the root is not an anchored literal read of elsewhere');
        self::assertFalse(ReadOnlyCommands::autoAllows('cat /etc/hostname', $this->root));
        self::assertFalse(ReadOnlyCommands::autoAllows('cat ' . $this->base . '/elsewhere/README.md', $this->root));
        self::assertFalse(ReadOnlyCommands::autoAllows('cat ~/.pgpass', $this->root));
        self::assertFalse(ReadOnlyCommands::autoAllows('cat ~/x.txt', $this->root), 'home is home, dot-file or not');
        self::assertFalse(ReadOnlyCommands::autoAllows('cat sub/../../etc/passwd', $this->root));
        self::assertFalse(ReadOnlyCommands::autoAllows('cat /etc/hostname', null), 'no root: anchored operands fail closed');
        self::assertFalse(ReadOnlyCommands::autoAllows('cd ' . $this->root . '/sub && cat ../notes.md', $this->root), 'mid-chain .. is unjudgeable against an untracked cwd');
        self::assertFalse(ReadOnlyCommands::autoAllows('env', $this->root), 'the environment is a credential dump');
        self::assertFalse(ReadOnlyCommands::autoAllows('printenv', $this->root));
    }

    public function testPlanKeepsItsOwnReadOnlyJudgement(): void
    {
        $plan = new PermissionGate(PermissionMode::Plan);
        self::assertSame(PermissionDecision::Allow, $this->decide($plan, 'cd /etc && ls'), 'plan withholds writes, not visibility');
        self::assertSame(PermissionDecision::Allow, $this->decide($plan, 'cat /etc/hostname'), 'ISS-010: the operand scope is the auto-allow\u{2019}s, not plan\u{2019}s');
        self::assertSame(PermissionDecision::Allow, $this->decide($plan, 'env | sort && printenv'), 'plan still lets the env dump through');
        self::assertSame(PermissionDecision::Allow, $this->decide($plan, 'git log -3 | head'));
        self::assertSame(PermissionDecision::Deny, $this->decide($plan, 'ls | sh'));
        self::assertSame(PermissionDecision::Deny, $this->decide($plan, 'echo x > f'));
    }

    public function testDontAskAndAutoAreUnchanged(): void
    {
        self::assertSame(PermissionDecision::Deny, $this->decide(new PermissionGate(PermissionMode::DontAsk), 'ls'));
        self::assertSame(PermissionDecision::Allow, $this->decide(new PermissionGate(PermissionMode::Auto, [], new \SugarCraft\Code\Permissions\SafetyClassifier()), 'ls | head'));
    }

    public function testTheSettingIsUserTierAndAProjectMayOnlySwitchItOff(): void
    {
        $definition = SettingsSchema::byKey(ReadOnlyCommands::SETTING);
        self::assertNotNull($definition);
        self::assertTrue($definition->default);
        self::assertSame(RiskClass::Narrowing, $definition->riskClass);

        $home = $this->base . '/home';
        mkdir($home . '/.candy-code', 0o700, true);
        mkdir($this->root . '/' . LayeredSettings::dir(), 0o700, true);
        $this->useHomeSandbox($home);
        file_put_contents($home . '/.candy-code/config.json', (string) json_encode([
            LayeredSettings::PROJECT_SETTINGS_TRUST_KEY => [$this->root],
        ]));
        chmod($home . '/.candy-code/config.json', 0o600);
        Bootstrap::useProjectRootForSettings($this->root);

        $user = static function (array $data) use ($home): void {
            $path = $home . '/.candy-code/' . LayeredSettings::USER_FILE;
            file_put_contents($path, (string) json_encode($data));
            chmod($path, 0o600);
            UiSettings::forget();
        };
        $project = function (array $data): void {
            file_put_contents($this->root . '/' . LayeredSettings::SHARED_PATH, (string) json_encode($data));
            UiSettings::forget();
        };

        self::assertTrue(ReadOnlyCommands::autoAllowEnabled(), 'default on');

        $project([ReadOnlyCommands::SETTING => false]);
        self::assertFalse(ReadOnlyCommands::autoAllowEnabled(), 'a trusted project may switch it off');

        $user([ReadOnlyCommands::SETTING => false]);
        $project([ReadOnlyCommands::SETTING => true]);
        self::assertFalse(ReadOnlyCommands::autoAllowEnabled(), 'and may not switch it back on over the user');

        $user([ReadOnlyCommands::SETTING => true]);
        self::assertTrue(ReadOnlyCommands::autoAllowEnabled());
    }

    /** @param array<string, mixed> $args */
    private function context(array $args): HookContext
    {
        return new HookContext(
            sessionId: 'test-session',
            toolName: 'Bash',
            toolArgs: $args,
            toolInput: json_encode($args) ?: '{}',
            toolOutput: '',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $this->root,
        );
    }

    private function removeTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir) || is_link($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
