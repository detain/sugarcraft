<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Field;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Field\Note;
use PHPUnit\Framework\TestCase;

final class NoteTest extends TestCase
{
    public function testRendersTitleAndDescription(): void
    {
        $n = Note::new('intro')->withTitle('Welcome')->withDescription('A short note.');
        $this->assertSame("Welcome\nA short note.", $n->view());
    }

    public function testIsSkippable(): void
    {
        $this->assertTrue(Note::new('x')->skippable());
    }

    public function testKeyAndValue(): void
    {
        $n = Note::new('intro');
        $this->assertSame('intro', $n->key());
        $this->assertNull($n->value());
    }

    public function testKeysIgnored(): void
    {
        [$n, ] = Note::new('x')->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertSame('', $n->getTitle());
    }

    public function testWithNextRendersButtonAndIsNotSkippable(): void
    {
        $n = Note::new('intro')
            ->withTitle('Welcome')
            ->withNext()
            ->withNextLabel('Continue');
        $this->assertFalse($n->skippable());
        $this->assertTrue($n->isNext());
        $this->assertSame('Continue', $n->getNextLabel());
        $rendered = $n->view();
        $this->assertStringContainsString('[ Continue ]', $rendered);
    }

    public function testWithHeightPadsToFixedRowCount(): void
    {
        $n = Note::new('intro')->withTitle('A')->withDescription('B')->withHeight(5);
        $this->assertSame(5, $n->getHeight());
        $rows = explode("\n", $n->view());
        $this->assertCount(5, $rows);
    }

    public function testFocusedNoteRendersCursorMarker(): void
    {
        [$n, ] = Note::new('intro')->withTitle('Hi')->withNext()->focus();
        $this->assertTrue($n->isFocused());
        $this->assertStringContainsString('> [ Next ]', $n->view());
    }

    public function testNextLabelIsLangResolvedAtViewTimeNotCtorBaked(): void
    {
        // SF-34 (sugar-forms-041): the ctor no longer bakes 'Next' — the
        // property carries the unset sentinel and view() resolves through
        // Lang, so a locale switch re-colors ALREADY-BUILT notes.
        $n = Note::new('intro')->withNext(true);
        $this->assertSame('', $n->nextLabel, 'ctor must not bake the English default');
        $this->assertStringContainsString('[ Next ]', $n->view(), 'en fallback keeps the shipped bytes');

        $tmp = sys_get_temp_dir() . '/sf34-lang-' . uniqid();
        @mkdir($tmp . '/forms-lang', 0777, true);
        $dir = $tmp . '/forms-lang';
        file_put_contents($dir . '/fr.php', "<?php\nreturn ['field.next' => 'Continuer'];\n");
        $realDir = (new \ReflectionClass(\SugarCraft\Forms\Lang::class))->getConstant('DIR');
        $oldLocale = \SugarCraft\Core\I18n\T::locale();
        \SugarCraft\Core\I18n\T::overrideNamespace('forms', $dir);
        \SugarCraft\Core\I18n\T::setLocale('fr');
        try {
            $this->assertStringContainsString('[ Continuer ]', $n->view(), 'post-construction locale switch flips the button');
            $hostSet = $n->withNextLabel('Proceed ->');
            $this->assertStringContainsString('[ Proceed -> ]', $hostSet->view(), 'explicit host labels keep winning');
        } finally {
            \SugarCraft\Core\I18n\T::setLocale($oldLocale);
            \SugarCraft\Core\I18n\T::overrideNamespace('forms', $realDir);
            @unlink($dir . '/fr.php');
            @rmdir($dir);
            @rmdir($tmp);
        }
    }

    public function testExplicitEmptySentinelResolvesLikeDefault(): void
    {
        // Deliberate disclosure: '' has meant "unset" since SF-34 (new()
        // stopped baking English), so an explicit empty label now resolves
        // to the locale default instead of rendering a bare bracket.
        $n = Note::new('intro')->withNext(true)->withNextLabel('');
        $this->assertStringContainsString('[ Next ]', $n->view());
    }
}
