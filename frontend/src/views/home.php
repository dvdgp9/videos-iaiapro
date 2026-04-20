<?php
use App\Auth\Auth;
$user = Auth::currentUser();
?>
<div class="card">
    <h1>Genera vídeos con plantillas Hyperframes</h1>
    <p class="muted">MVP v1 · Login, plantillas, render a MP4 y descarga.</p>
    <?php if ($user): ?>
        <a class="btn" href="/dashboard">Ir al panel</a>
    <?php else: ?>
        <a class="btn" href="/register">Crear cuenta</a>
        <a class="btn secondary" href="/login" style="margin-left:.5rem">Tengo cuenta</a>
    <?php endif; ?>
</div>
