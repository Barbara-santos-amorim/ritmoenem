<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: ../home.php");
    exit();
}

require_once __DIR__ . '/../conexao.php';

$usuarioId = (int) $_SESSION['usuario_id'];
$urlBruta  = trim((string) ($_POST['google_doc_url'] ?? ''));

// ── Função para redirecionar com mensagem de erro ou sucesso ──
function redirecionar(string $tipo, string $mensagem): never
{
    header("Location: ../estudante.php?{$tipo}=" . urlencode($mensagem) . "#anotacoes");
    exit();
}

// ── Validação básica: precisa ser uma URL válida do domínio docs.google.com ──
if ($urlBruta === '' || !filter_var($urlBruta, FILTER_VALIDATE_URL)) {
    redirecionar('erro', 'Cole um link válido do Google Docs.');
}

$host = parse_url($urlBruta, PHP_URL_HOST);
if ($host === null || !str_ends_with($host, 'docs.google.com')) {
    redirecionar('erro', 'O link precisa ser do Google Docs (docs.google.com).');
}

// Converte um link comum de compartilhamento do Google Docs em um link de EMBED.
if (!preg_match('#/d/([a-zA-Z0-9_-]+)#', $urlBruta, $match)) {
    redirecionar('erro', 'Não foi possível identificar o ID do documento no link enviado.');
}

$idDocumento = $match[1];

// Detecta se é Documento, Planilha ou Apresentação, pra manter o mesmo tipo no link de embed
$tipo = 'document';
if (str_contains($urlBruta, '/spreadsheets/')) $tipo = 'spreadsheets';
if (str_contains($urlBruta, '/presentation/')) $tipo = 'presentation';

$urlEmbed = "https://docs.google.com/{$tipo}/d/{$idDocumento}/preview";

// Upsert: insere um novo registro ou atualiza o existente, garantindo que cada usuário tenha apenas um link de anotações.
$stmt = $conn->prepare(
    "INSERT INTO anotacoes_config (usuario_id, google_doc_url)
     VALUES (?, ?)
     ON DUPLICATE KEY UPDATE google_doc_url = VALUES(google_doc_url), atualizado_em = NOW()"
);
$stmt->bind_param('is', $usuarioId, $urlEmbed);
$sucesso = $stmt->execute();
$stmt->close();

redirecionar($sucesso ? 'sucesso' : 'erro', $sucesso ? 'Anotações vinculadas com sucesso.' : 'Não foi possível salvar o link.');