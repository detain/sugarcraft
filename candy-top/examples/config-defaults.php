<?php

declare(strict_types=1);

/**
 * config-defaults — print the default value of a handful of config options.
 *
 *   php examples/config-defaults.php
 *
 * Every option lives in Schema::options(). This example reads the schema
 * directly, so the defaults it prints are the ones a fresh config file gets.
 * It never reads or writes a config file.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Top\Config\Schema;

printf("%d options, %d persisted to config.conf\n", count(Schema::options()), count(Schema::persistedNames()));

foreach (['color_theme', 'update_ms', 'shown_boxes', 'proc_sorting', 'graph_symbol', 'tty_mode', 'save_config_on_exit'] as $name) {
    $option = Schema::option($name) ?? throw new \RuntimeException("unknown option: {$name}");
    printf("%-22s %s\n", $name, var_export($option->default, true));
}
