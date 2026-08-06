<?php

declare(strict_types=1);

function renderizarTabelaCronograma(
    array $dias,
    array $linhas,
    array $porChave,
    array $labelDias,
    string $nome,
    int $horas,
    int $blocoMin,
    string $horarioInicio,
    string $horarioFim,
    string $motivacao,
    array $erros = [],
    array $sugestoesHorario = []
): string {
    $saidaCronograma = '';

    if (empty($erros)) {
        $saidaCronograma .= "<p class='semana-msg'>" . htmlspecialchars($motivacao) . "</p>";
        $saidaCronograma .= "<h2>Cronograma: " . htmlspecialchars($nome) . "</h2>";
        $saidaCronograma .= "<p>Total semanal: {$horas}h &nbsp;|&nbsp; Bloco: {$blocoMin} min"
            . " &nbsp;|&nbsp; Horário: "
            . htmlspecialchars($horarioInicio) . " – " . htmlspecialchars($horarioFim) . "</p>";

        $saidaCronograma .= "<table class='tabela-cronograma'><thead><tr><th>Horário</th>";
        foreach ($dias as $d) {
            $saidaCronograma .= "<th>" . ($labelDias[$d] ?? $d) . "</th>";
        }
        $saidaCronograma .= "</tr></thead><tbody>";

        foreach ($linhas as $linha) {
            $saidaCronograma .= "<tr><th>{$linha['inicio']}–{$linha['fim']}</th>";

            foreach ($dias as $d) {
                $chave    = $d . '|' . $linha['inicio'];
                $idCelula = $d . '_' . str_replace(':', '', $linha['inicio']);

                if (isset($porChave[$chave])) {
                    $slot = $porChave[$chave];

                    /* Aceita as duas nomenclaturas possíveis:
                      - fluxo de geração em memória (gerar_cronograma.php): 'nome' / 'nivel'
                      - fluxo de banco de dados (visualizar.php via buscarBlocosIndexados): 'materia' / 'dificuldade' */
                    $nomeBloco = (string)($slot['nome']  ?? $slot['materia']     ?? '');
                    $nivel     = (string)($slot['nivel'] ?? $slot['dificuldade'] ?? '');

                    $classe = match ($nivel) {
                        'Difícil' => 'dificuldade-dificil',
                        'Médio'   => 'dificuldade-medio',
                        'Fácil'   => 'dificuldade-facil',
                        default   => '',
                    };
                    //EDITAR BLOCO E APAGAR BLOCO
                    $saidaCronograma .= "<td class='celula-cronograma {$classe}' data-id-celula='{$idCelula}' data-dia='{$d}' data-horario='{$linha['inicio']}' draggable='true'>";
                    $saidaCronograma .= "<div class='acoes-celula'>";
                    $saidaCronograma .= "<button class='btn-acao btn-editar' title='Editar bloco'>✏️</button>";
                    $saidaCronograma .= "<button class='btn-acao btn-apagar' title='Apagar bloco'>✕</button>";
                    $saidaCronograma .= "</div>";
                    $saidaCronograma .= "<div class='conteudo-celula'>"
                        . htmlspecialchars($nomeBloco)
                        . "</div>";
                    $saidaCronograma .= "</td>";
                } else {
                    $saidaCronograma .= "<td class='vazia celula-cronograma' data-id-celula='{$idCelula}' data-dia='{$d}' data-horario='{$linha['inicio']}' draggable='true'>";
                    $saidaCronograma .= "<div class='acoes-celula'>";
                    $saidaCronograma .= "<button class='btn-acao btn-editar' title='Editar bloco'>✏️</button>";
                    $saidaCronograma .= "<button class='btn-acao btn-apagar' title='Apagar bloco'>✕</button>";
                    $saidaCronograma .= "</div>";
                    $saidaCronograma .= "<div class='conteudo-celula'></div>";
                    $saidaCronograma .= "</td>";
                }
            }

            $saidaCronograma .= "</tr>";
        }

        $saidaCronograma .= "</tbody></table>";
    } else {
        foreach ($erros as $e) {
            $saidaCronograma .= "<p class='erro'>" . htmlspecialchars((string)$e) . "</p>";
        }

        if (!empty($sugestoesHorario)) {
            $saidaCronograma .= "<div class='container-sugestoes'>";
            $saidaCronograma .= "<h3 class='titulo-sugestoes'>💡 Sugestões para o seu Cronograma</h3>";
            foreach ($sugestoesHorario as $sug) {
                $saidaCronograma .= "<div class='card-sugestao'>";
                $saidaCronograma .= "<h4>" . htmlspecialchars((string)($sug['titulo'] ?? '')) . "</h4>";
                $saidaCronograma .= "<p>" . htmlspecialchars((string)($sug['descricao'] ?? '')) . "</p>";
                $saidaCronograma .= "</div>";
            }
            $saidaCronograma .= "</div>";
        }
    }

    return $saidaCronograma;
}