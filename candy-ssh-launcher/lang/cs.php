<?php

/**
 * Czech translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'spuštění {bin} selhalo',
    'config.not_found'         => 'Konfigurace wishlist nenalezena: {path}',
    'config.json_top_level'    => 'wishlist json: hodnota nejvyšší úrovně musí být pole',
    'config.json_entry_object' => 'wishlist json: každá položka musí být objekt',
    'config.yaml_continuation' => "wishlist yaml: pokračování před jakýmkoliv blokem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: neparsovatelný řádek {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: blokové mapování pod `{field}:` na řádku {line}: {found} — toto pole musí být seznam položek `- value`',
    'config.entry_missing_field' => 'položka wishlist #{index} postrádá povinný údaj: {field}',
    'config.unknown_key' => 'neznámé pole `{key}` ignorováno — není dokumentovaný klíč',
    'config.unknown_key_nearmiss' => 'neznámé pole `{key}` ignorováno — mysleli jste `{field}`?',
    'config.duplicate_name' => 'duplicitní název endpointu `{name}` — všechny řádky se načtou; názvy jsou identifikátory',
    'config.field_not_scalar'  => 'položka wishlist: pole [{field}] musí být skalár, získáno {type}',
    'config.unreadable'       => 'konfiguraci wishlist nelze načíst: {path}',
    'config.home_unset'       => 'nelze rozbalit ~ bez $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: řádková flow kolekce není podporována: {value} (použijte vnořený seznam)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: pole [{field}] má nebezpečnou hodnotu začínající pomlčkou [{value}]',
    'endpoint.host_empty' => 'koncový bod `{name}` má prázdného hostitele — vybraný řádek potřebuje hostname',
    'endpoint.name_empty' => 'endpoint má prázdný název — vyhledávaný řádek potřebuje název',
    'endpoint.option_empty' => 'koncový bod `{name}` má prázdnou položku options — prázdná hodnota -o je odmítnuta',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: neplatný port [{port}] — musí to být celé číslo od 1 do 65535',

    'cli.usage'         => 'Použití: candy-ssh-launcher [--config <cesta>] [--ssh <ssh-binární>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: neznámý argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: konfigurace nenalezena. Předejte --config <cesta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: zrušeno.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binární soubor ssh nenalezen nebo není spustitelný: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} vyžaduje hodnotu',
    'cli.no_endpoints'   => 'candy-ssh-launcher: žádné endpointy v {path}',
    'cli.not_a_terminal' => 'výběr vyžaduje interaktivní terminál — stdin a stdout musí být TTY. Pro strojově čitelný výstup použijte --list.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtr: ',
    'picker.no_matches'  => '  (žádné shody)',
    'picker.help'        => '  ↑/↓ nebo j/k vybrat · Enter připojit · Esc ukončit · psát pro filtr',
    'picker.count'       => '  ({shown} z {total})',
];
