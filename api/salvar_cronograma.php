<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_cronograma.php'; // Importa função salvarCronograma() e constantes DIAS_VALIDOS, PRIORIDADES_VALIDAS

//Valida se o usuário está autenticado
if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado.']);
    exit();
}
$usuarioId = (int) $_SESSION['usuario_id'];

//Valida se o método HTTP é POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido.']);
    exit();
}
// Lê o corpo da requisição e decodifica o JSON
$corpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Payload inválido.']);
    exit();
}

// Extrai os dados do corpo da requisição, garantindo tipos corretos
$nome          = trim((string)($corpo['nome'] ?? ''));
$horas         = (int)($corpo['horas'] ?? 0);
$blocoMin      = (int)($corpo['bloco_min'] ?? 0);
$horarioInicio = (string)($corpo['horario_inicio'] ?? '');
$horarioFim    = (string)($corpo['horario_fim'] ?? '');
$dias          = array_values(array_intersect(DIAS_VALIDOS, (array)($corpo['dias'] ?? [])));
$prioridades   = (array)($corpo['prioridades'] ?? []);
$blocos        = (array)($corpo['blocos'] ?? []);

// $horas é o total de horas de estudo POR DIA (mesma coisa que o formulário  em cronograma.php pede e valida) — não o total semanal. Faixa alinhada com validarEntradaCronograma(): 1 a 12h/dia.
if ($nome === '' || $horas < 1 || $horas > 12
    || $blocoMin < 15 || $blocoMin > 240 || $blocoMin % 15 !== 0
    || empty($dias)
    || !preg_match('/^\d{2}:\d{2}$/', $horarioInicio)
    || !preg_match('/^\d{2}:\d{2}$/', $horarioFim)
) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => 'Dados do cronograma inválidos.']);
    exit();
}

// Garante que a estrutura do cronograma possua blocos de estudo criados
if (empty($blocos)) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => 'Não há blocos para salvar.']);
    exit();
}

require_once __DIR__ . '/../conexao.php'; // Conexão com o banco de dados

try { //Envia os dados para a função salvarCronograma() que grava no banco de dados. Se houver erro, retorna 500 com mensagem amigável.
    $idCronograma = salvarCronograma(
        $conn,
        $usuarioId,
        $nome,
        $horas,
        $blocoMin,
        $horarioInicio,
        $horarioFim,
        $dias,
        $prioridades,
        $blocos
    );
    echo json_encode(['sucesso' => true, 'cronograma_id' => $idCronograma]);
} catch (Throwable $e) { // Captura qualquer exceção ou erro que ocorra durante a gravação no banco de dados
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível salvar o cronograma no banco de dados.']);
}