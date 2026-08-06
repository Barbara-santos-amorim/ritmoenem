<?php
declare(strict_types=1);

// Verifica se o usuário logado é administrador.
// Assume que a tabela `usuarios` tem uma coluna `tipo` com valores 'aluno' ou 'admin'

function usuarioEhAdmin(mysqli $conn, int $usuarioId): bool
{
    $stmt = $conn->prepare("SELECT tipo FROM usuarios WHERE id = ?");
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row !== null && ($row['tipo'] ?? '') === 'admin';
}

// Interrompe a execução se o usuário logado não for admin.
function exigirAdmin(mysqli $conn, int $usuarioId, ?string $urlSemAcesso = null): void
{
    if (usuarioEhAdmin($conn, $usuarioId)) {
        return;
    }

    if ($urlSemAcesso !== null) {
        header("Location: {$urlSemAcesso}?erro=" . urlencode('Acesso restrito a administradores.'));
        exit();
    }

    http_response_code(403);
    echo 'Acesso restrito a administradores.';
    exit();
}