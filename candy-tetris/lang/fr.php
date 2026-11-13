<?php

/**
 * French UI strings for candy-tetris.
 *
 * Keys, key order and every {placeholder} / sprintf token mirror
 * lang/en.php, which stays the source of truth. Flag spellings in the
 * usage screen are kept verbatim, so a refusal is never half-translated.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // Single-player renderer
    'score_card' => 'score :  {score}
lignes : {lines}
niveau : {level}',
    'help_card'  => 'déplacer
rotation horaire
rotation anti-hor.
rotation 180
chute lente
chute rapide
réserve
pause
relancer (fin)
quitter',
    'next_label' => 'suivante :',
    'hold_label' => 'réserve :',
    'paused'     => '[ en pause — appuyez sur {keys} pour reprendre ]',

    // Game-over banner
    'over.title'                => 'PARTIE TERMINÉE',
    'over.final_score'          => 'score final : {points}',
    'over.restart_hint'         => 'appuyez sur {restart} pour relancer — sur {quit} pour quitter',
    'over.reason'               => 'motif : {reason}',
    'over.reason.spawn_blocked' => 'plus de place pour la pièce suivante',
    'over.reason.top_out'       => 'la pile a atteint le plafond',
    'over.reason.crushed'       => 'écrasé par les déchets',

    // On-frame cues
    'hold_used'  => '(utilisée)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B armé',
    'over.best'    => 'record : {points}',
    'over.new_best' => 'nouveau record !',
    'over.games' => 'parties terminées : {games}',

    // Versus renderer
    'label.player'   => 'JOUEUR',
    'label.computer' => 'ORDINATEUR',
    'win.player'     => 'VOUS GAGNEZ !',
    'win.computer'   => "L'ORDINATEUR GAGNE !",
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'les lignes effacées envoient des lignes parasites à l\'adversaire, chacune avec un trou',
    'vs.record' => 'bilan : {wins} gagnés, {losses} perdus',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'vous',
    'vs.strip.computer' => 'ordinateur',
    'vs.strip.score' => 'score:',
    'vs.strip.lines' => 'lignes:',
    'vs.strip.level' => 'niveau:',
    'intro.title' => 'bienvenue dans candy-tetris',
    'intro.goal' => 'effacez des lignes — ne pas atteindre le sommet',
    'intro.press' => 'appuyez sur {keys} pour jouer',
    // candy-tetris-006/-095 (fr): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'appuyez sur {keys} à tout moment pour les touches',
    'intro.vs' => 'mode duel : candy-tetris -v',
    'key_or' => ' ou ',

    // Launch screen
    'mode.unknown' => 'candy-tetris : mode inconnu "{flag}"',
    'help_text'    => "candy-tetris - clone de Tetris\n\nUtilisation : candy-tetris [options]\n  (aucun)    Démarrer une partie solo\n  -v, --vs   Mode contre l'ordinateur\n  -h, --help Afficher cette aide\n  --version  Afficher la version\n",
    // candy-tetris-006/-095 (fr): --help keys section header.
    'help.keys' => 'touches :',
    // candy-tetris-006/-095 (fr): the press that closes the key reference.
    'help.close' => 'appuyez à nouveau sur {keys} pour fermer',
    // candy-tetris-006/-095 (fr): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'l\'affichage exige un terminal d\'au moins {cols}x{rows}',
    // candy-tetris-006/-095 (fr): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'touches : {keys}',
    // candy-tetris-054/-083 (fr): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.quit' => 'appuyez encore sur {keys} pour quitter',
    // candy-tetris-054/-083 (fr): the confirm question for an armed
    // destructive press, naming the live binding.
    'confirm.restart' => 'appuyez encore sur {keys} pour recommencer',
    // candy-tetris-092/-025 (fr): game-feel moments and the on-frame slot copy.
    'moment.lines.one' => '1 ligne !',
    'moment.lines.many' => '{count} lignes !',
    'moment.level' => 'niveau {level} !',
    'moment.garbage' => 'attaque en approche !',
    'cli.no_tty' => 'candy-tetris a besoin d\'un terminal interactif : lancez-le depuis un TTY (pas un tube ni une redirection) avec un TERM autre que dumb.',
    'launch.extras' => 'candy-tetris: un seul argument accepté, mais {count} en trop : {list}',
    'score.save.failed' => 'candy-tetris: impossible denregistrer les scores ({problem})',
    'keys.ignored' => 'candy-tetris: fichier de touches illisible ignoré ({problem})',
    'scores.ignored' => 'candy-tetris: fichier de scores illisible ignoré ({problem})',
    'config.problem' => '{detail} dans {path}',
    'cli.refusal' => 'candy-tetris a besoin d\'un terminal interactif (un TTY).
Il joue sans couleur : définissez NO_COLOR.',
    'cli.too_small' => 'candy-tetris exige un terminal {size} ; celui-ci fait {actual}.
Redimensionnez la fenêtre et la partie reprend ici.',
    'cli.too_small_vs' => 'candy-tetris VS exige un terminal {size} ; celui-ci fait {actual}.
Redimensionnez la fenêtre et la partie reprend ici.',
];
