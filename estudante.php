<?php
declare(strict_types=1);

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit();
}

require_once "conexao.php";

$usuarioId = (int) $_SESSION['usuario_id'];

// DADOS DO USUÁRIO (nome + foto de perfil)
$stmtUsuario = $conn->prepare("SELECT nome, foto FROM usuarios WHERE id = ?");
$stmtUsuario->bind_param("i", $usuarioId);
$stmtUsuario->execute();
$usuario = $stmtUsuario->get_result()->fetch_assoc() ?? ['nome' => '', 'foto' => null];
$stmtUsuario->close();

/**  A foto é definida no cadastro (cadastro.php salva o caminho em usuarios.foto, ex: "imagens/abc-arquivo.jpg").
* Aqui só reaproveitamos esse valor; o botão de câmera permite trocá-la depois, se o usuário quiser.
* $fotoPerfil = $usuario['foto'] ?: 'https://via.placeholder.com/80?text=Foto';
* Se não houver foto cadastrada, cai num avatar padrão (ajuste o caminho conforme seu projeto) */
 $fotoPerfil = $usuario['foto'] ?: 'https://via.placeholder.com/80?text=Foto';

//  CRONOGRAMA MAIS RECENTE (usado para as barras de progresso)
$stmtAtivo = $conn->prepare(
    "SELECT id, nome, dias
     FROM cronogramas
     WHERE usuario_id = ?
     ORDER BY created_at DESC
     LIMIT 1"
);
$stmtAtivo->bind_param("i", $usuarioId);
$stmtAtivo->execute();
$cronogramaAtivo = $stmtAtivo->get_result()->fetch_assoc();
$stmtAtivo->close();

$percentualConcluido = 0;   // % de blocos concluídos no cronograma mais recente
$diasEstudadosSemana = 0;   // quantos dias diferentes da semana atual já tiveram algum bloco concluído
$diasConfigurados    = 0;   // meta semanal: quantos dias o cronograma tem configurado

if ($cronogramaAtivo) {
    $cronogramaId     = (int) $cronogramaAtivo['id'];
    $diasConfigurados = count(json_decode($cronogramaAtivo['dias'], true) ?? []);

    //Progresso do cronograma (blocos concluídos / total de blocos) 
    $stmtTotal = $conn->prepare("SELECT COUNT(*) AS total FROM cronograma_blocos WHERE cronograma_id = ?");
    $stmtTotal->bind_param("i", $cronogramaId);
    $stmtTotal->execute();
    $totalBlocos = (int) ($stmtTotal->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtTotal->close();

    $stmtConcluidos = $conn->prepare(
        "SELECT COUNT(*) AS total FROM cronograma_blocos WHERE cronograma_id = ? AND concluido_em IS NOT NULL"
    );
    $stmtConcluidos->bind_param("i", $cronogramaId);
    $stmtConcluidos->execute();
    $blocosConcluidos = (int) ($stmtConcluidos->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtConcluidos->close();

    $percentualConcluido = $totalBlocos > 0 ? (int) round(($blocosConcluidos / $totalBlocos) * 100) : 0;

    // Progresso semanal (dias diferentes com pelo menos 1 bloco concluído ESTA semana)
    $inicioSemana = new DateTime('monday this week');
    $inicioSemana->setTime(0, 0, 0);
    $fimSemana = (clone $inicioSemana)->modify('+6 days')->setTime(23, 59, 59);

    $stmtDiasSemana = $conn->prepare(
        "SELECT COUNT(DISTINCT dia) AS total
         FROM cronograma_blocos
         WHERE cronograma_id = ? AND concluido_em BETWEEN ? AND ?"
    );
    $inicioStr = $inicioSemana->format('Y-m-d H:i:s');
    $fimStr    = $fimSemana->format('Y-m-d H:i:s');
    $stmtDiasSemana->bind_param("iss", $cronogramaId, $inicioStr, $fimStr);
    $stmtDiasSemana->execute();
    $diasEstudadosSemana = (int) ($stmtDiasSemana->get_result()->fetch_assoc()['total'] ?? 0);
    $stmtDiasSemana->close();
}


// LISTAGEM DE CRONOGRAMAS (aba "Cronogramas")
$stmt = $conn->prepare(
    "SELECT id, nome, horas, bloco_min, horario_inicio, horario_fim, created_at
     FROM cronogramas
     WHERE usuario_id = ?
     ORDER BY created_at DESC"
);
$stmt->bind_param("i", $usuarioId);
$stmt->execute();
$resultadoCronogramas = $stmt->get_result();
$stmt->close();


// MATERIAIS DE ESTUDO (aba "Materiais"), agrupados por matéria
/**  Materiais são um repositório central mantido pelo admin: todo usuário logado vê a lista completa, não apenas os materiais que ele mesmo enviou.*/
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


// ANOTAÇÕES (aba "Anotações") — repositório de links salvos pelo estudante
$stmtLinks = $conn->prepare(
    "SELECT id, titulo, url, criado_em
     FROM anotacoes_links
     WHERE usuario_id = ?
     ORDER BY criado_em DESC"
);
$stmtLinks->bind_param("i", $usuarioId);
$stmtLinks->execute();
$linksResultado = $stmtLinks->get_result();

$listaLinks = [];
while ($l = $linksResultado->fetch_assoc()) {
    $listaLinks[] = $l;
}
$stmtLinks->close();


//  MENSAGENS DE FEEDBACK (vindas de redirects dos processadores de upload/exclusão)

$mensagemSucesso = $_GET['sucesso'] ?? null;
$mensagemErro     = $_GET['erro'] ?? null;
?>

<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <title>Área do Estudante</title>

    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/cronograma.css">
    <link rel="stylesheet" href="css/estudante.css">

    <style>
        .topo-estudante {
            display: flex;
            align-items: center;
            gap: 24px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .avatar-wrapper {
            position: relative;
            width: 90px;
            height: 90px;
            flex-shrink: 0;
        }
        .avatar-wrapper img {
            width: 90px;
            height: 90px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #ddd;
        }
        .avatar-overlay {
            position: absolute;
            bottom: 0;
            right: 0;
            background: #007bff;
            color: #fff;
            width: 28px;
            height: 28px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 14px;
        }
        .progresso-container {
            flex: 1;
            min-width: 260px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        .progresso-item label {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-bottom: 4px;
        }
        .progresso-barra {
            background: #e9ecef;
            border-radius: 6px;
            height: 12px;
            overflow: hidden;
        }
        .progresso-preenchido {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #5cd97c);
            transition: width 0.4s ease;
        }
        .abas-nav {
            display: flex;
            gap: 4px;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
        }
        .abas-nav button {
            padding: 10px 18px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            cursor: pointer;
            font-size: 0.95rem;
            color: #666;
        }
        .abas-nav button.ativa {
            border-bottom-color: #007bff;
            color: #007bff;
            font-weight: 600;
        }
        .aba-conteudo { display: none; }
        .aba-conteudo.ativa { display: block; }

        .lista-materiais { display: flex; flex-direction: column; gap: 20px; }

        .grupo-materia h3 {
            margin-bottom: 8px;
            font-size: 1.05rem;
        }
        .item-material {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            margin-bottom: 6px;
        }

        .item-material .info { font-size: 0.9rem; color: #444; }
        .item-material .acoes a { margin-left: 10px; font-size: 0.85rem; }

        .form-upload-material {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 25px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .aviso-mensagem {
            padding: 10px 14px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
        }
        .aviso-sucesso { background: #d4edda; color: #155724; }
        .aviso-erro    { background: #f8d7da; color: #721c24; }

        .card-anotacoes {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding: 18px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        .card-anotacoes label {
            font-size: 0.85rem;
            font-weight: 600;
            color: #444;
        }
        .form-anotacoes {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .form-anotacoes input[type="text"],
        .form-anotacoes input[type="url"] {
            padding: 10px 14px;
            border: 1.5px solid #ddd;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: border-color 0.15s ease;
        }
        .form-anotacoes input[type="text"] {
            flex: 1;
            min-width: 180px;
        }
        .form-anotacoes input[type="url"] {
            flex: 2;
            min-width: 220px;
        }
        .form-anotacoes input:focus {
            outline: none;
            border-color: #007bff;
        }
        .form-anotacoes .btn {
            white-space: nowrap;
        }

        .lista-links {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .item-link {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 4px 10px;
            background: #f8f9fa;
            border-radius: 5px;
            min-height: 30px;
        }
        .link-titulo {
            color: #007bff;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.85rem;
        }
        .link-titulo:hover {
            text-decoration: underline;
        }
        .form-excluir-link {
            margin: 0 !important;
            padding: 0 !important;
            background: transparent !important;
            border: none !important;
            border-radius: 0 !important;
            box-shadow: none !important;
            display: inline-flex;
        }
        /* Sobrescreve o estilo global de <button> do site (que deixa botões
           grandes e em formato de pílula) para virar só um ícone discreto */
        .form-excluir-link button.btn-excluir-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            min-width: 0;
            background: transparent !important;
            border: none !important;
            box-shadow: none !important;
            border-radius: 5px !important;
            padding: 0 !important;
            margin: 0;
            font-size: 0.75rem;
            line-height: 1;
            color: #c0392b;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .form-excluir-link button.btn-excluir-link:hover {
            background: #f8d7da !important;
        }
    </style>
</head>

<body>

<?php include "includes/header.php"; ?>

<main class="container">
    <div class="page-header">
        <h1>Área do Estudante</h1>
    </div>

    <?php if ($mensagemSucesso): ?>
        <div class="aviso-mensagem aviso-sucesso"><?= htmlspecialchars($mensagemSucesso) ?></div>
    <?php endif; ?>
    <?php if ($mensagemErro): ?>
        <div class="aviso-mensagem aviso-erro"><?= htmlspecialchars($mensagemErro) ?></div>
    <?php endif; ?>

    <!-- TOPO: foto de perfil + barras de progresso -->
    <div class="topo-estudante">

        <div class="avatar-wrapper">
            <img id="fotoPerfil" src="<?= htmlspecialchars($fotoPerfil) ?>?t=<?= time() ?>" alt="Foto de perfil">
            <label class="avatar-overlay" for="inputFoto" title="Trocar foto">📷</label>
            <input type="file" id="inputFoto" accept="image/png,image/jpeg,image/webp" hidden>
        </div>

        <div class="progresso-container">

            <div class="progresso-item">
                <label>
                    <span>Progresso do cronograma <?= $cronogramaAtivo ? '(' . htmlspecialchars($cronogramaAtivo['nome']) . ')' : '' ?></span>
                    <span><?= $percentualConcluido ?>%</span>
                </label>
                <div class="progresso-barra">
                    <div class="progresso-preenchido" style="width: <?= $percentualConcluido ?>%;"></div>
                </div>
            </div>

            <div class="progresso-item">
                <label>
                    <span>Progresso semanal</span>
                    <span><?= $diasEstudadosSemana ?> / <?= $diasConfigurados ?: '—' ?> dias</span>
                </label>
                <div class="progresso-barra">
                    <?php $pctSemana = $diasConfigurados > 0 ? (int) round(($diasEstudadosSemana / $diasConfigurados) * 100) : 0; ?>
                    <div class="progresso-preenchido" style="width: <?= $pctSemana ?>%;"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- ABAS: Cronogramas | Materiais | Anotações -->
    <div class="abas-nav">
        <button type="button" class="aba-botao ativa" data-aba="cronogramas">📅 Cronogramas</button>
        <button type="button" class="aba-botao" data-aba="materiais">📚 Materiais</button>
        <button type="button" class="aba-botao" data-aba="anotacoes">📝 Anotações</button>
    </div>

    <!-- ABA: CRONOGRAMAS -->
    <div id="aba-cronogramas" class="aba-conteudo ativa">

        <div style="margin-bottom:25px;">
            <a href="cronograma.php" class="btn btn-azul">+ Criar Novo Cronograma</a>
        </div>

        <div class="grid-cronogramas">
            <?php if ($resultadoCronogramas->num_rows > 0): ?>
                <?php while ($cronograma = $resultadoCronogramas->fetch_assoc()): ?>
                    <div class="card-cronograma">
                        <h2><?= htmlspecialchars($cronograma['nome']) ?></h2>
                        <p><strong>Horas:</strong> <?= $cronograma['horas'] ?>h/dia</p>
                        <p><strong>Bloco:</strong> <?= $cronograma['bloco_min'] ?> minutos</p>
                        <p>
                            <strong>Horário:</strong>
                            <?= substr($cronograma['horario_inicio'], 0, 5) ?> às <?= substr($cronograma['horario_fim'], 0, 5) ?>
                        </p>
                        <div class="acoes-cronograma">
                            <a href="visualizar.php?id=<?= $cronograma['id'] ?>" class="btn btn-verde">Visualizar</a>
                            <a href="excluir.php?id=<?= $cronograma['id'] ?>"
                               class="btn btn-vermelho"
                               onclick="return confirm('Deseja realmente excluir este cronograma?')">
                                Excluir
                            </a>
                        </div>
                    </div>
                <?php endwhile; ?>
            <?php else: ?>
                <p>Você ainda não possui cronogramas cadastrados.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ABA: MATERIAIS -->
    <div id="aba-materiais" class="aba-conteudo">

        <div class="lista-materiais">
            <?php if (empty($materiaisPorMateria)): ?>
                <p>Nenhum material enviado ainda.</p>
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
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ABA: ANOTAÇÕES -->
    <div id="aba-anotacoes" class="aba-conteudo">

        <div class="card-anotacoes">
            <label for="input-titulo-link">Adicionar link</label>
            <form class="form-anotacoes" action="api/salvar_link.php" method="POST">
                <input type="text" id="input-titulo-link" name="titulo" maxlength="150"
                       placeholder="Título (ex: Resumo de Química)" required>
                <input type="url" id="input-url-link" name="url"
                       placeholder="Cole aqui o link (Google Docs, PDF, site, etc.)" required>
                <button type="submit" class="btn btn-azul">+ Adicionar Link</button>
            </form>
        </div>

        <div class="lista-links">
            <?php if (empty($listaLinks)): ?>
                <p>Nenhum link salvo ainda.</p>
            <?php endif; ?>

            <?php foreach ($listaLinks as $link): ?>
                <div class="item-link">
                    <a href="<?= htmlspecialchars($link['url']) ?>"
                       target="_blank" rel="noopener noreferrer" class="link-titulo">
                        🔗 <?= htmlspecialchars($link['titulo']) ?>
                    </a>
                    <form class="form-excluir-link" action="api/excluir_link.php" method="POST"
                          onsubmit="return confirm('Remover este link?')">
                        <input type="hidden" name="id" value="<?= (int) $link['id'] ?>">
                        <button type="submit" class="btn-excluir-link" title="Remover link">🗑️</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

</main>

<?php include "includes/footer.php"; ?>

<script>
/*  ABAS: alterna qual .aba-conteudo fica visível*/
document.querySelectorAll('.aba-botao').forEach(botao => {
    botao.addEventListener('click', () => {
        document.querySelectorAll('.aba-botao').forEach(b => b.classList.remove('ativa'));
        document.querySelectorAll('.aba-conteudo').forEach(c => c.classList.remove('ativa'));

        botao.classList.add('ativa');
        document.getElementById('aba-' + botao.dataset.aba).classList.add('ativa');
    });
});

/* UPLOAD DE FOTO DE PERFIL (via AJAX, sem recarregar a página) */
document.getElementById('inputFoto').addEventListener('change', async (evento) => {
    const arquivo = evento.target.files[0];
    if (!arquivo) return;

    const dados = new FormData();
    dados.append('foto', arquivo);

    try {
        const resposta = await fetch('api/atualizar_foto.php', { method: 'POST', body: dados });
        const resultado = await resposta.json();

        if (resultado.sucesso) {
            // Cache-busting (?t=timestamp) garante que o navegador não mostre a foto antiga em cache
            document.getElementById('fotoPerfil').src = resultado.caminho + '?t=' + Date.now();
        } else {
            alert(resultado.mensagem || 'Não foi possível enviar a foto.');
        }
    } catch (erro) {
        alert('Erro de conexão ao enviar a foto.');
    }
});
</script>

</body>
</html>