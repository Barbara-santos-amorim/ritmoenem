<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../home.php");
    exit();
}

require_once __DIR__ . '/../conexao.php';

$usuarioId = (int) $_SESSION['usuario_id'];

function redirecionarComMensagem(string $tipo, string $mensagem): never
{
    $param = $tipo === 'erro' ? 'erro' : 'sucesso';
    // Volta para a área do estudante, já na aba de anotações
    header("Location: ../estudante.php?{$param}=" . urlencode($mensagem) . "#aba-anotacoes");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionarComMensagem('erro', 'Requisição inválida.');
}

$titulo = trim((string) ($_POST['titulo'] ?? ''));
$url    = trim((string) ($_POST['url'] ?? ''));

if ($titulo === '' || $url === '') {
    redirecionarComMensagem('erro', 'Informe um título e um link.');
}

// Valida se o texto digitado realmente parece uma URL
if (!filter_var($url, FILTER_VALIDATE_URL)) {
    redirecionarComMensagem('erro', 'O link informado não é válido.');
}

$stmt = $conn->prepare(
    "INSERT INTO anotacoes_links (usuario_id, titulo, url) VALUES (?, ?, ?)"
);
$stmt->bind_param('iss', $usuarioId, $titulo, $url);

if (!$stmt->execute()) {
    $stmt->close();
    redirecionarComMensagem('erro', 'Não foi possível salvar o link.');
}

$stmt->close();
redirecionarComMensagem('sucesso', 'Link adicionado com sucesso.');