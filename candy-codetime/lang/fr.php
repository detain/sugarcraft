<?php

/**
 * French translations for candy-codetime.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.entry_pushed' => '{duration}s enregistrées : {project} ({language})',
    'cli.usage' => "Utilisation: candy-codetime [--backend=file|sqlite] <command> [args]\n\nCommandes:\n  dashboard              Démarrer le tableau de bord TUI (lit le backend sélectionné)\n  push                   <project> <language> <file> [duration] | --  Enregistrer un heartbeat\n  milestone              add [name] [description] | list  Gérer les jalons (sqlite uniquement)\n  export                 <csv|json|ics> [days]  Exporter les heartbeats (7 jours par défaut)\n  gaps                   [days]  Afficher les lacunes non suivies d'au moins 300 secondes (7 jours par défaut)\n  backup                 Archiver les fichiers de données de plus de 30 jours (copie ; ne supprime jamais)\n  help, --help, -h       Afficher cette utilisation\n  --version, -v          Afficher la version\n\nOptions:\n  --backend=file|sqlite  Backend de stockage pour push/export/gaps/dashboard (par défaut : file)\n  --time=<unix>          push : enregistrer le battement à ces secondes unix, pas maintenant\n  CANDY_CODETIME_DIR     Répertoire de données (par défaut : \$XDG_DATA_HOME/candy-codetime, sinon \$HOME/.local/share/candy-codetime, ou ./.local/share/candy-codetime quand HOME n'est pas défini)",
    'cli.push_usage' => 'Utilisation : candy-codetime push <project> <language> <file> [duration] [--time=<unix>] | candy-codetime push -- (lot de lignes JSON sur stdin)',
    'cli.push_missing_args' => "push : arguments obligatoires manquants (project, language, file)",
    'cli.push_ignored' => "push : {file} correspondait à .sugartrackignore — ignoré",
    'cli.push_ignored_wholesale' => 'push : {file} ignoré par un motif global dans {ignore_file} ({patterns} motifs) ; le projet "{project}" ne consigne rien — supprimez ce fichier ou restreignez-le',
    'cli.push_invalid_duration' => 'push : la durée doit être un nombre entier de secondes, valeur reçue : "{value}"',
    'cli.push_invalid_time' => 'push : --time doit être un nombre entier de secondes unix, valeur reçue : "{value}"',
    'cli.push_time_stdin_conflict' => 'push : --time est refusé en mode lot stdin ; chaque ligne porte son propre horodatage',
    'cli.push_stdin_malformed' => 'push : la ligne {line} de stdin n’est pas un battement valide — ignorée',
    'cli.push_stdin_done' => 'push : {count} battements enregistrés',
    'cli.push_stdin_done_one' => 'push : {count} battement enregistré',
    'cli.invalid_days' => 'le nombre de jours doit être un nombre entier, valeur reçue : "{value}"',
    'cli.backend_invalid' => "backend invalide (utiliser file ou sqlite)",
    'cli.backend_unavailable' => "sqlite backend indisponible : l'extension PHP sqlite3 n'est pas chargée",
    'cli.backend_other_hint' => 'remarque : le backend {other} contient aussi des enregistrements - sélectionnez-le avec --backend={other}',
    'cli.storage_error' => 'candy-codetime : erreur de stockage : {message}',
    'cli.dashboard_needs_tty' => 'candy-codetime : le tableau de bord exige un terminal interactif (stdin et stdout ne sont pas des TTY). Pour une sortie sans terminal : candy-codetime export <csv|json|ics>',
    'cli.milestone_usage' => "Utilisation : candy-codetime milestone add [name] [description] | list",
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
    'cli.backup_done_one' => 'backup : {count} fichier archivé',
    'cli.backup_none' => "backup : rien de nouveau à archiver",
    'cli.backup_sqlite_notice' => "backup : la base de donnees SQLite {db} n'est PAS archivee par cette commande — utilisez sqlite3 <db> \".backup <dest>\" ou VACUUM INTO pour une copie sure",
    'cli.unknown_command' => "candy-codetime : commande inconnue : {command}",
    'cli.legacy_env_deprecated' => "candy-codetime : {variable} est déprécié ; définissez CANDY_CODETIME_DIR à la place.",
    'cli.migration_notice' => "candy-codetime : {notice}",
    'ui.top_projects' => 'Top des projets',
    'ui.by_language' => 'Par langage',
    'ui.help_row' => '  ←  jour précédent  ·  →  jour suivant  ·  r  recharger  ·  q/Esc  quitter',
    'ui.help_row_compact' => '  ← prev  ·  → next  ·  r  reload  ·  q/Esc  quit',
    'ui.total' => 'total :',
    'ui.range_format' => 'M j',
    'ui.weekday_format' => 'D',
    'ui.no_activity' => '(aucune activité)',
    'ui.more_suffix' => '+{count} de plus',
    'ui.earlier_suffix' => '+{count} précédents',
    'ui.activity_minutes_heading' => 'Activité quotidienne (minutes)',
    'ui.activity_heading' => 'Activité quotidienne',
    'ui.no_data' => '(aucune donnée)',
];
