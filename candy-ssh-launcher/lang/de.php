<?php

/**
 * German translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'Ausführung von {bin} fehlgeschlagen',
    'launcher.transport_not_executable' => 'Transport-Binärdatei nicht gefunden oder nicht ausführbar: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: Endpoint {endpoint}: der eigene Transport ignoriert {legs}',
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
    'config.field_not_scalar'  => 'Wishlist-Eintrag: Feld [{field}] muss ein Skalar sein, erhalten {type}',
    'config.too_large'          => 'Wishlist-Konfiguration ist größer als das Limit von {limit} Bytes: {path}',
    'config.list_too_long'      => 'Wishlist-Eintrag: Feld [{field}] enthält mehr als die {limit} Einträge, die der Lader erlaubt',
    'config.field_not_list'       => 'Wishlist-Eintrag: Feld [{field}] muss eine Werteliste sein, keine Schlüssel-Wert-Zuordnung',
    'config.encoding_utf16'       => '{path} ist UTF-16-kodiert — die Konfiguration bitte als UTF-8 neu speichern',
    'config.unreadable'       => 'Wishlist-Konfiguration konnte nicht gelesen werden: {path}',
    'config.home_unset'       => '~ kann ohne $HOME nicht expandiert werden: {path}',
    'config.yaml_inline_flow' => 'Wishlist-YAML: Inline-Flow-Sammlung nicht unterstützt: {value} (verschachtelte Liste verwenden)',

    // endpoint security
    'endpoint.option_injection' => 'Wishlist-Endpoint: Feld [{field}] enthält unsicheren Wert mit führendem Bindestrich [{value}]',
    'endpoint.host_empty' => 'Endpoint `{name}` hat einen leeren Host — eine wählbare Zeile braucht einen Hostnamen',
    'endpoint.name_empty' => 'Endpoint hat einen leeren Namen — eine auswählbare Zeile braucht einen Namen',
    'endpoint.option_empty' => 'Endpoint `{name}` hat einen leeren Options-Eintrag — ein leerer -o-Wert wird abgelehnt',
    'endpoint.port_invalid'     => 'Wishlist-Endpoint [{host}]: ungültiger Port [{port}] — muss eine Ganzzahl von 1 bis 65535 sein',


    // bin/candy-ssh-launcher
    'cli.usage'         => "Aufruf: candy-ssh-launcher [--config <pfad>] [--ssh <pfad>] [--from-ssh-config] [--list] [-h|--help] [-v|--version] [--] [endpoint]\nTasten: Pfeiltasten bewegen (bei leerem Filter auch j/k), Tippen filtert, Enter verbindet, Ctrl+U leert die Zeile, Ctrl+W löscht ein Wort, Ctrl+H löscht zurück, Esc bricht ab\nExit-Codes: 0 gestartet oder aufgelistet, 1 keine oder fehlerhafte Config oder verweigertes Programm, 2 unbekannter Schalter oder Argument oder kein Terminal, 3 Endpoint beim Start verweigert, 130 abgebrochen\nKein interaktives Terminal? --list gibt alle Endpoints aus, und ein exakt benannter Endpoint verbindet sich trotzdem.\nEin bloßes -- beendet die Optionsauswertung; alles danach wird ignoriert — den Endpoint vorher nennen.",
    'cli.unknown_arg'   => 'candy-ssh-launcher: unbekanntes Argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: keine Konfiguration gefunden. Erstellen Sie ~/.config/wishlist.yml (siehe Schnellstart im README) oder übergeben Sie --config <pfad>.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: abgebrochen.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh-Binary nicht gefunden oder nicht ausführbar: {bin} — geben Sie --ssh <pfad> an, wenn ssh woanders liegt',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} benötigt einen Wert',
    'cli.no_endpoints'   => 'candy-ssh-launcher: keine Endpoints in {path}',
    'cli.not_a_terminal' => 'candy-ssh-launcher: Der Picker benötigt ein interaktives Terminal — stdin und stdout müssen TTYs sein. Verwenden Sie --list für maschinenlesbare Ausgabe.',
    'cli.config_unowned'   => 'candy-ssh-launcher: Konfiguration von einem anderen Benutzer verworfen: {path} — Konfigurationen sind vertrauenswürdige Eingaben; siehe Sicherheit im README.',
    'cli.config_writable'  => 'candy-ssh-launcher: Konfiguration verworfen, die Gruppe oder Andere schreiben dürfen: {path} — auf 0644 oder 0600 beschränken oder explizit über --config übergeben.',
    'cli.project_local'    => 'candy-ssh-launcher: projektlokale Wishlist wird verwendet: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: der Endpunkt verlangt -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'die Rechnerprüfung des Host-Schlüssels ist herabgestuft — der Serverschlüssel bleibt womöglich ungeprüft',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'die Vertrauensdatenbank der bekannten Hosts des Benutzers ist umgeleitet',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'die systemweite Vertrauensdatenbank ist umgeleitet',
    'cli.unsafe_option_effect_log_level' => 'ssh-Warnungen und Hinweise werden versteckt',
    'cli.unsafe_option_effect_identity_agent' => 'die Authentifizierung wird an einen anderen Agent-Socket delegiert',
    'cli.unsafe_option_effect_certificate_file' => 'ein zusätzliches Authentifizierungszertifikat wird angeboten',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: Endpunkte werden aus der ssh-Konfiguration importiert: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: keine ssh-Konfiguration unter {path} — mit --config <path> und --from-ssh-config eine andere Datei angeben',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path} sieht wie eine OpenSSH-Konfiguration aus, keine Wishlist-Datei — importiere sie mit --from-ssh-config (optional mit --config <path>)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'Filter: ',
    'picker.no_matches'  => '  (keine Treffer — Backspace oder Esc)',
    'picker.help'        => '↑/↓ wählen · Enter verbinden · Esc beenden · tippen filtert',
    'picker.help_keys'   => '⌫ löschen · Strg+U/W leeren · PgUp/PgDn blättern · Pos/End springen',
    'picker.count'       => '  ({shown} von {total})',
];
