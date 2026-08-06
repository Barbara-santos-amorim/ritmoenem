<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit();
}

require_once "conexao.php";
require_once "includes/admin_auth.php";

$usuarioId = (int) $_SESSION['usuario_id'];

// ── Só administradores acessam este painel ──
exigirAdmin($conn, $usuarioId, 'estudante.php');

// LISTAGEM DE MATERIAIS (todos, de todas as matérias)
$stmtMateriais = $conn->prepare(
    "SELECT id, materia, nome_original, tipo_arquivo, tamanho_bytes, criado_em
     FROM materiais
     ORDER BY materia ASC, criado_em DESC"
);
$stmtMateriais->execute();
$materiaisResultado = $stmtMateriais->get_result();

$materiaisPorMateria = [];
while ($m = $materiaisResultado->fetch_assoc()) {
    $materiaisPorMateria[$m['materia']][] = $m;
}
$stmtMateriais->close();

$mensagemSucesso = $_GET['sucesso'] ?? null;
$mensagemErro     = $_GET['erro'] ?? null;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Administração de Materiais</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/cronograma.css">
    <style>
        .painel-admin { max-width: 900px; margin: 0 auto; padding: 20px; }
        .lista-materiais { margin-top: 25px; }
        .grupo-materia h3 { margin-bottom: 8px; }
        .item-material {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 14px;
            border: 1px solid #e2e2e2;
            border-radius: 8px;
            margin-bottom: 8px;
        }
        .item-material .acoes a { margin-left: 12px; }

        /* ── Formulário de upload ── */
        .form-upload-material {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .campo-arquivo {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }

        .campo-arquivo input[type="file"] {
            /* Escondemos o input nativo, mas mantemos acessível via label */
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        .btn-arquivo {
            display: inline-block;
            padding: 10px 18px;
            background: #fff;
            border: 1.5px solid #009999;
            color: #009999;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.15s ease;
            white-space: nowrap;
        }

        .btn-arquivo:hover {
            background: #e6fafa;
        }

        .btn-arquivo:focus-within {
            outline: 2px solid #009999;
            outline-offset: 2px;
        }

        .nome-arquivo-selecionado {
            color: #666;
            font-size: 0.9rem;
        }

        .form-upload-material .btn {
            align-self: flex-start;
        }
    </style>
</head>
<body>
<?php include "includes/header.php"; ?>

<main class="painel-admin">
    <div class="page-header">
        <h1>Administração de Materiais</h1>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="mensagem mensagem-sucesso"><?= htmlspecialchars($mensagemSucesso) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="mensagem mensagem-erro"><?= htmlspecialchars($mensagemErro) ?></div>
    <?php endif; ?>

    <form class="form-upload-material" action="api/enviar_material.php" method="POST" enctype="multipart/form-data">
        <div>
            <label for="input-materia">Matéria</label><br>
            <input type="text" id="input-materia" name="materia" class="form-control" placeholder="Ex: Matemática" required>
        </div>
        <div>
            <label>Arquivo</label><br>
            <div class="campo-arquivo">
                <label for="input-arquivo" class="btn-arquivo">Escolher arquivo</label>
                <span class="nome-arquivo-selecionado" id="nome-arquivo">Nenhum arquivo selecionado</span>
                <input type="file" id="input-arquivo" name="arquivo" required
                       accept=".pdf,.doc,.docx,.ppt,.pptx,.xls,.xlsx,.txt,.png,.jpg,.jpeg">
            </div>
        </div>
        <button type="submit" class="btn btn-azul">Disponibilizar Material</button>
    </form>

    <div class="lista-materiais">
        <?php if (empty($materiaisPorMateria)): ?>
            <p>Nenhum material disponibilizado ainda.</p>
        <?php endif; ?>

        <?php foreach ($materiaisPorMateria as $materia => $arquivos): ?>
            <div class="grupo-materia">
                <h3><?= htmlspecialchars($materia) ?></h3>
                <?php foreach ($arquivos as $arq): ?>
                    <div class="item-material">
                        <div class="info">
                            📄 <?= htmlspecialchars($arq['nome_original']) ?>
                            <small>(<?= number_format($arq['tamanho_bytes'] / 1024, 0) ?> KB)</small>
                        </div>
                        <div class="acoes">
                            <a href="api/baixar_material.php?id=<?= $arq['id'] ?>">Baixar</a>
                            <a href="api/excluir_material.php?id=<?= $arq['id'] ?>"
                               onclick="return confirm('Excluir este material para todos os usuários?')"
                               style="color:#c0392b;">Excluir</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
</main>

<?php include "includes/footer.php"; ?>

<script>
document.getElementById('input-arquivo').addEventListener('change', function () {
    const nomeSpan = document.getElementById('nome-arquivo');
    nomeSpan.textContent = this.files.length > 0 ? this.files[0].name : 'Nenhum arquivo selecionado';
});
</script>
</body>
</html>