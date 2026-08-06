<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../home.php");
    exit();
}

require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/../includes/admin_auth.php';

$usuarioId = (int) $_SESSION['usuario_id'];

// ── Só administradores podem excluir materiais do repositório. ──
exigirAdmin($conn, $usuarioId, '../estudante.php');

$materialId = (int) ($_GET['id'] ?? 0);

// Busca o material (agora sem filtro por dono, já que é um repositório central)
$stmt = $conn->prepare("SELECT nome_arquivo FROM materiais WHERE id = ?");
$stmt->bind_param('i', $materialId);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$material) {
    header("Location: ../admin_materiais.php?erro=" . urlencode('Material não encontrado.'));
    exit();
}

$caminhoFisico = __DIR__ . "/../uploads/materiais/{$material['nome_arquivo']}";

$stmtExcluir = $conn->prepare("DELETE FROM materiais WHERE id = ?");
$stmtExcluir->bind_param('i', $materialId);
$sucesso = $stmtExcluir->execute();
$stmtExcluir->close();

if ($sucesso && file_exists($caminhoFisico)) {
    unlink($caminhoFisico);
}

$mensagem = $sucesso ? 'Material excluído com sucesso.' : 'Não foi possível excluir o material.';
$tipo     = $sucesso ? 'sucesso' : 'erro';
header("Location: ../admin_materiais.php?{$tipo}=" . urlencode($mensagem));
exit();