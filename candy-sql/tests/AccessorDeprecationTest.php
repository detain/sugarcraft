<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests;

use PDOException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use SugarCraft\Sql\Admin\AdminQueryCache;
use SugarCraft\Sql\Admin\Connections\ConnectionsPage;
use SugarCraft\Sql\Admin\Connections\ConnectionDetailTabs;
use SugarCraft\Sql\Admin\History\HistoryQuery;
use SugarCraft\Sql\Admin\History\HistoryStoreInterface;
use SugarCraft\Sql\Admin\PerfSchema\SetupActors;
use SugarCraft\Sql\Admin\QueryLogger;
use SugarCraft\Sql\Renderer;
use SugarCraft\Sql\Admin\PerfSchema\SetupObjects;
use SugarCraft\Sql\Admin\PerfSchema\SetupTimers;
use SugarCraft\Sql\Admin\ServerContextInterface;
use SugarCraft\Sql\Admin\StatusSnapshot;
use SugarCraft\Sql\Admin\Variables\VariableEditor;
use SugarCraft\Sql\Db\DatabaseInterface;
use SugarCraft\Sql\Db\ReconnectException;
use SugarCraft\Sql\Explain\MysqlExplainProvider;
use SugarCraft\Sql\Explain\PostgresExplainProvider;
use SugarCraft\Sql\Explain\SqliteExplainProvider;

/**
 * Pins the get*-era sweep of the candy-sql public accessors (owner ruling 3b,
 * 2026-10-24): every renamed pair must (a) keep the legacy name delegating to
 * the bare twin byte-identically, (b) carry the machine-readable deprecation
 * marker, (c) appear in the src census and the README table, and (d) leave no
 * un-annotated public get-prefix accessor anywhere in src/.
 *
 * The forwarders are one-line delegators by contract; the bare twin is the
 * keeper of behaviour and its docblock carries no removal marker.
 */
final class AccessorDeprecationTest extends TestCase
{
    public const MARKER = 'REMOVED-IN: 2.0.0';

    /**
     * Named keepers allowed to stay get-prefixed without a marker. Empty for
     * candy-sql: the era census swept all 20 members (the 20th,
     * ExplainProviderInterface::getDriverName(), is an interface member renamed
     * outright — interface declarations are exempted by the inverse leg).
     */
    private const NAMED_CARVE_OUTS = [];

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: list<mixed>, 4?: callable(mixed): mixed}>
     */
    public static function deprecatedAccessors(): array
    {
        return [
            'history-rate' => [
                HistoryQuery::class, 'getRate', 'rate', ['Queries', 1.0, 2.0],
            ],
            'admin-cache-status' => [
                AdminQueryCache::class, 'getStatusVariables', 'statusVariables', [],
            ],
            'admin-cache-server' => [
                AdminQueryCache::class, 'getServerVariables', 'serverVariables', [],
            ],
            'connections-table' => [
                ConnectionsPage::class, 'getTable', 'table', [],
                static fn (object $t): string => $t->View(),
            ],
            'tabs-details' => [
                ConnectionDetailTabs::class, 'getDetails', 'details', [1],
            ],
            'tabs-attributes' => [
                ConnectionDetailTabs::class, 'getAttributes', 'attributes', [1],
            ],
            'tabs-mdl' => [
                ConnectionDetailTabs::class, 'getMdlLocks', 'mdlLocks', [1],
            ],
            'tabs-stack' => [
                ConnectionDetailTabs::class, 'getThreadStack', 'threadStack', [1],
            ],
            'tabs-explain' => [
                ConnectionDetailTabs::class, 'getExplain', 'explain', [1],
            ],
            'setup-actors-change' => [
                SetupActors::class, 'getChangeType', 'changeType', [],
            ],
            'setup-objects-change' => [
                SetupObjects::class, 'getChangeType', 'changeType', [],
            ],
            'setup-timers-change' => [
                SetupTimers::class, 'getChangeType', 'changeType', [],
            ],
            'variables-edit-preview' => [
                VariableEditor::class, 'getEditPreview', 'editPreview', ['max_connections', '200'],
            ],
            'snapshot-int' => [
                StatusSnapshot::class, 'getInt', 'int', ['Queries'],
            ],
            'snapshot-float' => [
                StatusSnapshot::class, 'getFloat', 'float', ['Uptime'],
            ],
            'reconnect-original' => [
                ReconnectException::class, 'getOriginal', 'original', [],
            ],
            'explain-sqlite-driver' => [
                SqliteExplainProvider::class, 'getDriverName', 'driverName', [],
            ],
            'explain-postgres-driver' => [
                PostgresExplainProvider::class, 'getDriverName', 'driverName', [],
            ],
            'explain-mysql-driver' => [
                MysqlExplainProvider::class, 'getDriverName', 'driverName', [],
            ],
            'query-log-entries' => [
                QueryLogger::class, 'getEntries', 'entries', [],
            ],
            'query-log-display-lines' => [
                QueryLogger::class, 'getDisplayLines', 'displayLines', [],
            ],
            'renderer-terminal-size' => [
                Renderer::class, 'getTerminalSize', 'terminalSize', [],
            ],
        ];
    }

    public function testTheSweepCoversNineteenPublicForwarders(): void
    {
        $this->assertCount(22, self::deprecatedAccessors());
        $this->assertCount(22, self::srcCensus());
    }

    /**
     * @dataProvider deprecatedAccessors
     */
    public function testTheDeprecatedForwarderMatchesTheBareTwin(
        string $class,
        string $legacy,
        string $bare,
        array $args,
        ?callable $normalizer = null,
    ): void {
        if ((new ReflectionMethod($class, $legacy))->isStatic()) {
            $viaBare = $normalizer === null
                ? $class::$bare(...$args)
                : $normalizer($class::$bare(...$args));
            $viaLegacy = $normalizer === null
                ? $class::$legacy(...$args)
                : $normalizer($class::$legacy(...$args));
            $this->assertEquals($viaBare, $viaLegacy, $class . '::' . $legacy . '() must return what ' . $bare . '() returns');
            return;
        }

        $subject = $this->fixture($class);

        $this->assertTrue(method_exists($subject, $bare), $class . '::' . $bare . '() must exist');
        $this->assertTrue(method_exists($subject, $legacy), $class . '::' . $legacy . '() must exist');

        $viaBare = $normalizer === null
            ? $subject->{$bare}(...$args)
            : $normalizer($subject->{$bare}(...$args));
        $viaLegacy = $normalizer === null
            ? $subject->{$legacy}(...$args)
            : $normalizer($subject->{$legacy}(...$args));

        $this->assertEquals($viaBare, $viaLegacy, $class . '::' . $legacy . '() must return what ' . $bare . '() returns');
    }

    /**
     * @dataProvider deprecatedAccessors
     */
    public function testEverySweptForwarderCarriesMarkersAndSingleReturn(
        string $class,
        string $legacy,
        string $bare,
        array $args,
        ?callable $normalizer = null,
    ): void {
        $forwarder = new ReflectionMethod($class, $legacy);
        $keeper = new ReflectionMethod($class, $bare);

        $this->assertTrue($forwarder->isPublic());

        $doc = (string) $forwarder->getDocComment();
        $this->assertStringContainsString('@deprecated', $doc, $class . '::' . $legacy . '() needs @deprecated');
        $this->assertStringContainsString(self::MARKER, $doc, $class . '::' . $legacy . '() needs the removal marker');
        $this->assertStringContainsString('{@see self::' . $bare . '()}', $doc);

        $keeperDoc = (string) $keeper->getDocComment();
        $this->assertStringNotContainsString(self::MARKER, $keeperDoc, $class . '::' . $bare . '() is the keeper');

        $file = file($forwarder->getFileName());
        $body = implode('', array_slice(
            $file,
            $forwarder->getStartLine() - 1,
            $forwarder->getEndLine() - $forwarder->getStartLine() + 1
        ));
        $this->assertCount(1, array_filter(explode("\n", $body)), $legacy . '() must be one line');

        $delegate = $forwarder->isStatic()
            ? 'return self::' . $bare
            : 'return $this->' . $bare;
        $pattern = '/public (?:static )?function ' . preg_quote($legacy, '/') . '\(.*\)[^{]*\{ ' . preg_quote($delegate, '/')
            . '\((\$\w+(, \$\w+)*)?\); \}\s*$/';
        $this->assertMatchesRegularExpression(
            $pattern,
            $body,
            $legacy . '() must be a one-line forwarder to ' . $bare . '()'
        );

        $this->assertSame(
            $keeper->getNumberOfParameters(),
            $forwarder->getNumberOfParameters(),
            $legacy . '() must keep the same signature width'
        );
        $this->assertSame(
            (string) $keeper->getReturnType(),
            (string) $forwarder->getReturnType()
        );
    }

    public function testTheSrcCensusFindsExactlyTheTestedPairs(): void
    {
        $expected = [];
        foreach (self::deprecatedAccessors() as [$class, $legacy]) {
            $expected[] = substr($class, (int) strrpos($class, '\\') + 1) . '::' . $legacy;
        }
        sort($expected);

        $found = self::srcCensus();
        sort($found);

        $this->assertSame($expected, $found);
    }

    public function testTheReadmeTableAgreesWithTheSrcCensus(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__) . '/README.md');

        $section = null;
        foreach (preg_split('/^## /m', $readme) as $chunk) {
            if (str_starts_with($chunk, 'Accessor deprecation')) {
                $section = $chunk;
                break;
            }
        }
        $this->assertNotNull($section, 'README needs the dated "Accessor deprecation" section');

        $rows = [];
        preg_match_all(
            '/^\| `[^`]*` \| `(?P<class>[^`]+)` \| `(?P<legacy>get[A-Za-z]+)\(\)` \| `(?P<bare>[a-z][A-Za-z]+)\(\)` \|$/m',
            $section,
            $rows,
            PREG_SET_ORDER
        );
        $this->assertCount(22, $rows, 'the README table must list all 19 forwarders');

        $documented = [];
        foreach ($rows as $row) {
            $documented[] = $row['class'] . '::' . $row['legacy'];
        }
        sort($documented);

        $census = self::srcCensus();
        sort($census);

        $this->assertSame($census, $documented, 'README and src census must agree member-for-member');
    }

    public function testNoPublicGetAccessorEscapesTheSweep(): void
    {
        $violations = [];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $inInterface = false;
            $class = '';
            foreach ($tokens as $i => $token) {
                if ($token->is([T_INTERFACE, T_CLASS, T_TRAIT])) {
                    // Remember kind for the member that follows; classes reset
                    // the interface flag, interfaces set it.
                    $kind = $token->id;
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->id === T_WHITESPACE) {
                            continue;
                        }
                        if ($tokens[$j]->id === T_FINAL) {
                            continue;
                        }
                        if ($tokens[$j]->id === T_STRING || $tokens[$j]->id === T_NAME_QUALIFIED) {
                            $name = $tokens[$j]->text;
                            if ($kind === T_INTERFACE) {
                                $inInterface = true;
                                $class = $name;
                            } elseif ($kind === T_CLASS) {
                                $inInterface = false;
                                $class = $name;
                            }
                        }
                        break;
                    }
                    continue;
                }
                if (!$token->is(T_FUNCTION)) {
                    continue;
                }
                $name = null;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->is(T_WHITESPACE)) {
                        continue;
                    }
                    if ($token->id === T_FUNCTION && $tokens[$j]->is(T_STRING)) {
                        $name = $tokens[$j]->text;
                    }
                    break;
                }
                if ($name === null || !preg_match('/^get[A-Z]/', $name)) {
                    continue;
                }
                if ($inInterface) {
                    continue; // interface members are renamed outright, not forwarded
                }
                // Visibility: default is public; skip private/protected.
                $visibility = 'public';
                $doc = '';
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->is([T_STATIC, T_FINAL, T_READONLY, T_ABSTRACT])) {
                        continue;
                    }
                    if ($tokens[$j]->is([T_PUBLIC, T_PRIVATE, T_PROTECTED])) {
                        $visibility = strtolower($tokens[$j]->text);
                        continue;
                    }
                    if ($tokens[$j]->id === T_DOC_COMMENT) {
                        $doc = $tokens[$j]->text;
                    }
                    break;
                }
                if ($visibility !== 'public') {
                    continue;
                }
                if (str_contains($doc, self::MARKER)) {
                    continue; // swept and annotated
                }
                if (in_array($class . '::' . $name, self::NAMED_CARVE_OUTS, true)) {
                    continue;
                }
                // Protocol-bound: a class implementing an interface that declares
                // the same member cannot break rank on the interface.
                $fqcn = self::resolveClass((string) file_get_contents($file->getPathname()), $class);
                if ($fqcn !== null && class_exists($fqcn)) {
                    foreach (class_implements($fqcn) as $interface) {
                        if (interface_exists($interface) && method_exists($interface, $name)) {
                            continue 2;
                        }
                    }
                }
                $violations[] = $file->getPathname() . ' ' . $class . '::' . $name . '()';
            }
        }

        $this->assertSame([], $violations, 'a public get-prefix accessor escaped the sweep');
    }

    /** @return list<string> ShortClass::legacyName for every marker'd forwarder in src/ */
    private static function srcCensus(): array
    {
        $found = [];
        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__) . '/src'));
        foreach ($iter as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $tokens = \PhpToken::tokenize((string) file_get_contents($file->getPathname()));
            $class = '';
            foreach ($tokens as $i => $token) {
                if ($token->id === T_CLASS) {
                    for ($j = $i + 1; $j < count($tokens); $j++) {
                        if ($tokens[$j]->is(T_WHITESPACE)) {
                            continue;
                        }
                        if ($tokens[$j]->is([T_STRING, T_NAME_QUALIFIED])) {
                            $class = $tokens[$j]->text;
                        }
                        break;
                    }
                    continue;
                }
                if (!$token->is(T_FUNCTION)) {
                    continue;
                }
                $name = null;
                for ($j = $i + 1; $j < count($tokens); $j++) {
                    if ($tokens[$j]->is(T_WHITESPACE)) {
                        continue;
                    }
                    if ($tokens[$j]->is(T_STRING)) {
                        $name = $tokens[$j]->text;
                    }
                    break;
                }
                if ($name === null || !preg_match('/^get[A-Z]/', $name)) {
                    continue;
                }
                for ($j = $i - 1; $j >= 0; $j--) {
                    if ($tokens[$j]->is(T_WHITESPACE) || $tokens[$j]->is([T_STATIC, T_FINAL, T_READONLY, T_ABSTRACT, T_PUBLIC])) {
                        continue;
                    }
                    if ($tokens[$j]->id === T_DOC_COMMENT && str_contains($tokens[$j]->text, self::MARKER)) {
                        $found[] = $class . '::' . $name;
                    }
                    break;
                }
            }
        }
        return $found;
    }

    /** @return object A ready subject exercising both twins. */
    private function fixture(string $class): object
    {
        $db = self::createMock(DatabaseInterface::class);

        if ($class === HistoryQuery::class) {
            $store = self::createMock(HistoryStoreInterface::class);
            $store->method('query')->willReturn([
                new StatusSnapshot(['Queries' => '10', 'Uptime' => '10'], 1.0),
                new StatusSnapshot(['Queries' => '20', 'Uptime' => '20'], 2.0),
            ]);
            return new HistoryQuery($store);
        }

        if ($class === AdminQueryCache::class) {
            $cache = new AdminQueryCache();
            $cache->store('status', [['Variable_name' => 'Uptime', 'Value' => '7']]);
            $cache->store('server', [['Variable_name' => 'max_connections', 'Value' => '151']]);
            return $cache;
        }

        $context = self::createMock(ServerContextInterface::class);

        if ($class === ConnectionsPage::class) {
            $db->method('query')->willReturn([]);
            $context->method('connection')->willReturn($db);
            $context->method('statusVariables')->willReturn([]);
            $context->method('statusVariablesTs')->willReturn(0.0);
            return ConnectionsPage::new($context);
        }

        if ($class === ConnectionDetailTabs::class) {
            $db->method('prepare')->willThrowException(new PDOException('simulated'));
            $context->method('connection')->willReturn($db);
            return ConnectionDetailTabs::new($context);
        }

        if ($class === SetupActors::class) {
            return SetupActors::new();
        }
        if ($class === SetupObjects::class) {
            return SetupObjects::new();
        }
        if ($class === SetupTimers::class) {
            return SetupTimers::new();
        }

        if ($class === VariableEditor::class) {
            $db->method('query')->willReturn([]);
            $context->method('connection')->willReturn($db);
            return VariableEditor::new($context);
        }

        if ($class === StatusSnapshot::class) {
            return new StatusSnapshot(['Queries' => '42', 'Uptime' => '12.5'], 1.0);
        }

        if ($class === ReconnectException::class) {
            return new ReconnectException('gone', new PDOException('orig'));
        }

        return new $class($db); // the three Explain providers take a DatabaseInterface
    }

    /** @return class-string|null */
    private static function resolveClass(string $src, string $short): ?string
    {
        if (!preg_match('/^namespace\s+([^;]+);/m', $src, $ns)) {
            return null;
        }
        $fqcn = trim($ns[1]) . '\\' . $short;
        return class_exists($fqcn) ? $fqcn : null;
    }
}
