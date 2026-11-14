<?php

declare(strict_types=1);

/**
 * Child-process probe for the {@see \SugarCraft\Core\Util\Tty\EnvDetect}
 * environment arms (finding sugar-core-065 remainder, sc-42: the host-shape
 * detection had zero coverage).
 *
 * Driven by {@see \SugarCraft\Core\Tests\Util\Tty\EnvDetectTest}; never
 * collected by PHPUnit itself, because the suite only picks up files whose
 * name ends in `Test.php`.
 *
 * Why a child at all — the same reason as closed_descriptor_zero_probe.php:
 * isWsl()/isMintty()/isCygwin() memoise their answers in FUNCTION-LOCAL
 * `static $cached` variables that no in-process seam can reset (and adding
 * one to production for testability would let a test drive the platform
 * story with a switch a host can never flip — the census lesson of
 * DescriptorSinkArgumentCensusTest). A fresh process IS the reset: each arm
 * gets the environment it names, answers once, and reports JSON.
 *
 * Usage: `php env_detect_probe.php <result-file>`; the interesting input is
 * the CHILD'S ENVIRONMENT, which the caller controls through proc_open().
 *
 * The `control_env` row echoes back whether each fakeable variable actually
 * arrived in the child. An arm that "passes" while its variable never made
 * it across would prove nothing — that echo is what makes a green row mean
 * the environment was the environment we asked for.
 */

use SugarCraft\Core\Util\Tty\EnvDetect;

require __DIR__ . '/../../../vendor/autoload.php';

$resultFile = $argv[1] ?? '';
if ($resultFile === '') {
    exit(64);
}

$fakes = ['WSL_INTEROP', 'WSL_DISTRO_NAME', 'MSYSTEM', 'MINTTY_SHORTCUT', 'TERM_PROGRAM', 'OSTYPE'];

$results = [];
foreach ($fakes as $name) {
    $value = getenv($name);
    $results['control_env'][$name] = $value === false ? null : $value;
}

$results['is_wsl']          = EnvDetect::isWsl();
$results['is_mintty']       = EnvDetect::isMintty();
$results['is_cygwin']       = EnvDetect::isCygwin();
$results['is_console_stdin'] = EnvDetect::isConsoleStdin();

// The osrelease fallback cannot be faked (the child reads the real kernel
// string), so the parent needs to know it to interpret the clean-host arm.
// Match the production reading EXACTLY: EnvDetect uses stripos, not a
// case-sensitive contains, and the probe echo must mean the same test.
$release = @file_get_contents('/proc/sys/kernel/osrelease');
$results['osrelease_microsoft'] = is_string($release)
    && (\stripos($release, 'microsoft') !== false || \stripos($release, 'WSL') !== false);
$results['php_os_family'] = \PHP_OS_FAMILY;

file_put_contents($resultFile, json_encode($results, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

// Failures are reported through the result file, never the exit code, so the
// parent can tell "the child disagreed" from "the child never ran".
exit(0);
