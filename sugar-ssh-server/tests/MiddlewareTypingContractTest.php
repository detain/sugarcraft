<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the strict-typing contract sweep.
 *
 * PHP rejects `void` inside any union return type, so the Middleware
 * interface cannot state its contract natively — the docblock union
 * and the dispatch-time refusal of stray values are the enforcement.
 * The honest half that IS expressible: every concrete handle()
 * declaration must carry the tightest native return type it honours
 * (`void` or `PromiseInterface`). A new untyped implementation means
 * a return value nobody declared could be silently dropped.
 */
final class MiddlewareTypingContractTest extends TestCase
{
    /**
     * Sentinel a pass-through door must carry verbatim to be exempt from
     * the native-type law. Only legitimately needed by
     * `ClosureMiddleware`: it forwards a closure's return value, which
     * is exactly the `void|PromiseInterface` union PHP cannot express
     * natively. A new untyped `handle()` without this line in its own
     * file still reddens this guard.
     */
    private const PASS_THROUGH_SENTINEL = 'MiddlewareTypingContract: pass-through exemption';

    public function testEveryConcreteHandleCarriesANativeReturnType(): void
    {
        $offenders = [];
        $files     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $exempt = \str_contains((string) \file_get_contents($file->getPathname()), self::PASS_THROUGH_SENTINEL);
            foreach (\file($file->getPathname()) as $line) {
                if (\preg_match('/^\s*\*/', $line) === 1) {
                    continue; // comment prose is not a declaration
                }
                // Scope: the middleware contract signature specifically
                // (other handle() contracts in src/ are not this law).
                if (\preg_match('/function handle\(Context .*callable \$next/', $line) !== 1) {
                    continue;
                }
                $decl = \rtrim($line);
                if (\str_ends_with($decl, ');')) {
                    continue; // the interface itself: union is docblock-only
                }
                if ($exempt && \str_ends_with($decl, ')')) {
                    continue; // declared pass-through door (see below)
                }
                $native = \str_ends_with($decl, '): void')
                    || \str_ends_with($decl, '): PromiseInterface')
                    || \str_ends_with($decl, '): \\React\\Promise\\PromiseInterface');
                if (!$native) {
                    $offenders[] = $file->getBasename() . ': ' . \trim($line);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'every concrete Middleware::handle() must declare a native void or PromiseInterface return type',
        );
    }

    public function testThePassThroughExemptionIsSoleAndNamed(): void
    {
        // The exemption above must never widen quietly: exactly one file
        // in src/ carries the sentinel, and it is the door that
        // genuinely forwards a caller's return value.
        $carriers = [];
        $files    = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(\dirname(__DIR__) . '/src', \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($files as $file) {
            if ($file->getExtension() === 'php'
                && \str_contains((string) \file_get_contents($file->getPathname()), self::PASS_THROUGH_SENTINEL)) {
                $carriers[] = $file->getBasename();
            }
        }

        $this->assertSame(['ClosureMiddleware.php'], $carriers);
    }
}
