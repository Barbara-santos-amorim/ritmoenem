<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['sucesso' => false, 'mensagem' => 'Sessão expirada. Faça login novamente.']);
    exit();
}

$usuarioId = (int) $_SESSION['usuario_id'];

if (!isset($_FILES['foto']) || $_FILES['foto']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Nenhum arquivo válido foi enviado.']);
    exit();
}

$arquivo = $_FILES['foto'];

// ── Validação de tipo de arquivo (permitidos: JPG, PNG, WEBP) ──
$tiposPermitidos = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$mimeReal = finfo_file($finfo, $arquivo['tmp_name']);
finfo_close($finfo);

if (!isset($tiposPermitidos[$mimeReal])) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Formato de imagem inválido. Use JPG, PNG ou WEBP.']);
    exit();
}

// ── Validação de tamanho (máx. 5 MB) ──
$tamanhoMaximo = 5 * 1024 * 1024;
if ($arquivo['size'] > $tamanhoMaximo) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'A imagem deve ter no máximo 5 MB.']);
    exit();
}

// ── Monta o caminho de destino: mesma pasta usada em cadastro.php ("imagens/") ──
$pastaDestino = __DIR__ . "/../imagens/";
if (!is_dir($pastaDestino)) {
    mkdir($pastaDestino, 0755, true);
}

$extensao       = $tiposPermitidos[$mimeReal];
$nomeArquivo    = uniqid() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '_', $arquivo['name']);
$caminhoFisico  = $pastaDestino . $nomeArquivo;
$caminhoPublico = "imagens/{$nomeArquivo}"; // Caminho relativo, usado no <img src="">

if (!move_uploaded_file($arquivo['tmp_name'], $caminhoFisico)) {
    echo json_encode(['sucesso' => false, 'mensagem' => 'Falha ao salvar a imagem no servidor.']);
    exit();
}

// ── Atualiza o banco (coluna 'foto', a mesma preenchida em cadastro.php) e remove a foto antiga ──
require_once __DIR__ . '/../conexao.php';

$stmtAntiga = $conn->prepare("SELECT foto FROM usuarios WHERE id = ?");
$stmtAntiga->bind_param("i", $usuarioId);
$stmtAntiga->execute();
$fotoAntiga = $stmtAntiga->get_result()->fetch_assoc()['foto'] ?? null;
$stmtAntiga->close();

$stmtAtualizar = $conn->prepare("UPDATE usuarios SET foto = ? WHERE id = ?");
$stmtAtualizar->bind_param("si", $caminhoPublico, $usuarioId);
$sucesso = $stmtAtualizar->execute();
$stmtAtualizar->close();

// Só apaga a foto antiga se ela estiver na pasta "imagens/" (evita apagar por engano
// um caminho externo/placeholder que porventura esteja salvo na coluna)
if ($sucesso && $fotoAntiga && str_starts_with($fotoAntiga, 'imagens/') && file_exists(__DIR__ . '/../' . $fotoAntiga)) {
    unlink(__DIR__ . '/../' . $fotoAntiga); // Limpa o arquivo antigo do disco pra não acumular lixo
}

echo json_encode([
    'sucesso'  => $sucesso,
    'caminho'  => $caminhoPublico,
    'mensagem' => $sucesso ? null : 'Não foi possível atualizar o banco de dados.',
]);