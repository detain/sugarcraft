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
            return;
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
            return;
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

        $this->assertArrayHasKey('ext-ffi', $manifest['require'], 'sugar-pty allocates the PTY over FFI');

        $suggest = $manifest['suggest'] ?? [];
        $this->assertIsArray($suggest);
        $this->assertArrayNotHasKey('ext-ssh2', $suggest, 'no ssh2 middleware ships in this library');
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
            return;
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
            return;
        }

        $this->assertStringNotContainsString('new InProcessTransport($ptySystem)', $dev);
        $flatDev = (string) \preg_replace('/\s+/', ' ', $dev);
        $this->assertStringContainsString('Only one of those messages is produced', $flatDev);
        $this->assertStringContainsString('decodeCommandString()', $flatDev);
    }

    private function adminGuide(): ?string
    {
        $path = \dirname(__DIR__, 3) . '/docs/admin/sugar-ssh-server.md';
        if (!\is_file($path)) {
            return null;
        }

        return (string) \file_get_contents($path);
    }

    private function devGuide(): ?string
    {
        $path = \dirname(__DIR__, 3) . '/docs/dev/sugar-ssh-server.md';
        if (!\is_file($path)) {
            return null;
        }

        return (string) \file_get_contents($path);
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
