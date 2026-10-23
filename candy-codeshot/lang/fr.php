<?php

/**
 * French translations for candy-codeshot.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'cli.unknown_flag'         => "candy-codeshot : indicateur non reconnu '{flag}'",
    'cli.unknown_theme'       => "candy-codeshot : thème inconnu '{name}'. Connus : dark, light, dracula, tokyo-night, nord",
    'cli.unknown_window_style' => "candy-codeshot : style de fenêtre inconnu '{style}'. Connus : macos, windows-terminal, iterm, hyper, none",
    'cli.unknown_format'      => "candy-codeshot : format inconnu '{format}'. Connus : svg, png",
    'cli.gd_required'         => 'candy-codeshot : ext-gd est requis pour la sortie PNG',
    'cli.font_not_found'      => "candy-codeshot : fichier de police introuvable : '{path}'",
    'cli.font_invalid'        => "candy-codeshot : '{path}' n'est pas un fichier de police valide (attendu TTF, OTF, WOFF ou WOFF2)",
    'cli.bad_highlight'       => "candy-codeshot : format de surbrillance invalide '{format}'. Utiliser debut:fin (ex. 3:7) ou debut:fin:#couleur (ex. 3:7:#fffbe6)",
    'cli.read_failed'          => 'candy-codeshot : impossible de lire la donnée',
    'cli.path_outside_cwd'    => "candy-codeshot : le chemin '{path}' est hors du répertoire de travail courant (utiliser --unsafe-paths pour autoriser)",
    'cli.write_failed'        => "candy-codeshot : impossible d'écrire '{path}'",
    'cli.missing_value'       => "candy-codeshot : {option} nécessite une valeur",
    'cli.bad_number'          => "candy-codeshot : {option} attend un entier non négatif, reçu '{value}'",
    'cli.png_ignores'         => "candy-codeshot : la sortie PNG ignore les options SVG uniquement : {options} (utiliser --format svg)",
    'cli.highlight_rejected'  => "candy-codeshot : surbrillance '{spec}' refusée : {reason}",
    'cli.format_mismatch'     => "candy-codeshot : le format de sortie '{format}' ne correspond pas à l'extension de '{path}'",
    'cli.usage'               => <<<'USAGE'
candy-codeshot - rendre du code ou une sortie de terminal en capture SVG ou PNG

Utilisation :
  echo "hello" | candy-codeshot --theme dracula > out.svg
  candy-codeshot input.txt --theme tokyo-night --no-window --line-numbers

Les valeurs des indicateurs sont des arguments séparés : "--theme dracula", pas "--theme=dracula"
(la forme "=" est refusée comme indicateur non reconnu).

Options :
  --theme <nom>           Nom du thème : dark, light, dracula, tokyo-night, nord (défaut : dark)
  --padding <n>           Marge autour du contenu (défaut : 24)
  --no-window             Masquer la fenêtre (feux, barre de titre)
  --no-shadow             Désactiver l'ombre portée
  --no-border             Masquer la bordure
  --line-numbers          Afficher les numéros de ligne
  --border-radius <n>     Rayon des coins (défaut : 8)
  --window-style <style>  Style de fenêtre : macos, windows-terminal, iterm, hyper, none (défaut : macos)
  --ligatures             Activer les ligatures (font-variant-ligatures: normal ; SVG uniquement)
  --font <chemin>         Chemin d'un fichier de police TTF/OTF/WOFF/WOFF2 à intégrer dans le SVG (SVG uniquement)
  --unsafe-paths          Autoriser les chemins entrée/--font/--output hors du répertoire courant
  --highlight <debut:fin[:couleur]>
                          Surbrillance des lignes debut:fin avec couleur optionnelle (couleur par défaut : #fffbe6 ; SVG uniquement)
  --format <svg|png>      Format de sortie (défaut : svg ; png requiert ext-gd)
  -t, --type <langue>     Force la langue de la coloration syntaxique (défaut : nom de fichier, shebang, contenu)
  -o, --output <chemin>   Écrire la sortie dans un fichier au lieu de stdout
  -h, --help              Afficher ce message d'aide

Remarques :
  Les chemins d'entrée et de sortie doivent rester dans le répertoire de travail
  courant, sauf avec --unsafe-paths. La sortie PNG ignore les options SVG uniquement
  ci-dessus et annonce chaque option ignorée sur stderr.

Codes de sortie :
  0  succès
  1  autoload composer manquant (problème d'installation)
  2  erreur d'utilisation ou d'entrée (indicateur invalide, chemin illisible, surbrillance refusée, ...)

Exemples :
  cat script.php | candy-codeshot --theme dracula
  candy-codeshot script.php --theme tokyo-night --line-numbers -o out.svg
  candy-codeshot script.php --window-style none --highlight 3:7
  candy-codeshot notes.md --format png -o notes.png
USAGE,
];
