<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher\Tests;

use SugarCraft\SshLauncher\Config;
use SugarCraft\SshLauncher\Lang;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParseJsonRoundTrip(): void
    {
        $raw = <<<JSON
        [
          {"name": "production", "host": "prod.example.com", "port": 2222, "user": "deploy"},
          {"name": "staging", "host": "stage.example.com"}
        ]
        JSON;
        $endpoints = Config::parse($raw, 'wishlist.json');
        $this->assertCount(2, $endpoints);
        $this->assertSame('production',         $endpoints[0]->name);
        $this->assertSame('prod.example.com',   $endpoints[0]->host);
        $this->assertSame(2222,                 $endpoints[0]->port);
        $this->assertSame('deploy',             $endpoints[0]->user);
        $this->assertSame(22,                   $endpoints[1]->port);
    }

    public function testParseYamlFlatList(): void
    {
        $raw = <<<YAML
        - name: production
          host: prod.example.com
          port: 2222
          user: deploy

        - name: staging
          host: stage.example.com
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(2, $endpoints);
        $this->assertSame('production',         $endpoints[0]->name);
        $this->assertSame('prod.example.com',   $endpoints[0]->host);
        $this->assertSame(2222,                 $endpoints[0]->port);
        $this->assertSame('deploy',             $endpoints[0]->user);
        $this->assertSame('staging',            $endpoints[1]->name);
        $this->assertSame(22,                   $endpoints[1]->port);
    }

    public function testYamlComments(): void
    {
        $raw = <<<YAML
        # top-level comment
        - name: prod        # inline comment
          host: prod.test
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod',      $endpoints[0]->name);
        $this->assertSame('prod.test', $endpoints[0]->host);
    }

    public function testYamlScalarTypeCoercion(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          port: 9000
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame(9000, $endpoints[0]->port);
        $this->assertIsInt($endpoints[0]->port);
    }

    public function testYamlIdentityFileSnakeAndCamel(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          identity_file: /tmp/key1
        - name: b
          host: b.test
          identityFile: /tmp/key2
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame(['/tmp/key1'], $endpoints[0]->identityFiles);
        $this->assertSame(['/tmp/key2'], $endpoints[1]->identityFiles);
    }

    // Step 22 (F-4): identity fields on the FLAT path expand a leading `~`
    // exactly like the ssh_config import path has always done. OpenSSH 9.x
    // happens to expand `~/...` in argv too, but against the PASSWD home and
    // not $HOME — so a launcher that passed the literal through could silently
    // pick a different key than the import path expands to, and non-OpenSSH
    // clients (dropbear/busybox) never expand argv tildes at all.

    public function testYamlIdentityFileTildeExpandsToHome(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          identity_file: ~/.ssh/prod-deploy
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame([$this->home() . '/.ssh/prod-deploy'], $endpoints[0]->identityFiles);
    }

    public function testYamlIdentityFileCamelTildeExpandsToHome(): void
    {
        $raw = <<<YAML
        - name: a
          host: a.test
          identityFile: ~/.ssh/camel
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame([$this->home() . '/.ssh/camel'], $endpoints[0]->identityFiles);
    }

    public function testJsonIdentityFilesListTildesExpandAndLiteralsSurvive(): void
    {
        // The plural list expands per item; every non-leading-tilde shape
        // (absolute, relative, mid-string, path-segment) is byte-untouched.
        $endpoints = Config::parse(
            '[{"name":"a","host":"a.test","identityFiles":["~/k1","/abs/k2","./rel/k3","mid~dle","/x/~/y"]}]',
            'wishlist.json',
        );
        $this->assertSame(
            [$this->home() . '/k1', '/abs/k2', './rel/k3', 'mid~dle', '/x/~/y'],
            $endpoints[0]->identityFiles,
        );
    }

    public function testIdentityQuotedBareTildeExpandsToHome(): void
    {
        // Quoted "~" is a string scalar and lands on the home directory.
        $raw = "- name: a\n  host: a.test\n  identity_file: \"~\"\n";
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame([self::home()], $endpoints[0]->identityFiles);
    }

    public function testIdentityUnquotedBareTildeStaysNull(): void
    {
        // Unquoted bare "~" remains YAML null (null meaning, not a path):
        // the field is simply absent and NO identity entry is minted.
        $raw = "- name: a\n  host: a.test\n  identity_file: ~\n";
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertSame([], $endpoints[0]->identityFiles);
    }

    public function testOmittedOptionalFieldHasThreeLegalSpellings(): void
    {
        // -019 (docs pin): an optional field is left out, or written
        // `key: null` / `key: ~` — all three read as omitted. A BARE `key:`
        // is a different shape: it opens list mode for the list fields and
        // is refused for the scalar ones, never silently omitted.
        foreach (['null' => 'user: null', 'tilde' => 'user: ~', 'absent' => ''] as $spelling => $line) {
            $raw = "- name: a\n  host: a.test\n  $line\n";
            $endpoints = Config::parse($raw, 'wishlist.yml');
            $this->assertNull($endpoints[0]->user, "spelling '$spelling' means omitted");
        }
    }

    public function testBareScalarKeyIsRefusedNotOmitted(): void
    {
        // -019 refusal leg: `user:` with nothing under it arrives as the
        // empty block shape, and the scalar guard names it.
        $raw = "- name: a\n  host: a.test\n  user:\n";
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('field [user] must be a scalar, got array');
        Config::parse($raw, 'wishlist.yml');
    }

    public function testBareListKeyOpensListItemMode(): void
    {
        // -019 list leg: `options:` with nothing on the line is exactly the
        // block-list opener the README promises; with no items under it,
        // the field reads as empty (null scalar → []).
        $withItems = Config::parse("- name: a\n  host: a.test\n  options:\n  - x\n", 'wishlist.yml');
        $this->assertSame(['x'], $withItems[0]->options);

        $empty = Config::parse("- name: a\n  host: a.test\n  options:\n", 'wishlist.yml');
        $this->assertSame([], $empty[0]->options);
    }

    public function testValuelessEntryHeaderAccumulatesListThenRefusesByPosition(): void
    {
        // -099(c): the `- key:` ENTRY-HEADER branch (value-less first key of
        // a row opens the same list mode) was an unpinned defensive leg.
        // The distinguishing byte: if the header branch dropped list mode,
        // the indented `- item` line would surface as "unparseable line 2";
        // because it opens correctly, the row fails validation the honest
        // way — by position and missing field name (-052 pairing).
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("wishlist entry #2 is missing a required field: name");
        Config::parse(
            "- name: a\n  host: a.test\n- options:\n  - ServerAliveInterval=30\n",
            'wishlist.yml',
        );
    }

    public function testIdentityExpansionNeverTouchesNonTildePathsEvenWhenHomeMoves(): void
    {
        // Trust-boundary leg: expansion consults $HOME only for values that
        // START with `~`; a relocated HOME must not rewrite anything else.
        $prior = getenv('HOME');
        putenv('HOME=/tmp/c4-poison-home');
        try {
            $endpoints = Config::parse(
                '[{"name":"a","host":"a.test","identityFiles":["/abs/id","./rel/id"]}]',
                'wishlist.json',
            );
        } finally {
            if ($prior === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $prior);
            }
        }
        $this->assertSame(['/abs/id', './rel/id'], $endpoints[0]->identityFiles);
    }

    public function testIdentityUserTildeResolvesViaPasswdAndUnresolvableStaysLiteral(): void
    {
        if (!function_exists('posix_getpwuid') || !function_exists('posix_getpwnam')) {
            $this->markTestSkipped('posix extension required for ~user expansion');
        }
        $pw = posix_getpwuid(posix_geteuid());
        $this->assertIsArray($pw);
        $me = (string) $pw['name'];
        $myHome = rtrim((string) $pw['dir'], '/');
        $endpoints = Config::parse(
            '[{"name":"a","host":"a.test","identityFiles":["~' . $me . '/shared-key","~nosuchuser42/k"]}]',
            'wishlist.json',
        );
        $this->assertSame([$myHome . '/shared-key', '~nosuchuser42/k'], $endpoints[0]->identityFiles);
    }

    public function testOtherFieldsNeverExpandTilde(): void
    {
        // Scope leg: ONLY identity fields are expanded. proxyJump and options
        // ride to ssh exactly as written, tildes included.
        $endpoints = Config::parse(
            '[{"name":"a","host":"a.test","proxyJump":"~/jump","options":["Mode=~x"]}]',
            'wishlist.json',
        );
        $this->assertSame('~/jump', $endpoints[0]->proxyJump);
        $this->assertSame(['Mode=~x'], $endpoints[0]->options);
    }

    /**
     * Mirror of the expansion home ladder ($HOME, then passwd entry) for
     * expected-value construction in the tests above.
     */
    private function home(): string
    {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            return rtrim($home, '/');
        }
        $this->assertTrue(function_exists('posix_getpwuid') && function_exists('posix_geteuid'));
        $pw = posix_getpwuid(posix_geteuid());
        $this->assertIsArray($pw);
        return rtrim((string) $pw['dir'], '/');
    }

    public function testYamlNestedOptionsList(): void
    {
        // Nested string lists under a value-less key are the only
        // shape of nested mapping the wishlist schema needs — the
        // sample-wishlist.yml exercises this for `options:` so the
        // jumpbox entry can carry through `ProxyJump=...` etc.
        $raw = <<<YAML
        - name: jumpbox
          host: bastion.example.com
          options:
            - ServerAliveInterval=30
            - ProxyJump=gw.example.com
        YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(
            ['ServerAliveInterval=30', 'ProxyJump=gw.example.com'],
            $endpoints[0]->options,
        );
    }

    public function testYamlSampleConfigParses(): void
    {
        // End-to-end: the bundled sample-wishlist.yml — which the
        // VHS picker recording uses — must parse cleanly.
        $raw = file_get_contents(__DIR__ . '/../examples/sample-wishlist.yml');
        $this->assertNotFalse($raw);
        $endpoints = Config::parse($raw, 'sample-wishlist.yml');
        $this->assertCount(4, $endpoints);
        $this->assertSame('production', $endpoints[0]->name);
        $this->assertSame('jumpbox',    $endpoints[3]->name);
        $this->assertNotEmpty($endpoints[3]->options);
    }

    public function testRejectsTopLevelObjectInJson(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::parse('{"oops": true}', 'wishlist.json');
    }

    public function testRejectsMissingRequiredField(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is missing a required field: host');
        Config::parse('[{"name": "incomplete"}]', 'wishlist.json');
    }

    public function testLoadFromFile(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'candy-ssh-launcher') . '.json';
        file_put_contents($tmp, '[{"name":"a","host":"a.test"}]');
        try {
            $endpoints = Config::load($tmp);
            $this->assertCount(1, $endpoints);
        } finally {
            unlink($tmp);
        }
    }

    public function testLoadMissingFileThrows(): void
    {
        $this->expectException(\RuntimeException::class);
        Config::load('/nonexistent/path/wishlist.json');
    }

    public function testYamlDocMarkerParsesSuccessfully(): void
    {
        // YAML document markers (---) at the start should be skipped gracefully.
        $raw = <<<YAML
---
- name: prod
  host: prod.example.com
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod', $endpoints[0]->name);
    }

    public function testYamlTabIndentedContinuationValue(): void
    {
        // A tab before a continuation value should be normalized to spaces
        // so the nested option under `options:` parses correctly.
        $raw = <<<YAML
- name: prod
  host: prod.example.com
  options:
  	- ServerAliveInterval=60
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(1, $endpoints);
        $this->assertSame(
            ['ServerAliveInterval=60'],
            $endpoints[0]->options,
        );
    }

    public function testYamlGarbageLineStillThrows(): void
    {
        // A genuinely malformed line (not a comment, not a doc marker, not
        // a valid key:value) should still throw yaml_unparseable.
        $raw = <<<YAML
- name: prod
  host: prod.example.com
!!!not a valid line!!!
YAML;
        $this->expectException(\RuntimeException::class);
        Config::parse($raw, 'wishlist.yml');
    }

    public function testRejectsJsonEntryObject(): void
    {
        // A top-level array whose entries are not objects (e.g. [1, 2])
        // should throw config.json_entry_object.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('each entry must be an object');
        Config::parse('[1, 2]', 'wishlist.json');
    }

    public function testRejectsYamlContinuationWithoutHeader(): void
    {
        // A YAML doc that begins with an indented continuation key
        // (before any - name: header) should throw yaml_continuation.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("continuation before any '- name:' block");
        Config::parse("  port: 2222\n- name: prod", 'wishlist.yml');
    }

    public function testMalformedJsonRejectedViaGuardedDecode(): void
    {
        // A present-but-corrupt JSON config must be rejected with the
        // localized config error. The guarded decode (sugar-core
        // Json::decodeArray, JSON_THROW_ON_ERROR) chains the underlying
        // \JsonException as the cause; the old unguarded json_decode()
        // returned null with no cause, so getPrevious() pins the guard.
        try {
            Config::parse('[{"name": "prod", "host":', 'wishlist.json');
            $this->fail('Expected malformed JSON to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame(Lang::t('config.json_top_level'), $e->getMessage());
            $this->assertInstanceOf(\JsonException::class, $e->getPrevious());
        }
    }

    public function testNonArrayScalarJsonTopLevelRejectedViaGuard(): void
    {
        // A present-but-non-array top level (a bare scalar) must be rejected.
        // The guarded decode raises a \RuntimeException that is chained as the
        // cause; the old json_decode()->!is_array() path threw with no cause.
        try {
            Config::parse('42', 'wishlist.json');
            $this->fail('Expected non-array JSON top level to be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame(Lang::t('config.json_top_level'), $e->getMessage());
            $this->assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonScalarFieldProvider(): array
    {
        // Each entry supplies a JSON config whose named field carries a
        // non-scalar (array/object) value. Without the is_scalar() guards
        // these would silently (string)/(int)-cast — e.g. `ssh user@Array`.
        return [
            'name is array'              => ['[{"name": ["oops"], "host": "h.test"}]'],
            'host is object'            => ['[{"name": "n", "host": {"a": 1}}]'],
            'user is array'             => ['[{"name": "n", "host": "h.test", "user": ["deploy"]}]'],
            'port is array'             => ['[{"name": "n", "host": "h.test", "port": [22]}]'],
            'description is array'      => ['[{"name": "n", "host": "h.test", "description": ["d"]}]'],
            'proxyJump is array'        => ['[{"name": "n", "host": "h.test", "proxyJump": ["gw"]}]'],
            'options element is array'  => ['[{"name": "n", "host": "h.test", "options": [["nested"]]}]'],
            'identityFiles element arr' => ['[{"name": "n", "host": "h.test", "identityFiles": [["nested"]]}]'],
        ];
    }

    #[DataProvider('nonScalarFieldProvider')]
    public function testNonScalarFieldShapeIsRejected(string $json): void
    {
        // Revert-prove: drop the assertScalar() guards in Config::buildEndpoint
        // and these silently (string)/(int)-cast an array into the ssh argv
        // (the classic `ssh user@Array` footgun) instead of throwing.
        $this->expectException(\InvalidArgumentException::class);
        Config::parse($json, 'wishlist.json');
    }

    public function testScalarFieldsStillParseAfterGuards(): void
    {
        // The guards must not reject legitimate scalar-valued configs.
        $raw = '[{"name": "prod", "host": "prod.test", "port": 2222, "user": "deploy", '
             . '"identityFiles": ["/tmp/key"], "options": ["ServerAliveInterval=30"], '
             . '"description": "prod box", "proxyJump": "gw.test"}]';
        $endpoints = Config::parse($raw, 'wishlist.json');
        $this->assertCount(1, $endpoints);
        $this->assertSame('prod',    $endpoints[0]->name);
        $this->assertSame(2222,      $endpoints[0]->port);
        $this->assertSame('deploy',  $endpoints[0]->user);
        $this->assertSame(['/tmp/key'], $endpoints[0]->identityFiles);
        $this->assertSame(['ServerAliveInterval=30'], $endpoints[0]->options);
    }

    public function testYamlCommentStrippingIsQuoteAware(): void
    {
        // F-18 (Step 16): a '#' inside quotes is data. The old blind strip
        // cut `description: "hotfix #1"` mid-value and mangled option
        // commands; unquoted whitespace-hash comments keep working.
        $raw = <<<'YAML'
- name: prod
  host: p.test
  description: "hotfix #1"
- name: web # tag
  host: w.test
  description: 'plain #2'
- name: cmd
  host: c.test
  options:
    - "VerifyOnly #3"
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(3, $endpoints);
        $this->assertSame('hotfix #1', $endpoints[0]->description);
        $this->assertSame('web', $endpoints[1]->name);
        $this->assertSame('plain #2', $endpoints[1]->description);
        $this->assertSame(['VerifyOnly #3'], $endpoints[2]->options);
    }

    public function testYamlEscapedQuoteLegsKeepTheHashOnTheConfigCarrier(): void
    {
        // -095 (F-18 promise, escaped legs): the backslash-pair consume and
        // the doubled '' keep the scanner inside the quoted value, so a '#'
        // after an ESCAPED quote still does not truncate — the leg the
        // clover report never exercised. Since -069 the closing scalar is
        // also unescaped: the escape pairs resolve to the bytes the value
        // was quoted to protect.
        $raw = <<<'YAML'
- name: prod
  host: p.test
  description: "a\"b # c"
- name: single
  host: s.test
  description: 'it''s #1'
YAML;
        $endpoints = Config::parse($raw, 'wishlist.yml');
        $this->assertCount(2, $endpoints);
        $this->assertSame('a"b # c', $endpoints[0]->description);
        $this->assertSame("it's #1", $endpoints[1]->description);
    }

    /** @return array<string, array{string, string}> */
    public static function yamlQuoteStateProvider(): array
    {
        $dq = chr(34);
        $sq = chr(39);
        $bs = chr(92);
        $tb = chr(9);

        return [
            // -066: a tab inside a quoted scalar is data — the ssh_config
            // ingest path rewrites no bytes mid-value either.
            'tab inside quotes survives' => [
                $dq . 'x' . $tb . 'y' . $dq,
                'x' . $tb . 'y',
            ],
            // -069: the two escape pairs the scanner recognizes resolve.
            'escaped double quote unescapes' => [
                $dq . 'a' . $bs . $dq . 'b' . $dq,
                'a"b',
            ],
            'escaped backslash unescapes' => [
                $dq . 'a' . $bs . $bs . 'b' . $dq,
                'a' . $bs . 'b',
            ],
            'backslash before ordinary char rides verbatim' => [
                $dq . 'a' . $bs . 'nb' . $dq,
                'a' . $bs . 'nb',
            ],
            'doubled single quote unescapes' => [
                $sq . 'it' . $sq . $sq . 's' . $sq,
                "it's",
            ],
            'empty double-quoted scalar is empty string' => [$dq . $dq, ''],
            'quoted inline flow stays an ordinary scalar' => [
                $dq . '[a, b]' . $dq,
                '[a, b]',
            ],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('yamlQuoteStateProvider')]
    public function testYamlQuoteStateScalars(string $quoted, string $expected): void
    {
        $endpoints = Config::parse(
            "- name: a\n  host: h.test\n  description: " . $quoted . "\n",
            'wishlist.yml'
        );
        $this->assertSame($expected, $endpoints[0]->description);
    }

    public function testUnterminatedDoubleQuoteIsRefusedNotDispatched(): void
    {
        // -068 measured pre-fix: `host: "unclosed` produced the literal
        // quote-prefixed hostname `"unclosed` on the argv path. The quote
        // must now name a broken config instead.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unterminated quote');
        Config::parse("- name: a\n  host: \"unclosed\n", 'wishlist.yml');
    }

    public function testUnterminatedSingleQuoteIsRefusedNotDispatched(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unterminated quote');
        Config::parse("- name: a\n  host: h.test\n  description: 'no end\n", 'wishlist.yml');
    }

    public function testEscapedQuoteDoesNotFakeTermination(): void
    {
        // The only quote byte inside is escaped — the scalar is still open.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unterminated quote');
        Config::parse("- name: a\n  host: h.test\n  description: \"a\\\"b\n", 'wishlist.yml');
    }

    public function testLeadingTabIndentationStillParses(): void
    {
        // -066's other half: the leading-indent expansion (not the global
        // one) must keep tab-indented continuation keys working.
        $endpoints = Config::parse("- name: a\n\thost: h.test\n", 'wishlist.yml');
        $this->assertSame('h.test', $endpoints[0]->host);
    }
}
