<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests;

use SugarCraft\Dashboard\Components\Calendar\HeatmapCalendar;
use SugarCraft\Dashboard\Components\Card\Diff;
use SugarCraft\Dashboard\Components\Feedback\LoadingText;
use SugarCraft\Dashboard\Components\Feedback\Spinner;
use SugarCraft\Dashboard\Components\Form\CommandPalette;
use SugarCraft\Dashboard\Components\Form\Cursor;
use SugarCraft\Dashboard\Components\Form\Editor;
use SugarCraft\Dashboard\Components\GridTable\Cell;
use SugarCraft\Dashboard\Components\GridTable\Filter;
use SugarCraft\Dashboard\Components\GridTable\Footer;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Header;
use SugarCraft\Dashboard\Components\GridTable\Overflow;
use SugarCraft\Dashboard\Components\GridTable\Scrolling;
use SugarCraft\Dashboard\Components\GridTable\Sort;
use SugarCraft\Dashboard\Components\Media\Video;
use SugarCraft\Dashboard\Components\StatusBar\StatusIndicator;
use SugarCraft\Dashboard\Components\System\LogViewer;
use SugarCraft\Dashboard\Components\System\Stopwatch;
use SugarCraft\Dashboard\Components\System\Terminal;
use SugarCraft\Dashboard\Components\System\Timer;
use SugarCraft\Dashboard\Components\Tree\BranchStyle;
use SugarCraft\Dashboard\Components\Tree\DendrogramNode;
use SugarCraft\Dashboard\Components\Tree\DrilldownTree;
use SugarCraft\Dashboard\Components\Tree\SunburstSegment;
use SugarCraft\Dashboard\Components\Tree\UMLClass;
use SugarCraft\Dashboard\Foundation\Buffer;
use SugarCraft\Dashboard\Foundation\Theme;
use SugarCraft\Dashboard\Keys\KeyMap;
use SugarCraft\Dashboard\Module\BaseModule;
use SugarCraft\Dashboard\Layout\Boxer\Address;
use SugarCraft\Dashboard\Layout\Boxer\Boxer;
use SugarCraft\Dashboard\Layout\Boxer\Node;
use SugarCraft\Dashboard\Layout\FocusManager;
use SugarCraft\Dashboard\Layout\Tile\BaseTile;
use SugarCraft\Dashboard\Layout\Tile\Tile;
use SugarCraft\Dashboard\Plot\Braille\BrailleCanvas;
use SugarCraft\Dashboard\Plot\Canvas\Canvas;
use SugarCraft\Dashboard\Plot\Chart\Heatmap;
use SugarCraft\Dashboard\Plot\Chart\OHLCPoint;
use SugarCraft\Dashboard\Plot\Chart\PartitionSegment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the accessor-deprecation era (2026-10-24, ruling 3b).
 *
 * Every swept legacy get*() accessor must stay a byte-identical BC
 * forwarder to its bare twin until v2.0.0 (REMOVED-IN: 2.0.0): the
 * provider legs pin runtime equality per pair, the structural pins
 * assert the forwarder shape, the docblock markers, and that the
 * census agrees with the README Accessor-deprecation table.
 */
final class AccessorDeprecationTest extends TestCase
{
    private const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * Names that stay get*() by construction: the bare name is taken
     * by a different member. Address::child(self, int) is the static
     * factory, so the getter cannot move there.
     */
    private const NAMED_CARVE_OUTS = [
        'Address::getChild',
    ];

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<mixed>}>
     */
    public static function deprecatedAccessors(): array
    {
        return [
            'HeatmapCalendar::getAverageValue' => [HeatmapCalendar::class, 'getAverageValue', 'averageValue', []],
            'HeatmapCalendar::getDataDimensions' => [HeatmapCalendar::class, 'getDataDimensions', 'dataDimensions', []],
            'Diff::getStats' => [Diff::class, 'getStats', 'stats', []],
            'LoadingText::getCurrentFrame' => [LoadingText::class, 'getCurrentFrame', 'currentFrame', []],
            'LoadingText::getFrameAt' => [LoadingText::class, 'getFrameAt', 'frameAt', [0]],
            'LoadingText::getInterval' => [LoadingText::class, 'getInterval', 'interval', []],
            'LoadingText::getStyle' => [LoadingText::class, 'getStyle', 'style', []],
            'Spinner::getCurrentFrame' => [Spinner::class, 'getCurrentFrame', 'currentFrame', []],
            'Spinner::getFrameAt' => [Spinner::class, 'getFrameAt', 'frameAt', [0]],
            'Spinner::getFrameCount' => [Spinner::class, 'getFrameCount', 'frameCount', []],
            'CommandPalette::getFilteredCommands' => [CommandPalette::class, 'getFilteredCommands', 'filteredCommands', []],
            'Cursor::getCursorChar' => [Cursor::class, 'getCursorChar', 'cursorChar', []],
            'Editor::getContent' => [Editor::class, 'getContent', 'content', []],
            'Editor::getCursorPosition' => [Editor::class, 'getCursorPosition', 'cursorPosition', []],
            'Editor::getLine' => [Editor::class, 'getLine', 'line', [0]],
            'Editor::getLineCount' => [Editor::class, 'getLineCount', 'lineCount', []],
            'Cell::getFormat' => [Cell::class, 'getFormat', 'format', []],
            'Cell::getValue' => [Cell::class, 'getValue', 'value', []],
            'Filter::getFilter' => [Filter::class, 'getFilter', 'filter', ['col-a']],
            'Filter::getFilters' => [Filter::class, 'getFilters', 'filters', []],
            'Footer::getSummaryColumns' => [Footer::class, 'getSummaryColumns', 'summaryColumns', []],
            'GridTable::getFilterText' => [GridTable::class, 'getFilterText', 'filterText', []],
            'GridTable::getFrozenColumns' => [GridTable::class, 'getFrozenColumns', 'frozenColumns', []],
            'GridTable::getHeight' => [GridTable::class, 'getHeight', 'height', []],
            'GridTable::getPagination' => [GridTable::class, 'getPagination', 'pagination', []],
            'GridTable::getSortState' => [GridTable::class, 'getSortState', 'sortState', []],
            'GridTable::getWidth' => [GridTable::class, 'getWidth', 'width', []],
            'Header::getColumns' => [Header::class, 'getColumns', 'columns', []],
            'Header::getSortedColumn' => [Header::class, 'getSortedColumn', 'sortedColumn', []],
            'Overflow::getHorizontal' => [Overflow::class, 'getHorizontal', 'horizontal', []],
            'Overflow::getVertical' => [Overflow::class, 'getVertical', 'vertical', []],
            'Scrolling::getRowHeight' => [Scrolling::class, 'getRowHeight', 'rowHeight', []],
            'Scrolling::getTotalHeight' => [Scrolling::class, 'getTotalHeight', 'totalHeight', []],
            'Scrolling::getVisibleRows' => [Scrolling::class, 'getVisibleRows', 'visibleRows', []],
            'Sort::getColumn' => [Sort::class, 'getColumn', 'column', []],
            'Sort::getDirection' => [Sort::class, 'getDirection', 'direction', []],
            'Video::getProgressWidth' => [Video::class, 'getProgressWidth', 'progressWidth', []],
            'StatusIndicator::getStatus' => [StatusIndicator::class, 'getStatus', 'status', []],
            'StatusIndicator::getSymbol' => [StatusIndicator::class, 'getSymbol', 'symbol', []],
            'LogViewer::getEntryCount' => [LogViewer::class, 'getEntryCount', 'entryCount', []],
            'Stopwatch::getElapsedMilliseconds' => [Stopwatch::class, 'getElapsedMilliseconds', 'elapsedMilliseconds', []],
            'Stopwatch::getElapsedSeconds' => [Stopwatch::class, 'getElapsedSeconds', 'elapsedSeconds', []],
            'Terminal::getHistory' => [Terminal::class, 'getHistory', 'history', []],
            'Terminal::getInput' => [Terminal::class, 'getInput', 'input', []],
            'Terminal::getOutput' => [Terminal::class, 'getOutput', 'output', []],
            'Terminal::getPrompt' => [Terminal::class, 'getPrompt', 'prompt', []],
            'Timer::getRemainingSeconds' => [Timer::class, 'getRemainingSeconds', 'remainingSeconds', []],
            'BranchStyle::getConnectorChar' => [BranchStyle::class, 'getConnectorChar', 'connectorChar', []],
            'BranchStyle::getEndConnectorChar' => [BranchStyle::class, 'getEndConnectorChar', 'endConnectorChar', []],
            'BranchStyle::getLineChar' => [BranchStyle::class, 'getLineChar', 'lineChar', []],
            'DrilldownTree::getBreadcrumbItems' => [DrilldownTree::class, 'getBreadcrumbItems', 'breadcrumbItems', []],
            'DrilldownTree::getNavigationStack' => [DrilldownTree::class, 'getNavigationStack', 'navigationStack', []],
            'UMLClass::getAttributes' => [UMLClass::class, 'getAttributes', 'attributes', []],
            'UMLClass::getMethods' => [UMLClass::class, 'getMethods', 'methods', []],
            'UMLClass::getTemplateParams' => [UMLClass::class, 'getTemplateParams', 'templateParams', []],
            'Buffer::getCell' => [Buffer::class, 'getCell', 'cell', [0, 0]],
            'Theme::getName' => [Theme::class, 'getName', 'name', []],
            'KeyMap::getRegisteredKeys' => [KeyMap::class, 'getRegisteredKeys', 'registeredKeys', []],
            'Address::getParent' => [Address::class, 'getParent', 'parent', []],
            'Boxer::getHeight' => [Boxer::class, 'getHeight', 'height', []],
            'Boxer::getItem' => [Boxer::class, 'getItem', 'item', ['0']],
            'Boxer::getModelMap' => [Boxer::class, 'getModelMap', 'modelMap', []],
            'Boxer::getRoot' => [Boxer::class, 'getRoot', 'root', []],
            'Boxer::getWidth' => [Boxer::class, 'getWidth', 'width', []],
            'Node::getAddress' => [Node::class, 'getAddress', 'address', []],
            'Node::getHeight' => [Node::class, 'getHeight', 'height', []],
            'Node::getWidth' => [Node::class, 'getWidth', 'width', []],
            'FocusManager::getFocusedId' => [FocusManager::class, 'getFocusedId', 'focusedId', []],
            'BaseTile::getName' => [BaseTile::class, 'getName', 'name', []],
            'BaseTile::getSize' => [BaseTile::class, 'getSize', 'size', []],
            'Tile::getContent' => [Tile::class, 'getContent', 'content', []],
            'Tile::getParent' => [Tile::class, 'getParent', 'parent', []],
            'BaseModule::getState' => [BaseModule::class, 'getState', 'state', []],
            'BrailleCanvas::getCell' => [BrailleCanvas::class, 'getCell', 'cell', [0, 0]],
            'BrailleCanvas::getPixelSize' => [BrailleCanvas::class, 'getPixelSize', 'pixelSize', []],
            'Canvas::getPixel' => [Canvas::class, 'getPixel', 'pixel', [0, 0]],
            'Heatmap::getDataDimensions' => [Heatmap::class, 'getDataDimensions', 'dataDimensions', []],
            'Heatmap::getMaxValue' => [Heatmap::class, 'getMaxValue', 'maxValue', []],
            'Heatmap::getMinValue' => [Heatmap::class, 'getMinValue', 'minValue', []],
            'OHLCPoint::getBodySize' => [OHLCPoint::class, 'getBodySize', 'bodySize', []],
            'OHLCPoint::getRange' => [OHLCPoint::class, 'getRange', 'range', []],
            'HeatmapCalendar::getTotalValue' => [HeatmapCalendar::class, 'getTotalValue', 'totalValue', []],
            'DendrogramNode::getDepth' => [DendrogramNode::class, 'getDepth', 'depth', []],
            'DendrogramNode::getTotalValue' => [DendrogramNode::class, 'getTotalValue', 'totalValue', []],
            'SunburstSegment::getTotalValue' => [SunburstSegment::class, 'getTotalValue', 'totalValue', []],
            'Boxer::getNode' => [Boxer::class, 'getNode', 'node', ['0']],
            'Node::getChildren' => [Node::class, 'getChildren', 'children', []],
            'PartitionSegment::getDepth' => [PartitionSegment::class, 'getDepth', 'depth', []],
            'PartitionSegment::getTotalValue' => [PartitionSegment::class, 'getTotalValue', 'totalValue', []],
        ];
    }

    private static function fixture(string $class): object
    {
        return match ($class) {
            HeatmapCalendar::class => new HeatmapCalendar([[1, 2, 3], [4, 5, 6]]),
            Diff::class => new Diff("a\nb", "b\nc"),
            LoadingText::class => new LoadingText(),
            Spinner::class => new Spinner(),
            CommandPalette::class => new CommandPalette([['name' => 'Save', 'shortcut' => 'Ctrl+S']]),
            Cursor::class => new Cursor(),
            Editor::class => new Editor("line one\nline two"),
            Cell::class => new Cell(42, '%d'),
            Filter::class => new Filter(),
            Footer::class => new Footer(),
            GridTable::class => new GridTable(['a', 'b'], [[1, 2]]),
            Header::class => new Header(['a', 'b']),
            Overflow::class => new Overflow(),
            Scrolling::class => new Scrolling(),
            Sort::class => new Sort('a'),
            Video::class => new Video(),
            StatusIndicator::class => new StatusIndicator(),
            LogViewer::class => new LogViewer(['hello']),
            Stopwatch::class => new Stopwatch(),
            Terminal::class => new Terminal(),
            Timer::class => new Timer(60),
            BranchStyle::class => new BranchStyle(),
            DrilldownTree::class => new DrilldownTree(),
            UMLClass::class => new UMLClass('c1', 'Widget'),
            Buffer::class => new Buffer(4, 4),
            Theme::class => Theme::dark(),
            KeyMap::class => new KeyMap(new Spinner()),
            Address::class => Address::root(),
            Boxer::class => new Boxer(new Node()),
            BrailleCanvas::class => new BrailleCanvas(8, 8),
            Heatmap::class => new Heatmap([[1, 2], [3, 4]]),
            OHLCPoint::class => new OHLCPoint('x', 1.0, 2.0, 0.5, 1.5),
            PartitionSegment::class => new PartitionSegment('a', 'A', 10.0),
            DendrogramNode::class => new DendrogramNode('a', 'A', 10.0),
            SunburstSegment::class => new SunburstSegment('a', 'A', 10.0),
            BaseTile::class => new BaseTile('t'),
            Tile::class => new Tile('t'),
            Canvas::class => new Canvas(),
            FocusManager::class => new FocusManager(),
            Node::class => new Node(),
            BaseModule::class => new ProbeModule(),
            default => self::fail("no fixture for {$class}"),
        };
    }

    /**
     * BC leg: the deprecated name must return exactly what the bare
     * twin returns, on the same object, with the same arguments.
     *
     * @param class-string $class
     * @param list<mixed> $args
     */
    #[DataProvider('deprecatedAccessors')]
    public function testTheDeprecatedForwarderMatchesTheBareTwin(string $class, string $legacy, string $bare, array $args): void
    {
        $object = self::fixture($class);
        $this->assertTrue(method_exists($object, $bare), $class . "::" . $bare . "() must exist");
        $this->assertEquals($object->{$bare}(...$args), $object->{$legacy}(...$args));
    }

    /**
     * Structural leg: every swept forwarder is a one-line return
     * statement carrying the @deprecated + REMOVED-IN markers and
     * naming its bare twin in the docblock.
     *
     * @param class-string $class
     */
    #[DataProvider('deprecatedAccessors')]
    public function testEverySweptForwarderCarriesMarkersAndSingleReturn(string $class, string $legacy, string $bare, array $args): void
    {
        $method = new \ReflectionMethod($class, $legacy);
        $doc = (string) $method->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc);
        $this->assertStringContainsString(self::MARKER, $doc);
        $this->assertStringContainsString('{@see self::' . $bare . '()}', $doc);
        $this->assertTrue($method->isPublic());
        $lines = array_slice(
            file($method->getFileName()),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        );
        $this->assertCount(1, $lines, $class . '::' . $legacy . ' must be a single-line forwarder');
        $this->assertMatchesRegularExpression(
            '/public function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ return \$this->' . preg_quote($bare, '/') . '\((\$\w+(, \$\w+)*)?\); \}\s*$/',
            $lines[0]
        );
        $bareMethod = new \ReflectionMethod($class, $bare);
        $this->assertTrue($bareMethod->isPublic());
        $this->assertStringNotContainsString(self::MARKER, (string) $bareMethod->getDocComment());
        $this->assertSame($method->getNumberOfParameters(), $bareMethod->getNumberOfParameters());
        $this->assertSame((string) $method->getReturnType(), (string) $bareMethod->getReturnType());
    }

    /**
     * Census legs: discovery-first scan of src/ must find exactly the
     * pairs under test, and the README Accessor-deprecation table must
     * list the same 89 renames (nothing added, nothing dropped).
     */
    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(file_get_contents($file->getPathname()));
            foreach ($tokens as $i => $token) {
                if ($token->id !== T_FUNCTION) {
                    continue;
                }
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->id === T_WHITESPACE) {
                        continue;
                    }
                    break;
                }
                $name = $tokens[$j]->text;
                if (preg_match('/^get[A-Z]/', $name) !== 1) {
                    continue;
                }
                for ($k = $i - 1; $k >= 0; $k--) {
                    if (
                        $tokens[$k]->id === T_WHITESPACE
                        || $tokens[$k]->id === T_PUBLIC
                        || $tokens[$k]->id === T_STATIC
                        || $tokens[$k]->id === T_FINAL
                        || $tokens[$k]->id === T_ABSTRACT
                    ) {
                        continue;
                    }
                    break;
                }
                if ($tokens[$k]->id !== T_DOC_COMMENT || !str_contains($tokens[$k]->text, self::MARKER)) {
                    continue;
                }
                $found[] = basename($file->getFilename(), '.php') . '::' . $name;
            }
        }
        $expected = [];
        foreach (self::deprecatedAccessors() as $key => [$class, $legacy]) {
            $expected[] = substr($class, (int) strrpos($class, '\\') + 1) . '::' . $legacy;
        }
        sort($found);
        sort($expected);
        $this->assertSame($expected, $found, 'src/ census drifted from the tested pairs');
        $this->assertCount(89, $found);
    }

    public function testTheReadmeTableAgreesWithTheSrcCensus(): void
    {
        $readme = (string) file_get_contents(\dirname(__DIR__) . '/README.md');
        $section = (string) preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY)[
            array_key_first(array_filter(
                preg_split('/^## /m', $readme, -1, PREG_SPLIT_NO_EMPTY),
                static fn (string $s): bool => str_starts_with($s, 'Accessor deprecation')
            ))
        ];
        preg_match_all(
            '/^\| `[^`]*` \| `(?P<class>[^`]+)` \| `(?P<legacy>get[A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER
        );
        $this->assertCount(89, $rows, 'README Accessor-deprecation table must list exactly the swept pairs');
        $tabled = [];
        foreach ($rows as $row) {
            $tabled[$row['class'] . '::' . $row['legacy']] = $row['bare'];
        }
        foreach (self::deprecatedAccessors() as [$class, $legacy, $bare]) {
            $short = substr($class, (int) strrpos($class, '\\') + 1);
            $this->assertArrayHasKey($short . '::' . $legacy, $tabled);
            $this->assertSame($bare, $tabled[$short . '::' . $legacy]);
        }
    }

    /**
     * Discovery leg (inverse census): every public get*() in src/ must
     * be a MARKED forwarder, an interface declaration itself, a
     * protocol implementation (an implemented interface declares the
     * same name), or a named carve-out. An unmarked public getFoo()
     * anywhere makes this red — the era cannot silently re-grow, and
     * a name shared with TreeProvider no longer excuses a class that
     * does not implement it.
     */
    public function testNoPublicGetAccessorEscapesTheSweep(): void
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src'));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize(file_get_contents($file->getPathname()));
            $count = \count($tokens);
            $namespace = '';
            $inInterface = false;
            $className = '';
            for ($i = 0; $i < $count; $i++) {
                $token = $tokens[$i];
                if ($token->id === T_NAMESPACE) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if ($tokens[$j]->is([T_WHITESPACE])) {
                            continue;
                        }
                        if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                            $namespace = $tokens[$j]->text;
                        }
                        break;
                    }
                    continue;
                }
                if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM])) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if ($tokens[$j]->is([T_WHITESPACE])) {
                            continue;
                        }
                        if ($tokens[$j]->id === T_STRING) {
                            $inInterface = $token->id === T_INTERFACE;
                            $className = $tokens[$j]->text;
                        }
                        break;
                    }
                    continue;
                }
                if ($token->id !== T_FUNCTION) {
                    continue;
                }
                $name = '';
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j]->is([T_WHITESPACE])) {
                        continue;
                    }
                    $name = $tokens[$j]->text;
                    break;
                }
                if (preg_match('/^get[A-Z]/', $name) !== 1) {
                    continue;
                }
                $private = false;
                $doc = null;
                for ($k = $i - 1; $k >= 0; $k--) {
                    $before = $tokens[$k];
                    if (
                        $before->is([T_WHITESPACE])
                        || $before->is([T_PUBLIC, T_STATIC, T_FINAL, T_ABSTRACT])
                        || (\defined('T_READONLY') && $before->id === T_READONLY)
                    ) {
                        continue;
                    }
                    if ($before->is([T_PRIVATE, T_PROTECTED])) {
                        $private = true;
                        break;
                    }
                    if ($before->id === T_DOC_COMMENT) {
                        $doc = $before->text;
                    }
                    break;
                }
                if ($private) {
                    continue;
                }
                $key = basename($file->getFilename(), '.php') . '::' . $name;
                if ($doc !== null && str_contains($doc, self::MARKER)) {
                    continue;
                }
                if ($inInterface) {
                    continue;
                }
                if ($className !== '') {
                    $fqcn = ($namespace !== '' ? $namespace . '\\' : '') . $className;
                    if (\class_exists($fqcn)) {
                        $protocolBound = false;
                        foreach (\class_implements($fqcn) as $interface) {
                            if (\interface_exists($interface) && \method_exists($interface, $name)) {
                                $protocolBound = true;
                                break;
                            }
                        }
                        if ($protocolBound) {
                            continue;
                        }
                    }
                }
                if (\in_array($key, self::NAMED_CARVE_OUTS, true)) {
                    continue;
                }
                $violations[] = $file->getPathname() . ':' . $token->line . ' ' . $key;
            }
        }
        $this->assertSame([], $violations, 'public get*() accessors escaped the deprecation sweep');
    }
}

/**
 * A concrete BaseModule stand-in: the lib class is abstract and
 * Module::name() is the only unimplemented member.
 */
final class ProbeModule extends \SugarCraft\Dashboard\Module\BaseModule
{
    public function name(): string
    {
        return 'probe';
    }
}
