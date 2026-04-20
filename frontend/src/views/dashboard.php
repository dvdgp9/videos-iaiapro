<?php
/** @var array $user */
use App\Http\View;
?>
<div class="card">
    <h1>Hola, <?= View::e($user['name'] ?: $user['email']) ?></h1>
    <p class="muted">Aquí crearás y gestionarás tus proyectos de vídeo.</p>

    <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem; margin-top: 1.5rem;">
        <div style="padding:1rem; border:1px solid var(--border); border-radius:8px;">
            <div style="color:var(--muted); font-size:.85rem;">Minutos de render</div>
            <div style="font-size:1.5rem; font-weight:700;">
                <?= (int) ($user['used_render_seconds'] / 60) ?> / <?= (int) ($user['quota_render_seconds'] / 60) ?>
            </div>
        </div>
        <div style="padding:1rem; border:1px solid var(--border); border-radius:8px;">
            <div style="color:var(--muted); font-size:.85rem;">Almacenamiento (MB)</div>
            <div style="font-size:1.5rem; font-weight:700;">
                <?= (int) $user['used_storage_mb'] ?> / <?= (int) $user['quota_storage_mb'] ?>
            </div>
        </div>
    </div>

    <div style="margin-top:2rem; display:flex; gap:1rem;">
        <a class="btn" href="/projects">Ver mis proyectos</a>
        <a class="btn secondary" href="/projects/new">+ Nuevo proyecto</a>
    </div>
</div>
