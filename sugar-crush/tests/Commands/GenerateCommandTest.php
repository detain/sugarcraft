<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\GenerateCommand;
use SugarCraft\Crush\Commands\Specs\BuiltInCommands;
use SugarCraft\Crush\Commands\Specs\CommandArguments;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\GenerateHostCommand;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Support\ToolCancelRequests;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\GenerateImage;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * W2.3 pins for the `/generate` console command (crush-media): the argv
 * parser's fail-fast table, the spec row's wiring, the tool-boundary argument
 * equality (wire names, not flag spellings — `n_iter` reaches the tool, never
 * `--n-iter`), and that a submitted draft really routes into the SAME
 * GenerateImage pipeline the model takes, dials zero when nothing is
 * configured, and never starts a turn.
 *
 * The SD server is always the transport fake (CallableSdTransport) or the
 * unconfigured refusal — no network is reachable from this file.
 */
final class GenerateCommandTest extends TestCase
{
    use HomeSandboxTrait;

    /** The same 1x1 PNG fixture the tool's suite wraps (lane-A family). */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $mediaRoot = '';

    private string $sandbox = '';

    /** @var list<array{0: string, 1: string, 2: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-gencmd-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        $this->mediaRoot = $this->sandbox . '/media';
        $this->calls = [];
        putenv(Client::BASE_URL_ENV);
        ToolCancelRequests::forget();
    }

    protected function tearDown(): void
    {
        ToolCancelRequests::forget();
        putenv(Client::BASE_URL_ENV);
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    // ---- parser table -----------------------------------------------------------

    /**
     * @param list<string> $tokens
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedDrafts')]
    public function testTheParserBuildsTheToolArgumentMap(array $tokens, array $expected): void
    {
        $plan = GenerateCommand::parse($tokens);

        self::assertNull($plan['error']);
        self::assertFalse($plan['help']);
        self::assertSame($expected, $plan['args']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: array<string, mixed>}>
     */
    public static function acceptedDrafts(): array
    {
        return [
            'plan example, whole flag table' => [
                ['a', 'lacy', 'test', 'cat', '--negative', 'blurry', '--steps', '20', '--cfg', '7.5',
                    '--size', '1024x1024', '--seed', '42', '--sampler', 'DPM++2M', '--scheduler', 'Karras',
                    '--batch', '2', '--n-iter', '3', '--save'],
                [
                    'prompt' => 'a lacy test cat',
                    'negative_prompt' => 'blurry',
                    'steps' => 20,
                    'cfg_scale' => 7.5,
                    'width' => 1024,
                    'height' => 1024,
                    'seed' => 42,
                    'sampler_name' => 'DPM++2M',
                    'scheduler' => 'Karras',
                    'batch_size' => 2,
                    'n_iter' => 3,
                    'save_to_disk' => true,
                ],
            ],
            'positionals join into the prompt' => [
                ['a', 'watercolor', 'fox'],
                ['prompt' => 'a watercolor fox'],
            ],
            'size splits into width and height' => [
                ['p', '--size', '512X768'],
                ['prompt' => 'p', 'width' => 512, 'height' => 768],
            ],
            'prompt first regardless of flag order' => [
                ['--steps', '9', 'late', 'prompt'],
                ['prompt' => 'late prompt', 'steps' => 9],
            ],
            'seed accepts a bare zero' => [
                ['p', '--seed', '0'],
                ['prompt' => 'p', 'seed' => 0],
            ],
        ];
    }

    /**
     * @param list<string> $tokens
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusedDrafts')]
    public function testEveryMalformedDraftIsRefusedByNameWithNoArguments(array $tokens, string $needle): void
    {
        $plan = GenerateCommand::parse($tokens);

        self::assertSame([], $plan['args'], 'a refusal must never carry a partial argument map');
        self::assertIsString($plan['error']);
        self::assertStringContainsString($needle, $plan['error']);
    }

    /**
     * @return array<string, array{0: list<string>, 1: string}>
     */
    public static function refusedDrafts(): array
    {
        return [
            'unknown flag names itself' => [['a', 'cat', '--bogus', 'x'], '--bogus'],
            'missing value names the flag' => [['a', 'cat', '--steps'], '--steps needs a value'],
            'a flag cannot eat the next flag' => [['--steps', '--save'], '--steps needs a value'],
            'non-integer steps' => [['p', '--steps', 'lots'], '--steps expects a whole number'],
            'non-numeric cfg' => [['p', '--cfg', 'seven'], '--cfg expects a number'],
            'malformed size' => [['p', '--size', 'big'], '--size expects WIDTHxHEIGHT'],
            'no prompt left' => [['--steps', '20'], 'prompt is required'],
        ];
    }

    public function testHelpShortCircuitsAndSpellsEveryFlag(): void
    {
        $plan = GenerateCommand::parse(['--help']);
        self::assertTrue($plan['help']);
        self::assertSame([], $plan['args']);

        $help = GenerateCommand::help();
        foreach (array_keys(GenerateCommand::FLAGS) as $flag) {
            self::assertStringContainsString($flag, $help);
        }
        // The help block teaches the WIRE names too, so the console and the
        // tool schema stay one vocabulary.
        self::assertStringContainsString('n_iter', $help);
        self::assertStringContainsString('cfg_scale', $help);
    }

    // ---- spec row and routing wiring --------------------------------------------

    public function testTheSpecRowWiresTheHandlerAndHostCommand(): void
    {
        $command = BuiltInCommands::forSpelling('generate');

        self::assertNotNull($command, 'builtin-commands/4000-generate.php must register /generate');
        self::assertSame('generate', $command->name());
        self::assertSame('handleGenerateCommand', $command->handler);
        self::assertSame(CommandArguments::Text, $command->arguments);
        self::assertSame(GenerateHostCommand::class, $command->hostCommand);
        // The registry constructs host commands zero-arg (`new $class()`).
        self::assertInstanceOf(GenerateHostCommand::class, $command->instantiateHostCommand());
    }

    public function testChatCarriesTheThinHandlerTheDispatchNames(): void
    {
        $method = new \ReflectionMethod(Chat::class, 'handleGenerateCommand');

        self::assertTrue($method->isPrivate());
        $parameter = $method->getParameters()[0];
        self::assertSame('string', (string) $parameter->getType());
    }

    public function testGenerateIsDeliberatelyNotAReadOnlyCommand(): void
    {
        $allowlist = (new \ReflectionClass(Chat::class))
            ->getReflectionConstant('READ_ONLY_COMMANDS')
            ->getValue();

        self::assertIsArray($allowlist);
        self::assertNotContains('generate', $allowlist, 'a dial that spends and writes artifacts is not a read');
    }

    // ---- the tool boundary: argument equality ------------------------------------

    public function testTheFlagTableReachesTheToolAsInputSchemaNames(): void
    {
        $capture = new class {
            /** @var array<string, mixed>|null */
            public ?array $seen = null;

            /**
             * @param array<string, mixed> $args
             */
            public function execute(array $args): ToolResult
            {
                $this->seen = $args;

                return new ToolResult('call-console', "Generated 1 image on the SD server:\nimage 1: /tmp/x.png");
            }
        };

        $host = new GenerateHostCommand(static fn (CommandContext $context): object => $capture);
        $result = $host->run(
            CommandContext::new(),
            '/generate "a lacy test cat" --steps 20 --cfg 7.5 --size 64x32 --seed 42 --n-iter 2 --save',
        );

        self::assertSame([
            'prompt' => 'a lacy test cat',
            'steps' => 20,
            'cfg_scale' => 7.5,
            'width' => 64,
            'height' => 32,
            'seed' => 42,
            'n_iter' => 2,
            'save_to_disk' => true,
        ], $capture->seen, 'the console must hand the tool the EXACT inputSchema map');
        self::assertCount(2, $result->rows);
        self::assertSame('/generate "a lacy test cat" --steps 20 --cfg 7.5 --size 64x32 --seed 42 --n-iter 2 --save', $result->rows[0]->content);
        self::assertStringContainsString('Generated 1 image', $result->rows[1]->content);
    }

    public function testTheHostCommandDialsTheRealToolOnceThroughTheStubbedTransport(): void
    {
        $host = new GenerateHostCommand(function (CommandContext $context): GenerateImage {
            return $this->stubTool($this->stubServer(
                [['state' => ['finished' => true]]],
                $this->stubEnvelope([self::PNG_B64], ['a lacy test cat']),
            ));
        });

        $result = $host->run(CommandContext::new(), '/generate "a lacy test cat" --steps 20 --seed 42');

        self::assertSame(1, count(array_filter($this->calls, static fn (array $c): bool => $c[0] === 'POST')));
        $post = $this->stubPostJson();
        self::assertSame('a lacy test cat', $post['prompt']);
        self::assertSame(20, $post['steps']);
        self::assertSame(42, $post['seed']);
        self::assertStringContainsString('Generated 1 image', $result->rows[1]->content);
    }

    public function testAMalformedDraftRefusesBeforeAnyDial(): void
    {
        $host = new GenerateHostCommand(function (CommandContext $context): GenerateImage {
            return $this->stubTool($this->stubServer([['state' => ['finished' => true]]], $this->stubEnvelope([self::PNG_B64], ['x'])));
        });

        $result = $host->run(CommandContext::new(), '/generate a cat --bogus');

        self::assertSame([], $this->calls, 'usage refusals must cost zero requests');
        $notice = $result->rows[1]->content;
        self::assertStringContainsString('--bogus', $notice);
        self::assertNotSame('', $notice);
    }

    public function testAnUnconfiguredLaunchRefusesWithoutDialing(): void
    {
        // The production seam with base null: the capability gate passes
        // (fail-open roster), Client::configured() is the door that speaks.
        $host = new GenerateHostCommand(function (CommandContext $context): GenerateImage {
            return $this->stubTool($this->stubServer([], '{"images":[]}'), base: null);
        });

        $result = $host->run(CommandContext::new(), '/generate a lacy cat');

        self::assertSame([], $this->calls);
        self::assertStringContainsString('no SD base URL configured', $result->rows[1]->content);
    }

    public function testTheBareCommandAnswersUsageAndDialsNothing(): void
    {
        $host = new GenerateHostCommand(static function (CommandContext $context): never {
            self::fail('the bare command must not reach the tool at all');
        });

        $result = $host->run(CommandContext::new(), '/generate');

        self::assertStringContainsString(GenerateCommand::USAGE, $result->rows[1]->content);
        self::assertStringContainsString('--help', $result->rows[1]->content);
    }

    // ---- dispatch level: a submitted draft routes into the pipeline ---------------

    public function testASubmittedGenerateDraftRoutesIntoTheToolPipeline(): void
    {
        // No seam is reachable through Chat's generic dispatch, so the
        // production build runs — and with HOME sandboxed and the env
        // override cleared, the run must die at the unconfigured door.
        // Routing proven: the refusal text is the GenerateImage pipeline's
        // own, not a usage line and not a turn.
        $next = $this->sendDraft('/generate a lacy cat');

        self::assertFalse($next->inFlight, '/generate answers locally, never as a model turn');
        $rows = $this->rowsAfter($next);
        self::assertNotCount(0, $rows);
        self::assertStringContainsString('no SD base URL configured', $rows[count($rows) - 1]->content);
    }

    public function testABareSubmittedGenerateAnswersWithUsage(): void
    {
        $next = $this->sendDraft('/generate');

        self::assertFalse($next->inFlight);
        $rows = $this->rowsAfter($next);
        self::assertNotCount(0, $rows);
        self::assertStringContainsString(GenerateCommand::USAGE, $rows[count($rows) - 1]->content);
    }

    // ---- fixtures -----------------------------------------------------------------

    private function window(string $draft = ''): Chat
    {
        return (new Chat(
            history: [Message::user('hello'), Message::assistant('hi')],
            inputBuf: $draft,
            backend: new EchoBackend(),
        ))->withSize(100, 30);
    }

    /** Submit $draft as it stands and hand back the resulting Chat. */
    private function sendDraft(string $draft): Chat
    {
        [$next] = $this->window($draft)->update(new KeyMsg(KeyType::Enter));

        return $next;
    }

    /** @return list<Message> */
    private function rowsAfter(Chat $next): array
    {
        return array_slice($next->history, 2);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function stubTool(CallableSdTransport $transport, array $settings = [], ?string $base = 'http://sd.test:7860'): GenerateImage
    {
        return GenerateImage::new()
            ->withFakeDial($transport, $base, $settings)
            ->withMediaRoot($this->mediaRoot)
            ->withLoopPacing(0.01, static function (float $seconds): void {
            });
    }

    /**
     * @param list<array<string, mixed>> $progressFrames frames served in order, last repeats
     * @param string                     $generationBody raw body for POST responses
     */
    private function stubServer(array $progressFrames, string $generationBody): CallableSdTransport
    {
        return new CallableSdTransport(function (string $method, string $path, array $json) use (&$progressFrames, $generationBody) {
            $this->calls[] = [$method, $path, $json];
            if ($method === 'POST') {
                return ['status' => 200, 'body' => $generationBody];
            }
            $frame = count($progressFrames) > 0
                ? (count($progressFrames) > 1 ? array_shift($progressFrames) : $progressFrames[0])
                : ['state' => ['finished' => true]];

            return ['status' => 200, 'body' => json_encode($frame)];
        });
    }

    /**
     * @param list<string>         $infotexts
     * @param array<string, mixed> $extraInfo
     */
    private function stubEnvelope(array $images, array $infotexts, array $extraInfo = []): string
    {
        return json_encode([
            'images' => $images,
            'parameters' => ['prompt' => 'echo'],
            'info' => json_encode($extraInfo + ['infotexts' => array_values($infotexts)]),
        ]);
    }

    /** @return array<string, mixed> */
    private function stubPostJson(): array
    {
        foreach ($this->calls as $call) {
            if ($call[0] === 'POST') {
                return $call[2];
            }
        }
        self::fail('no POST was recorded');
    }
}
