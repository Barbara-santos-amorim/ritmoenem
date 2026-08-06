<?php
declare(strict_types=1);

/**
 * Funções para gerar o cronograma (nenhuma interage com o banco).
 * Isoladas aqui para poderem ser chamadas por api/gerar_cronograma.php sem depender de includes/db_cronograma.php.
 */

function distribuirPorPeso(int $total, array $pesos): array
{
    $somaPesos = array_sum($pesos);
    if ($somaPesos <= 0 || $total <= 0) {
        return array_map(fn() => 0, $pesos);
    }

    $base  = [];
    $resto = [];
    $usado = 0;

    foreach ($pesos as $k => $p) {
        $exato    = $total * $p / $somaPesos;
        $base[$k] = (int) floor($exato);
        $resto[$k] = $exato - $base[$k];
        $usado   += $base[$k];
    }

    $sobra = $total - $usado;
    arsort($resto);

    foreach (array_keys($resto) as $k) {
        if ($sobra <= 0) break;
        $base[$k]++;
        $sobra--;
    }

    return $base;
}

function blocosDisponiveisNoDia(DateTimeInterface $inicio, DateTimeInterface $fim, int $blocoMin): int
{
    $minutos = ($fim->getTimestamp() - $inicio->getTimestamp()) / 60;
    return (int) floor($minutos / $blocoMin);
}

function gerarFilaRevezamento(array $contadores): array
{
    $fila   = [];
    $ultima = null;

    while (array_sum($contadores) > 0) {
        arsort($contadores);
        $escolhida = null;

        foreach ($contadores as $k => $qtd) {
            if ($qtd > 0 && $k !== $ultima) {
                $escolhida = $k;
                break;
            }
        }

        if ($escolhida === null) {
            foreach ($contadores as $k => $qtd) {
                if ($qtd > 0) {
                    $escolhida = $k;
                    break;
                }
            }
        }

        if ($escolhida === null) break;

        $fila[]               = $escolhida;
        $contadores[$escolhida]--;
        $ultima               = $escolhida;
    }

    return $fila;
}

function formatarHora(DateTimeInterface $dt): string
{
    return $dt->format('H:i');
}

/**
 * Ordena as matérias alocadas em um dia conforme a prioridade do dia.
 * Prioridade 3 (alta)  -> matérias mais difíceis primeiro.
 * Prioridade 1 (baixa) -> matérias mais fáceis primeiro.
 * Prioridade 2 (média) -> mantém a ordem do revezamento (sem alteração).
 */
function ordenarPorPrioridade(array $indicesMaterias, array $materias, int $prioridade): array
{
    if ($prioridade === 2 || count($indicesMaterias) <= 1) {
        return $indicesMaterias;
    }

    $pesoNivel = ['Difícil' => 3, 'Médio' => 2, 'Fácil' => 1];

    usort($indicesMaterias, function ($a, $b) use ($materias, $pesoNivel, $prioridade) {
        $pesoA = $pesoNivel[$materias[$a]['nivel']] ?? 2;
        $pesoB = $pesoNivel[$materias[$b]['nivel']] ?? 2;

        return $prioridade === 3 ? ($pesoB <=> $pesoA) : ($pesoA <=> $pesoB);
    });

    return $indicesMaterias;
}
//Sugestões de ajustes de horário para o usuário, caso o cronograma não caiba na janela de tempo escolhida.
function gerarSugestoesHorarios(int $horas, int $blocoMin, array $diasDisponiveis): array
{
    $sugestoes   = [];
    $totalBlocos = (int) round(($horas * 60) / $blocoMin);

    $blocosPor1h = (int) floor(60 / $blocoMin);
    $novasHoras1 = ($totalBlocos * $blocoMin) / 60;
    if ($novasHoras1 <= 10) {
        $sugestoes[] = [
            'titulo'    => '📅 Aumentar janela diária',
            'descricao' => "Estude mais 1 hora por dia. Isso adicionaria ~{$blocosPor1h} bloco(s) ao seu cronograma.",
            'tipo'      => 'horario',
        ];
    }

    $diasAtuais    = count($diasDisponiveis);
    $diasSugeridos = $diasAtuais + 1;
    if ($diasSugeridos <= 7) {
        $sugestoes[] = [
            'titulo'    => '📆 Adicionar mais um dia',
            'descricao' => "Estudar {$diasSugeridos} dias/semana em vez de {$diasAtuais} dias. "
                         . "Suas horas serão redistribuídas de forma equilibrada.",
            'tipo'      => 'dias',
        ];
    }

    $blocoMaior = $blocoMin + 15;
    if ($blocoMaior <= 120) {
        $sugestoes[] = [
            'titulo'    => '⏱️ Blocos mais longos',
            'descricao' => "Use blocos de {$blocoMaior} min em vez de {$blocoMin} min. "
                         . "Menos interrupções, mais foco profundo.",
            'tipo'      => 'bloco',
        ];
    }

    $horasReduzidas = max(1, $horas - 2);
    $sugestoes[] = [
        'titulo'    => '✨ Estudar menos horas',
        'descricao' => "Considere estudar {$horasReduzidas}h por dia em vez de {$horas}h. "
                     . "Mais qualidade, menos sobrecarga.",
        'tipo'      => 'horas',
    ];

    $diasIdeal  = 5;
    $horasIdeal = 3;
    $sugestoes[] = [
        'titulo'    => '⏰ Combinação recomendada',
        'descricao' => "A fórmula ideal: {$diasIdeal} dias/semana × {$horasIdeal}h/dia "
                     . "com blocos de 45–60 min.",
        'tipo'      => 'ideal',
    ];

    return $sugestoes;
}

/**
 * Pega os dados que vieram do formulário (POST) e confere se está tudo certo,
 * arrumando o que precisar. Se tiver algo errado, guarda numa lista de erros.
 * Não mexe no banco de dados, só organiza as informações.
 * Usada só no arquivo api/gerar_cronograma.php.
 */
function validarEntradaCronograma(array $input): array
{
    $erros = [];

    $nome          = trim((string)($input['nome'] ?? ''));  // Nome do cronograma 
    $horas         = (int)($input['horas'] ?? 0); 
    $blocoMin      = (int)($input['bloco_min'] ?? 0);
    $horarioInicio = (string)($input['horario_inicio'] ?? '');
    $horarioFim    = (string)($input['horario_fim'] ?? '');
    $dias          = (array)($input['dias'] ?? []);
    $prioridadesIn = (array)($input['prioridades'] ?? []);
    $materiasInput = (array)($input['materias'] ?? []);
    $dificuldadesIn = (array)($input['dificuldades'] ?? []);

    if ($nome === '') {
        $erros[] = 'Informe um nome para o cronograma.';
    }
    if ($horas < 1 || $horas > 12) {
        $erros[] = 'O total de horas de estudo por dia deve ser entre 1 e 12.';
    }
    if ($blocoMin < 15 || $blocoMin > 240 || $blocoMin % 15 !== 0) {
        $erros[] = 'O tamanho do bloco deve ser entre 15 e 240 min e múltiplo de 15.';
    }
    if (empty($dias)) {
        $erros[] = 'Selecione pelo menos um dia.';
    }

    $diasValidos = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
    $dias        = array_values(array_intersect($diasValidos, $dias));
    if (empty($dias) && !in_array('Selecione pelo menos um dia.', $erros, true)) {
        $erros[] = 'Selecione pelo menos um dia válido.';
    }

    $dtInicio = DateTime::createFromFormat('H:i', $horarioInicio) ?: null;
    $dtFim    = DateTime::createFromFormat('H:i', $horarioFim) ?: null;
    if (!$dtInicio || !$dtFim) {
        $erros[] = 'Horário inválido.';
    } elseif ($dtInicio >= $dtFim) {
        $erros[] = 'O horário de início deve ser anterior ao horário de fim.';
    }

    $prioridades = [];
    foreach ($dias as $d) {
        $p = (int)($prioridadesIn[$d] ?? 0);
        $prioridades[$d] = ($p === 0) ? 2 : max(1, min(3, $p));
    }

    $materias = [];
    foreach ($materiasInput as $i => $m) {
        $m = trim((string)$m);
        if ($m === '') continue;

        $dif = $dificuldadesIn[$i] ?? null;
        if (!in_array($dif, ['Fácil', 'Médio', 'Difícil'], true)) {
            $erros[] = 'Selecione uma dificuldade válida para: ' . htmlspecialchars($m);
            continue;
        }
        $materias[] = ['nome' => $m, 'nivel' => $dif];
    }
    if (empty($materias) && !array_filter($erros, fn($e) => str_contains($e, 'dificuldade'))) {
        $erros[] = 'Adicione pelo menos uma matéria.';
    }

    return [
        'erros'         => $erros,
        'nome'          => $nome,
        'horas'         => $horas,
        'blocoMin'      => $blocoMin,
        'horarioInicio' => $horarioInicio,
        'horarioFim'    => $horarioFim,
        'dias'          => $dias,
        'prioridades'   => $prioridades,
        'materias'      => $materias,
        'dtInicio'      => $dtInicio,
        'dtFim'         => $dtFim,
    ];
}

/* Gera o cronograma em memória a partir de uma entrada já validada. 
Puramente cálculo — não grava nada. */
function gerarCronogramaEmMemoria(array $dadosValidados): array
{
    $horas       = $dadosValidados['horas'];
    $blocoMin    = $dadosValidados['blocoMin'];
    $dias        = $dadosValidados['dias'];
    $prioridades = $dadosValidados['prioridades'];
    $materias    = $dadosValidados['materias'];
    $dtInicio    = $dadosValidados['dtInicio'];
    $dtFim       = $dadosValidados['dtFim'];

    $diasValidos = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];

    $blocosPorDiaMax = 0;
    if ($dtInicio && $dtFim && $dtInicio < $dtFim) {
        $blocosPorDiaMax = blocosDisponiveisNoDia($dtInicio, $dtFim, $blocoMin);
    }

    $blocosPorDiaAlvo = ($blocoMin > 0) ? (int) round(($horas * 60) / $blocoMin) : 0;
    $totalBlocos      = $blocosPorDiaAlvo * count($dias);

    if ($blocosPorDiaAlvo > $blocosPorDiaMax) {
        $horasMax       = ($blocosPorDiaMax * $blocoMin) / 60;
        $erroCapacidade = "Com blocos de {$blocoMin} min, sua janela de horário comporta no máximo {$horasMax}h/dia. Reduza o total de horas diárias, aumente o bloco ou amplie a janela de horário.";
        return [
            'cronograma'       => [],
            'sugestoesHorario' => gerarSugestoesHorarios($horas, $blocoMin, $dias),
            'erroCapacidade'   => $erroCapacidade,
        ];
    }

    $pesoDificuldade = ['Fácil' => 1, 'Médio' => 2, 'Difícil' => 3];
    $pesoMateria = [];
    foreach ($materias as $k => $m) {
        $pesoMateria[$k] = $pesoDificuldade[$m['nivel']];
    }
    $blocosPorMateria = distribuirPorPeso($totalBlocos, $pesoMateria);

    $blocosPorDia = [];
    foreach ($dias as $d) {
        $blocosPorDia[$d] = $blocosPorDiaAlvo;
    }

    $fila = gerarFilaRevezamento($blocosPorMateria);

    $cronograma = [];
    foreach ($diasValidos as $d) $cronograma[$d] = [];

    $indiceFila = 0;
    foreach ($dias as $d) {
        $limite = $blocosPorDia[$d];

        $indicesDoDia = [];
        for ($b = 0; $b < $limite && $indiceFila < count($fila); $b++) {
            $indicesDoDia[] = $fila[$indiceFila++];
        }

        $indicesDoDia = ordenarPorPrioridade($indicesDoDia, $materias, $prioridades[$d] ?? 2);

        $cursor = clone $dtInicio;
        foreach ($indicesDoDia as $indiceMateria) {
            $horaInicio = clone $cursor;
            $cursor->modify("+{$blocoMin} minutes");

            $cronograma[$d][] = [
                'inicio' => formatarHora($horaInicio),
                'fim'    => formatarHora($cursor),
                'nome'   => $materias[$indiceMateria]['nome'],
                'nivel'  => $materias[$indiceMateria]['nivel'],
            ];
        }
    }

    return [
        'cronograma'       => $cronograma,
        'sugestoesHorario' => [],
        'erroCapacidade'   => null,
    ];
}