<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../home.php");
    exit();
}

require_once __DIR__ . '/../conexao.php';

$usuarioId = (int) $_SESSION['usuario_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../estudante.php?erro=" . urlencode('Requisição inválida.') . "#aba-anotacoes");
    exit();
}

$linkId = (int) ($_POST['id'] ?? 0);

// O "AND usuario_id = ?" garante que ninguém consiga excluir link de outra pessoa
// mesmo que tente forçar um id diferente no formulário.
$stmt = $conn->prepare("DELETE FROM anotacoes_links WHERE id = ? AND usuario_id = ?");
$stmt->bind_param('ii', $linkId, $usuarioId);
$sucesso = $stmt->execute() && $stmt->affected_rows > 0;
$stmt->close();

$mensagem = $sucesso ? 'Link removido com sucesso.' : 'Não foi possível remover o link.';
$tipo     = $sucesso ? 'sucesso' : 'erro';
header("Location: ../estudante.php?{$tipo}=" . urlencode($mensagem) . "#aba-anotacoes");
exit();