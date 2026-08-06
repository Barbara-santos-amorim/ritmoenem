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

// Só administradores podem enviar materiais.
exigirAdmin($conn, $usuarioId, '../estudante.php');

function redirecionarComMensagem(string $tipo, string $mensagem): never
{
    $param = $tipo === 'erro' ? 'erro' : 'sucesso';
    // Redireciona para o painel de administração
    header("Location: ../admin_materiais.php?{$param}=" . urlencode($mensagem));
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirecionarComMensagem('erro', 'Requisição inválida.');
}

$materia = trim((string) ($_POST['materia'] ?? ''));
if ($materia === '') {
    redirecionarComMensagem('erro', 'Informe a matéria do material.');
}

if (!isset($_FILES['arquivo']) || $_FILES['arquivo']['error'] !== UPLOAD_ERR_OK) {
    redirecionarComMensagem('erro', 'Selecione um arquivo válido.');
}

$arquivo = $_FILES['arquivo'];

// ── Whitelist de extensões permitidas
$extensoesPermitidas = ['pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt', 'png', 'jpg', 'jpeg'];
$extensao = strtolower(pathinfo($arquivo['name'], PATHINFO_EXTENSION));

if (!in_array($extensao, $extensoesPermitidas, true)) {
    redirecionarComMensagem('erro', 'Tipo de arquivo não permitido.');
}

// ── Limite de tamanho: 20 MB ──
$tamanhoMaximo = 20 * 1024 * 1024;
if ($arquivo['size'] > $tamanhoMaximo) {
    redirecionarComMensagem('erro', 'O arquivo deve ter no máximo 20 MB.');
}

// Materiais agora ficam num repositório central (não mais por pasta de usuário),
$pastaDestino = __DIR__ . "/../uploads/materiais/";
if (!is_dir($pastaDestino)) {
    mkdir($pastaDestino, 0755, true);
}

$nomeArquivo   = uniqid('material_', true) . '.' . $extensao;
$caminhoFisico = $pastaDestino . $nomeArquivo;

if (!move_uploaded_file($arquivo['tmp_name'], $caminhoFisico)) {
    redirecionarComMensagem('erro', 'Falha ao salvar o arquivo no servidor.');
}

// Insere o registro no banco. `usuario_id` aqui passa a significar "admin que enviou"
$stmt = $conn->prepare(
    "INSERT INTO materiais (usuario_id, materia, nome_original, nome_arquivo, tipo_arquivo, tamanho_bytes)
     VALUES (?, ?, ?, ?, ?, ?)"
);
$stmt->bind_param(
    'issssi',
    $usuarioId, $materia, $arquivo['name'], $nomeArquivo, $extensao, $arquivo['size']
);

if (!$stmt->execute()) {
    unlink($caminhoFisico); // Remove o arquivo do disco se o INSERT falhar
    $stmt->close();
    redirecionarComMensagem('erro', 'Não foi possível registrar o material no banco de dados.');
}

$stmt->close();
redirecionarComMensagem('sucesso', 'Material disponibilizado com sucesso.');