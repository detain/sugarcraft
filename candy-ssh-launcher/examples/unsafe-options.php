<?php

declare(strict_types=1);

/**
 * unsafe-options — report the ssh options in an inline wishlist that weaken
 * host-key checking or logging, before the launcher would pass them to ssh.
 *
 *   php examples/unsafe-options.php
 *
 * The launcher prints one stderr line per flagged option at connect time. This
 * example prints the same list without connecting, so a config can be audited
 * from a script.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\SshLauncher\Config;

$wishlist = <<<'YAML'
- name: lab
  host: lab.example.com
  options:
    - StrictHostKeyChecking=no
    - ServerAliveInterval=30
- name: legacy
  host: old.example.com
  options:
    - UserKnownHostsFile=/dev/null
    - LogLevel=quiet
YAML;

foreach (Config::parse($wishlist, 'wishlist.yml') as $endpoint) {
    $flagged = $endpoint->unsafeOptions();
    echo $endpoint->name, ': ', $flagged === [] ? 'none' : implode(', ', $flagged), "\n";
}
