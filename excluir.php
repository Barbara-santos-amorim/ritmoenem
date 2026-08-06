<?php
declare(strict_types=1);
session_start();
if (!isset($_SESSION['usuario_id'])) {
    header("Location: home.php");
    exit();
}
require_once "conexao.php";
$usuarioId = (int)$_SESSION['usuario_id'];
$cronogramaId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($cronogramaId <= 0) {
    header("Location: estudante.php");
    exit();
}

/*Verifica se o cronograma pertence ao usuário.*/
$stmt = $conn->prepare("SELECT id
FROM cronogramas
WHERE id = ?
AND usuario_id = ?
");

$stmt->bind_param("ii",$cronogramaId,$usuarioId);
$stmt->execute();

if($stmt->get_result()->num_rows==0){
    header("Location: estudante.php");
    exit();
}

/*Apaga primeiro os blocos.*/

$stmt=$conn->prepare("DELETE FROM cronograma_blocos
WHERE cronograma_id=?
");

$stmt->bind_param("i",$cronogramaId);
$stmt->execute();

/*Apaga o cronograma.*/

$stmt=$conn->prepare("DELETE FROM cronogramas
WHERE id=?
");

$stmt->bind_param("i",$cronogramaId);
$stmt->execute();

header("Location: estudante.php");
exit();
?> 