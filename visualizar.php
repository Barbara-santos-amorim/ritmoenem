<?php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit();
}

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/includes/db_cronograma.php';

$usuarioId    = (int) $_SESSION['usuario_id'];
$cronogramaId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($cronogramaId <= 0) {
    header("Location: estudante.php");
    exit();
}

$cronograma = buscarCronogramaCompleto($conn, $cronogramaId, $usuarioId);
if (!$cronograma) {
    // Ou o cronograma não existe, ou não pertence a este usuário — em ambos os casos, tratamos igual (não revelamos qual dos dois é o motivo, por segurança).
    header("Location: estudante.php");
    exit();
}

$blocoMin      = (int) $cronograma['bloco_min'];
$horarioInicio = substr((string) $cronograma['horario_inicio'], 0, 5); // "HH:MM:SS" -> "HH:MM"
$horarioFim    = substr((string) $cronograma['horario_fim'], 0, 5);
$dias          = $cronograma['dias'];

$labelDias = [
    'seg' => 'Segunda', 'ter' => 'Terça',  'qua' => 'Quarta',
    'qui' => 'Quinta',  'sex' => 'Sexta',  'sab' => 'Sábado', 'dom' => 'Domingo',
];

// Monta as linhas de horário (mesma lógica usada na geração)
$dtInicio = DateTime::createFromFormat('H:i', $horarioInicio);
$dtFim    = DateTime::createFromFormat('H:i', $horarioFim);

$linhas = [];
$cursor = clone $dtInicio;
while ($cursor < $dtFim) {
    $ini = $cursor->format('H:i');
    $cursor->modify("+{$blocoMin} minutes");
    $linhas[] = ['inicio' => $ini, 'fim' => $cursor->format('H:i')];
}

$porChave = buscarBlocosIndexados($conn, $cronogramaId);

//Progresso deste cronograma específico (para a barra no topo da página)
$totalBlocos      = count($porChave);
$blocosConcluidos = count(array_filter($porChave, fn($b) => $b['concluido_em'] !== null));
$percentual       = $totalBlocos > 0 ? (int) round(($blocosConcluidos / $totalBlocos) * 100) : 0;
?>
<?php include 'includes/header.php'; ?>

<main>
    <div class="cabecalho-visualizar">
        <h1 class="titulo-pagina"><?= htmlspecialchars($cronograma['nome']) ?></h1>
        <p class="info-cronograma">
            <strong><?= $cronograma['horas'] ?>h/dia</strong> &nbsp;|&nbsp;
            Bloco: <?= $blocoMin ?> min &nbsp;|&nbsp;
            Horário: <?= htmlspecialchars($horarioInicio) ?> – <?= htmlspecialchars($horarioFim) ?>
        </p>
        <div class="progresso-item">
            <label>
                <span>Progresso</span>
                <span id="progresso-texto"><?= $blocosConcluidos ?> / <?= $totalBlocos ?> blocos (<?= $percentual ?>%)</span>
            </label>
            <div class="progresso-barra">
                <div id="progresso-preenchido" style="width:<?= $percentual ?>%;"></div>
            </div>
        </div>
    </div>

    <main class="container mt-2">
        <div id="alertas-visualizar"></div>
        <div class="mt-3 table-responsive">
            <table class="tabela-cronograma">
                <thead>
                    <tr>
                        <th>Horário</th>
                        <?php foreach ($dias as $d): ?>
                            <th><?= $labelDias[$d] ?? $d ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($linhas as $linha): ?>
                        <tr>
                            <th><?= $linha['inicio'] ?>–<?= $linha['fim'] ?></th>
                            <?php foreach ($dias as $d):
                                $chave = $d . '|' . $linha['inicio'];
                                $slot  = $porChave[$chave] ?? null;

                                $classeDificuldade = match ($slot['dificuldade'] ?? '') {
                                    'Difícil' => 'dificuldade-dificil',
                                    'Médio'   => 'dificuldade-medio',
                                    'Fácil'   => 'dificuldade-facil',
                                    default   => '',
                                };
                                $classeVazia    = $slot ? '' : 'vazia';
                                $classeConcluido = ($slot && $slot['concluido_em'] !== null) ? 'concluido' : '';
                            ?>
                                <td class="celula-cronograma <?= $classeDificuldade ?> <?= $classeVazia ?> <?= $classeConcluido ?>"
                                    data-dia="<?= $d ?>"
                                    data-horario="<?= $linha['inicio'] ?>"
                                    data-dificuldade="<?= htmlspecialchars($slot['dificuldade'] ?? 'Médio') ?>"
                                    draggable="true">
                                    <div class="acoes-celula">
                                        <?php if ($slot): ?>
                                            <button class="btn-acao btn-concluir" title="Marcar como concluído">
                                                <?= $slot['concluido_em'] !== null ? '✅' : '⬜' ?>
                                            </button>
                                        <?php endif; ?>
                                        <button class="btn-acao btn-editar" title="Editar bloco">✏️</button>
                                        <button class="btn-acao btn-apagar" title="Apagar bloco">✕</button>
                                    </div>
                                    <div class="conteudo-celula"><?= htmlspecialchars($slot['materia'] ?? '') ?></div>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="text-center mt-4">
            <a href="estudante.php" class="btn btn-azul">← Voltar</a>
            <a href="excluir.php?id=<?= $cronogramaId ?>"
               class="btn btn-vermelho"
               onclick="return confirm('Deseja realmente excluir este cronograma? Esta ação não pode ser desfeita.')">
                🗑️ Excluir Cronograma
            </a>
        </div>

    </main>
</main>

<script>
/**
 * Diferente de cronograma.php: aqui NÃO existe estado em memória nem botão "Salvar".
 * Cada ação (editar, apagar, trocar, marcar concluído) já dispara uma requisição AJAX imediata pro backend, 
 * porque o cronograma_id já existe. Se a requisição falhar, a alteração visual é revertida.
 */
const CRONOGRAMA_ID = <?= $cronogramaId ?>;
const BLOCO_MIN      = <?= $blocoMin ?>;
const TOTAL_BLOCOS   = <?= $totalBlocos ?>;

function mostrarAlerta(mensagem, tipo = 'danger') {
    const area = document.getElementById('alertas-visualizar');
    area.innerHTML = mensagem ? `<div class="alert alert-${tipo}">${mensagem}</div>` : '';
}

async function chamarAtualizarBloco(payload) {
    const resp = await fetch('api/atualizar_bloco.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ cronograma_id: CRONOGRAMA_ID, ...payload }),
    });
    return resp.json();
}

/* ── Atualiza a barra de progresso no topo, sem recarregar a página ── */
let blocosConcluidosAtual = <?= $blocosConcluidos ?>;
function atualizarBarraProgresso(delta) {
    blocosConcluidosAtual += delta;
    const pct = TOTAL_BLOCOS > 0 ? Math.round((blocosConcluidosAtual / TOTAL_BLOCOS) * 100) : 0;
    document.getElementById('progresso-preenchido').style.width = pct + '%';
    document.getElementById('progresso-texto').textContent =
        `${blocosConcluidosAtual} / ${TOTAL_BLOCOS} blocos (${pct}%)`;
}

/* Marcar/desmarcar conclusão  */
async function alternarConcluido(botao) {
    const celula   = botao.closest('.celula-cronograma');
    const dia      = celula.dataset.dia;
    const inicio   = celula.dataset.horario;
    const jaConcluido = botao.textContent.trim() === '✅';
    const novoEstado  = !jaConcluido;

    // Atualização otimista (muda a tela antes da resposta do servidor chegar)
    botao.textContent = novoEstado ? '✅' : '⬜';
    celula.classList.toggle('concluido', novoEstado);
    atualizarBarraProgresso(novoEstado ? 1 : -1);

    try {
        const resp = await fetch('api/marcar_concluido.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ cronograma_id: CRONOGRAMA_ID, dia, inicio, concluir: novoEstado }),
        });
        const data = await resp.json();
        if (!data.sucesso) throw new Error(data.erro || 'Falha ao salvar.');
    } catch (e) {
        // Reverte a UI se o servidor recusou a alteração
        botao.textContent = jaConcluido ? '✅' : '⬜';
        celula.classList.toggle('concluido', jaConcluido);
        atualizarBarraProgresso(jaConcluido ? 1 : -1);
        mostrarAlerta('Não foi possível salvar essa alteração. Tente novamente.');
    }
}

/* Apagar bloco */
async function apagarBloco(botao) {
    const celula = botao.closest('.celula-cronograma');
    if (!window.confirm('Apagar este bloco de estudo?')) return;
    if (celula.classList.contains('vazia')) return;

    const dia    = celula.dataset.dia;
    const inicio = celula.dataset.horario;

    try {
        const data = await chamarAtualizarBloco({ acao: 'apagar', dia, inicio });
        if (!data.sucesso) throw new Error(data.erro || 'Falha ao apagar.');

        celula.querySelector('.conteudo-celula').innerHTML = '';
        ['dificuldade-dificil', 'dificuldade-medio', 'dificuldade-facil', 'concluido'].forEach(c => celula.classList.remove(c));
        celula.classList.add('vazia');
        celula.dataset.dificuldade = 'Médio';
        const btnConcluir = celula.querySelector('.btn-concluir');
        if (btnConcluir) btnConcluir.remove();
    } catch (e) {
        mostrarAlerta('Não foi possível apagar o bloco. Tente novamente.');
    }
}

/* Editar bloco (texto da matéria + dificuldade, inline)  */
function editarBloco(botao) {
    const celula   = botao.closest('.celula-cronograma');
    const conteudo = celula.querySelector('.conteudo-celula');

    celula.classList.add('editando');
    celula.setAttribute('draggable', 'false');
    conteudo.setAttribute('contenteditable', 'true');

    const dia      = celula.dataset.dia;
    const inicio   = celula.dataset.horario;
    const original = conteudo.textContent;
    const dificuldadeOriginal = celula.dataset.dificuldade || 'Médio';

    // Cria o seletor de dificuldade e injeta antes do texto editável
    const seletor = document.createElement('select');
    seletor.className = 'seletor-dificuldade-inline';
    ['Fácil', 'Médio', 'Difícil'].forEach(nivel => {
        const opt = document.createElement('option');
        opt.value = nivel;
        opt.textContent = nivel;
        if (nivel === dificuldadeOriginal) opt.selected = true;
        seletor.appendChild(opt);
    });
    celula.insertBefore(seletor, conteudo);

    conteudo.focus();
    const sel = window.getSelection();
    const range = document.createRange();
    range.selectNodeContents(conteudo);
    range.collapse(false);
    sel.removeAllRanges();
    sel.addRange(range);

    const classesDificuldade = ['dificuldade-dificil', 'dificuldade-medio', 'dificuldade-facil'];
    const classePorNivel = { 'Difícil': 'dificuldade-dificil', 'Médio': 'dificuldade-medio', 'Fácil': 'dificuldade-facil' };

    // Evita salvar duas vezes (ex: Enter disparando salvar() e, em seguida, o focusout também disparando)
    let jaSalvo = false;

    const salvar = async () => {
        if (jaSalvo) return;
        jaSalvo = true;

        conteudo.removeAttribute('contenteditable');
        celula.classList.remove('editando');
        celula.setAttribute('draggable', 'true');
        celula.removeEventListener('focusout', aoPerderFoco);
        conteudo.removeEventListener('keydown', aoTeclar);

        const novoTexto = conteudo.textContent.trim();
        const novaDificuldade = seletor.value;
        seletor.remove();

        // Nada mudou, evita chamada desnecessária
        if (novoTexto === original.trim() && novaDificuldade === dificuldadeOriginal) return;

        try {
            const data = await chamarAtualizarBloco({
                acao: 'editar', dia, inicio, materia: novoTexto, dificuldade: novaDificuldade
            });
            if (!data.sucesso) throw new Error(data.erro || 'Falha ao editar.');

            if (novoTexto === '') {
                celula.classList.add('vazia');
                [...classesDificuldade, 'concluido'].forEach(c => celula.classList.remove(c));
                celula.dataset.dificuldade = 'Médio';
                const btnConcluir = celula.querySelector('.btn-concluir');
                if (btnConcluir) btnConcluir.remove();
            } else {
                celula.classList.remove('vazia');
                classesDificuldade.forEach(c => celula.classList.remove(c));
                celula.classList.add(classePorNivel[novaDificuldade]);
                celula.dataset.dificuldade = novaDificuldade;
            }
        } catch (e) {
            conteudo.textContent = original; // Reverte visualmente se o servidor recusou
            mostrarAlerta('Não foi possível salvar essa edição. Tente novamente.');
        }
    };

    const aoTeclar = (ev) => {
        if (ev.key === 'Enter')  { ev.preventDefault(); salvar(); }
        if (ev.key === 'Escape') { conteudo.textContent = original; salvar(); }
    };

    // Só salva quando o foco sai da célula inteira — não quando ele apenas
    // migra do texto pro <select> (ou do <select> de volta pro texto).
    // ev.relatedTarget é o elemento que RECEBEU o foco; se ainda estiver
    // dentro da célula, ainda estamos editando.
    const aoPerderFoco = (ev) => {
        if (celula.contains(ev.relatedTarget)) return;
        salvar();
    };

    celula.addEventListener('focusout', aoPerderFoco);
    conteudo.addEventListener('keydown', aoTeclar);
}

/*  Arrastar e soltar (troca/move blocos entre slots) */
let celulaArrastada = null;

function iniciarArrastarSoltar() {
    document.querySelectorAll('.celula-cronograma').forEach(celula => {

        celula.addEventListener('dragstart', (e) => {
            if (celula.classList.contains('editando')) { e.preventDefault(); return; }
            celulaArrastada = celula;
            celula.classList.add('arrastando');
        });

        celula.addEventListener('dragend', () => {
            celula.classList.remove('arrastando');
            celulaArrastada = null;
        });

        celula.addEventListener('dragover', e => {
            e.preventDefault();
            if (celulaArrastada && celulaArrastada !== celula) celula.classList.add('sobre-alvo');
        });

        celula.addEventListener('dragleave', () => celula.classList.remove('sobre-alvo'));

        celula.addEventListener('drop', async e => {
            e.preventDefault();
            celula.classList.remove('sobre-alvo');
            if (!celulaArrastada || celulaArrastada === celula) return;

            const origem = celulaArrastada;
            const destino = celula;

            try {
                const data = await chamarAtualizarBloco({
                    acao: 'trocar',
                    dia1: origem.dataset.dia, inicio1: origem.dataset.horario,
                    dia2: destino.dataset.dia, inicio2: destino.dataset.horario,
                });
                if (!data.sucesso) throw new Error(data.erro || 'Falha ao trocar.');

                trocarConteudoVisual(origem, destino);
            } catch (err) {
                mostrarAlerta('Não foi possível mover o bloco. Tente novamente.');
            }
        });
    });
}

function trocarConteudoVisual(celulaA, celulaB) {
    const classesEstado = ['dificuldade-dificil', 'dificuldade-medio', 'dificuldade-facil', 'vazia', 'concluido'];

    const htmlA = celulaA.innerHTML;
    const htmlB = celulaB.innerHTML;
    const estadoA = classesEstado.filter(c => celulaA.classList.contains(c));
    const estadoB = classesEstado.filter(c => celulaB.classList.contains(c));
    const dificuldadeA = celulaA.dataset.dificuldade;
    const dificuldadeB = celulaB.dataset.dificuldade;

    celulaA.innerHTML = htmlB;
    celulaB.innerHTML = htmlA;

    classesEstado.forEach(c => { celulaA.classList.remove(c); celulaB.classList.remove(c); });
    estadoB.forEach(c => celulaA.classList.add(c));
    estadoA.forEach(c => celulaB.classList.add(c));

    celulaA.dataset.dificuldade = dificuldadeB;
    celulaB.dataset.dificuldade = dificuldadeA;
}

/* Tratamento de eventos para os botões de cada célula  */
document.addEventListener('click', (e) => {
    const btnConcluir = e.target.closest('.btn-concluir');
    if (btnConcluir) { e.preventDefault(); alternarConcluido(btnConcluir); return; }

    const btnApagar = e.target.closest('.btn-apagar');
    if (btnApagar) { e.preventDefault(); apagarBloco(btnApagar); return; }

    const btnEditar = e.target.closest('.btn-editar');
    if (btnEditar) { e.preventDefault(); editarBloco(btnEditar); return; }
});

iniciarArrastarSoltar();
</script>

<?php include 'includes/footer.php'; ?>