<?php 
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Checar se o usuário logado é admin (link "ADMIN" abaixo)
require_once __DIR__ . '/../conexao.php';
require_once __DIR__ . '/admin_auth.php';
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="utf-8">
    <title>Ritmo Enem</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter&family=Kodchasan:wght@400;600&family=Klee+One&family=Linden+Hill&family=Syne:wght@700;800&family=Plus+Jakarta+Sans:wght@600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="css/style.css">
    <?php
    $pagina = basename($_SERVER['PHP_SELF'], '.php');
    if (file_exists("css/$pagina.css")) {
        echo '<link rel="stylesheet" href="css/' . $pagina . '.css">';
    }
    ?>
</head>
<body>
    <header>
        <div class="logo">Ritmo Enem</div>
        <nav>
            <a href="home.php">HOME</a>
            <a href="sobre.php">SOBRE</a>
            <?php if (isset($_SESSION['usuario_id'])): ?>
                <a href="estudante.php">ESTUDANTE</a>
                <a href="cronograma.php">CRONOGRAMA</a>
                <?php if (usuarioEhAdmin($conn, (int) $_SESSION['usuario_id'])): ?>
                    <a href="admin_materiais.php">ADMIN</a>
                <?php endif; ?>
                <a href="logout.php">SAIR</a>
            <?php else: ?>
                <a href="cadastro.php">CADASTRO</a>
            <?php endif; ?>
        </nav>
    </header>