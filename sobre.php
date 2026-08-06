<?php
$criadora = "Bárbara Santos Amorim";
$instituicao = "Estudante do IFMG Campus Sabará";
$slogan = "SEJA VOCÊ A SUA MAIOR EXPECTATIVA!";
$missao = "Nossa missão é tornar sua trajetória mais fácil e eficiente, com recursos inovadores e um suporte contínuo para garantir que você alcance seus objetivos acadêmicos.";
?>

<?php include 'includes/header.php'; ?>

<main>
<section class="page-header">
    <h1>Sobre Nós</h1>
    <p>
        Conheça a proposta do Ritmo Enem, nossa criadora e a missão de ajudar estudantes
        a seguirem um caminho mais organizado rumo ao ENEM.
    </p>
</section>

<section class="about-grid">
    
    <article class="about-card">
        <h2>Por que surgimos?</h2>
        <img src="img/help.png" alt="Está assim né?">
        <p>
            O Ritmo Enem nasceu como um trabalho final de curso para oferecer organização,
            rotina e apoio aos estudantes.
        </p>
    </article>

    <article class="about-card">
        <h2>Objetivos</h2>
        <img src="img/objetivos.png" alt="Objetivos">
        <p class="highlight"><?= $slogan ?></p>
        <p><?= $missao ?></p>
    </article>
</section>
</main>

<?php include 'includes/footer.php'; ?>