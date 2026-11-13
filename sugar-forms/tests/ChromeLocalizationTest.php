<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\I18n\T;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Color;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Form;
use SugarCraft\Forms\Group;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\ItemList\ItemList;
use SugarCraft\Forms\ItemList\StringItem;
use SugarCraft\Forms\Theme;
use SugarCraft\Style\Style;

/**
 * SF-33 (sugar-forms-039/-070/-107): the last raw chrome literals in the
 * render path — the Step-of-N indicator, the terminal `[submitted]` /
 * `[aborted]` lines, the empty-list rows and the keyBinds() labels — joined
 * the catalogue, and the two terminal lines gained Theme slots.
 *
 * Each leg pins both halves (the SF-34/SF-56 locale-switch idiom): the
 * default-locale bytes are identical to the pre-catalogue literals
 * (zero-regression for every view pin and future golden), and a switched
 * locale re-colors the chrome (proof the sites consult the catalogue
 * instead of interpolating raw). The scan leg fails loudly if a raw
 * literal ever creeps back into the named source sites.
 */
final class ChromeLocalizationTest extends TestCase
{
    /**
     * German catalogue for the probe — deliberately only the SF-33 keys,
     * proving untouched keys still fall through to their en values.
     */
    private const GERMAN = [
        'form.step_of'      => 'Schritt {current} von {total}',
        'form.submitted'    => '[übermittelt]',
        'keybind.next'      => 'weiter',
        'keybind.enter_keys' => 'bewegt den Fokus; sendet das letzte Feld',
        'list.no_items'     => 'Keine Einträge',
    ];

    public function testStepOfNRendersPreCatalogueBytes(): void
    {
        $form = Form::groups(
            Group::new(Input::new('a')),
            Group::new(Input::new('b')),
        );
        $this->assertStringContainsString('Step 1 of 2', $form->view());
        [$paged, ] = $form->update(new KeyMsg(KeyType::Tab));
        /** @var Form $paged */
        $this->assertStringContainsString('Step 2 of 2', $paged->view());
    }

    public function testTerminalLinesRenderPreCatalogueBytes(): void
    {
        $form = Form::new(Input::new('a'));

        [$submitted, ] = $form->update(new KeyMsg(KeyType::Enter));
        /** @var Form $submitted */
        $this->assertTrue($submitted->isSubmitted());
        $this->assertStringEndsWith("\n\n[submitted]", $submitted->view());

        [$aborted, ] = $form->update(new KeyMsg(KeyType::Escape));
        /** @var Form $aborted */
        $this->assertStringEndsWith("\n\n[aborted]", $aborted->view());
    }

    public function testEmptyListRowsRenderPreCatalogueBytes(): void
    {
        $empty = ItemList::new([])->withShowStatusBar(false);
        $this->assertStringContainsString('No items.', $empty->view());

        $l = ItemList::new([new StringItem('apple'), new StringItem('banana')], 60, 5);
        [$l, ] = $l->focus();
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, '/'));
        [$l, ] = $l->update(new KeyMsg(KeyType::Char, 'z'));
        $this->assertStringContainsString('No matches.', $l->view());
    }

    public function testKeyBindsLabelsAndEnterRowAreThePreCatalogueBytes(): void
    {
        $labels = array_map(
            static fn (array $row): string => $row[0],
            Form::new(Input::new('a'))->keyBinds(),
        );
        // SF-107: the advisory enter row rides alongside the four map rows.
        $this->assertSame(['next', 'prev', 'submit', 'quit', 'enter'], $labels);

        $paged   = Form::groups(Group::new(Input::new('a')), Group::new(Input::new('b')));
        $rows    = $paged->keyBinds();
        $byLabel = [];
        foreach ($rows as [$label, $keys]) {
            $byLabel[$label] = $keys;
        }
        $this->assertSame('tab past last field', $byLabel['next page']);
        $this->assertSame('shift+tab on first field', $byLabel['prev page']);
    }

    public function testTerminalLinesRideTheThemeSlots(): void
    {
        // SF-070: the slots are plain in every preset (byte-neutral), but a
        // host that supplies a style sees it applied to the terminal line.
        $form = Form::new(Input::new('a'));
        [$submitted, ] = $form->update(new KeyMsg(KeyType::Enter));
        /** @var Form $submitted */

        $red = Style::new()->foreground(Color::hex('#ff0000'));
        $themed = $submitted->withTheme(new Theme(
            title: Style::new(), description: Style::new(),
            focusedTitle: Style::new(), blurredTitle: Style::new(),
            error: Style::new(), errorSummary: Style::new(), cursor: Style::new(),
            option: Style::new(), selectedOption: Style::new(), help: Style::new(),
            prompt: Style::new(), submitted: $red,
        ));
        $rendered = $themed->view();
        $this->assertStringEndsWith("\n\n" . $red->render('[submitted]'), $rendered);

        // The shipped default (ansi preset, the form's default theme) keeps
        // the plain legacy bytes — the slot lands byte-neutral.
        $this->assertStringEndsWith("\n\n[submitted]", $submitted->view());
    }

    public function testGermanLocaleProbeRoutesEveryChromeSiteThroughTheCatalogue(): void
    {
        $tmp = sys_get_temp_dir() . '/sf33-lang-' . uniqid();
        $dir = $tmp . '/forms-lang';
        @mkdir($dir, 0777, true);
        $realDir = (new \ReflectionClass(Lang::class))->getConstant('DIR');
        // The override swaps the whole catalogue directory — ship the real
        // en.php alongside the partial de.php so untouched keys prove their
        // exact -> base -> en -> raw fallback end-to-end.
        copy($realDir . '/en.php', $dir . '/en.php');
        file_put_contents($dir . '/de.php', "<?php\nreturn " . var_export(self::GERMAN, true) . ";\n");

        $oldLocale = T::locale();
        T::overrideNamespace('forms', $dir);
        T::setLocale('de');
        try {
            $form = Form::groups(
                Group::new(Input::new('a')),
                Group::new(Input::new('b')),
            );
            $this->assertStringContainsString('Schritt 1 von 2', $form->view());

            // Single-group form for the terminal line: Enter on a
            // non-final group pages instead of submitting.
            [$submitted, ] = Form::new(Input::new('a'))->update(new KeyMsg(KeyType::Enter));
            /** @var Form $submitted */
            $this->assertStringEndsWith("\n\n[übermittelt]", $submitted->view());

            $this->assertSame(
                'weiter',
                Form::new(Input::new('a'))->keyBinds()[0][0],
            );
            $this->assertStringContainsString(
                'bewegt den Fokus; sendet das letzte Feld',
                Form::new(Input::new('a'))->help(),
            );
            $this->assertStringContainsString('Keine Einträge', ItemList::new([])->view());

            // Untouched keys still fall through to their en bytes.
            $filtering = ItemList::new([new StringItem('apple'), new StringItem('banana')], 60, 5);
            [$filtering, ] = $filtering->focus();
            [$filtering, ] = $filtering->update(new KeyMsg(KeyType::Char, '/'));
            [$filtering, ] = $filtering->update(new KeyMsg(KeyType::Char, 'z'));
            /** @var ItemList $filtering */
            $this->assertStringContainsString('No matches.', $filtering->view());
        } finally {
            T::setLocale($oldLocale);
            T::overrideNamespace('forms', $realDir);
            @unlink($dir . '/de.php');
            @unlink($dir . '/en.php');
            @rmdir($dir);
            @rmdir($tmp);
        }
    }

    /**
     * Fail-closed scan: the named raw chrome literals must not reappear in
     * the render path. Patterns target the old code shapes (quoted
     * literals), not the docblock prose that still names them.
     */
    public function testNoRawChromeLiteralsRemainAtTheNamedSites(): void
    {
        $formSrc = (string) file_get_contents(__DIR__ . '/../src/Form.php');
        $listSrc = (string) file_get_contents(__DIR__ . '/../src/ItemList/ItemList.php');
        $pickerSrc = (string) file_get_contents(__DIR__ . '/../src/FilePicker/FilePicker.php');

        $this->assertStringNotContainsString("'Step %d of %d'", $formSrc);
        $this->assertStringNotContainsString('"\n\n[submitted]"', $formSrc);
        $this->assertStringNotContainsString('"\n\n[aborted]"', $formSrc);
        $this->assertStringNotContainsString("'No matches.'", $listSrc);
        $this->assertStringNotContainsString("'No items.'", $listSrc);
        $this->assertStringNotContainsString("['next',", $formSrc);
        $this->assertStringNotContainsString("'next page'", $formSrc);
        $this->assertStringNotContainsString("'prev page'", $formSrc);
        $this->assertStringNotContainsString("'tab past last field'", $formSrc);
        // SF-54 rider: the picker's empty-list row joined the catalogue.
        $this->assertStringNotContainsString("'(empty)'", $pickerSrc);
    }
}
