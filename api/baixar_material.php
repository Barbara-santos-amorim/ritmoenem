<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../home.php");
    exit();
}

require_once __DIR__ . '/../conexao.php';

$materialId = (int) ($_GET['id'] ?? 0);

// Os materiais são um repositório central: qualquer usuário logado pode baixar qualquer material disponibilizado. 
$stmt = $conn->prepare(
    "SELECT nome_original, nome_arquivo, tipo_arquivo
     FROM materiais
     WHERE id = ?"
);
$stmt->bind_param('i', $materialId);
$stmt->execute();
$material = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$material) {
    http_response_code(404);
    exit('Material não encontrado.');
}

$caminhoFisico = __DIR__ . "/../uploads/materiais/{$material['nome_arquivo']}";

if (!file_exists($caminhoFisico)) {
    http_response_code(404);
    exit('Arquivo não encontrado no servidor.');
}

// ── Envia o arquivo como download, preservando o nome original que foi enviado ──
header('Content-Description: File Transfer');
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($material['nome_original']) . '"');
header('Content-Length: ' . filesize($caminhoFisico));
header('Cache-Control: no-cache, must-revalidate');

readfile($caminhoFisico);
exit();