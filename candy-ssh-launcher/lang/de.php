<?php

/**
 * German translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'Ausführung von {bin} fehlgeschlagen',
    'config.not_found'         => 'Wishlist-Konfiguration nicht gefunden: {path}',
    'config.json_top_level'    => 'Wishlist-JSON: Oberster Wert muss ein Array sein',
    'config.json_entry_object' => 'Wishlist-JSON: Jeder Eintrag muss ein Objekt sein',
    'config.yaml_continuation' => "Wishlist-YAML: Fortsetzung vor jedem '- name:'-Block",
    'config.yaml_unparseable'  => 'Wishlist-YAML: nicht analysierbare Zeile {line}: {found}',
    'config.yaml_block_mapping' => 'Wishlist-YAML: Block-Mapping unter `{field}:` in Zeile {line}: {found} — dieses Feld muss eine Liste von `- value`-Einträgen sein',
    'config.yaml_unterminated_quote' => 'Wishlist-YAML: nicht abgeschlossenes Anführungszeichen im Wert: {found}',
    'config.entry_missing_field' => 'Wishlist-Eintrag #{index} fehlt ein Pflichtfeld: {field}',
    'config.unknown_key' => 'Wishlist-Eintrag: unbekanntes Feld `{key}` ignoriert — kein dokumentierter Schlüssel',
    'config.unknown_key_nearmiss' => 'Wishlist-Eintrag: unbekanntes Feld `{key}` ignoriert — meinten Sie `{field}`?',
    'config.duplicate_name' => 'Wishlist: doppelter Endpoint-Name `{name}` — alle Zeilen werden geladen; Namen sind Identifikatoren',
    'config.field_not_scalar'  => 'wishlist-Eintrag: Feld [{field}] muss ein Skalar sein, erhalten {type}',
    'config.too_large'          => 'Wishlist-Konfiguration ist größer als das Limit von {limit} Bytes: {path}',
    'config.list_too_long'      => 'Wishlist-Eintrag: Feld [{field}] enthält mehr als die {limit} Einträge, die der Lader erlaubt',
    'config.field_not_list'       => 'Wishlist-Eintrag: Feld [{field}] muss eine Werteliste sein, keine Schlüssel-Wert-Zuordnung',
    'config.unreadable'       => 'Wishlist-Konfiguration konnte nicht gelesen werden: {path}',
    'config.home_unset'       => '~ kann ohne $HOME nicht expandiert werden: {path}',
    'config.yaml_inline_flow' => 'Wishlist-YAML: Inline-Flow-Sammlung nicht unterstützt: {value} (verschachtelte Liste verwenden)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist-Endpunkt: Feld [{field}] enthält unsicheren Wert mit führendem Bindestrich [{value}]',
    'endpoint.host_empty' => 'Endpoint `{name}` hat einen leeren Host — eine wählbare Zeile braucht einen Hostnamen',
    'endpoint.name_empty' => 'Endpoint hat einen leeren Namen — eine auswählbare Zeile braucht einen Namen',
    'endpoint.option_empty' => 'Endpoint `{name}` hat einen leeren Options-Eintrag — ein leerer -o-Wert wird abgelehnt',
    'endpoint.port_invalid'     => 'wishlist-Endpunkt [{host}]: ungültiger Port [{port}] — muss eine Ganzzahl von 1 bis 65535 sein',


    // bin/candy-ssh-launcher
    'cli.usage'         => 'Aufruf: candy-ssh-launcher [--config <pfad>] [--ssh <ssh-binär>] [--list] [-h|--help] [-v|--version]',
    'cli.unknown_arg'   => 'candy-ssh-launcher: unbekanntes Argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: keine Konfiguration gefunden. Erstellen Sie ~/.config/wishlist.yml (siehe Schnellstart im README) oder übergeben Sie --config <pfad>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: abgebrochen.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh-Binary nicht gefunden oder nicht ausführbar: {bin}',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} benötigt einen Wert',
    'cli.no_endpoints'   => 'candy-ssh-launcher: keine Endpunkte in {path}',
    'cli.not_a_terminal' => 'candy-ssh-launcher: Der Picker benötigt ein interaktives Terminal — stdin und stdout müssen TTYs sein. Verwenden Sie --list für maschinenlesbare Ausgabe.',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'Filter: ',
    'picker.no_matches'  => '  (keine Treffer — Backspace oder Esc)',
    'picker.help'        => '↑/↓ wählen · Enter verbinden · Esc beenden · tippen filtert',
    'picker.help_keys'   => '⌫ löschen · Strg+U/W leeren · PgUp/PgDn blättern · Pos/End springen',
    'picker.count'       => '  ({shown} von {total})',
];
