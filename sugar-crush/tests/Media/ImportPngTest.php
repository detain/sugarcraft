<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Media;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Media\Infotext;
use SugarCraft\Crush\Media\MediaRequest;
use SugarCraft\Crush\Media\Png;
use SugarCraft\Crush\Media\Sd\CallableSdTransport;
use SugarCraft\Crush\Media\Sd\Client;
use SugarCraft\Crush\Tui\MediaForm\ImportPng;

/**
 * Plan W4.4 gates for the PNG-info import channel: the local-first chunk read,
 * the onlyUnset polarity law on import (a value the user already set on the
 * draft outranks the file's — W1.6 step 5), the non-PNG refusal that never
 * dials, the server png-info fallback only when the chunk is absent, the loud
 * refusal when neither channel answers, the paste-params fold (fromInfotext),
 * the round-trip law (generation → stamped file → import → emit is
 * byte-identity), and the zero-dial capability gate of the production server
 * channel (serverInfoFor).
 */
final class ImportPngTest extends TestCase
{
    private const CHUNKED = __DIR__ . '/../fixtures/png/tiny-parameters.png';
    private const PLAIN = __DIR__ . '/../fixtures/img/tiny.png';

    public function testExtractReadsTheParametersChunkOfAStampedPng(): void
    {
        $bytes = (string) file_get_contents(self::CHUNKED);
        $text = ImportPng::extract($bytes);

        self::assertNotNull($text);
        self::assertStringContainsString('a cat, sitting', $text);
        self::assertStringContainsString('Steps: 20', $text);
    }

    public function testExtractIsSilentOnNonPngMissingChunkAndNull(): void
    {
        self::assertNull(ImportPng::extract('not a png at all'));
        self::assertNull(ImportPng::extract((string) file_get_contents(self::PLAIN)));
        self::assertNull(ImportPng::extract(null));
    }

    public function testImportFoldsTheLocalChunkOntoAFreshDraft(): void
    {
        $result = ImportPng::import((string) file_get_contents(self::CHUNKED), MediaRequest::new());

        self::assertNull($result['error']);
        self::assertSame(ImportPng::VIA_LOCAL, $result['via']);
        $request = $result['request'];
        self::assertNotNull($request);
        self::assertSame('a cat, sitting', $request->prompt());
        self::assertSame(20, $request->steps());
        self::assertSame(42, $request->seed());
        self::assertSame(4, $request->width());
    }

    public function testAChunkedPngImportsLocallyWithoutTouchingTheServerChannel(): void
    {
        // R11-m2 (W7 ledger): the wiring pin proves the production channel
        // is not dialed, but no unit test combined chunked bytes WITH a live
        // server double — a local-bypass regression (always dialing) would
        // have passed. The throwing double makes local-first loud.
        $serverInfo = static fn (string $base64): ?string => throw new \LogicException('the server channel must stay shut when the chunk answers');

        $result = ImportPng::import((string) file_get_contents(self::CHUNKED), MediaRequest::new(), $serverInfo);

        self::assertNull($result['error']);
        self::assertSame(ImportPng::VIA_LOCAL, $result['via']);
        $request = $result['request'];
        self::assertNotNull($request);
        self::assertSame('a cat, sitting', $request->prompt());
        self::assertSame(20, $request->steps());
    }

    public function testImportHonoursTheOnlyUnsetLawAUserSetValueOutranksTheFile(): void
    {
        // The draft already answers Steps: 11 — the chunk's "Steps: 20" must
        // NOT clobber it, while the unset prompt still lands.
        $draft = MediaRequest::new()->withSteps(11);

        $result = ImportPng::import((string) file_get_contents(self::CHUNKED), $draft);

        self::assertNull($result['error']);
        $request = $result['request'];
        self::assertNotNull($request);
        self::assertSame(11, $request->steps(), 'onlyUnset law: a set field outranks the imported file');
        self::assertSame('a cat, sitting', $request->prompt(), 'unset fields still import');
    }

    public function testANonPngRefusesLoudlyAndNeverDialsTheServer(): void
    {
        $dials = 0;
        $serverInfo = static function (string $base64) use (&$dials): ?string {
            ++$dials;

            return null;
        };

        $result = ImportPng::import('plain bytes, no signature', MediaRequest::new(), $serverInfo);

        self::assertSame(ImportPng::NOT_PNG, $result['error']);
        self::assertNull($result['request']);
        self::assertSame(0, $dials, 'the chunk law and the dial law are one guard — non-PNG never reaches the server');
    }

    public function testAnAbsentChunkFallsBackToTheServerChannel(): void
    {
        $bytes = (string) file_get_contents(self::PLAIN);
        $seen = null;
        $serverInfo = static function (string $base64) use (&$seen, $bytes): ?string {
            $seen = $base64;

            return "imported over the wire\nSteps: 25";
        };

        $result = ImportPng::import($bytes, MediaRequest::new(), $serverInfo);

        self::assertNull($result['error']);
        self::assertSame(ImportPng::VIA_SERVER, $result['via']);
        self::assertSame(base64_encode($bytes), $seen, 'the server channel receives the file base64');
        $request = $result['request'];
        self::assertNotNull($request);
        self::assertSame('imported over the wire', $request->prompt());
        self::assertSame(25, $request->steps());
    }

    public function testNeitherChannelAnsweringIsALoudRefusal(): void
    {
        $bytes = (string) file_get_contents(self::PLAIN);

        $withoutChannel = ImportPng::import($bytes, MediaRequest::new());
        self::assertSame(ImportPng::NO_PARAMS, $withoutChannel['error']);
        self::assertNull($withoutChannel['request']);

        $serverSilent = ImportPng::import($bytes, MediaRequest::new(), static fn (string $base64): ?string => null);
        self::assertSame(ImportPng::NO_PARAMS, $serverSilent['error']);
    }

    public function testAChunkThatCarriesNothingStillRefuses(): void
    {
        // A stamped-but-blank chunk parses to empty values only — the import
        // must refuse rather than silently "succeed" with nothing folded.
        $blank = Png::setInfotext((string) file_get_contents(self::PLAIN), '   ');

        $result = ImportPng::import($blank, MediaRequest::new());

        self::assertSame(ImportPng::NO_PARAMS, $result['error']);
        self::assertNull($result['request']);
    }

    public function testFromInfotextFoldsAPastedStringUnderTheSameLaw(): void
    {
        $draft = MediaRequest::new()->withCfgScale(4.0);

        $request = ImportPng::fromInfotext(
            "a fox at dawn\nNegative prompt: blur\nSteps: 33, CFG scale: 7.5, Style: analog style, ControlNet 0: pixelway",
            $draft,
        );

        self::assertSame('a fox at dawn', $request->prompt());
        self::assertSame(33, $request->steps());
        self::assertSame(4.0, $request->cfgScale(), 'the paste channel obeys onlyUnset too');
        // Legacy/unmodelled keys ride the W1.6 parse-tolerant table: they must
        // not throw and must not disturb the modelled fields.
        $array = $request->toArray();
        self::assertSame('a fox at dawn', $array['prompt']);
    }

    public function testTheRoundTripLawStampedFileImportsToByteIdenticalEmission(): void
    {
        $original = MediaRequest::new()
            ->withPrompt('a cat, sitting')
            ->withNegativePrompt('blurry, low-res')
            ->withSteps(20)
            ->withCfgScale(7.0)
            ->withSeed(123456789)
            ->withSamplerName('DPM++ 2M')
            ->withScheduler('Karras')
            ->withWidth(512)
            ->withHeight(512);

        $text = Infotext::emit($original);
        $stamped = Png::setInfotext((string) file_get_contents(self::PLAIN), $text);

        $result = ImportPng::import($stamped, MediaRequest::new());
        self::assertNull($result['error']);
        self::assertNotNull($result['request']);
        self::assertSame($text, Infotext::emit($result['request']), 'generation → stamped file → import → emit must replay byte-identical');
    }

    // ---- the production server channel: zero-dial capability gate --------------

    public function testAnUnimplementedFamilyRefusesTheFallbackWithoutDialing(): void
    {
        $result = ImportPng::serverInfoFor(
            base64_encode('x'),
            ['mediaFamily' => 'sglang-diffusion', Client::BASE_URL_CONFIG_KEY => 'http://base.invalid'],
            $this->mustNotDial(),
        );

        self::assertNull($result);
    }

    public function testAMediaKindsRosterWithoutImageRefusesTheFallbackWithoutDialing(): void
    {
        $result = ImportPng::serverInfoFor(
            base64_encode('x'),
            ['mediaKinds' => ['video'], Client::BASE_URL_CONFIG_KEY => 'http://base.invalid'],
            $this->mustNotDial(),
        );

        self::assertNull($result);
    }

    public function testNoConfiguredBaseMeansZeroDial(): void
    {
        $result = ImportPng::serverInfoFor(base64_encode('x'), [], $this->mustNotDial());

        self::assertNull($result);
    }

    public function testTheHappyServerChannelReadsTheInfoField(): void
    {
        $transport = new CallableSdTransport(static fn (string $method, string $path): array => [
            'status' => 200,
            'body' => json_encode(['info' => "from the wire\nSteps: 30"]),
        ]);

        $info = ImportPng::serverInfoFor(base64_encode('x'), [Client::BASE_URL_CONFIG_KEY => 'http://base.invalid'], $transport);

        self::assertSame("from the wire\nSteps: 30", $info);
    }

    public function testAFailedOrMalformedServerAnswerIsUnavailableNotAnError(): void
    {
        $settings = [Client::BASE_URL_CONFIG_KEY => 'http://base.invalid'];

        self::assertNull(ImportPng::serverInfoFor(base64_encode('x'), $settings, new CallableSdTransport(
            static fn (): array => ['status' => 500, 'body' => 'boom'],
        )));

        self::assertNull(ImportPng::serverInfoFor(base64_encode('x'), $settings, new CallableSdTransport(
            static fn (): array => ['status' => 200, 'body' => json_encode(['nope' => 1])],
        )));

        self::assertNull(ImportPng::serverInfoFor(base64_encode('x'), $settings, new CallableSdTransport(
            static fn (): array => throw new \RuntimeException('socket died'),
        )));
    }

    private function mustNotDial(): CallableSdTransport
    {
        return new CallableSdTransport(static fn (): never => throw new \LogicException('the zero-dial law was violated — a refusal arm opened a request'));
    }
}
