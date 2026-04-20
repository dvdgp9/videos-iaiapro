<?php
/** @var array $user */
/** @var array $projects */
use App\Http\View;
?>
<link rel="stylesheet" href="/assets/app.css">

<div class="toolbar">
    <h1>Tus proyectos</h1>
    <a class="btn" href="/projects/new">+ Nuevo proyecto</a>
</div>

<?php if (empty($projects)): ?>
    <div class="empty">
        <p>Aún no tienes ningún proyecto.</p>
        <a class="btn" href="/projects/new">Crear el primero</a>
    </div>
<?php else: ?>
    <div class="grid">
        <?php foreach ($projects as $p): ?>
            <div class="item">
                <a href="/projects/<?= (int) $p['id'] ?>"><?= View::e($p['name']) ?></a>
                <div class="meta">
                    <?= View::e($p['template_id']) ?> · <?= View::e($p['format']) ?>
                </div>
                <div class="meta">
                    Actualizado: <?= View::e(substr((string) $p['updated_at'], 0, 16)) ?>
                </div>
                <div>
                    <?php
                        $st = (string) ($p['status'] ?? 'draft');
                        $cls = match ($st) {
                            'ready'    => 'ok',
                            'failed'   => 'err',
                            'rendering','queued' => 'run',
                            default    => '',
                        };
                    ?>
                    <span class="badge <?= $cls ?>"><?= View::e($st) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
