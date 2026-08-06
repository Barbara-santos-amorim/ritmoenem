<?php
declare(strict_types=1); 

const DIAS_VALIDOS    = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
const NIVEIS_VALIDOS  = ['Fácil', 'Médio', 'Difícil'];

/* Cria um cronograma novo e todos os seus blocos numa única transação. */
function salvarCronograma(
    mysqli $conexao,       
    int    $usuarioId,     
    string $nome,          
    int    $horas,         
    int    $blocoMin,      
    string $horarioInicio, 
    string $horarioFim,    
    array  $dias,          
    array  $prioridades,   
    array  $blocos // lista de ['dia','inicio','fim','materia','dificuldade']
): int { 
    /* Mantém só os dias que realmente estão na lista de válidos, na ordem de DIAS_VALIDOS 
    intersect ignora dias inválidos que porventura venham do front-end; array_values reindexa o array */
    $dias = array_values(array_intersect(DIAS_VALIDOS, $dias));
    $diasJson = json_encode($dias, JSON_UNESCAPED_UNICODE); // Converte o array de dias pra uma string JSON, pra salvar na coluna `dias` (tipo JSON no banco)

    $prioridadesSanitizadas = []; //Sanitizada = valores 1,2,3 (Fácil,Médio,Difícil) para cada dia. Se não vier prioridade, assume 2 (Médio).
    foreach ($dias as $dia) { // Percorre cada dia já validado acima
        $p = (int)($prioridades[$dia] ?? 0); // Pega a prioridade enviada pra esse dia; se não veio, usa 0 como valor temporário
        $prioridadesSanitizadas[$dia] = ($p === 0) ? 2 : max(1, min(3, $p)); // Se a prioridade for 0 (não informada), vira 2 (Médio); senão, é "prensada/ponderada" para ficar entre 1 e 3
    }

    $prioridadesJson = json_encode($prioridadesSanitizadas, JSON_UNESCAPED_UNICODE); // Converte o array de prioridades sanitizadas em JSON, pra salvar na coluna `prioridades`

    $conexao->begin_transaction(); // Inicia uma transação: ou tudo abaixo é salvo, ou nada é (evita cronograma "pela metade")

    try {
        // Prepara o INSERT do cronograma em si (ainda sem os blocos, que são uma tabela separada)
        $stmt = $conexao->prepare(
            "INSERT INTO cronogramas (usuario_id, nome, horas, bloco_min, horario_inicio, horario_fim, dias, prioridades)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) { // prepare() retorna false se houver erro de sintaxe SQL, por exemplo
            throw new RuntimeException('Falha ao preparar a inserção do cronograma.'); // Aborta e cai no catch, que faz rollback
        }
        /* Associa as variáveis aos "?" da query, na ordem certa. A string 'isiissss' descreve o tipo de cada uma:
        i=inteiro, s=string — usuarioId(i), nome(s), horas(i), blocoMin(i), horarioInicio(s), horarioFim(s), diasJson(s), prioridadesJson(s)*/
        $stmt->bind_param(
            'isiissss',
            $usuarioId, $nome, $horas, $blocoMin, $horarioInicio, $horarioFim, $diasJson, $prioridadesJson
        );
        if (!$stmt->execute()) { // Executa o INSERT; retorna false se falhar (ex: violação de constraint)
            $stmt->close(); // Libera os recursos do statement antes de lançar a exceção
            throw new RuntimeException('Falha ao salvar o cronograma.');
        }
        $idCronograma = $conexao->insert_id; // Pega o ID gerado automaticamente (AUTO_INCREMENT) pro cronograma recém-inserido
        $stmt->close(); // Fecha o statement do INSERT do cronograma, já que não será mais usado

        // Prepara o INSERT dos blocos — vai ser reutilizado dentro do loop abaixo, um execute() por bloco
        $stmt2 = $conexao->prepare(
            "INSERT INTO cronograma_blocos (cronograma_id, dia, inicio, fim, materia, dificuldade)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt2) { // Mesma checagem de erro de preparo, agora pro segundo statement
            throw new RuntimeException('Falha ao preparar a inserção dos blocos.');
        }

        foreach ($blocos as $item) { // Percorre cada bloco recebido (um por horário/matéria do cronograma)
            $dia         = (string)($item['dia']         ?? ''); 
            $inicio      = (string)($item['inicio']       ?? ''); 
            $fim         = (string)($item['fim']          ?? ''); 
            $materia     = trim((string)($item['materia'] ?? '')); 
            $dificuldade = (string)($item['dificuldade']  ?? ''); 

            if (!in_array($dia, DIAS_VALIDOS, true)) continue; // Ignora blocos com dia inválido
            if ($materia === '' || !in_array($dificuldade, NIVEIS_VALIDOS, true)) continue; // Ignora blocos sem matéria ou com dificuldade inválida
            if (!preg_match('/^\d{2}:\d{2}$/', $inicio) || !preg_match('/^\d{2}:\d{2}$/', $fim)) continue; // Ignora blocos com horário fora do formato HH:MM

            // Associa os valores do bloco atual aos "?" da query preparada (reaproveitando o mesmo $stmt2)
            $stmt2->bind_param('isssss', $idCronograma, $dia, $inicio, $fim, $materia, $dificuldade);
            if (!$stmt2->execute()) { // Executa o INSERT desse bloco específico
                $stmt2->close();
                throw new RuntimeException('Falha ao salvar um bloco do cronograma.'); // Qualquer falha aborta a transação inteira
            }
        }
        $stmt2->close(); // Fecha o statement dos blocos depois que o loop termina

        $conexao->commit(); // Confirma a transação: cronograma + todos os blocos válidos são salvos de vez
        return $idCronograma; // Devolve o ID do cronograma criado pra quem chamou a função
    } catch (Throwable $e) { // Captura qualquer erro/exceção que tenha acontecido no bloco try acima
        $conexao->rollback(); // Desfaz tudo que foi feito na transação (cronograma e blocos já inseridos)
        throw new RuntimeException('Não foi possível salvar o cronograma no banco de dados.', 0, $e); // Relança um erro genérico, guardando o erro original em $e
    }
}

/*Confirma que o cronograma existe e pertence ao usuário logado. Retorna o bloco_min ou null. */
function obterBlocoMinDoCronograma(mysqli $conexao, int $idCronograma, int $usuarioId): ?int
{
    // Busca a duração do bloco (bloco_min) só se o cronograma pertencer a esse usuário —
    // essa condição "AND usuario_id = ?" é a proteção contra um usuário mexer no cronograma de outro
    $stmt = $conexao->prepare("SELECT bloco_min FROM cronogramas WHERE id = ? AND usuario_id = ?");
    if (!$stmt) return null; // Se o prepare falhar, retorna null (tratado como "não encontrado" por quem chama)
    $stmt->bind_param('ii', $idCronograma, $usuarioId); // Associa os dois inteiros aos "?" da query
    $stmt->execute(); // Executa a busca
    $linha = $stmt->get_result()->fetch_assoc(); // Pega a única linha de resultado (ou null se não achou nada)
    $stmt->close(); // Libera o statement
    return $linha ? (int) $linha['bloco_min'] : null; // Se achou linha, retorna bloco_min como int; senão, null
}

/*
 * Busca o cronograma completo (dados + dias/prioridades já decodificados de JSON),
 * confirmando que ele pertence ao usuário logado. Usado por visualizar.php.
 */
function buscarCronogramaCompleto(mysqli $conexao, int $idCronograma, int $usuarioId): ?array
{
    // Busca todos os campos relevantes do cronograma, de novo restringindo por usuario_id (proteção de acesso)
    $stmt = $conexao->prepare(
        "SELECT id, nome, horas, bloco_min, horario_inicio, horario_fim, dias, prioridades
         FROM cronogramas
         WHERE id = ? AND usuario_id = ?"
    );
    if (!$stmt) return null; // Erro de preparo -> trata como não encontrado
    $stmt->bind_param('ii', $idCronograma, $usuarioId); // Associa os IDs aos "?"
    $stmt->execute(); // Executa a busca
    $linha = $stmt->get_result()->fetch_assoc(); // Pega a linha encontrada (ou null)
    $stmt->close(); // Libera o statement

    if (!$linha) return null; // Se não achou o cronograma (ou não pertence a esse usuário), retorna null

    // As colunas `dias` e `prioridades` vêm do banco como texto JSON — aqui são decodificadas de volta pra array PHP.
    // O "?? []" / "?? {}" garante um array vazio como fallback se a coluna vier nula ou vazia.
    $linha['dias']        = json_decode($linha['dias'] ?? '[]', true) ?? [];
    $linha['prioridades'] = json_decode($linha['prioridades'] ?? '{}', true) ?? [];
    return $linha; // Retorna o array associativo completo, já com dias/prioridades decodificados
}

/*
 * Busca todos os blocos de um cronograma, indexados por "dia|inicio" (mesma chave
 * usada em porChave no fluxo de geração), para lookup O(1) na hora de montar a tabela.
 * Inclui 'concluido_em', usado pra desenhar o checkbox de conclusão já marcado ou não.
 */
function buscarBlocosIndexados(mysqli $conexao, int $idCronograma): array
{
    // Busca todos os blocos daquele cronograma, sem filtro de usuário aqui (quem chama já validou o dono via obterBlocoMinDoCronograma)
    $stmt = $conexao->prepare(
        "SELECT dia, inicio, fim, materia, dificuldade, concluido_em
         FROM cronograma_blocos
         WHERE cronograma_id = ?"
    );
    if (!$stmt) return []; // Erro de preparo -> retorna array vazio (nenhum bloco encontrado)
    $stmt->bind_param('i', $idCronograma); // Associa o ID do cronograma ao "?"
    $stmt->execute(); // Executa a busca
    $resultado = $stmt->get_result(); // Pega o conjunto de resultados (pode ter várias linhas)

    $porChave = []; // Array que vai guardar os blocos indexados por "dia|horário"
    while ($linha = $resultado->fetch_assoc()) { // Percorre cada linha retornada, uma de cada vez
        // O MySQL retorna a coluna TIME como "HH:MM:SS" (ex: "15:00:00"),
        // mas visualizar.php monta a chave de busca no formato "HH:MM" (ex: "15:00").
        // Sem essa normalização, a chave nunca bate e o slot sempre fica "vazio".
        $inicioNormalizado = substr($linha['inicio'], 0, 5); // Corta os segundos, ficando ("HH:MM")
        $porChave[$linha['dia'] . '|' . $inicioNormalizado] = $linha; // Guarda a linha inteira usando "dia|HH:MM" como chave do array
    }
    $stmt->close(); // Libera o statement
    return $porChave; // Retorna o array indexado, pronto pra consulta rápida por chave
}

function calcularFimBloco(string $inicio, int $blocoMin): string
{
    $dt = DateTime::createFromFormat('H:i', $inicio); // Converte a string "HH:MM" num objeto DateTime
    $dt->modify("+{$blocoMin} minutes"); // Soma a duração do bloco (em minutos) a esse horário
    return $dt->format('H:i'); // Devolve o novo horário já formatado de volta como "HH:MM"
}

/* Busca um bloco pelo slot (dia + horário de início). */
function buscarBlocoPorSlot(mysqli $conexao, int $idCronograma, string $dia, string $inicio): ?array
{
    // Busca o bloco que ocupa exatamente esse dia+horário dentro desse cronograma
    // (a constraint uq_bloco_slot no banco garante que só existe, no máximo, 1 linha assim)
    $stmt = $conexao->prepare(
        "SELECT id, materia, dificuldade FROM cronograma_blocos
         WHERE cronograma_id = ? AND dia = ? AND inicio = ?"
    );
    if (!$stmt) return null; 
    $stmt->bind_param('iss', $idCronograma, $dia, $inicio); 
    $stmt->execute(); 
    $linha = $stmt->get_result()->fetch_assoc(); 
    $stmt->close();
    return $linha ?: null; 
}

/* Apaga o bloco de um slot, se existir. */
function apagarBlocoPorSlot(mysqli $conexao, int $idCronograma, string $dia, string $inicio): void
{
    $conexao->begin_transaction(); // Abre transação (aqui só tem 1 DELETE, mas mantém o padrão do resto do arquivo)
    try {
        // Apaga a linha correspondente ao slot, se existir; se não existir, o DELETE simplesmente afeta 0 linhas (sem erro)
        $stmt = $conexao->prepare(
            "DELETE FROM cronograma_blocos WHERE cronograma_id = ? AND dia = ? AND inicio = ?"
        );
        if (!$stmt) throw new RuntimeException('Falha ao preparar a exclusão do bloco.'); // Erro de preparo aborta a operação
        $stmt->bind_param('iss', $idCronograma, $dia, $inicio); 
        if (!$stmt->execute()) { // Executa o DELETE
            $stmt->close();
            throw new RuntimeException('Falha ao apagar o bloco.'); // Falha de execução também aborta a operação
        }
        $stmt->close(); // Libera o statement após sucesso
        $conexao->commit(); // Confirma a exclusão de forma definitiva
    } catch (Throwable $e) { // Captura qualquer erro/exceção do bloco try
        $conexao->rollback(); // Desfaz a transação em caso de erro
        // Se já for um RuntimeException, relança do jeito que está. Se não, embrulha o erro original numa RuntimeException genérica
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Falha ao apagar o bloco.', 0, $e);
    }
}

/*
 Atualiza (ou cria/remove) o bloco de um slot com um novo texto de matéria e dificuldade.
 Texto vazio remove o bloco. Slot sem bloco existente e texto não vazio cria um novo.
 $dificuldade deve ser um dos NIVEIS_VALIDOS; se vier algo diferente, cai para 'Médio'.
 */
function editarBlocoPorSlot(
    mysqli $conexao,      
    int    $idCronograma, 
    string $dia,          
    string $inicio,       // Horário de início do slot a editar
    string $materia,      // Novo texto de matéria (vazio = remover o bloco)
    string $dificuldade,  // Nova dificuldade
    int    $blocoMin      // Duração do bloco, usada só se for necessário criar um bloco novo (pra calcular o horário de fim)
): void {
    $materia = trim($materia); // Remove espaços em branco nas pontas do texto da matéria 

    if (!in_array($dificuldade, NIVEIS_VALIDOS, true)) { // Se a dificuldade recebida não é uma das válida usa "MÉDIO" como padrão
        $dificuldade = 'Médio'; 
    }

    $conexao->begin_transaction(); // Abre a transação: leitura + escrita precisam ser consistentes entre si
    try {
        $existente = buscarBlocoPorSlot($conexao, $idCronograma, $dia, $inicio); // Verifica se já existe um bloco nesse slot

        /*CASO 1: O usuário quer apagar o bloco -> apenas deleta se existir algo*/
        if ($materia === '') { 
            if ($existente) { 
                $stmt = $conexao->prepare("DELETE FROM cronograma_blocos WHERE id = ?"); // Apaga pelo ID já conhecido do bloco existente
                if (!$stmt) throw new RuntimeException('Falha ao preparar a exclusão do bloco.');
                $stmt->bind_param('i', $existente['id']); // Associa o ID do bloco ao "?"
                if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao remover o bloco.'); } // Executa o DELETE
                $stmt->close(); // Libera o statement
            }
            // Se não existia nada no slot e a matéria já veio vazia, não há nada a fazer (nem erro, nem ação)
        /* CASO 2: já existe um bloco nesse slot -> apenas atualiza matéria/dificuldade */
        } elseif ($existente) { 
            $stmt = $conexao->prepare("UPDATE cronograma_blocos SET materia = ?, dificuldade = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Falha ao preparar a atualização do bloco.');
            $stmt->bind_param('ssi', $materia, $dificuldade, $existente['id']); // Associa os novos valores + o ID do bloco a atualizar
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao atualizar o bloco.'); } // Executa o UPDATE
            $stmt->close(); // Libera o statement
        /* CASO 3: slot estava vazio e a matéria não é vazia -> cria um bloco novo*/     
        } else {
            $fim = calcularFimBloco($inicio, $blocoMin); // Calcula o horário de término a partir do início + duração do bloco
            $stmt = $conexao->prepare(
                "INSERT INTO cronograma_blocos (cronograma_id, dia, inicio, fim, materia, dificuldade)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) throw new RuntimeException('Falha ao preparar a criação do bloco.');
            $stmt->bind_param('isssss', $idCronograma, $dia, $inicio, $fim, $materia, $dificuldade); // Associa todos os valores do novo bloco
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao criar o bloco.'); } // Executa o INSERT
            $stmt->close(); // Libera o statement
        }

        $conexao->commit(); // Confirma a operação (delete, update ou insert, dependendo do caso)
    } catch (Throwable $e) { // Captura qualquer erro ocorrido acima
        $conexao->rollback(); // Desfaz qualquer alteração parcial
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Falha ao editar o bloco.', 0, $e); // Relança o erro (preservando o tipo, se já for RuntimeException)
    }
}

/** Troca/move o conteúdo entre dois slots — usado pelo arrastar-e-soltar.
* - Se os dois slots têm bloco: troca matéria/dificuldade entre eles (2 UPDATEs). 
* - Se só um tem bloco: move esse bloco para o slot vazio (1 UPDATE de dia/inicio/fim).
* - Se nenhum tem bloco: não faz nada.
 */
function trocarBlocosPorSlot(
    mysqli $conexao,      
    int    $idCronograma, // Cronograma ao qual os dois slots pertencem
    string $dia1,         // Dia do primeiro slot
    string $inicio1,      // Horário de início do primeiro slot
    string $dia2,         // Dia do segundo slot
    string $inicio2,      // Horário de início do segundo slot
    int    $blocoMin      // Duração do bloco, usada se for preciso mover (recalcular horário de fim)
): void {
    $conexao->begin_transaction(); // Abre transação: leituras dos dois slots + escrita precisam ser atômicas
    try {
        $bloco1 = buscarBlocoPorSlot($conexao, $idCronograma, $dia1, $inicio1); // Busca o que existe (se existir) no primeiro slot
        $bloco2 = buscarBlocoPorSlot($conexao, $idCronograma, $dia2, $inicio2); // Busca o que existe (se existir) no segundo slot

        if ($bloco1 && $bloco2) { // CASO A: os dois slots já têm bloco -> troca o conteúdo entre eles
            $stmt = $conexao->prepare("UPDATE cronograma_blocos SET materia = ?, dificuldade = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Falha ao preparar a troca dos blocos.');

            // Primeiro UPDATE: o bloco1 passa a ter a matéria/dificuldade que era do bloco2
            $stmt->bind_param('ssi', $bloco2['materia'], $bloco2['dificuldade'], $bloco1['id']);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao trocar os blocos.'); }

            // Segundo UPDATE: o bloco2 passa a ter a matéria/dificuldade que era do bloco1
            $stmt->bind_param('ssi', $bloco1['materia'], $bloco1['dificuldade'], $bloco2['id']);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao trocar os blocos.'); }
            $stmt->close(); // Libera o statement (reaproveitado nos dois UPDATEs acima)

        } elseif ($bloco1 && !$bloco2) { // CASO B: só o primeiro slot tem bloco -> move ele pro segundo slot (que está vazio)
            $fim2 = calcularFimBloco($inicio2, $blocoMin); // Recalcula o horário de fim baseado no novo horário de início
            $stmt = $conexao->prepare("UPDATE cronograma_blocos SET dia = ?, inicio = ?, fim = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Falha ao preparar a movimentação do bloco.');
            // Atualiza o bloco1 pra assumir a posição (dia/inicio/fim) do slot2
            $stmt->bind_param('sssi', $dia2, $inicio2, $fim2, $bloco1['id']);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao mover o bloco.'); }
            $stmt->close(); // Libera o statement

        } elseif (!$bloco1 && $bloco2) { // CASO C: só o segundo slot tem bloco -> move ele pro primeiro slot (espelho do caso B)
            $fim1 = calcularFimBloco($inicio1, $blocoMin); // Recalcula o horário de fim baseado no novo horário de início
            $stmt = $conexao->prepare("UPDATE cronograma_blocos SET dia = ?, inicio = ?, fim = ? WHERE id = ?");
            if (!$stmt) throw new RuntimeException('Falha ao preparar a movimentação do bloco.');
            // Atualiza o bloco2 pra assumir a posição (dia/inicio/fim) do slot1
            $stmt->bind_param('sssi', $dia1, $inicio1, $fim1, $bloco2['id']);
            if (!$stmt->execute()) { $stmt->close(); throw new RuntimeException('Falha ao mover o bloco.'); }
            $stmt->close(); // Libera o statement
        }
        /*Se nenhum dos dois slots tem bloco, não há nada a trocar. (Nenhum código aqui: os três "if/elseif" acima cobrem os casos com pelo menos 1 bloco. 
        Se ambos forem null, nenhum ramo executa e a transação só faz commit sem alterar nada.)*/

        $conexao->commit(); // Confirma a(s) alteração(ões) feita(s) acima, seja de troca/movimentação ou nada
    } catch (Throwable $e) { // Captura qualquer erro ocorrido na leitura/escrita acima
        $conexao->rollback(); // Desfaz qualquer alteração parcial
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Falha ao trocar os blocos.', 0, $e); // Relança o erro
    }
}

/**
 * Marca (ou desmarca) um bloco como concluído, localizando-o pelo slot (dia + horário),
 * na mesma convenção usada por apagarBlocoPorSlot/editarBlocoPorSlot/trocarBlocosPorSlot.
 * Não lança erro se o slot não tiver bloco algum (0 linhas afetadas é um resultado válido
 * — por exemplo, se o usuário conseguir clicar duas vezes rápido antes do DOM atualizar).
 */
function marcarBlocoConcluido(
    mysqli $conexao,      
    int    $idCronograma, 
    string $dia,          
    string $inicio,       
    bool   $concluir      
): void {
    // Atualiza a coluna concluido_em do bloco que corresponde a esse dia+horário, dentro desse cronograma
    $stmt = $conexao->prepare(
        "UPDATE cronograma_blocos SET concluido_em = ? WHERE cronograma_id = ? AND dia = ? AND inicio = ?"
    );
    if (!$stmt) { // Se o preparo falhar, não há como continuar
        throw new RuntimeException('Falha ao preparar a atualização de conclusão do bloco.');
    }
    $valor = $concluir ? date('Y-m-d H:i:s') : null; // Se for pra concluir, usa a data/hora atual; se for desmarcar, usa null
    $stmt->bind_param('siss', $valor, $idCronograma, $dia, $inicio); // Associa o valor (data ou null) + os 3 critérios do WHERE
    if (!$stmt->execute()) { // Executa o UPDATE
        $stmt->close();
        throw new RuntimeException('Falha ao marcar o bloco como concluído.'); // Só lança erro se a query em si falhar (não se 0 linhas forem afetadas)
    }
    $stmt->close(); // Libera o statement
}