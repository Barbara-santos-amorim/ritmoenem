<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/db_cronograma.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'erro' => 'Não autenticado.']);
    exit();
}
$usuarioId = (int) $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['sucesso' => false, 'erro' => 'Método não permitido.']);
    exit();
}

$corpo = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($corpo)) {
    http_response_code(400);
    echo json_encode(['sucesso' => false, 'erro' => 'Payload inválido.']);
    exit();
}

$idCronograma = (int)($corpo['cronograma_id'] ?? 0);
$dia          = (string)($corpo['dia'] ?? '');
$inicio       = (string)($corpo['inicio'] ?? '');
$concluir     = (bool)($corpo['concluir'] ?? false);

// Valida se o ID é maior que zero, se o dia é permitido e se o horário de início está no formato 00:00
if (
    $idCronograma <= 0
    || !in_array($dia, DIAS_VALIDOS, true)
    || !preg_match('/^\d{2}:\d{2}$/', $inicio)
) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => 'Requisição inválida.']);
    exit();
}

require_once __DIR__ . '/../conexao.php';

// Garante que o cronograma pertence ao usuário logado antes de qualquer alteração
$blocoMin = obterBlocoMinDoCronograma($conn, $idCronograma, $usuarioId);
if ($blocoMin === null) {
    http_response_code(403);
    echo json_encode(['sucesso' => false, 'erro' => 'Cronograma não encontrado.']);
    exit();
}
// Tenta atualizar o status do bloco no banco; se falhar, retorna erro 500 com mensagem amigável
try {
    marcarBlocoConcluido($conn, $idCronograma, $dia, $inicio, $concluir);
    echo json_encode(['sucesso' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível salvar a alteração no banco de dados.']);
}