<?php

/**
 * Brazilian Portuguese translations for candy-minesweeper.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'board.too_small' => 'tabuleiro muito pequeno',
    'board.minecount_out_of_range' => 'mineCount fora do intervalo',
    'board.rows_shape_mismatch' => 'o formato das linhas não corresponde à largura/altura',
    'custom.min_rows' => 'as linhas devem ser no mínimo 2',
    'custom.min_cols' => 'as colunas devem ser no mínimo 2',
    'custom.max_rows' => 'as linhas devem ser no máximo 50',
    'custom.max_cols' => 'as colunas devem ser no máximo 50',
    'custom.min_mines' => 'pelo menos 1 mina é necessária',
    'custom.max_mines' => 'minas demais — deixe uma área inicial segura',
    'ui.status' => 'minas: {mines}  ·  bandeiras: {flags}  ·  restantes: {remaining}  ·  tempo: {time}',
    'ui.boom' => '💥 boom — pressione r para reiniciar',
    'ui.cleared' => '★ vencido — pressione r para jogar novamente',
    'ui.help_moves' => '↑ ↓ ← → / hjkl  mover  ·  espaço / enter  revelar',
    'ui.help_board' => 'f  bandeira  ·  c / clique do meio  acorde  ·  clique direito  bandeira',
    'ui.help_session' => 'r  reiniciar  ·  S  salvar  ·  L  carregar  ·  q / Esc / Ctrl+C  sair',
];
