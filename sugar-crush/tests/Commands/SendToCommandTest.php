<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\SendToCommand;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\CommandResult;
use SugarCraft\Crush\Host\Commands\CommandEffectKind;
use SugarCraft\Crush\Host\Commands\SendToHostCommand;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tui\MediaForm\ImportPng;

/**
 * W4.4 pins for `/sendto` (crush-media): the argv parser's refusal table
 * (mode values, mask-needs-inpaint, one-path law, the local-only scheme
 * refusal mirroring ImageIngress, the .png door), and the host command's
 * send — chunk import onto a fresh draft, `--to` attaching the init image,
 * `--mask` riding the draft's mask field, the gated server fallback through
 * the seam, and loud failures for unreadable or parameter-less files.
 */
final class SendToCommandTest extends TestCase
{
    use HomeSandboxTrait;

    private const CHUNKED = __DIR__ . '/../fixtures/png/tiny-parameters.png';
    private const PLAIN = __DIR__ . '/../fixtures/img/tiny.png';

    private string $sandbox = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-sendto-' . bin2hex(random_bytes(6));
        $this->useHomeSandbox($this->sandbox . '/home');
        putenv(Client::BASE_URL_ENV);
    }

    protected function tearDown(): void
    {
        putenv(Client::BASE_URL_ENV);
        $this->restoreHomeSandbox();
        if (is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }
    }

    // ---- parser table -----------------------------------------------------------

    public function testTheParserAcceptsThePathWithItsModeWires(): void
    {
        $plan = SendToCommand::parse(['/x/out.png', '--to', 'inpaint', '--mask', '/x/m.png']);

        self::assertNull($plan['error']);
        self::assertSame('/x/out.png', $plan['path']);
        self::assertSame('inpaint', $plan['to']);
        self::assertSame('/x/m.png', $plan['mask']);
    }

    public function testHelpShortCircuitsBeforeEveryPathLaw(): void
    {
        $plan = SendToCommand::parse(['--help']);

        self::assertTrue($plan['help']);
        self::assertNull($plan['error']);
        self::assertStringContainsString('Options:', SendToCommand::help());
    }

    public function testAPositionalIsRequiredAndOnlyOne(): void
    {
        self::assertStringContainsString('path is required', (string) SendToCommand::parse([])['error']);

        $two = SendToCommand::parse(['/a.png', '/b.png']);
        self::assertStringContainsString('Expected one PNG path', (string) $two['error']);
        self::assertNull($two['path']);
    }

    public function testTheModeValuesAreBoundedAndFlagsNeedValues(): void
    {
        self::assertStringContainsString('img2img or inpaint', (string) SendToCommand::parse(['/a.png', '--to', 'txt2img'])['error']);
        self::assertStringContainsString('needs a value', (string) SendToCommand::parse(['/a.png', '--to'])['error']);
        self::assertStringContainsString('Unknown flag', (string) SendToCommand::parse(['/a.png', '--bogus'])['error']);
    }

    public function testRemoteSpellingsAreRefusedAtTheDoor(): void
    {
        // Mirrors ImageIngress's local-only law: the parse refuses before any
        // read, so no spelling of a remote PNG ever reaches a fetcher.
        self::assertStringContainsString('never fetched', (string) SendToCommand::parse(['https://x/y.png'])['error']);
        self::assertStringContainsString('never fetched', (string) SendToCommand::parse(['HTTP://X/Y.PNG'])['error']);
        self::assertStringContainsString('never fetched', (string) SendToCommand::parse(['data:image/png;base64,AAAA'])['error']);
    }

    public function testThePngDoorAndTheMaskLaw(): void
    {
        self::assertStringContainsString('not a .png', (string) SendToCommand::parse(['/a.jpg'])['error']);
        self::assertStringContainsString('--to inpaint', (string) SendToCommand::parse(['/a.png', '--mask', '/m.png'])['error']);
        self::assertStringContainsString('--to inpaint', (string) SendToCommand::parse(['/a.png', '--to', 'img2img', '--mask', '/m.png'])['error']);
    }

    // ---- host command -----------------------------------------------------------

    public function testTheBareCommandAnswersUsageAndOpensNothing(): void
    {
        $result = (new SendToHostCommand())->run(CommandContext::new(), '/sendto');

        self::assertStringContainsString(SendToCommand::USAGE, $result->rows[1]->content);
        self::assertSame([], $result->effects, 'a subjectless send must not open a form');
    }

    public function testAStampedFileImportsItsParametersOntoTheDraft(): void
    {
        $result = (new SendToHostCommand())->run(CommandContext::new(), '/sendto ' . escapeshellarg(self::absolute(self::CHUNKED)));

        self::assertStringContainsString('local chunk', $result->rows[1]->content);
        self::assertStringContainsString('parameters only', $result->rows[1]->content);
        $request = $this->effectRequest($result);
        self::assertSame('a cat, sitting', $request->prompt());
        self::assertSame(20, $request->steps());
        self::assertNull($request->initImages(), 'without --to the artifact stays out of the request');
    }

    public function testImg2imgAttachesTheArtifactAsTheInitImage(): void
    {
        $path = self::absolute(self::CHUNKED);
        $result = (new SendToHostCommand())->run(CommandContext::new(), '/sendto ' . escapeshellarg($path) . ' --to img2img');

        $request = $this->effectRequest($result);
        self::assertSame([base64_encode((string) file_get_contents($path))], $request->initImages());
        self::assertStringContainsString('img2img', $result->rows[1]->content);
    }

    public function testInpaintCarriesTheMaskOnTheDraft(): void
    {
        $mask = $this->sandbox . '/mask.png';
        copy(self::absolute(self::PLAIN), $mask);

        $result = (new SendToHostCommand())->run(CommandContext::new(), sprintf(
            '/sendto %s --to inpaint --mask %s',
            escapeshellarg(self::absolute(self::CHUNKED)),
            escapeshellarg($mask),
        ));

        $request = $this->effectRequest($result);
        self::assertSame([base64_encode((string) file_get_contents(self::absolute(self::CHUNKED)))], $request->initImages());
        self::assertSame(base64_encode((string) file_get_contents($mask)), $request->mask());
        self::assertStringContainsString('inpaint + mask', $result->rows[1]->content);
    }

    public function testTheServerFallbackRidesTheSeamWhenTheChunkIsAbsent(): void
    {
        $path = $this->sandbox . '/plain.png';
        copy(self::absolute(self::PLAIN), $path);
        $seen = null;
        $host = new SendToHostCommand(static function (string $base64) use (&$seen): ?string {
            $seen = $base64;

            return "over the wire\nSteps: 27";
        });

        $result = $host->run(CommandContext::new(), '/sendto ' . escapeshellarg($path));

        self::assertSame(base64_encode((string) file_get_contents($path)), $seen);
        $request = $this->effectRequest($result);
        self::assertSame('over the wire', $request->prompt());
        self::assertSame(27, $request->steps());
        self::assertStringContainsString(ImportPng::VIA_SERVER_LABEL, $result->rows[1]->content);
    }

    public function testUnreadableAndParameterLessFilesFailLoudlyWithNoForm(): void
    {
        $missing = $this->sandbox . '/gone.png';
        $result = (new SendToHostCommand())->run(CommandContext::new(), '/sendto ' . escapeshellarg($missing));
        self::assertStringContainsString('cannot read', $result->rows[1]->content);
        self::assertSame([], $result->effects);

        // A PNG with no chunk on a launch whose production hop is gated off
        // (home sandbox, no base) — the loud refusal row, never a silent form.
        $plain = $this->sandbox . '/plain.png';
        copy(self::absolute(self::PLAIN), $plain);
        $silent = (new SendToHostCommand())->run(CommandContext::new(), '/sendto ' . escapeshellarg($plain));
        self::assertStringContainsString(ImportPng::NO_PARAMS, $silent->rows[1]->content);
        self::assertSame([], $silent->effects);
    }

    public function testTheEffectCarriesTheRequestToTheFormDoor(): void
    {
        $result = (new SendToHostCommand())->run(CommandContext::new(), '/sendto ' . escapeshellarg(self::absolute(self::CHUNKED)));

        $effect = $result->effect(CommandEffectKind::OpenMediaForm);
        self::assertNotNull($effect);
        self::assertInstanceOf(MediaRequest::class, $effect->mediaRequest());
    }

    private function effectRequest(CommandResult $result): MediaRequest
    {
        $request = $result->effect(CommandEffectKind::OpenMediaForm)?->mediaRequest();
        self::assertInstanceOf(MediaRequest::class, $request, 'a successful send opens the form on the imported draft');

        return $request;
    }

    private static function absolute(string $relative): string
    {
        return (string) realpath($relative);
    }
}
