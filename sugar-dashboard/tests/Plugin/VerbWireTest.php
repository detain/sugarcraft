<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Plugin;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Dashboard\Plugin\ExternalModule;
use SugarCraft\Dashboard\Plugin\PluginSdk;
use SugarCraft\Dashboard\Plugin\Request;
use SugarCraft\Dashboard\Plugin\Response;
use SugarCraft\Dashboard\Plugin\Verb;
use SugarCraft\Dashboard\Plugin\Wire;

/**
 * sugar-dashboard-103 (+199): the wire vocabulary is one enum, the
 * refusal doctrine is one helper, and an error reply is a MESSAGE the
 * host quotes — not a mystery that disables a panel into silence.
 */
final class VerbWireTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = __DIR__ . '/../fixtures/hostile-plugin.php';

        if (!is_executable($this->fixture)) {
            $this->markTestSkipped('hostile-plugin.php fixture is not executable');
        }
    }

    public function testTheWireVocabularyIsExactlyFourVerbs(): void
    {
        $this->assertSame(
            ['init', 'update', 'view', 'error'],
            array_column(Verb::cases(), 'value'),
        );
        $this->assertSame('unknown', Verb::WIRE_MISSING, 'one spelling for the missing-type label');
    }

    public function testFactoriesAndDecodersParseThroughTheEnum(): void
    {
        $this->assertSame(Verb::Init, Request::init()->verb());
        $this->assertSame(Verb::Update, Request::update([])->verb());
        $this->assertSame(Verb::View, Request::view(80, 24, [])->verb());
        $this->assertSame(Verb::Error, Response::error('boom')->verb());

        // A payload without "type" decodes to the ONE missing label...
        $this->assertSame(Verb::WIRE_MISSING, Request::fromJson('{"data":{}}')->type);
        $this->assertNull(Request::fromJson('{"data":{}}')->verb());

        // ...and a foreign verb travels verbatim while parsing to nothing.
        $foreign = Request::fromJson('{"type":"rendar","data":{}}');
        $this->assertSame('rendar', $foreign->type);
        $this->assertNull($foreign->verb());
    }

    public function testTheChildSideAnswersUnknownVerbsWithAnErrorMessage(): void
    {
        $sdk = new class extends PluginSdk {
            protected function init(): array
            {
                return ['name' => 'v114', 'minSize' => [999999, 999999], 'interval' => 5];
            }

            protected function update(array $state): array
            {
                return $state;
            }

            protected function view(array $state, int $width, int $height): string
            {
                return 'ok';
            }
        };

        $handle = new ReflectionMethod(PluginSdk::class, 'handle');
        $handle->setAccessible(true);

        $reply = $handle->invoke($sdk, new Request('rendar', []));
        $this->assertSame(Verb::Error, $reply->verb());
        $this->assertStringContainsString('unknown request type: rendar', (string) $reply->data['message']);

        // The shared clamp ladder now governs the SDK side too.
        $initReply = $handle->invoke($sdk, Request::init());
        $this->assertSame(Verb::Init, $initReply->verb());
        $this->assertSame([Wire::MAX_MIN_WIDTH, Wire::MAX_MIN_HEIGHT], $initReply->data['minSize']);
    }

    public function testTheHostQuotesThePluginsOwnErrorMessageInItsRefusal(): void
    {
        $module = new ExternalModule('hostile', $this->fixture, ['--mode=errormouth']);
        $module->init();

        $state = $module->update(['tick' => 1]);
        $this->assertSame(['tick' => 1], $state, 'a refusal still keeps prior state intact');

        $view = $module->view($state, 120, 24);
        $this->assertStringContainsString('hostile disabled:', $view);
        $this->assertStringContainsString(
            'plugin error: cannot render: plugin exhausted (request: update)',
            $view,
            'the plugin\'s own words must reach the chrome, not the bare type',
        );
    }

    public function testTheClampLadderAndPreviewBudgetLiveInOnePlace(): void
    {
        $this->assertSame([10000, 1000], Wire::clampMinSize([999999, 999999]));
        $this->assertSame([77, 23], Wire::clampMinSize([77, 23]));
        $this->assertSame([30, 4], Wire::clampMinSize('nonsense'));
        $this->assertSame([30, 4], Wire::clampMinSize([0, 5]));

        $preview = Wire::preview(str_repeat('x', 200) . "\x1b[31m");
        $this->assertLessThanOrEqual(Wire::PREVIEW_BYTES + 3, strlen($preview));
        $this->assertStringNotContainsString("\x1b", $preview, 'previews never smuggle escapes');

        // The duplicated private seats are gone from both sides.
        $this->assertFalse(method_exists(ExternalModule::class, 'clampMinSize'));
        $this->assertFalse(method_exists(Response::class, 'preview'));
    }
}
