<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    header("Location: cronograma.php");
    exit();
}
require_once 'conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';

    // Busca o usuário no banco
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE email = ?");
    if (!$stmt){
        die("Erro SQL: ".$conn->error);
    }
    $stmt->bind_param("s", $email);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    // Verifica senha
    if ($user && password_verify($senha, $user['senha'])) {

        // Cria sessão
        $_SESSION['usuario_id'] = $user['id'];
        $_SESSION['nome'] = $user['nome'];

        // Redireciona
        header("Location: estudante.php");
        exit();

    } else {
        echo "<script>alert('Email ou senha incorretos.');</script>";
    }
}
?>
<?php include 'includes/header.php'; ?>
    <main class="main-content">
    <div class="welcome-panel">
        <h1>Bem-vindo ao Ritmo Enem</h1>
        <p>Organize seus estudos de forma prática e intuitiva. Acesse seu cronograma e prepare-se com foco para o ENEM.</p>
        <p>Aqui, você encontra um ambiente leve e funcional para acompanhar seu progresso e manter a rotina de estudos alinhada com seus objetivos.</p>
    </div>

    <div class="login-card">
        <h2>LOGIN</h2>
        <form method="POST" action="">
            <input type="text" name="email" placeholder="E-MAIL" required>
            <input type="password" name="senha" placeholder="SENHA" required>
            <input type="submit" value="LOGIN">
        </form>
        <div class="link-row">
            <a href="cadastro.php">Cadastrar-se</a>
        </div>
    </div>
</main>
<?php include 'includes/footer.php'; ?>
