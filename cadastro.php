<?php
session_start();
require_once 'conexao.php';
// Processa o formulário
$mensagem = "";
$cadastroSucesso=false;
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = $_POST['nome'] ?? '';
    $email = $_POST['email'] ?? '';
    $senha = $_POST['senha'] ?? '';
    /**  A coluna `usuarios.estilos` é do tipo JSON no banco, 
    * e o MySQL 8 valida que o valor inserido seja JSON válido. "visual, auditivo" (implode)
    * não é JSON válido; precisa ser json_encode(['visual','auditivo']) => '["visual","auditivo"]'. */ 
    $estilos = isset($_POST['estilo']) ? json_encode(array_values($_POST['estilo']), JSON_UNESCAPED_UNICODE) : json_encode([]);
    $foto = '';
    $mensagem = '';
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        $nomeFoto = uniqid() . "-" . $_FILES['foto']['name'];
        $diretorioImagens = __DIR__ . "/imagens/";
        if (!is_dir($diretorioImagens)) {
            mkdir($diretorioImagens, 0777, true); 
        }
        if (move_uploaded_file($_FILES['foto']['tmp_name'], $diretorioImagens . $nomeFoto)) {
            $foto = "imagens/" . $nomeFoto;
        } else {
            $mensagem = "Erro ao mover o arquivo da foto.";
        }
    }
    // Verifica se o e-mail já está cadastrado
    $stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $result = $stmt->get_result();
    if($result->num_rows>0){
        $mensagem="Poxa, este e-mail já está cadastrado. Faça seu login!";
        }else{
            $senhaHash=password_hash($senha, PASSWORD_DEFAULT);
            $stmt=$conn->prepare("INSERT INTO usuarios (nome,email,senha,foto,estilos) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sssss",$nome,$email,$senhaHash,$foto,$estilos);
            
            if ($stmt->execute()) {
                $cadastroSucesso=true;
            } else {
                $mensagem = "Erro ao cadastrar: " . $stmt->error;
                $cadastroSucesso=false;
            }

            $stmt->close();
        }
}
?>
<?php include 'includes/header.php'; ?>

<section class="page-header">
    <h1>Crie Sua Conta</h1>
    <p>Cadastre-se para começar a organizar seus estudos com o Ritmo Enem.</p>
</section>

<div class="form-box">
    <div class="profile-pic-container">
        <img id="previewFoto" src="https://via.placeholder.com/80?text=Foto" alt="Foto de Perfil">
    </div>

    <h2>Crie sua Conta</h2>
    <?php if ($mensagem && !$cadastroSucesso): ?>
      <div class="mensagem"><?php echo $mensagem; ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data">
      <label for="nome">Nome completo:</label>
      <input type="text" name="nome" required>
      
      <label for="email">E-mail:</label>
      <input type="email" name="email" required>

      <label for="senha">Senha:</label>
      <input type="password" name="senha" id="senha" minlength="8" required>

      <label>Estilo de aprendizado:</label>
      <div class="checkbox-group">
        <label><input type="checkbox" name="estilo[]" value="visual"> Visual</label>
        <label><input type="checkbox" name="estilo[]" value="auditivo"> Auditivo</label>
        <label><input type="checkbox" name="estilo[]" value="cinestesico"> Cinestésico</label>
        <label><input type="checkbox" name="estilo[]" value="leitura_escrita"> Leitura e Escrita</label>
      </div>

      <label for="foto">Foto de perfil:</label>
      <input type="file" name="foto" id="fotoInput">

      <input type="submit" value="Cadastrar">
    </form>
</div>

<?php if ($cadastroSucesso): ?> //Modal de sucesso
    <div class="overlay" id="modal">
        <div class="modal-box">

            <span class="sparkle">✦</span>
            <span class="sparkle">✦</span>
            <span class="sparkle">✦</span>
            <span class="sparkle">✦</span>

            <div class="modal-icon">
                <svg viewBox="0 0 24 24">
                    <polyline points="4,13 9,18 20,7"/>
                </svg>
            </div>
            <h2 class="modal-title">Cadastro Realizado!</h2>
            <p class="modal-sub">
                Bem-vindo ao <strong>Ritmo Enem</strong> ✨<br>
                Sua conta foi criada com sucesso.<br>
                Agora é hora de arrasar nos estudos!
            </p>
            <a href="home.php" class="modal-btn">
                Ir para o início →
            </a>
        </div>
    </div>
<?php endif; ?>

<script>
  // JavaScript para pré-visualizar a imagem
  document.getElementById('fotoInput').addEventListener('change', function(event) {
    const [file] = event.target.files;
    if (file) {
      const previewFoto = document.getElementById('previewFoto');
      previewFoto.src = URL.createObjectURL(file);
      previewFoto.style.display = 'block'; // Garante que a imagem seja mostrada
    } else {
      // Se nenhum arquivo for selecionado, volta para a imagem de placeholder
      document.getElementById('previewFoto').src = "https://via.placeholder.com/80?text=Foto";
    }
  });
</script>
<?php include 'includes/footer.php'; ?>