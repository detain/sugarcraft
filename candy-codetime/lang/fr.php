<?php

/**
 * French translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => "entrée enregistrée : {project} ({language}) +{duration}s",
    'cli.usage' => "Utilisation: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommandes:\n  dashboard              Enregistrer un heartbeat\n  push                   <project> <language> <file> [duration]  Gérer les jalons (sqlite uniquement)\n  milestone              <add|list> [name] [description]  Exporter les heartbeats (7 jours par défaut)\n  export                 <csv|json|ics> [days]  Afficher les lacunes non suivies de plus de 300 secondes (7 jours par défaut)\n  gaps                   [days]  Archiver les fichiers de données de plus de 30 jours (copie ; ne supprime jamais)\n  backup                 Afficher cette utilisation\n  help, --help           Afficher la version\n  --version, -v          par défaut : file\n\nOptions:\n  --backend=file|sqlite  Backend de stockage pour push/export/gaps/dashboard (par défaut : file)\n  CANDY_CODETIME_DIR     Répertoire de données (par défaut : \$XDG_DATA_HOME/candy-codetime)",
    'cli.push_usage' => "Utilisation : candy-codetime push <project> <language> <file> [duration]",
    'cli.push_missing_args' => "push : arguments obligatoires manquants (project, language, file)",
    'cli.push_ignored' => "push : {file} correspondait à .sugartrackignore — ignoré",
    'cli.push_invalid_duration' => "push : la durée doit être un entier non négatif de secondes, valeur reçue : \"{value}\"",
    'cli.backend_invalid' => "backend invalide (utiliser file ou sqlite)",
    'cli.backend_unavailable' => "sqlite backend indisponible : l'extension PHP sqlite3 n'est pas chargée",
    'cli.milestone_usage' => "Utilisation : candy-codetime milestone <add|list> [name] [description]",
    'cli.milestone_missing_args' => "milestone add : argument obligatoire manquant (name)",
    'cli.milestone_added' => "jalon ajouté : {name}",
    'cli.milestone_list_header' => "Jalons :",
    'cli.milestone_list_empty' => "Aucun jalon enregistré.",
    'cli.milestone_entry' => "  {date}  {name}  {description}",
    'cli.export_usage' => "Utilisation : candy-codetime export <csv|json|ics> [days]",
    'cli.export_invalid_format' => "export : format invalide (utiliser csv, json ou ics)",
    'cli.gaps_no_data' => "Aucune lacune trouvée.",
    'cli.gaps_header' => "Lacunes non suivies :",
    'cli.gaps_entry' => "  {start} – {end}  ({seconds}s untracked)",
    'cli.backup_done' => "backup : {count} fichiers archivés",
    'cli.backup_none' => "backup : rien de nouveau à archiver",
    'cli.unknown_command' => "candy-codetime : commande inconnue : {command}",
    'cli.legacy_env_deprecated' => "candy-codetime : {variable} est déprécié ; définissez CANDY_CODETIME_DIR à la place.",
    'cli.migration_notice' => "candy-codetime : {notice}",
];
