<?php

/**
 * Portuguese translations for candy-scriptui.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    'style.empty_color'       => 'Cor vazia.',
    'style.unrecognised_color' => 'Cor não reconhecida: {value} — Permitidos: {allowed}.',
    'style.hex_digit_count' => "A cor hexadecimal deve ter 3 ou 6 dígitos hexadecimais (por ex. '#f80' ou '#ff8800'); obtido '{value}'.",
    'style.padding_token_int' => "O token de padding/margin tem de ser um inteiro; obtido: '{token}'.",
    'style.padding_count'     => 'Padding/margin precisa de 1, 2 ou 4 inteiros; obtido: {count}.',
    'style.bad_entry'         => "--style as entradas têm de ser 'chave=valor' ou 'elemento.prop=valor'; obtido: '{raw}'.",
    'style.unknown_prop'      => "Propriedade de estilo desconhecida: '{prop}'.",
    'process.spawn_failed'    => 'Falha ao iniciar o processo filho',
    'border.unknown'          => 'Estilo de borda desconhecido: {name}.',
    'log.unknown_level'       => 'Nível de registo desconhecido: {name}.',
    'log.encode_failed' => 'Falha ao codificar a entrada de log para json.',
    'log.bad_format' => 'String --format inválida: {format}.',
    'spinner.unknown_style'   => 'Estilo de spinner desconhecido: {name}.',
    'spinner.windows_unsupported' => 'O spin não pode executar comandos no Windows nativo: a interface de processos não é suportada — utilize o WSL em alternativa.',
    'spinner.output_truncated' => '…[truncated]',
    'format.unknown_theme'    => 'Tema desconhecido: {name}.',
    'io.read_failed' => 'Não foi possível ler o arquivo: {path}.',
    'io.write_failed' => 'Não foi possível escrever no arquivo: {path}.',
    'runtime.no_terminal' => '{command} precisa de um terminal interativo; nenhum terminal de controle disponível.',
    'runtime.bad_timeout' => "Valor de --timeout inválido '{value}' — esperado um número não negativo de segundos ou uma duração como 5s/250ms/1m30s (0 desativa o prazo).",
    'runtime.bad_number' => "Valor de {flag} inválido: '{value}' — espera-se um número inteiro não negativo.",
    'runtime.pcntl_missing' => 'ext-pcntl não está carregado: SIGINT/SIGTERM matarão este programa sem o mapeamento de saída 130/143.',
    'runtime.unknown_choice' => "Valor inválido para {option}: '{value}'. Permitidos: {allowed}.",
    'hints.choose' => '↑/↓ mover · espaço alterna (múltiplo) · enter selecionar · esc cancelar',
    'hints.filter' => 'digite para filtrar · ↑/↓ mover · tab alterna (múltiplo) · enter selecionar · esc cancelar',
    'hints.pager' => 'space/PgDn para baixo · PgUp/b para cima · g início · G fim · q sair',
    'format.template_unresolved' => 'Modelo: {count} espaço(s) reservado(s) expandidos para vazio: {names} — uma variável deve estar definida e permitida via --allow-env.',
    'runtime.empty_input' => 'A entrada não continha linhas; nada a fazer.',
    'runtime.empty_input_filter' => 'Nada para filtrar; a entrada não continha linhas.',
    'runtime.empty_input_pager' => 'Nada para paginar; a entrada não continha linhas.',
    'runtime.conflicting_flags' => 'As opções {first} e {second} não podem ser combinadas com valores diferentes.',
    'runtime.empty_selection' => 'Nenhum item selecionado — prima Espaço para marcar pelo menos um item antes de Enter.',
];
