<?php

/**
 * Dutch translations for candy-ssh-launcher.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.exec_failed'     => 'uitvoeren van {bin} mislukt',
    'launcher.transport_not_executable' => 'transportbinairbestand niet gevonden of niet uitvoerbaar: {bin}',
    'launcher.transport_dropped' => 'candy-ssh-launcher: endpoint {endpoint}: het eigen transport negeert {legs}',
    'config.not_found'         => 'wishlist-configuratie niet gevonden: {path}',
    'config.json_top_level'    => 'wishlist json: bovenste waarde moet een array zijn',
    'config.json_entry_object' => 'wishlist json: elke entry moet een object zijn',
    'config.yaml_continuation' => "wishlist yaml: Continuation voor elk '- name:'-blok",
    'config.yaml_unparseable'  => 'wishlist yaml: onleesbare regel {line}: {found}',
    'config.yaml_block_mapping' => 'wishlist yaml: blokindeling onder `{field}:` op regel {line}: {found} — dit veld moet een lijst van `- value` items zijn',
    'config.yaml_unterminated_quote' => 'wishlist yaml: onafgesloten aanhalingsteken in waarde: {found}',
    'config.entry_missing_field' => 'wishlist-item #{index}: verplicht veld ontbreekt: {field}',
    'config.unknown_key' => 'onbekend veld `{key}` genegeerd — niet gedocumenteerd',
    'config.unknown_key_nearmiss' => 'onbekend veld `{key}` genegeerd — bedoelde je `{field}`?',
    'config.duplicate_name' => 'dubbele endpointnaam `{name}` — alle regels laden; namen zijn identificatoren',
    'config.field_not_scalar'  => 'wishlist-invoer: veld [{field}] moet een scalair zijn, ontvangen {type}',
    'config.too_large'          => 'wishlist-configuratie is groter dan de limiet van {limit} bytes: {path}',
    'config.list_too_long'      => 'wishlist-item: veld [{field}] bevat meer dan de {limit} items die de lader toestaat',
    'config.field_not_list'       => 'wishlist-item: veld [{field}] moet een lijst met waarden zijn, geen sleutel-waardetabel',
    'config.encoding_utf16'       => '{path} is UTF-16-gecodeerd — sla de configuratie opnieuw op als UTF-8',
    'config.unreadable'       => 'wishlist-configuratie kon niet worden gelezen: {path}',
    'config.home_unset'       => 'kan ~ niet uitvouwen zonder $HOME: {path}',
    'config.yaml_inline_flow' => 'wishlist yaml: inline flow-verzameling niet ondersteund: {value} (gebruik een geneste lijst)',

    // endpoint security
    'endpoint.option_injection' => 'wishlist-endpoint: veld [{field}] heeft een onveilige waarde met een koppelteken aan het begin [{value}]',
    'endpoint.host_empty' => 'endpoint `{name}` heeft een lege host — een selecteerbare rij heeft een hostnaam nodig',
    'endpoint.name_empty' => 'endpoint heeft een lege naam — een selecteerbare regel heeft een naam nodig',
    'endpoint.option_empty' => 'endpoint `{name}` heeft een lege optie-ingang — een lege -o-waarde wordt geweigerd',
    'endpoint.port_invalid'     => 'wishlist-endpoint [{host}]: ongeldige poort [{port}] — moet een geheel getal zijn van 1 tot 65535',

    'cli.usage'         => "Gebruik: candy-ssh-launcher [--config <pad>] [--ssh <pad>] [--from-ssh-config] [--list] [-h|--help] [-v|--version] [--] [endpoint]\nToetsen: pijltjes bewegen (ook j/k als het filter leeg is), typen filtert, Enter verbindt, Ctrl+U wist de regel, Ctrl+W wist een woord, Ctrl+H wist terug, Esc annuleert\nExitcodes: 0 gestart of vermeld, 1 geen of defecte config of geweigerd programma, 2 onbekende vlag of argument of geen terminal, 3 endpoint bij start geweigerd, 130 geannuleerd\nGeen interactieve terminal? --list print alle endpoints, en een exact genoemde endpoint start nog steeds.\nEen kale -- beëindigt de optieverwerking; alles daarna wordt genegeerd — noem de endpoint ervoor.",
    'cli.unknown_arg'   => 'candy-ssh-launcher: onbekend argument: {arg}',
    'cli.no_config'     => 'candy-ssh-launcher: geen configuratie gevonden. Maak ~/.config/wishlist.yml aan (zie de snelstart in de README) of geef --config <pad> door.',
    'cli.error'         => 'candy-ssh-launcher: {message}',
    'cli.cancelled'     => 'candy-ssh-launcher: geannuleerd.',
    'cli.ssh_not_executable' => 'candy-ssh-launcher: ssh-binary niet gevonden of niet uitvoerbaar: {bin} — geef --ssh <pad> op als ssh elders staat',
    'cli.missing_value'  => 'candy-ssh-launcher: {arg} vereist een waarde',
    'cli.no_endpoints'   => 'candy-ssh-launcher: geen endpoints in {path}',
    'cli.not_a_terminal' => 'de kiezer heeft een interactieve terminal nodig — stdin en stdout moeten TTY\'s zijn. Gebruik --list voor machineleesbare uitvoer.',
    'cli.config_unowned'   => 'candy-ssh-launcher: config geweigerd omdat een andere gebruiker eigenaar is: {path} — configs zijn vertrouwde invoer; zie Security in de README.',
    'cli.config_writable'  => 'candy-ssh-launcher: config geweigerd omdat groep of iedereen mag schrijven: {path} — zet op 0644 of 0600, of geef expliciet --config op.',
    'cli.project_local'    => 'candy-ssh-launcher: projectlokale wishlist in gebruik: {path}',
    'cli.unsafe_option_notice' => 'candy-ssh-launcher: het endpoint vraagt -o {option} — {effect}',
    'cli.unsafe_option_effect_strict_host_key_checking' => 'de controle van host-sleutels is afgezwakt — de serversleutel blijft mogelijk ongecontroleerd',
    'cli.unsafe_option_effect_user_known_hosts_file' => 'de vertrouwensdatabase van bekende hosts van de gebruiker is omgeleid',
    'cli.unsafe_option_effect_global_known_hosts_file' => 'de systeemvertrouwensdatabase is omgeleid',
    'cli.unsafe_option_effect_log_level' => 'ssh-waarschuwingen en meldingen worden verborgen',
    'cli.unsafe_option_effect_identity_agent' => 'authenticatie wordt gedelegeerd naar een andere agent-socket',
    'cli.unsafe_option_effect_certificate_file' => 'er wordt een extra authenticatiecertificaat aangeboden',
    'cli.ssh_config_source'  => 'candy-ssh-launcher: endpoints worden geïmporteerd uit de ssh-configuratie: {path}',
    'cli.ssh_config_missing' => 'candy-ssh-launcher: geen ssh-configuratie op {path} — geef --config <path> met --from-ssh-config op om een ander bestand te noemen',
    'cli.config_is_ssh_config' => 'candy-ssh-launcher: {path} lijkt op een OpenSSH-configuratie, geen wishlist-bestand — importeer het met --from-ssh-config (optioneel met --config <path>)',

    // picker chrome
    'picker.title'       => '── wishlist ──',
    'picker.filter'      => 'filter: ',
    'picker.no_matches'  => '  (geen treffers — Backspace of Esc)',
    'picker.help'        => '↑/↓ kiezen · Enter verbinden · Esc afsluiten · typ om te filteren',
    'picker.help_keys'   => '⌫ verwijderen · Ctrl+U/W wissen · PgUp/PgDn pagina · Home/End springen',
    'picker.count'       => '  ({shown} van {total})',
];
