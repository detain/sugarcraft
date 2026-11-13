<?php

/**
 * Portuguese (European) UI strings for candy-tetris.
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
    'score_card' => 'pontos: {score}
linhas: {lines}
nível:  {level}',
    'help_card'  => 'mover
rodar horário
rodar anti-horário
queda lenta
queda rápida
guardar
pausa
reiniciar (fim)
sair',
    'next_label' => 'seguinte:',
    'hold_label' => 'reserva:',
    'paused'     => '[ em pausa — prima {keys} para retomar ]',

    // Game-over banner
    'over.title'                => 'FIM DO JOGO',
    'over.final_score'          => 'pontuação final: {points}',
    'over.restart_hint'         => 'prima {restart} para reiniciar — prima {quit} para sair',
    'over.reason'               => 'motivo: {reason}',
    'over.reason.spawn_blocked' => 'sem espaço para a peça seguinte',
    'over.reason.top_out'       => 'a pilha atingiu o teto',
    'over.reason.crushed'       => 'esmagado pelo lixo',

    // On-frame cues
    'hold_used'  => '(usada)',
    'combo_line' => 'combo x%d',
    'b2b_line'   => 'B2B pronto',
    'over.best'    => 'melhor: {points}',
    'over.new_best' => 'novo recorde!',
    'over.games' => 'partidas concluídas: {games}',

    // Versus renderer
    'label.player'   => 'JOGADOR',
    'label.computer' => 'COMPUTADOR',
    'win.player'     => 'GANHASTE!',
    'win.computer'   => 'O COMPUTADOR GANHA!',
    // The versus frame teaches the live keys through the shared
    // help_card column (candy-tetris-015 folded the five-verb roster
    // away); the exchange rule stays a sentence of its own.
    'vs.garbage_rule' => 'as linhas removidas enviam lixo ao adversário, cada linha com um buraco',
    'vs.record' => 'percurso: {wins} ganhos, {losses} perdidos',
    // Match-strip frame words (candy-tetris-008): the scoreboard
    // labels are catalogue-owned; keep them short — the strip
    // derives its columns from their display widths.
    'vs.strip.you' => 'você',
    'vs.strip.computer' => 'computador',
    'vs.strip.score' => 'marcador:',
    'vs.strip.lines' => 'linhas:',
    'vs.strip.level' => 'nível:',
    'intro.title' => 'bem-vindo ao candy-tetris',
    'intro.goal' => 'elimina linhas — não chegues ao topo',
    'intro.press' => 'prime {keys} para jogar',
    // candy-tetris-006/-095 (pt): the on-ramp advertises the reachable key reference.
    'intro.keys' => 'prima {keys} a qualquer momento para ver as teclas',
    'intro.vs' => 'modo versus: candy-tetris -v',
    'key_or' => ' ou ',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modo desconhecido "{flag}"',
    'help_text'    => 'candy-tetris - clone de Tetris

Uso: candy-tetris [opções]
  (sem args) Iniciar jogo a solo
  -v, --vs   Modo contra o computador
  -h, --help Mostrar esta ajuda
  --version  Mostrar a versão
',
    // candy-tetris-006/-095 (pt): --help keys section header.
    'help.keys' => 'teclas:',
    // candy-tetris-006/-095 (pt): the press that closes the key reference.
    'help.close' => 'prima {keys} novamente para fechar',
    // candy-tetris-006/-095 (pt): --help terminal floor, numbers read from the Renderer constants.
    'help.floor' => 'o quadro precisa de um terminal de pelo menos {cols}x{rows}',
    // candy-tetris-006/-095 (pt): compact sidebar pointer replacing the budgeted-out help card.
    'keys.mini' => 'teclas: {keys}',
    'cli.no_tty' => 'O candy-tetris precisa de um terminal interativo: execute-o a partir de um TTY (não de um pipe ou redirecionamento) com TERM diferente de dumb.',
    'launch.extras' => 'candy-tetris: apenas um argumento é aceito, mas {count} a mais: {list}',
    'score.save.failed' => 'candy-tetris: não foi possível guardar as pontuações ({problem})',
    'keys.ignored' => 'candy-tetris: ficheiro de teclas ilegível ignorado ({problem})',
    'scores.ignored' => 'candy-tetris: ficheiro de pontuações ilegível ignorado ({problem})',
    'config.problem' => '{detail} em {path}',
    'cli.refusal' => 'candy-tetris precisa de um terminal interativo (TTY).
Funciona sem cor: define NO_COLOR.',
    'cli.too_small' => 'candy-tetris precisa de um terminal {size}; este tem {actual}.
Redimensione a janela e o jogo continua aqui.',
    'cli.too_small_vs' => 'candy-tetris VS precisa de um terminal {size}; este tem {actual}.
Redimensione a janela e o duelo continua aqui.',
];
