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
    'score_card' => 'pontos: %d
linhas: %d
nível:  %d',
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
    'paused'     => '[ em pausa — prima p para retomar ]',

    // Game-over banner
    'over.title'                => 'FIM DO JOGO',
    'over.final_score'          => 'pontuação final: {points}',
    'over.restart_hint'         => 'prima r para reiniciar — prima q para sair',
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

    // Versus renderer
    'label.player'   => 'JOGADOR',
    'label.computer' => 'COMPUTADOR',
    'win.player'     => 'GANHASTE!',
    'win.computer'   => 'O COMPUTADOR GANHA!',
    'over.quit_hint' => 'prima q para sair',
    'vs_keys'        => 'queda
guardar
pausa
reinício
sair',
    'vs.record' => 'percurso: {wins} ganhos, {losses} perdidos',
    'intro.title' => 'bem-vindo ao candy-tetris',
    'intro.goal' => 'elimina linhas - não chegues ao topo',
    'intro.press' => 'prime {keys} para jogar',
    'intro.vs' => 'modo versus: candy-tetris -v',

    // Launch screen
    'mode.unknown' => 'candy-tetris: modo desconhecido "{flag}"',
    'help_text'    => 'candy-tetris - clone de Tetris

Uso: candy-tetris [opções]
  (sem args) Iniciar jogo a solo
  -v, --vs   Modo contra o computador
  -h, --help Mostrar esta ajuda
  --version  Mostrar a versão
',
    'cli.no_tty' => 'O candy-tetris precisa de um terminal interativo: execute-o a partir de um TTY (não de um pipe ou redirecionamento) com TERM diferente de dumb.',
];
