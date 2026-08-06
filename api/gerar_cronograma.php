<?php
declare(strict_types=1);
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/geracao_cronograma.php';
require_once __DIR__ . '/../includes/tabela_cronograma.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erros' => ['Não autenticado.']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erros' => ['Método não permitido.']]);
    exit();
}

$labelDias = ['seg' => 'Seg', 'ter' => 'Ter', 'qua' => 'Qua', 'qui' => 'Qui',
              'sex' => 'Sex', 'sab' => 'Sáb', 'dom' => 'Dom'];

$dados = validarEntradaCronograma($_POST);

if (!empty($dados['erros'])) {
    $html = renderizarTabelaCronograma(
        [], [], [], $labelDias, '', 0, 0, '', '', '', $dados['erros'], []
    );
    echo json_encode(['ok' => false, 'html' => $html, 'erros' => $dados['erros']]);
    exit();
}

$resultado = gerarCronogramaEmMemoria($dados);

if ($resultado['erroCapacidade'] !== null) {
    $html = renderizarTabelaCronograma(
        [], [], [], $labelDias, '', 0, 0, '', '',
        '', [$resultado['erroCapacidade']], $resultado['sugestoesHorario']
    );
    echo json_encode(['ok' => false, 'html' => $html, 'erros' => [$resultado['erroCapacidade']]]);
    exit();
}

$cronograma = $resultado['cronograma'];

$mensagemDia = [
    'seg' => 'Segunda-feira: Vamos começar a semana com tudo!',
    'ter' => 'Terça-feira: Continue firme nos estudos!',
    'qua' => 'Quarta-feira: Já estamos no meio da semana!',
    'qui' => 'Quinta-feira: A semana está quase acabando!',
    'sex' => 'Sexta-feira: Dia de revisar tudo que estudou!',
    'sab' => 'Sábado: Ótimo dia para sessões mais longas!',
    'dom' => 'Domingo: Revisão e descanso!',
];
$diasValidos = ['seg', 'ter', 'qua', 'qui', 'sex', 'sab', 'dom'];
$hoje        = $diasValidos[(int)(new DateTime())->format('N') - 1];
$motivacao   = $mensagemDia[$hoje] ?? '';

$linhas = [];
$cursor = clone $dados['dtInicio'];
while ($cursor < $dados['dtFim']) {
    $horaInicio = clone $cursor;
    $cursor->modify("+{$dados['blocoMin']} minutes");
    $linhas[] = ['inicio' => formatarHora($horaInicio), 'fim' => formatarHora($cursor)];
}

$porChave = [];
foreach ($cronograma as $d => $itens) {
    foreach ($itens as $item) {
        $porChave[$d . '|' . $item['inicio']] = $item;
    }
}

$html = renderizarTabelaCronograma(
    $dados['dias'],
    $linhas,
    $porChave,
    $labelDias,
    $dados['nome'],
    $dados['horas'],
    $dados['blocoMin'],
    $dados['horarioInicio'],
    $dados['horarioFim'],
    $motivacao,
    [],
    []
);

// Nada foi salvo no banco ainda. O array 'meta' e os dados da tabela só serão enviados para api/salvar_cronograma.php se o usuário confirmar a gravação.
echo json_encode([
    'ok'   => true,
    'html' => $html,
    'meta' => [
        'nome'          => $dados['nome'],
        'horas'         => $dados['horas'],
        'blocoMin'      => $dados['blocoMin'],
        'horarioInicio' => $dados['horarioInicio'],
        'horarioFim'    => $dados['horarioFim'],
        'dias'          => $dados['dias'],
        'prioridades'   => $dados['prioridades'],
    ],
], JSON_UNESCAPED_UNICODE);