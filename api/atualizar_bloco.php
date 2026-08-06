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

$acao         = (string)($corpo['acao'] ?? '');
$idCronograma = (int)($corpo['cronograma_id'] ?? 0);

if ($idCronograma <= 0 || !in_array($acao, ['editar', 'apagar', 'trocar'], true)) {
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

function horarioValido(string $h): bool
{
    return (bool) preg_match('/^\d{2}:\d{2}$/', $h);
}

try {
    if ($acao === 'apagar') {
        $dia    = (string)($corpo['dia'] ?? '');
        $inicio = (string)($corpo['inicio'] ?? '');
        if (!in_array($dia, DIAS_VALIDOS, true) || !horarioValido($inicio)) {
            throw new InvalidArgumentException('Dados inválidos.');
        }
        apagarBlocoPorSlot($conn, $idCronograma, $dia, $inicio);

    } elseif ($acao === 'editar') {
        $dia         = (string)($corpo['dia'] ?? '');
        $inicio      = (string)($corpo['inicio'] ?? '');
        $materia     = (string)($corpo['materia'] ?? '');
        $dificuldade = (string)($corpo['dificuldade'] ?? 'Médio');
        if (!in_array($dia, DIAS_VALIDOS, true) || !horarioValido($inicio)) {
            throw new InvalidArgumentException('Dados inválidos.');
        }
        editarBlocoPorSlot($conn, $idCronograma, $dia, $inicio, $materia, $dificuldade, $blocoMin);

    } elseif ($acao === 'trocar') {
        $dia1    = (string)($corpo['dia1'] ?? '');
        $inicio1 = (string)($corpo['inicio1'] ?? '');
        $dia2    = (string)($corpo['dia2'] ?? '');
        $inicio2 = (string)($corpo['inicio2'] ?? '');
        if (!in_array($dia1, DIAS_VALIDOS, true) || !in_array($dia2, DIAS_VALIDOS, true)
            || !horarioValido($inicio1) || !horarioValido($inicio2)
        ) {
            throw new InvalidArgumentException('Dados inválidos.');
        }
        trocarBlocosPorSlot($conn, $idCronograma, $dia1, $inicio1, $dia2, $inicio2, $blocoMin);
    }

    echo json_encode(['sucesso' => true]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => 'Não foi possível salvar a alteração no banco de dados.']);
}