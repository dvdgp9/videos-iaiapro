<?php
/** @var array $user */
use App\Http\View;
?>
<div class="card">
    <h1>Hola, <?= View::e($user['name'] ?: $user['email']) ?></h1>
    <p class="muted">Aquí controlas tus proyectos de vídeo, cuota disponible y el siguiente render.</p>

    <div class="stats-grid">
        <div class="stat-box">
            <div class="label">Minutos de render</div>
            <div class="value">
                <?= (int) ($user['used_render_seconds'] / 60) ?> / <?= (int) ($user['quota_render_seconds'] / 60) ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="label">Almacenamiento (MB)</div>
            <div class="value">
                <?= (int) $user['used_storage_mb'] ?> / <?= (int) $user['quota_storage_mb'] ?>
            </div>
        </div>
    </div>

    <div class="hero-actions" style="margin-top:1.4rem;">
        <a class="btn" href="/projects">Ver mis proyectos</a>
        <a class="btn secondary" href="/projects/new">+ Nuevo proyecto</a>
    </div>
</div>
