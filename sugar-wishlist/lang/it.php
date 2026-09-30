<?php

/**
 * Italian translations for sugar-wishlist.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'launcher.no_pcntl'        => 'pcntl_exec non disponibile; ext-pcntl richiesto',
    'launcher.exec_failed'     => 'esecuzione di {bin} fallita',
    'config.not_found'         => 'Configurazione wishlist non trovata: {path}',
    'config.json_top_level'    => 'wishlist json: il valore di primo livello deve essere un array',
    'config.json_entry_object' => 'wishlist json: ogni voce deve essere un oggetto',
    'config.yaml_continuation' => "wishlist yaml: continuazione prima di qualsiasi blocco '- name:'",
    'config.yaml_unparseable'  => 'wishlist yaml: riga non analizzabile: {line}',
    'config.entry_missing_field' => 'voce wishlist con campo obbligatorio mancante: name o host',
    'config.field_not_scalar'  => 'voce wishlist: il campo [{field}] deve essere uno scalare, ottenuto {type}',

    // endpoint security
    'endpoint.option_injection' => 'endpoint wishlist: il campo [{field}] ha un valore non sicuro con trattino iniziale [{value}]',
    'endpoint.port_invalid'     => 'endpoint wishlist [{host}]: porta non valida [{port}] — deve essere un intero da 1 a 65535',

    'cli.usage'         => 'Uso: wishlist [--config <percorso>] [--ssh <binario-ssh>]',
    'cli.unknown_arg'   => 'wishlist: argomento sconosciuto: {arg}',
    'cli.no_config'     => 'wishlist: nessuna configurazione trovata. Passare --config <percorso>.',
    'cli.error'         => 'wishlist: {message}',
    'cli.cancelled'     => 'wishlist: annullato.',
    'cli.ssh_not_executable' => 'wishlist: binario ssh non trovato o non eseguibile: {bin}',
];
