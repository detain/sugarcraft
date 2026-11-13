<?php

/**
 * Portuguese translations for candy-sql.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'database.no_file' => 'ficheiro SQLite não encontrado: {path}',
    'complete.in_literal' => 'nada a completar dentro de uma cadeia ou comentário',
    'complete.nothing' => 'nada a completar — escreva o início de uma palavra-chave',
    'complete.none_keyword' => 'nenhuma palavra-chave completa "{word}"',
    'complete.none_table' => 'nenhuma tabela completa "{word}"',
    'complete.none_column' => 'nenhuma coluna completa "{word}"',
    'complete.none_qualified' => 'nenhuma coluna de {qualifier} completa "{word}"',
    'complete.list' => '{count} completam "{word}": {list}{more}',
    'complete.done' => 'completado {word} → {insert}',
    'complete.inserted' => 'inserido {insert}',
    'edit.refuse_readonly' => 'sessão só de leitura — a edição está desativada',
    'edit.refuse_no_table' => 'abra primeiro a pré-visualização de uma tabela — os resultados de consultas não podem ser editados no local',
    'edit.refuse_no_rows' => 'nenhuma linha sob o cursor',
    'edit.refuse_no_pk' => 'esta tabela não tem chave primária — as linhas não podem ser endereçadas com segurança; escreva a instrução no painel de consulta',
    'edit.refuse_pk_hidden' => 'a chave primária desta linha é NULL ou um marcador binário — não pode ser endereçada',
    'edit.refuse_binary' => 'as células binárias ou grandes não são editáveis aqui — use uma instrução no painel de consulta',
    'edit.refuse_schema' => 'não foi possível ler o esquema de {table} — edição recusada',
    'edit.prompt_cell' => 'editar {table}.{column} = {value}  (enter pré-visualiza · ctrl+n NULL · esc cancela)',
    'edit.prompt_insert' => 'nova linha em {table} — {column} ({type}) [{n}/{total}] = {value}  (enter seguinte · vazio = DEFAULT · ctrl+n NULL · esc cancela)',
    'edit.prompt_confirm' => '{sql}  (enter confirma · esc cancela)',
    'edit.cancelled' => 'edição cancelada',
    'edit.unchanged' => 'valor inalterado — nada gravado',
    'edit.done_update' => '1 linha atualizada em {table}',
    'edit.done_insert' => '1 linha inserida em {table}',
    'edit.done_delete' => '1 linha eliminada de {table}',
    'edit.none_changed' => 'nenhuma linha alterada — o valor já lá estava ou a linha desapareceu; pré-visualização atualizada',
    'edit.failed' => 'falha na escrita — revertida, nada mudou: {reason}',
    'edit.too_many' => 'recusado: a instrução afetaria {count} linhas — revertida, nada mudou',
];
