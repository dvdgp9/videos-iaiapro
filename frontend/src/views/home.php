<?php
use App\Auth\Auth;
$user = Auth::currentUser();
?>
<section class="hero-wrap">
    <div class="hero-main">
        <h1>Genera vídeos listos para publicar</h1>
        <p class="muted">Crea piezas en minutos con plantillas Hyperframes, edición guiada y render final en MP4.</p>
        <div class="hero-actions">
            <?php if ($user): ?>
                <a class="btn" href="/dashboard">Ir al panel</a>
                <a class="btn secondary" href="/projects/new">Nuevo proyecto</a>
            <?php else: ?>
                <a class="btn" href="/register">Crear cuenta</a>
                <a class="btn secondary" href="/login">Tengo cuenta</a>
            <?php endif; ?>
        </div>
    </div>
    <aside class="hero-side">
        <span class="hero-chip">Flujo</span>
        <p>1. Define contenido.</p>
        <p>2. Ajusta formato y estilo.</p>
        <p>3. Lanza render y descarga.</p>
    </aside>
</section>

<?php if (!$user): ?>
    <div class="card" style="margin-top:1rem">
        <p class="muted" style="margin-bottom:0">
            El panel incluye gestión de proyectos, subida de assets por rol y seguimiento de estado de render en tiempo real.
        </p>
    </div>
<?php endif; ?>
</div>
