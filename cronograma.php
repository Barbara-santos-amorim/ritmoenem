<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit();
}
$usuarioId = (int) $_SESSION['usuario_id'];
?>
<?php include 'includes/header.php'; ?>
<main>
    <div class="page-header">
        <h1>Cronograma de Estudos</h1>
    </div>
    
    <main class="container mt-2">

        <div id="alertas-cronograma"></div>

        <form method="POST" id="form-cronograma">
            <div class="row">
                <div class="col-md-6">
                    <h4>Informações do Cronograma</h4><br>
                    <div class="form-group">
                        <label>Nome do cronograma:</label>
                        <input type="text" name="nome" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Total de horas de estudo por dia (1 a 12):</label>
                        <input type="number" name="horas" min="1" max="12" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Tamanho do bloco (minutos):</label>
                        <select name="bloco_min" class="form-control" required>
                            <option value="" disabled selected>Selecione...</option>
                            <?php foreach ([30, 45, 60, 90, 120] as $opcao): ?>
                                <option value="<?= $opcao ?>"><?= $opcao ?> min</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Horário de início:</label>
                        <input type="time" name="horario_inicio" class="form-control" required>
                    </div>

                    <div class="form-group">
                        <label>Horário de fim:</label>
                        <input type="time" name="horario_fim" class="form-control" required>
                    </div>
                </div>

                <h4>Dias da Semana</h4>
                <div class="container-dias" id="botoes-dias">
                    <?php
                    $rotulosDias = [
                        'seg' => 'Segunda', 'ter' => 'Terça',  'qua' => 'Quarta',
                        'qui' => 'Quinta',  'sex' => 'Sexta',  'sab' => 'Sábado', 'dom' => 'Domingo',
                    ];
                    foreach ($rotulosDias as $id => $rotulo): ?>
                        <button type="button" class="botao-dia" data-id-dia="<?= $id ?>"><?= $rotulo ?></button>
                        <input type="checkbox" class="form-check-input checkbox-dia"
                               name="dias[]" value="<?= $id ?>" id="dia-<?= $id ?>" style="display:none;">
                    <?php endforeach; ?>
                </div>

                <div class="form-group mt-2">
                    <h4>Prioridade</h4>
                    <p>(define a ordem das matérias, não a quantidade)</p>
                    <div class="d-flex flex-wrap" style="gap:8px;">
                        <?php foreach ($rotulosDias as $id => $rotulo): ?>
                            <div style="margin-right:8px; min-width:110px;">
                                <small><?= $rotulo ?></small>
                                <select name="prioridades[<?= $id ?>]" class="form-control">
                                    <option value="0" selected>—</option>
                                    <?php foreach ([1, 2, 3] as $p): ?>
                                        <option value="<?= $p ?>">P<?= $p ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <h4>Matérias e Dificuldade</h4><br>

                <div id="container-materias">
                    <div class="linha-materia">
                        <input type="text" name="materias[]" class="form-control" placeholder="Nome da matéria">
                        <div class="opcoes-dificuldade">
                            <div class="form-check">
                                <input type="radio" name="dificuldades[0]" value="Difícil" class="form-check-input" id="dificil-0">
                                <label for="dificil-0" class="form-check-label">Difícil</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" name="dificuldades[0]" value="Médio" class="form-check-input" id="medio-0" checked>
                                <label for="medio-0" class="form-check-label">Médio</label>
                            </div>
                            <div class="form-check">
                                <input type="radio" name="dificuldades[0]" value="Fácil" class="form-check-input" id="facil-0">
                                <label for="facil-0" class="form-check-label">Fácil</label>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="button" id="adicionar-materia">+ Adicionar Matéria</button>
            </div>

            <div class="text-center mt-4">
                <button type="submit" id="btnGerarCronograma">Gerar Cronograma</button>
            </div>
        </form>

        <div class="mt-5 table-responsive" id="area-cronograma"></div>

        <div class="text-center mt-4 acoes-cronograma" id="acoes-cronograma" style="display:none;">
            <button type="button" id="btnSalvarCronograma">💾 Salvar Cronograma</button>
            <button type="button" id="btnDescartarCronograma">🗑️ Descartar</button>
        </div>

    </main>
</main>

<script>
/* O cronograma gerado só existe aqui, em memória do Frontend, até o clique em "Salvar Cronograma". 
Antes disso, nenhuma requisição de escrita é feita — nem api/salvar_cronograma.php, nem api/atualizar_bloco.php (que só faz
sentido depois de existir um cronograma_id no banco).
*/
let estadoCronograma = null; // { nome, horas, blocoMin, horarioInicio, horarioFim, dias, prioridades }
let cronogramaFoiSalvo = false;
let contadorMaterias = 1;

function reindexarRadios() {
    const linhas = document.querySelectorAll('#container-materias .linha-materia');
    linhas.forEach((linha, indice) => {
        linha.querySelectorAll('input[type="radio"]').forEach(radio => {
            const idAntigo = radio.id;
            const prefixo  = idAntigo.replace(/-\d+$/, '');
            const idNovo   = `${prefixo}-${indice}`;
            const rotulo = linha.querySelector(`label[for="${idAntigo}"]`);
            if (rotulo) rotulo.setAttribute('for', idNovo);
            radio.id   = idNovo;
            radio.name = `dificuldades[${indice}]`;
        });
    });
    contadorMaterias = linhas.length;
}

function criarLinhaMateria(idx) {
    const div = document.createElement('div');
    div.className = 'linha-materia';
    div.innerHTML = `
        <input type="text" name="materias[]" class="form-control" placeholder="Nome da matéria">
        <div class="opcoes-dificuldade">
            <div class="form-check">
                <input type="radio" name="dificuldades[${idx}]" value="Difícil" class="form-check-input" id="dificil-${idx}">
                <label for="dificil-${idx}" class="form-check-label">Difícil</label>
            </div>
            <div class="form-check">
                <input type="radio" name="dificuldades[${idx}]" value="Médio" class="form-check-input" id="medio-${idx}" checked>
                <label for="medio-${idx}" class="form-check-label">Médio</label>
            </div>
            <div class="form-check">
                <input type="radio" name="dificuldades[${idx}]" value="Fácil" class="form-check-input" id="facil-${idx}">
                <label for="facil-${idx}" class="form-check-label">Fácil</label>
            </div>
        </div>`;

    const btnRemover = document.createElement('button');
    btnRemover.type        = 'button';
    btnRemover.className   = 'btn btn-danger btn-sm remover-materia';
    btnRemover.textContent = 'Remover';
    btnRemover.addEventListener('click', () => { div.remove(); reindexarRadios(); });

    div.querySelector('.opcoes-dificuldade').appendChild(btnRemover);
    return div;
}

function mostrarAlertas(mensagens, tipo = 'danger') {
    const area = document.getElementById('alertas-cronograma');
    if (!mensagens || !mensagens.length) { area.innerHTML = ''; return; }
    area.innerHTML = `<div class="alert alert-${tipo}"><ul class="mb-0">`
        + mensagens.map(m => `<li>${m}</li>`).join('')
        + '</ul></div>';
}

// Geração: chama o backend só para CALCULAR (api/gerar_cronograma.php)
async function gerarCronograma(evento) {
    evento.preventDefault();
    mostrarAlertas([]);

    const form = document.getElementById('form-cronograma');
    const formData = new FormData(form);

    const btn = document.getElementById('btnGerarCronograma');
    const textoOriginal = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Gerando...';

    try {
        const resp = await fetch('api/gerar_cronograma.php', { method: 'POST', body: formData });
        const data = await resp.json();

        document.getElementById('area-cronograma').innerHTML = data.html || '';

        if (!data.ok) {
            mostrarAlertas(data.erros || ['Não foi possível gerar o cronograma.']);
            document.getElementById('acoes-cronograma').style.display = 'none';
            estadoCronograma = null;
            return;
        }

        estadoCronograma = data.meta;
        cronogramaFoiSalvo = false;
        document.getElementById('acoes-cronograma').style.display = '';
        resetarBotaoSalvar();
        iniciarArrastarSoltar();
    } catch (e) {
        mostrarAlertas(['Erro de comunicação ao gerar o cronograma. Tente novamente.']);
    } finally {
        btn.disabled = false;
        btn.textContent = textoOriginal;
    }
}

// Soma minutos a "HH:MM" (para calcular o horário de fim de cada bloco) 
function somarMinutos(horaTexto, minutos) {
    const [h, m] = horaTexto.split(':').map(Number);
    const total = h * 60 + m + minutos;
    const hh = String(Math.floor((total % 1440) / 60)).padStart(2, '0');
    const mm = String(total % 60).padStart(2, '0');
    return `${hh}:${mm}`;
}

/* Lê o estado ATUAL da tabela (depois de drag/edit/apagar) direto do DOM.
Usa exatamente os atributos que includes/tabela_cronograma.php já gera: data-dia, data-horario, e a classe dificuldade-* de cada célula. */
function coletarBlocosDoDom(blocoMin) {
    const blocos = [];
    document.querySelectorAll('.celula-cronograma').forEach(celula => {
        if (celula.classList.contains('vazia')) return;

        const conteudo = celula.querySelector('.conteudo-celula');
        const materia = (conteudo?.textContent ?? '').trim();
        if (materia === '') return;

        const dia    = celula.dataset.dia;
        const inicio = celula.dataset.horario;

        let dificuldade = 'Médio';
        if (celula.classList.contains('dificuldade-dificil')) dificuldade = 'Difícil';
        else if (celula.classList.contains('dificuldade-facil')) dificuldade = 'Fácil';

        blocos.push({
            dia,
            inicio,
            fim: somarMinutos(inicio, blocoMin),
            materia,
            dificuldade,
        });
    });
    return blocos;
}

 // Botão Salvar: chama o api/salvar_cronograma.php que vocês já têm. É o único ponto do fluxo de criação que grava no banco de dados.
function resetarBotaoSalvar() {
    const btn = document.getElementById('btnSalvarCronograma');
    btn.disabled = false;
    btn.classList.remove('salvo');
    btn.textContent = '💾 Salvar Cronograma';
}

async function salvarCronogramaNoServidor() {
    if (!estadoCronograma) return;

    const btn = document.getElementById('btnSalvarCronograma');
    btn.disabled = true;
    btn.classList.add('salvo');
    btn.textContent = '⏳ Salvando...';

    const payload = {
        nome:            estadoCronograma.nome,
        horas:           estadoCronograma.horas, // horas por dia — mesma semântica usada na geração
        bloco_min:       estadoCronograma.blocoMin,
        horario_inicio:  estadoCronograma.horarioInicio,
        horario_fim:     estadoCronograma.horarioFim,
        dias:            estadoCronograma.dias,
        prioridades:     estadoCronograma.prioridades,
        blocos:          coletarBlocosDoDom(estadoCronograma.blocoMin),
    };

    try {
        const resp = await fetch('api/salvar_cronograma.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await resp.json();

        if (!data.sucesso) {
            mostrarAlertas([data.erro || 'Não foi possível salvar o cronograma.']);
            resetarBotaoSalvar();
            return;
        }

        btn.textContent = '✓ Salvo!';
        btn.classList.add('salvo');
        cronogramaFoiSalvo = true;
        mostrarAlertas(['Cronograma salvo com sucesso!'], 'success');

        setTimeout(() => {
            window.location.href = 'visualizar.php?id=' + encodeURIComponent(data.cronograma_id);
        }, 800);
    } catch (e) {
        mostrarAlertas(['Erro de comunicação ao salvar. Tente novamente.']);
        resetarBotaoSalvar();
    }
}

// Botão Descartar: 100% local, nunca chama o servidor 
function descartarRascunho() {
    if (!window.confirm('Deseja descartar este rascunho? As alterações serão perdidas.')) {
        return;
    }
    estadoCronograma = null;
    document.getElementById('area-cronograma').innerHTML = '';
    document.getElementById('acoes-cronograma').style.display = 'none';
    mostrarAlertas([]);
    window.location.href = document.referrer || 'estudante.php';
}

function iniciarInterface() {
    const form = document.getElementById('form-cronograma');
    if (form) form.addEventListener('submit', gerarCronograma);

    const btnAdicionar = document.getElementById('adicionar-materia');
    if (btnAdicionar) {
        btnAdicionar.addEventListener('click', () => {
            const container = document.getElementById('container-materias');
            if (container) {
                container.appendChild(criarLinhaMateria(contadorMaterias));
                contadorMaterias++;
            }
        });
    }

    const containerDias = document.getElementById('botoes-dias');
    if (containerDias) {
        containerDias.addEventListener('click', (evento) => {
            const botao = evento.target.closest('.botao-dia');
            if (!botao) return;
            evento.preventDefault();
            const idDia    = botao.getAttribute('data-id-dia');
            const checkbox = document.getElementById('dia-' + idDia);
            if (checkbox) {
                checkbox.checked = !checkbox.checked;
                botao.classList.toggle('selecionado', checkbox.checked);
            }
        });
    }

    const btnSalvar = document.getElementById('btnSalvarCronograma');
    if (btnSalvar) btnSalvar.addEventListener('click', salvarCronogramaNoServidor);

    const btnDescartar = document.getElementById('btnDescartarCronograma');
    if (btnDescartar) btnDescartar.addEventListener('click', descartarRascunho);

    // Avisa antes de sair da página com um rascunho ainda não salvo
    window.addEventListener('beforeunload', (e) => {
        if (estadoCronograma && !cronogramaFoiSalvo) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}

if (document.readyState !== 'loading') {
    iniciarInterface();
} else {
    document.addEventListener('DOMContentLoaded', iniciarInterface);
}
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
            if (celulaArrastada && celulaArrastada !== celula) {
                celula.classList.add('sobre-alvo');
            }
        });

        celula.addEventListener('dragleave', () => celula.classList.remove('sobre-alvo'));

        celula.addEventListener('drop', e => {
            e.preventDefault();
            celula.classList.remove('sobre-alvo');
            if (!celulaArrastada || celulaArrastada === celula) return;

            const classesDificuldade = ['dificuldade-dificil', 'dificuldade-medio', 'dificuldade-facil'];
            const obterDif = el => classesDificuldade.find(c => el.classList.contains(c)) || null;

            const conteudoAlvo      = celula.querySelector('.conteudo-celula').innerHTML;
            const conteudoArrastado = celulaArrastada.querySelector('.conteudo-celula').innerHTML;
            const difAlvo           = obterDif(celula);
            const difArrastada      = obterDif(celulaArrastada);
            const vaziaAlvo         = celula.classList.contains('vazia');
            const vaziaArrastada    = celulaArrastada.classList.contains('vazia');

            celula.querySelector('.conteudo-celula').innerHTML      = conteudoArrastado;
            celulaArrastada.querySelector('.conteudo-celula').innerHTML = conteudoAlvo;

            classesDificuldade.forEach(c => { celula.classList.remove(c); celulaArrastada.classList.remove(c); });
            celula.classList.toggle('vazia', vaziaArrastada);
            celulaArrastada.classList.toggle('vazia', vaziaAlvo);
            if (difArrastada) celula.classList.add(difArrastada);
            if (difAlvo)      celulaArrastada.classList.add(difAlvo);
        });
    });
}

// Apagar ou editar célula — também sem alteração da lógica original
document.addEventListener('click', (e) => {

    if (e.target.closest('.btn-apagar')) {
        e.preventDefault();
        const celula = e.target.closest('.celula-cronograma');
        if (!celula) return;
        if (!window.confirm('Apagar este bloco de estudo?')) return;

        celula.querySelector('.conteudo-celula').innerHTML = '';
        ['dificuldade-dificil', 'dificuldade-medio', 'dificuldade-facil'].forEach(c => celula.classList.remove(c));
        celula.classList.add('vazia');
        celula.classList.remove('editando');
        celula.setAttribute('draggable', 'true');
        return;
    }

    if (e.target.closest('.btn-editar')) {
        e.preventDefault();
        const celula = e.target.closest('.celula-cronograma');
        if (!celula) return;

        const conteudo = celula.querySelector('.conteudo-celula');
        celula.classList.add('editando');
        celula.setAttribute('draggable', 'false');
        conteudo.setAttribute('contenteditable', 'true');
        conteudo.focus();

        const sel = window.getSelection();
        const range = document.createRange();
        range.selectNodeContents(conteudo);
        range.collapse(false);
        sel.removeAllRanges();
        sel.addRange(range);

        const salvarEdicao = () => {
            conteudo.removeAttribute('contenteditable');
            celula.classList.remove('editando');
            celula.setAttribute('draggable', 'true');
            if (conteudo.textContent.trim() === '') {
                celula.classList.add('vazia');
            } else {
                celula.classList.remove('vazia');
            }
            conteudo.removeEventListener('blur',    salvarEdicao);
            conteudo.removeEventListener('keydown', aoTeclar);
        };
        const aoTeclar = (ev) => {
            if (ev.key === 'Enter')  { ev.preventDefault(); salvarEdicao(); }
            if (ev.key === 'Escape') { conteudo.textContent = conteudo.dataset.original || ''; salvarEdicao(); }
        };

        conteudo.dataset.original = conteudo.textContent;
        conteudo.addEventListener('blur',    salvarEdicao,  { once: true });
        conteudo.addEventListener('keydown', aoTeclar);
        return;
    }
});
</script>
<?php include 'includes/footer.php'; ?>