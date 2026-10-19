<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulePathNudge;
use SugarCraft\Code\Sessions\BackgroundShellRegistry;
use SugarCraft\Code\Skills\SkillPathNudge;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Tools\BuiltIn\ShellStop;
use SugarCraft\Code\Tools\Catalog\ToolBuildContext;

/**
 * SHL-1: the stop half of the background shell channel. The kill ladder
 * itself is pinned in the registry suite; what is pinned HERE is the tool's
 * honest narration — settled shells are no-ops, unknown ids are refusals,
 * and a confirmed kill is stated as confirmed and nothing stronger.
 */
final class ShellStopTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-stop-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $this->root . '/' . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    private function registry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('w2d-test', $this->root);
    }

    /** The house construction seam: the catalog builds these tools. */
    private function tool(?BackgroundShellRegistry $registry = null): ShellStop
    {
        $skills = new SkillRegistry();

        return ShellStop::fromCatalog(new ToolBuildContext(
            root: $this->root,
            loader: new InstructionFileLoader($this->root),
            skills: $skills,
            skillNudge: SkillPathNudge::new($skills),
            ruleNudge: RulePathNudge::fromLoader(static fn (): array => []),
            backgroundShells: $registry,
        ));
    }

    public function testAMalformedShellIdIsRefusedWhenStopping(): void
    {
        $result = $this->tool()->execute(['shell_id' => 'sh-ZZZZZZZZ']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-xxxxxxxx', $result->content());
    }

    public function testAToolWithoutARegistryRefusesLoudly(): void
    {
        $result = ShellStop::new()->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('no background shell registry is attached', $result->content());
    }

    public function testAnUnregisteredIdIsRefusedByName(): void
    {
        $result = $this->tool($this->registry())->execute(['shell_id' => 'sh-deadbeef']);
        self::assertTrue($result->isError());
        self::assertStringContainsString('sh-deadbeef', $result->content());
    }

    public function testStoppingALiveShellNarratesTheConfirmedKill(): void
    {
        $registry = $this->registry();
        $id = (string) $registry->start('sleep 120', 'sleeper')['shell']['id'];

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        self::assertFalse($result->isError(), $result->content());
        self::assertStringContainsString('is stopped', $result->content());
        self::assertStringContainsString('exit code unknown', $result->content(), 'a killed wrapper never writes its exit file');
        self::assertNotSame('running', $registry->status($id)['state']);
    }

    public function testStoppingASettledShellIsAnHonestNoOp(): void
    {
        $registry = $this->registry();
        $id = (string) $registry->start('exit 5', 'done')['shell']['id'];
        for ($i = 0; $i < 160; $i++) {
            if (($registry->status($id)['state'] ?? '') === 'finished') {
                break;
            }
            usleep(25_000);
        }

        $result = $this->tool($registry)->execute(['shell_id' => $id]);
        self::assertFalse($result->isError(), 'addressing a dead shell is not a failure — it is what the caller wanted');
        self::assertStringContainsString('had already settled (exit 5); nothing was signalled.', $result->content());
    }

    public function testThePositionRosterKeepsThirtyNineAndFortyReserved(): void
    {
        // The wave ruling: ShellOutput=37, ShellStop=38, and NOTHING else
        // lands in this pair's slots. This pin fails the moment a later lane
        // forgets the reservation.
        $out = new \ReflectionClass(\SugarCraft\Code\Tools\BuiltIn\ShellOutput::class);
        $stop = new \ReflectionClass(ShellStop::class);
        $attr = static function (\ReflectionClass $class): array {
            $positions = [];
            foreach ($class->getAttributes() as $attribute) {
                if ($attribute->getName() === \SugarCraft\Code\Tools\Catalog\BuiltInTool::class) {
                    $positions[] = $attribute->newInstance()->position;
                }
            }

            return $positions;
        };
        self::assertSame([37], $attr($out));
        self::assertSame([38], $attr($stop));
    }
}
