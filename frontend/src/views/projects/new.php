<?php
/** @var array $templates */
use App\Http\View;
?>
<link rel="stylesheet" href="/assets/app.css">

<div class="toolbar">
    <h1>Nuevo proyecto</h1>
    <a class="btn secondary" href="/projects">Cancelar</a>
</div>

<div class="card">
    <form id="new-project-form">
        <div class="form-grid">
            <div class="field">
                <label for="name">Nombre del proyecto</label>
                <input type="text" id="name" name="name" required maxlength="160" placeholder="Ej. Promo lanzamiento abril">
            </div>
            <div class="field">
                <label for="template">Plantilla</label>
                <select id="template" name="template_id" required>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= View::e($t['id']) ?>"
                                data-formats='<?= View::e(json_encode($t['formats'] ?? [], JSON_UNESCAPED_UNICODE)) ?>'
                                data-default-format="<?= View::e($t['default_format'] ?? '') ?>">
                            <?= View::e($t['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="hint" id="template-desc"></div>
            </div>
            <div class="field">
                <label for="format">Formato</label>
                <select id="format" name="format" required></select>
            </div>
        </div>

        <div id="form-error" class="alert err hidden"></div>

        <div style="margin-top:1.5rem; display:flex; gap:1rem;">
            <button type="submit" class="btn">Crear y editar</button>
            <a href="/projects" class="btn secondary">Cancelar</a>
        </div>
    </form>
</div>

<script>
const TEMPLATES = <?= json_encode($templates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/app.js"></script>
<script>
(function () {
    const sel = document.getElementById('template');
    const fmt = document.getElementById('format');
    const desc = document.getElementById('template-desc');
    const form = document.getElementById('new-project-form');
    const err  = document.getElementById('form-error');

    function refreshTemplate() {
        const opt = sel.options[sel.selectedIndex];
        const formats = JSON.parse(opt.dataset.formats || '[]');
        const defFmt = opt.dataset.defaultFormat || formats[0] || '';
        fmt.innerHTML = '';
        formats.forEach(f => {
            const o = document.createElement('option');
            o.value = f; o.textContent = f;
            if (f === defFmt) o.selected = true;
            fmt.appendChild(o);
        });
        const meta = TEMPLATES.find(t => t.id === opt.value);
        desc.textContent = meta ? (meta.description || '') : '';
    }

    sel.addEventListener('change', refreshTemplate);
    refreshTemplate();

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        err.classList.add('hidden');
        const payload = {
            name: document.getElementById('name').value.trim(),
            template_id: sel.value,
            format: fmt.value,
            content: {},
            style: {},
        };
        try {
            const r = await VI.post('/api/projects', payload);
            location.href = '/projects/' + r.project.id;
        } catch (ex) {
            err.textContent = 'No se pudo crear el proyecto: ' + (ex.message || 'error');
            if (ex.data && ex.data.fields) {
                err.textContent += ' — ' + JSON.stringify(ex.data.fields);
            }
            err.classList.remove('hidden');
        }
    });
})();
</script>
