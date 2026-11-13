<?php

/**
 * Czech translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'spuštění {bin} selhalo',
    'launcher.transport_not_executable' => 'binární soubor transportu nenalezen nebo není spustitelný: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: endpoint {endpoint}: vlastní transport ignoruje {legs}',
    'config.not_found'         => 'Konfigurace wishlist nenalezena: {path}',
    'config.json_top_level'    => 'wishlist json: hodnota nejvyšší úrovně musí být pole',
    'config.json_entry_object' => 'wishlist json: každá položka musí být objekt',
    'config.yaml_continuation' => "wishlist yaml: pokračování před jakýmkoliv blokem '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: neparsovatelný řádek {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: blokové mapování pod `{field}:` na řádku {line}: {found} — toto pole musí být seznam položek `- value`',
    'config.yaml_unterminated_quote' => 'wishlist yaml: v hodnotě nezavřená uvozovka: {found}',
    'config.entry_missing_field' => 'položka wishlist #{index} postrádá povinný údaj: {field}',
    'config.unknown_key' => 'neznámé pole `{key}` ignorováno — není dokumentovaný klíč',
    'config.unknown_key_nearmiss' => 'neznámé pole `{key}` ignorováno — mysleli jste `{field}`?',
    'config.duplicate_name' => 'duplicitní název endpointu `{name}` — všechny řádky se načtou; názvy jsou identifikátory',
    'config.field_not_scalar'  => 'položka wishlist: pole [{field}] musí být skalár, získáno {type}',
    'config.too_large'          => 'konfigurace wishlistu je větší než limit {limit} bajtů: {path}',
    'config.list_too_long'      => 'položka wishlistu: pole [{field}] obsahuje více než {limit} položek, které zástupce umožňuje',
    'config.field_not_list'       => 'položka wishlistu: pole [{field}] musí být seznam hodnot, ne mapa klíč a hodnot',
    'config.encoding_utf16'       => '{path} je kódován UTF-16 — uložte konfiguraci znovu jako UTF-8',
    'config.unreadable'       => 'konfiguraci wishlist nelze načíst: {path}',
    'config.home_unset'       => 'nelze rozbalit ~ bez $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: řádková flow kolekce není podporována: {value} (použijte vnořený seznam)',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: pole [{field}] má nebezpečnou hodnotu začínající pomlčkou [{value}]',
    'endpoint.host_empty' => 'endpoint `{name}` má prázdný host — řádek pro výběr potřebuje hostname',
    'endpoint.name_empty' => 'endpoint má prázdný název — řádek pro výběr potřebuje název',
    'endpoint.option_empty' => 'endpoint `{name}` má prázdnou položku options — prázdná hodnota -o je odmítnuta',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: neplatný port [{port}] — musí to být celé číslo od 1 do 65535',

    'cli.usage'         => 'Použití: candy-ssh-launcher [--config <cesta>] [--ssh <ssh-binární>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: neznámý argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: konfigurace nenalezena. Vytvořte ~/.config/wishlist.yml (viz rychlý start v README) nebo předejte --config <cesta>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: zrušeno.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: binární soubor ssh nenalezen nebo není spustitelný: {bin} — zadejte --ssh <cesta>, pokud je ssh jinde',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} vyžaduje hodnotu',
    'cli.no_endpoints'   => 'candy-ssh-launcher: žádné endpointy v {path}',
    'cli.not_a_terminal' => 'výběr vyžaduje interaktivní terminál — stdin a stdout musí být TTY. Pro strojově čitelný výstup použijte --list.',
    'cli.config_unowned'   => 'candy-ssh-launcher: zamítnuta konfigurace patřící jinému uživateli: {path} — konfigurace jsou důvěryhodný vstup; viz Security v README.',
    'cli.config_writable'  => 'candy-ssh-launcher: zamítnuta konfigurace zapisovatelná skupinou nebo všemi: {path} — zpřísněte na 0644 nebo 0600, nebo předejte explicitně přes --config.',
    'cli.project_local'    => 'candy-ssh-launcher: používána lokální wishlist projektu: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: endpoint požaduje -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'kontrola hostitelských klíčů je oslabena — klíč serveru nemusí být ověřen',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'databáze důvěry známých hostů uživatele je přesměrována',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'systémová databáze důvěry je přesměrována',
    'cli.unsafe_option_effect_log_level' => 'varování a upozornění ssh jsou skryta',
    'cli.unsafe_option_effect_identity_agent' => 'ověření je delegováno na jiný socket agenta',
    'cli.unsafe_option_effect_certificate_file' => 'je nabízen další autentizační certifikát',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filtr: ',
    'picker.no_matches'  => '  (žádné shody — Backspace nebo Esc)',
    'picker.help'        => '↑/↓ vybrat · Enter připojit · Esc ukončit · psát pro filtr',
    'picker.help_keys'   => '⌫ smazat · Ctrl+U/W vyčistit · PgUp/PgDn stránka · Home/End skok',
    'picker.count'       => '  ({shown} z {total})',
];
