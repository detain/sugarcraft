<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * The site page is generated from `docs/_data/sugar-ssh-server.body.html`, and
 * that body once described an API the library does not have: a two-argument
 * `InProcessTransport` constructor (the real one takes a single optional
 * `PtySystem`; the channel handler is a setter, so the documented call
 * compiled, PHP dropped the extra argument, and a custom handler silently
 * never ran), protocol metadata "populated after SSH handshake" (there is no
 * handshake here), an RFC 4256 challenge flow (it prints a prompt and reads a
 * line), and SSH-level keepalives (it writes a NUL to the child's stdin).
 *
 * Those are the shapes that survive a code refactor unnoticed because nothing
 * reads the prose, so each cure lands with a line here. The checks are on the
 * `_data` source rather than the generated page — that is what an editor
 * changes. In a standalone package checkout the monorepo docs directory does
 * not exist, and those tests skip: the lib's own README is always present and
 * is checked unconditionally.
 */
final class DocSurfaceTruthTest extends TestCase
{
    private const SITE_BODY = '/docs/_data/sugar-ssh-server.body.html';

    private const SITE_JSON = '/docs/_data/sugar-ssh-server.json';

    public function testTheBannerTruthTravelsWithTheOptInFlag(): void
    {
        $src = (string) \preg_replace(
            '/\s+/',
            ' ',
            (string) \file_get_contents(\dirname(__DIR__) . '/src/Middleware/Auth/AuthMethods.php')
        );
        // The code: silence is the default, and the reason is on the class.
        self::assertStringContainsString('bool $announce = false', $src);
        self::assertStringContainsString('opt-in, and off by default (audit MAJOR 7)', $src);

        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }
        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        self::assertStringContainsString('**opt-in** (`announce: true`) and off by default', $readme);
        self::assertStringNotContainsString('writes `SSH_AUTH_METHODS` banner to STDOUT; stores list in Context', $readme);

        $admin = $this->adminGuide();
        if ($admin === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }
        $admin = (string) \preg_replace('/\s+/', ' ', $admin);
        self::assertStringContainsString('It is *not* the RFC 4252 banner and cannot be', $admin);
        self::assertStringContainsString('is opt-in (`announce: true`)', $admin);
        self::assertStringNotContainsString('This is the RFC 4252 banner', $admin);

        $dev = $this->devGuide();
        if ($dev === null) {
            $this->markTestSkipped('no dev guide in a standalone checkout');
        }
        $dev = (string) \preg_replace('/\s+/', ' ', $dev);
        self::assertStringContainsString('opt-in (`announce: true`) because that text lands in the user', $dev);

        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('no site body in a standalone checkout');
        }
        $body = (string) \preg_replace('/\s+/', ' ', $body);
        self::assertStringContainsString('bool $announce = false', $body);
        self::assertStringContainsString('is <strong>opt-in</strong> and off by default', $body);
        self::assertStringNotContainsString('not an RFC 4252 banner and not an auth gate; an ordinary', $body);
    }

    private function siteBody(): ?string
    {
        $path = \dirname(__DIR__, 2) . self::SITE_BODY;
        if (!\is_file($path)) {
            return null;
        }

        $body = \file_get_contents($path);
        $this->assertNotFalse($body, 'the site body is unreadable');

        return $body;
    }

    public function testTheSiteNamesTheSetterThatActuallyWiresAHandler(): void
    {
        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs surface is absent in a standalone checkout');
        }

        $this->assertStringContainsString('setChannelHandler(ChannelHandler $handler)', $body);
        // The fabricated row: a constructor parameter that never existed. PHP
        // would have accepted the call and ignored the argument.
        $this->assertStringNotContainsString('new(?PtySystem, ?ChannelHandler)', $body);
        $this->assertStringContainsString('silently ignored by PHP', $body);
    }

    public function testTheSiteSaysWhereProtocolMetadataComesFrom(): void
    {
        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs surface is absent in a standalone checkout');
        }

        $this->assertStringContainsString('always null today', $body);
        $this->assertStringNotContainsString('after SSH handshake', $body);
        $this->assertStringContainsString('does not speak SSH', $body);
    }

    public function testTheSiteDoesNotSellWireProtocolsThisLibraryDoesNotSpeak(): void
    {
        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs surface is absent in a standalone checkout');
        }

        $flat = (string) \preg_replace('/\s+/', ' ', $body);
        // KeyboardInteractive is a print-and-read prompt, not RFC 4256; the
        // phrase may appear only inside the sentence that refuses it.
        $this->assertStringNotContainsString('Challenge-response prompts (RFC 4256)', $flat);
        $this->assertStringContainsString('not</strong> RFC 4256', $body);
        $this->assertStringNotContainsString('Sends SSH keepalive messages', $body);
        $this->assertStringNotContainsString('Advertises auth methods', $body);
        $this->assertStringContainsString('writes a NUL byte', $body);
        $this->assertStringContainsString('not an RFC 4252 banner', $body);
    }

    public function testTheSiteCarriesTheRealRateLimitSignatureAndPosture(): void
    {
        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('monorepo docs surface is absent in a standalone checkout');
        }

        $this->assertStringContainsString('bool $failClosed = false', $body);
        $this->assertStringContainsString('?float $userRatePerSec = null', $body);
        $this->assertStringContainsString('sidecar', $body);
        $this->assertStringContainsString('fails open', $body);
    }

    public function testTheSiteMetadataDoesNotClaimAMultiTenancyNobodyWired(): void
    {
        $path = \dirname(__DIR__, 2) . self::SITE_JSON;
        if (!\is_file($path)) {
            $this->markTestSkipped('monorepo docs surface is absent in a standalone checkout');
        }

        $meta = (string) \file_get_contents($path);
        // The library enforces no per-tenant isolation, quotas or namespaces:
        // it hands the peer whatever the entry script's stack allows.
        $this->assertStringNotContainsString('multi-tenant', $meta);
        $this->assertStringNotContainsString('multi-tenant', (string) $this->readme());
    }

    public function testTheReadmePointsAtTheAdminGuideThatCarriesTheDeployTruth(): void
    {
        $readme = $this->readme();
        if ($readme === null) {
            $this->markTestSkipped('no README in a standalone checkout');
        }

        $flat = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString('docs/admin/sugar-ssh-server.md', $flat);
        $this->assertStringContainsString('Provision the paths the stack writes', $readme);
    }

    /**
     * The pump this transport delegates to counts a short `fwrite()` as
     * success, so a non-blocking STDOUT silently truncates terminal output.
     * The repair belongs to sugar-pty, which means the only defence here is
     * the contract being written down where an embedder will hit it: on the
     * method, in the README next to the transport choice it argues for, and
     * in the admin guide's failure list. All three, because the lib's
     * signature defect is a truth that moved halfway.
     */
    public function testTheBlockingStdoutInvariantIsWrittenOnAllThreeSurfaces(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/InProcessTransport.php');
        $flatSource = (string) \preg_replace('/\s+/', ' ', $source);
        $this->assertStringContainsString('PosixPump::pumpMasterToStdout()', $flatSource);
        $this->assertStringContainsString('LEFT BLOCKING', $flatSource);
        $this->assertStringContainsString('never retried', $flatSource);

        $readme = (string) $this->readme();
        $this->assertStringContainsString('### The byte pump needs a blocking STDOUT', $readme);
        $flatReadme = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString('dropped on the floor', $flatReadme);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $flatGuide = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringContainsString('Output can be truncated on a non-blocking STDOUT', $flatGuide);
        $this->assertStringContainsString('loses every byte past', $flatGuide);
    }

    /**
     * The manifest used to advertise "ext-ssh2 client wrappers" in its
     * description and suggest ext-ssh2 for a "Ssh2 client middleware
     * (forwarding, sftp helpers)" that exists nowhere in src/ — while the two
     * extensions the code really leans on went undeclared. Composer metadata
     * is a doc surface: installers read it, nothing compiles it, so it is
     * pinned here alongside the prose surfaces.
     *
     * `ext-ffi` moved from `require` to `suggest`: this package's own code
     * never touches FFI, and the legacy HostSshdTransport runs without it.
     * The install-time gate that matters belongs to sugar-pty, whose manifest
     * hard-requires `ext-ffi`; this test pins only our own side, because a
     * split-repo clone has no sibling manifest to read.
     */
    public function testTheManifestNamesTheExtensionsThisCodeActuallyNeeds(): void
    {
        $path = \dirname(__DIR__) . '/composer.json';
        $manifest = \json_decode((string) \file_get_contents($path), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        $description = (string) $manifest['description'];
        $flatDescription = (string) \preg_replace('/\s+/', ' ', $description);
        $this->assertStringNotContainsString('ssh2', \strtolower($flatDescription));
        // The description carries the negative claim itself, so a future edit
        // that re-adds a client, forwarder or SFTP server cannot leave the
        // sentence standing: the words are asserted as present-in-denial.
        $this->assertStringContainsString('it ships no client, no port forwarding and no SFTP server', $flatDescription);

        $this->assertArrayNotHasKey('ext-ffi', $manifest['require'], 'the legacy transport runs the chain with no FFI at all');

        $suggest = $manifest['suggest'] ?? [];
        $this->assertIsArray($suggest);
        $this->assertArrayNotHasKey('ext-ssh2', $suggest, 'no ssh2 middleware ships in this library');
        $this->assertArrayHasKey('ext-ffi', $suggest, 'sugar-pty allocates the PTY over FFI');
        $this->assertStringContainsString('sugar-pty', (string) $suggest['ext-ffi']);
        $this->assertArrayHasKey('ext-pcntl', $suggest);
        $this->assertStringContainsString('PtyException', (string) $suggest['ext-pcntl']);

        $readme = (string) \preg_replace('/\s+/', ' ', (string) $this->readme());
        $this->assertStringContainsString('lists no `ext-ssh2`', $readme);
    }

    /**
     * The public runChild() docblock used to promise that "closing the master
     * signals SIGHUP to the child via lost-ctty" — a mechanism Linux does not
     * implement, and the very claim the same file's finally-block comment and
     * the CALIBER entry disprove. The sentence stayed after the ladder was
     * written, so the file argued with itself: read the top and you expect a
     * free hangup signal, read the bottom and you find explicit SIGHUP, a
     * grace period, and SIGKILL. Both surfaces are pinned, because the lying
     * half is the half a caller reads before calling.
     */
    public function testTheTeardownLadderIsStatedInsteadOfAFreeHangupSignal(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/InProcessTransport.php');
        $flatSource = (string) \preg_replace('/\s+/', ' ', $source);
        $this->assertStringContainsString('sends SIGHUP, waits ~200 ms, then SIGKILL', $flatSource);
        $this->assertStringContainsString('does NOT deliver SIGHUP on Linux', $flatSource);
        $this->assertStringNotContainsString('via lost-ctty', $flatSource);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $flatGuide = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringContainsString('SIGHUP', $flatGuide);
        $this->assertStringContainsString('delivers no signal on Linux', $flatGuide);
        $this->assertStringNotContainsString('and SIGHUP on EOF', $flatGuide);
    }

    /**
     * The channel specimen used to end with `new InProcessTransport($ptySystem)
     * ` — a class the snippet never imports and a variable it never defines, so
     * copy-pasting the documented wiring died on the line that was supposed to
     * wire it. Pinned together with the truth the specimen now carries: a
     * custom handler is not covered by the default-closed exec policy, and the
     * dev guide may no longer imply the seven channel messages all arrive.
     */
    public function testTheChannelSpecimenCompilesAsWritten(): void
    {
        $readme = (string) $this->readme();
        $this->assertStringContainsString(
            'use SugarCraft\\SshServer\\Transport\\InProcessTransport;',
            $readme,
            'the specimen must import the class it instantiates'
        );
        $this->assertStringNotContainsString('new InProcessTransport($ptySystem)', $readme);
        $flatReadme = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString('Replacing the handler replaces the policy', $flatReadme);

        $dev = $this->devGuide();
        if ($dev === null) {
            $this->markTestSkipped('no dev guide in a standalone checkout');
        }

        $this->assertStringNotContainsString('new InProcessTransport($ptySystem)', $dev);
        $flatDev = (string) \preg_replace('/\s+/', ' ', $dev);
        $this->assertStringContainsString('Only one of those messages is produced', $flatDev);
        $this->assertStringContainsString('decodeCommandString()', $flatDev);
    }

    public function testTheFailureTableAndTheExitCodeAreWrittenAsTheCodeBehaves(): void
    {
        // Audit MAJOR 19 shipped a table written in the language of HTTP: no
        // stack wrapper exists on any of these paths, so each 'error logged'
        // row promised something nothing did. Audit MAJOR 9 answered every
        // refusal with `0`. Both stories now sit on the two surfaces a reader
        // actually consults, and this pin is what keeps them there.
        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $flat = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringNotContainsString('500 response to SSH client', $flat);
        $this->assertStringNotContainsString('SSH DISCONNECT', $flat);
        $this->assertStringContainsString('no HTTP status code', $flat);
        $this->assertStringContainsString('uncaught PHP fatal', $flat);
        $this->assertStringContainsString('### Exit status', $guide);
        $this->assertStringContainsString('Server::EXIT_DENIED', $guide);
        $this->assertStringContainsString('(`129`)', $flat);
        $this->assertStringContainsString('A PHP **fatal** is `255`', $flat);

        $readme = (string) \preg_replace('/\s+/', ' ', (string) $this->readme());
        $this->assertStringContainsString('when a gate refused and nothing ran', $readme);
        $this->assertStringContainsString('cron entry or CI step', $readme);
    }

    /**
     * Five surfaces described the protocol fields as arriving 'after the SSH
     * handshake completes', which is a sentence about a negotiation this
     * library never performs (audit MAJORS 3 and 21). Provenance prose is
     * exactly the kind of sentence nobody re-reads once it has shipped, so
     * the phrase is now forbidden on each surface and the true story is
     * required in its place.
     */
    public function testTheProtocolProvenanceIsStatedOnEverySurface(): void
    {
        $session = (string) \file_get_contents(\dirname(__DIR__) . '/src/Session.php');
        $flatSession = (string) \preg_replace('/\s+/', ' ', $session);
        $this->assertStringContainsString('There is no handshake to wait for', $flatSession);
        $this->assertStringNotContainsString('after the SSH handshake completes', $flatSession);

        $transport = (string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/InProcessTransport.php');
        $flatTransport = (string) \preg_replace('/\s+/', ' ', $transport);
        $this->assertStringContainsString('performs no SSH handshake', $flatTransport);
        $this->assertStringContainsString('MINTED for this process', $flatTransport);
        // Audit minor: the class pointed readers at two plan files that never
        // shipped, one of them by campaign-stage name.
        $this->assertStringNotContainsString('plans/sugarcraft-is-a-mono-logical-twilight', $flatTransport);
        $this->assertStringNotContainsString("PR3's", $flatTransport);

        $readme = (string) \preg_replace('/\s+/', ' ', (string) $this->readme());
        $this->assertStringNotContainsString('After the SSH handshake completes', $readme);
        $this->assertStringContainsString('minted per process', $readme);
        $this->assertStringContainsString('are not audit columns', $readme);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $flatGuide = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringNotContainsString('correlating across multiple proxy hops', $flatGuide);
        $this->assertStringContainsString('Do not plan on cross-host correlation', $flatGuide);
        $this->assertStringContainsString('Auth *outcomes* are not in these lines either', $flatGuide);

        $dev = $this->devGuide();
        if ($dev === null) {
            $this->markTestSkipped('no dev guide in a standalone checkout');
        }

        $flatDev = (string) \preg_replace('/\s+/', ' ', $dev);
        $this->assertStringNotContainsString('after the SSH handshake completes', $flatDev);
        $this->assertStringContainsString('There is no handshake behind them', $flatDev);
    }

    /**
     * A prompt that asks for silence cannot deliver it, so the promise has to
     * be contradicted where the user can see it (audit MAJOR 6). The class
     * says so; the README, the admin guide and the generated site page must
     * say so too, and none of them may keep selling the exchange as RFC 4256.
     */
    public function testThePromptExchangeTruthTravelsOnEverySurface(): void
    {
        $source = (string) \file_get_contents(\dirname(__DIR__) . '/src/Middleware/Auth/KeyboardInteractive.php');
        $flatSource = (string) \preg_replace('/\s+/', ' ', $source);
        $this->assertStringContainsString('Echo is a label, not terminal state (audit MAJOR 6)', $flatSource);
        $this->assertStringContainsString('MAX_RESPONSE_BYTES', $flatSource);
        $this->assertStringContainsString('makeRaw()', $flatSource);

        $readme = (string) \preg_replace('/\s+/', ' ', (string) $this->readme());
        $this->assertStringNotContainsString('reads responses from STDIN (RFC 4256).', $readme);
        $this->assertStringContainsString('is a label, not terminal state', $readme);
        $this->assertStringContainsString('capped at `MAX_RESPONSE_BYTES`', $readme);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $flatGuide = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringNotContainsString('exchange-based (RFC 4256)', $flatGuide);
        $this->assertStringContainsString('cannot silence the terminal', $flatGuide);
        $this->assertStringContainsString('LoginGraceTime', $flatGuide);

        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('no site body in a standalone checkout');
        }

        $flatBody = (string) \preg_replace('/\s+/', ' ', $body);
        $this->assertStringNotContainsString('Challenge-response prompts (RFC 4256)', $flatBody);
        $this->assertStringContainsString('capped at <code>MAX_RESPONSE_BYTES</code>', $flatBody);
    }

    public function testTheProgramExamplePairShipsBothHalves(): void
    {
        $examples = \dirname(__DIR__) . '/examples';
        // The supervisor execs this path; a tour that names a file nobody ships
        // is how MAJOR 16 started.
        $this->assertFileExists(
            $examples . '/run-program.php',
            'examples/spawn-program.php execs a wrapper that has to exist'
        );

        $supervisor = (string) \file_get_contents($examples . '/spawn-program.php');
        $this->assertStringNotContainsString(
            'MyApp',
            $supervisor,
            'the wrapper survived for a while as a comment around a class nobody ships'
        );
        $this->assertStringContainsString('Logger::auto()', $supervisor);

        $bubbleTea = (string) \file_get_contents($examples . '/../src/Middleware/BubbleTea.php');
        $this->assertStringContainsString(
            'examples/run-program.php',
            $bubbleTea,
            'the class that teaches a wrapper command must point at the real file'
        );

        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }
        $this->assertStringContainsString(
            '[`examples/run-program.php`](examples/run-program.php)',
            $readme
        );
    }

    public function testTheIdleByteIsNamedAsWhatItIs(): void
    {
        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }

        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        $flat = (string) \preg_replace('/\s+/', ' ', (string) \file_get_contents(\dirname(__DIR__) . '/src/Middleware/Keepalive.php'));

        self::assertStringContainsString('installed by `Server::withKeepalive($seconds)`', $readme);
        self::assertStringContainsString("child's stdin", $readme);
        self::assertStringContainsString('is *not* an SSH-level keepalive', $readme);
        self::assertStringNotContainsString('reaches the wire only where the tty echoes', $readme);
        // The class says the same thing the table row now says.
        self::assertStringContainsString("child's input side", $flat);
        self::assertStringContainsString('raw-mode TUIs suppress echo', $flat);
        self::assertStringNotContainsString('Interval between keepalive messages', $flat);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $guide = (string) \preg_replace('/\s+/', ' ', $guide);
        self::assertStringContainsString("idle NUL into the child's stdin", $guide);
        self::assertStringNotContainsString('SSH-level keepalive interval', $guide);
    }

    public function testTheMiddlewareTableStatesWhatCannotArriveAndWhatNeverRuns(): void
    {
        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }

        $flat = (string) \preg_replace('/\s+/', ' ', $readme);

        // PasswordAuth: the variable it reads is one no OpenSSH exports.
        self::assertStringContainsString('Compares a password from `SSH_PASSWORD`', $flat);
        self::assertStringContainsString('Nothing in OpenSSH ever sets that variable', $flat);
        self::assertStringNotContainsString('against a caller-supplied callback (`SSH_PASSWORD` env var).', $flat);

        // CertificateAuth: same story, and the silence was the defect (audit K6).
        self::assertStringContainsString('Stock OpenSSH exports none of them', $flat);
        self::assertStringContainsString('`CertificateAuth::ENV_KEYS`', $flat);
        self::assertStringNotContainsString('Validates X.509 peer certificate (`SSL_CLIENT_CERT` / `SSH_CLIENT_CERT` env vars).', $flat);

        // penalize(): the gates still never call it on their own, but the
        // bridge is the shipped wiring (superseded MAJOR-20 sentence,
        // re-anchored with the bridge step — README and guide must agree).
        self::assertStringContainsString('no gate calls it directly', $flat);
        self::assertStringContainsString('wrap the gate in `AuthRateLimitBridge`', $flat);
        self::assertStringNotContainsString('nothing in this library calls it', $flat);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $guide = (string) \preg_replace('/\s+/', ' ', $guide);
        // The guide tells the bridged story in the same breath the README
        // does: gates still do not charge on their own, the bridge is the
        // shipped wiring, and the dead MAJOR-20 sentence stays dead.
        self::assertStringContainsString('no shipped gate calls it on its own', $guide);
        self::assertStringContainsString('`AuthRateLimitBridge` supplies the wiring', $guide);
        self::assertStringNotContainsString('nothing in this library calls it', $guide);
        self::assertStringNotContainsString('drain buckets faster than connection attempts alone.', $guide);
    }

    public function testTheLanguageStoryIsToldWherePeopleWouldLookForIt(): void
    {
        $lang = (string) \preg_replace(
            '/\s+/',
            ' ',
            (string) \file_get_contents(\dirname(__DIR__) . '/src/Lang.php'),
        );
        $this->assertStringContainsString("'sshserver'` namespace baked in", $lang);
        $this->assertStringNotContainsString("'wish'`", $lang, 'audit MINOR 19: the facade must not teach a namespace that was renamed away');
        $this->assertStringContainsString('Nothing here selects a locale implicitly (audit MAJOR 15)', $lang);
        $this->assertStringContainsString('Server::withLocale()', $lang, 'the facade docblock must point at the shipped arming door');
        $this->assertStringContainsString('Do not reach for `T::detect()`', $lang);

        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }
        $this->assertStringContainsString(
            '## Language: nothing selects it for you',
            $readme,
            'the README must own the headline, not just the admin guide',
        );
        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString('T::setLocale', $readme);
        $this->assertStringContainsString('->withLocale(', $readme, 'ssrv-10: both locale doors must be named where operators read');
        $this->assertStringContainsString('connecting peer choose the language', $readme);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }
        $this->assertStringContainsString('### Language', $guide);
        $guide = (string) \preg_replace('/\s+/', ' ', $guide);
        $this->assertStringContainsString('the one code path that calls `T::setLocale()` is `Server::withLocale()`', $guide);
        $this->assertStringContainsString('Peer-asserted', $guide);
        $this->assertStringContainsString('| `SSH_SERVER_LOG` |', $guide);
    }

    public function testTheEmbeddingConstraintsAreWrittenDown(): void
    {
        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }

        $this->assertStringContainsString('## Embedding this library', $readme);

        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        foreach ([
            'One session per process.',
            'would authenticate against the first one\'s environment',
            'withSizeProvider(callable $provider): self',
            'withPumpDeadlineUs()',
            'is a value you carry, not an',
            '`ChildSpawner` is narrower than the class behind it',
            '`TransportAware` is the other half',
        ] as $needle) {
            $this->assertStringContainsString($needle, $readme, 'README lost an embedding constraint: ' . $needle);
        }

        $spawner = (string) \preg_replace(
            '/\s+/',
            ' ',
            (string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/ChildSpawner.php'),
        );
        $this->assertStringContainsString('deliberately narrower than the class behind it', $spawner);
        $this->assertStringContainsString('instanceof', $spawner);
        $this->assertStringNotContainsString('PR3', $spawner);
    }

    public function testTheShippedCommentsCiteTheReviewNotAPrivateLedger(): void
    {
        // Campaign-internal identifiers - 'E730 (round 83)', 'LOW-15', the
        // 'audit MEDIUM-2' family, 'PR3', 'step-29' and citations of plan
        // files that never shipped - are unresolvable to anyone who was not
        // in the loop. The public form this library cites instead is
        // 'audit MAJOR 9' / 'audit K2', which points at a review record in
        // the monorepo. The sweep covers what a reader of the package sees:
        // src/, examples/, the README and the library's own learning file.
        // (audit m28)
        $forbidden = ['E727', 'E730', 'E734', 'LOW-', 'MEDIUM-', 'HIGH-', 'step-29', 'plans/'];

        $files = ['README.md', 'CALIBER_LEARNINGS.md'];
        $root = \dirname(__DIR__);
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
        foreach ($walk as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = 'src/' . \substr($f->getPathname(), \strlen($root) + 5);
            }
        }
        foreach (\scandir($root . '/examples') ?: [] as $entry) {
            if (\str_ends_with($entry, '.php')) {
                $files[] = 'examples/' . $entry;
            }
        }

        $seen = 0;
        foreach ($files as $relative) {
            $path = $root . '/' . $relative;
            if (!\is_file($path)) {
                continue;
            }
            ++$seen;
            $text = (string) \file_get_contents($path);
            foreach ($forbidden as $id) {
                $this->assertStringNotContainsString($id, $text, $relative);
            }
        }
        self::assertGreaterThan(20, $seen, 'the sweep should have read most of src/ plus both docs');
    }

    public function testTheCredentialReadingTruthIsOnTheSurfacesThatTeachIt(): void
    {
        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }

        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        self::assertStringContainsString(
            'refused before the validator is consulted',
            $readme,
        );
        self::assertStringContainsString('counts the fields', $readme);

        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }

        $guide = (string) \preg_replace('/\s+/', ' ', $guide);
        self::assertStringContainsString(
            'does **not** rewrite the',
            $guide,
            'the /proc mitigation must not be oversold (audit MINOR 2)',
        );
        self::assertStringContainsString(
            'Presence is checked separately from value',
            $guide,
        );
        self::assertStringNotContainsString(
            'PasswordAuth.php` :52-54',
            $guide,
            'line-number cites drift; name the file, not the offset',
        );

        $body = $this->siteBody();
        if ($body !== null) {
            $body = (string) \preg_replace('/\s+/', ' ', $body);
            self::assertStringContainsString(
                'refused before the callback runs',
                $body,
            );
        }
    }

    public function testTheChildEnvironmentContractIsWrittenWhereItIsUsed(): void
    {
        $handler = (string) \preg_replace(
            '/\s+/',
            ' ',
            (string) \file_get_contents(\dirname(__DIR__) . '/src/Channel/DefaultChannelHandler.php'),
        );
        foreach (['LD_AUDIT', 'DYLD_INSERT_LIBRARIES', 'GCONV_PATH', 'PROMPT_COMMAND', 'HOSTALIASES', 'POSIXLY_CORRECT'] as $name) {
            $this->assertStringContainsString($name, $handler, $name . ' is not in the deny-list');
        }

        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }
        $readme = (string) \preg_replace('/\s+/', ' ', $readme);
        $this->assertStringContainsString('applied even to names an operator put in `$acceptEnv`', $readme);
        $this->assertStringContainsString('Two exceptions are honest ones', $readme);

        // The pump borrows the caller's STDIN non-blocking and hands it back;
        // STDOUT was never flipped and stays blocking (the K9 contract).
        $transport = (string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/InProcessTransport.php');
        $this->assertStringContainsString('@\stream_set_blocking($stdin, true);', $transport);
        // The asymmetry is deliberate and written down: the stdout flip is
        // the one call this file forbids a reader from adding.
        $this->assertStringContainsString('do not "fix" the missing', $transport);
        $this->assertStringContainsString('it was never flipped', $transport);
    }

    /**
     * Audit NIT 6, NIT 10, NIT 11, NIT 13 and MINOR 12: five places where the
     * small print was wrong, thinner than the claim, or missing. All are
     * comment-level, so each is pinned as a presence assertion on the file
     * that carries it - the point is that a later smoothing-over of the
     * honesty reddens here.
     */
    public function testTheSmallPrintMatchesWhatTheCodeActuallyDoes(): void
    {
        $auth = $this->prose((string) \file_get_contents(\dirname(__DIR__) . '/src/Middleware/Auth.php'));
        self::assertStringContainsString('one line built from the `auth.unauthorized` message', $auth, 'Auth still promises a bare "Unauthorized." rather than the reason-carrying line');
        self::assertStringContainsString('There is no disconnect packet to send', $auth, 'the rejection note must stop implying an SSH-level close');

        $handler = $this->prose((string) \file_get_contents(\dirname(__DIR__) . '/src/Channel/DefaultChannelHandler.php'));
        self::assertStringContainsString('do nothing with it', $handler, 'handleBreak() needs the reason it is empty');
        self::assertStringContainsString('a literal is reached only where no extension defines', $handler, 'the numeric signal fallbacks need their platform stated');

        $transport = $this->prose((string) \file_get_contents(\dirname(__DIR__) . '/src/Transport/InProcessTransport.php'));
        self::assertStringContainsString('The broad `\Throwable` catch is not sloppiness', $transport, 'the resize probe must explain why its failure path is quiet');

        $contract = $this->prose((string) \file_get_contents(\dirname(__DIR__) . '/src/Middleware.php'));
        self::assertStringContainsString('May return void (synchronous) or a', $contract, 'the untyped handle() return is a promise channel and has to say so');
    }

    /**
     * Flatten a source or markdown surface into one comparable line: runs of
     * whitespace collapse, docblock ` * ` continuations disappear (they survive
     * a plain whitespace fold and silently break phrase needles, which cost
     * this file a false red once), and em dashes become hyphens so a needle can
     * be typed without fighting the file's typography.
     */
    private function prose(string $text): string
    {
        $text = (string) \preg_replace('/\s+/', ' ', $text);

        return str_replace([' * ', "\u{2014}"], [' ', '-'], $text);
    }

    public function testTheHealthProbeAndTheConcurrencyTruthAreWrittenDown(): void
    {
        $guide = $this->adminGuide();
        if ($guide === null) {
            $this->markTestSkipped('no admin guide in a standalone checkout');
        }
        $guide = $this->prose($guide);

        // The old recipe promised a command that ForceCommand never runs.
        self::assertStringNotContainsString('# Probe the hub endpoint', $guide);
        self::assertStringContainsString('Do **not** write the probe as', $guide);
        self::assertStringContainsString(
            'the `echo ok` never runs, the entry script does',
            $guide,
        );
        self::assertStringContainsString('A liveness check that must not depend on credentials', $guide);

        // Nothing here bounds concurrency; the guide says whose job that is.
        self::assertStringContainsString('### Concurrency and back-pressure', $guide);
        self::assertStringContainsString('Nothing in this library bounds the number of concurrent sessions', $guide);

        $example = (string) \file_get_contents(\dirname(__DIR__) . '/examples/hello-server.php');
        self::assertStringContainsString('There is no `Auth` and no', $this->prose($example));
        self::assertStringContainsString('something the connecting peer asserted, not something sshd proved', $this->prose($example));
    }

    /**
     * The canonical example in `Server.php` used to teach a stack without
     * `exit()` and a sink that printed into the peer's channel. Both halves
     * are pinned here, along with the constructor table and the deploy check
     * the README now asks for (audit NIT 8, MINOR 21, MINOR 24, MINOR 29).
     */
    public function testTheCanonicalExampleAndTheOptionTableAgreeWithTheCode(): void
    {
        $server = $this->prose((string) \file_get_contents(\dirname(__DIR__) . '/src/Server.php'));

        self::assertStringContainsString('exit(Server::new()', $server);
        self::assertStringContainsString('->use(Logger::auto())', $server);
        self::assertStringContainsString('is not decoration', $server);
        // Spawn takes a shaped array; an example that trims it teaches a fatal.
        self::assertStringContainsString("'cmd' => ['/bin/bash', '-l']", $server);
        self::assertStringNotContainsString('->use(new Logger())', $server);

        $readme = $this->readme();
        if ($readme === null) {
            $this::fail('the library README is missing');
        }

        $readme = $this->prose($readme);
        self::assertStringContainsString('### Constructor options', $readme);
        self::assertStringContainsString('Auth::MAX_ECHOED_BYTES', $readme);
        self::assertStringContainsString('MAX_RESPONSE_BYTES', $readme);
        self::assertStringContainsString('$failClosed = false', $readme);
        self::assertStringContainsString('there is no override flag', $readme);
        // Fields the metadata fence used to omit.
        self::assertStringContainsString('$s->serverHost;', $readme);
        self::assertStringContainsString('$s->lang;', $readme);
        // The deploy check the guide's health section already insists on.
        self::assertStringContainsString('check that it took effect', $readme);
        self::assertStringContainsString('echo "status=$?"', $readme);
    }

    public function testTheSiteQuickstartExitsTheWayTheLibraryPreaches(): void
    {
        $body = $this->siteBody();
        if ($body === null) {
            $this->markTestSkipped('no site body in a standalone checkout');
        }

        // Both fences on the page used to end in a bare ->serve(), which is the
        // one shape that reports success no matter what the stack decided
        // (audit NIT 7). The exit() wrapper is the prescription, so the page
        // has to carry it.
        self::assertSame(
            2,
            \substr_count($body, 'exit(Server::new()'),
            'both quickstart fences should wrap serve() in exit()',
        );
        self::assertStringNotContainsString("use(new Logger('", $body);
        self::assertStringContainsString('Logger::toFile(', $body);
    }

    private function adminGuide(): ?string
    {
        return $this->monorepoFile('docs/admin/sugar-ssh-server.md');
    }

    private function devGuide(): ?string
    {
        return $this->monorepoFile('docs/dev/sugar-ssh-server.md');
    }

    /**
     * Walk up from the lib until the monorepo's `docs/` tree shows up, so a
     * standalone composer checkout (which has none) skips instead of quietly
     * asserting nothing. The first draft of these two helpers pointed one
     * level too high and every guide leg was vacuous - the reason this method
     * resolves by search rather than by depth.
     */
    private function monorepoFile(string $relative): ?string
    {
        $dir = \dirname(__DIR__);

        for ($hop = 0; $hop < 4; $hop++) {
            $dir = \dirname($dir);
            if (\is_file($dir . '/' . $relative)) {
                return (string) \file_get_contents($dir . '/' . $relative);
            }
        }

        return null;
    }

    private function readme(): ?string
    {
        $path = \dirname(__DIR__) . '/README.md';
        if (!\is_file($path)) {
            $this->markTestSkipped('the lib README is unreadable');
        }

        return (string) \file_get_contents($path);
    }
}
