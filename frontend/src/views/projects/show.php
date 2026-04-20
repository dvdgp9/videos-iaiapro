<?php
/** @var array $project */
/** @var array $template */
use App\Http\View;
?>
<div class="toolbar">
    <h1><?= View::e($project['name']) ?></h1>
    <div style="display:flex; gap:.5rem;">
        <a href="/projects" class="btn secondary small">← Proyectos</a>
        <button type="button" class="btn small" id="save-btn">Guardar</button>
        <button type="button" class="btn" id="render-btn">Renderizar</button>
    </div>
</div>

<div class="form-grid project-shell">
    <div>
        <div class="section-title">Vista previa</div>
        <?php
            $fmt = (string) $project['format'];
            $cls = $fmt === '9:16' ? 'portrait' : ($fmt === '1:1' ? 'square' : '');
        ?>
        <div class="preview <?= $cls ?>" id="preview">
            <div class="placeholder" id="preview-placeholder">Aún no hay render.<br>Pulsa <strong>Renderizar</strong> para generar un vídeo.</div>
            <video id="preview-video" class="hidden" controls playsinline></video>
        </div>
        <div class="render-status" id="render-status">
            <span class="dot"></span>
            <span id="render-status-text">Listo para renderizar.</span>
        </div>
        <div class="progress hidden" id="render-progress"><div></div></div>
    </div>

    <div>
        <div class="section-title">Contenido</div>
        <div id="fields-content"></div>

        <?php if (!empty($template['style_fields'])): ?>
            <div class="section-title">Estilo</div>
            <div id="fields-style"></div>
        <?php endif; ?>

        <?php if (!empty($template['assets'])): ?>
            <div class="section-title">Imágenes</div>
            <div id="assets-panel"></div>
        <?php endif; ?>
    </div>
</div>

<div id="page-error" class="alert err hidden" style="margin-top:1rem"></div>
<div id="page-ok" class="alert ok hidden" style="margin-top:1rem"></div>

<script>
const PROJECT = <?= json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const TEMPLATE = <?= json_encode($template, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="/assets/app.js"></script>
<script>
(function () {
    const projectId = PROJECT.id;
    const contentEl = document.getElementById('fields-content');
    const styleEl   = document.getElementById('fields-style');
    const assetsEl  = document.getElementById('assets-panel');
    const pageErr = document.getElementById('page-error');
    const pageOk  = document.getElementById('page-ok');

    function showErr(msg) { pageErr.textContent = msg; pageErr.classList.remove('hidden'); pageOk.classList.add('hidden'); setTimeout(() => pageErr.classList.add('hidden'), 4000); }
    function showOk(msg) { pageOk.textContent = msg; pageOk.classList.remove('hidden'); pageErr.classList.add('hidden'); setTimeout(() => pageOk.classList.add('hidden'), 2500); }

    // --- Build content fields ----------------------------------------------
    (TEMPLATE.fields || []).forEach(f => {
        const wrap = document.createElement('div');
        wrap.className = 'field';
        const lbl = document.createElement('label');
        lbl.textContent = f.label + (f.required ? ' *' : '');
        lbl.htmlFor = 'f-' + f.key;
        wrap.appendChild(lbl);
        const input = (f.max_length && f.max_length > 80) ? document.createElement('textarea') : document.createElement('input');
        if (input.tagName === 'INPUT') input.type = 'text';
        input.id = 'f-' + f.key;
        input.name = f.key;
        input.maxLength = f.max_length || 255;
        if (f.placeholder) input.placeholder = f.placeholder;
        input.value = (PROJECT.content && PROJECT.content[f.key] != null) ? PROJECT.content[f.key] : (f.default || '');
        wrap.appendChild(input);
        if (f.max_length) {
            const hint = document.createElement('div');
            hint.className = 'hint';
            hint.textContent = 'Máx. ' + f.max_length + ' caracteres.';
            wrap.appendChild(hint);
        }
        contentEl.appendChild(wrap);
    });

    // --- Style fields -------------------------------------------------------
    if (styleEl) {
        (TEMPLATE.style_fields || []).forEach(f => {
            const wrap = document.createElement('div');
            wrap.className = 'field';
            const lbl = document.createElement('label');
            lbl.textContent = f.label;
            lbl.htmlFor = 's-' + f.key;
            wrap.appendChild(lbl);
            const input = document.createElement('input');
            input.type = 'color';
            input.id = 's-' + f.key;
            input.name = f.key;
            input.value = (PROJECT.style && PROJECT.style[f.key]) || f.default || '#000000';
            wrap.appendChild(input);
            styleEl.appendChild(wrap);
        });
    }

    // --- Save ---------------------------------------------------------------
    function collect() {
        const content = {};
        (TEMPLATE.fields || []).forEach(f => {
            const el = document.getElementById('f-' + f.key);
            if (el) content[f.key] = el.value;
        });
        const style = {};
        (TEMPLATE.style_fields || []).forEach(f => {
            const el = document.getElementById('s-' + f.key);
            if (el) style[f.key] = el.value;
        });
        return { content, style };
    }

    document.getElementById('save-btn').addEventListener('click', async () => {
        try {
            const data = collect();
            const r = await VI.put('/api/projects/' + projectId, data);
            Object.assign(PROJECT, r.project);
            showOk('Guardado.');
        } catch (ex) {
            showErr('Error al guardar: ' + (ex.message || 'error') + (ex.data && ex.data.fields ? ' · ' + JSON.stringify(ex.data.fields) : ''));
        }
    });

    // --- Assets panel -------------------------------------------------------
    let currentAssets = {};

    function renderAssets() {
        if (!assetsEl) return;
        assetsEl.innerHTML = '';
        (TEMPLATE.assets || []).forEach(a => {
            const role = a.role || a.key;
            const row = document.createElement('div');
            row.className = 'asset-row';
            const have = currentAssets[role];
            const thumb = document.createElement(have ? 'img' : 'div');
            thumb.className = 'thumb' + (have ? '' : ' empty');
            if (have) thumb.src = have.url; else thumb.textContent = 'sin imagen';
            row.appendChild(thumb);
            const info = document.createElement('div');
            info.className = 'info';
            info.innerHTML = '<div class="name">' + (a.label || role) + '</div>'
                + '<div class="meta">' + (have ? (have.original_name + ' · ' + Math.round(have.size_bytes/1024) + ' KB') : (a.accept || 'imagen')) + '</div>';
            row.appendChild(info);
            const actions = document.createElement('div');
            actions.className = 'actions';
            const inp = document.createElement('input');
            inp.type = 'file';
            inp.accept = a.accept || 'image/*';
            inp.style.display = 'none';
            inp.addEventListener('change', async () => {
                if (!inp.files[0]) return;
                const fd = new FormData();
                fd.append('role', role);
                fd.append('file', inp.files[0]);
                try {
                    const r = await VI.upload('/api/projects/' + projectId + '/assets', fd);
                    currentAssets[role] = r.asset;
                    renderAssets();
                    showOk('Subido.');
                } catch (ex) {
                    showErr('Error al subir: ' + (ex.message || 'error'));
                }
            });
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn small secondary';
            btn.textContent = have ? 'Reemplazar' : 'Subir';
            btn.addEventListener('click', () => inp.click());
            actions.appendChild(inp);
            actions.appendChild(btn);
            if (have) {
                const del = document.createElement('button');
                del.type = 'button';
                del.className = 'btn small danger';
                del.textContent = 'Quitar';
                del.addEventListener('click', async () => {
                    if (!confirm('¿Quitar esta imagen?')) return;
                    try {
                        await VI.del('/api/projects/' + projectId + '/assets/' + role);
                        delete currentAssets[role];
                        renderAssets();
                    } catch (ex) {
                        showErr('Error: ' + (ex.message || 'error'));
                    }
                });
                actions.appendChild(del);
            }
            row.appendChild(actions);
            assetsEl.appendChild(row);
        });
    }

    async function loadAssets() {
        try {
            const r = await VI.get('/api/projects/' + projectId + '/assets');
            currentAssets = {};
            (r.assets || []).forEach(a => { currentAssets[a.role] = a; });
            renderAssets();
        } catch (ex) { /* non-fatal */ }
    }
    if (assetsEl) loadAssets();

    // --- Render + status polling -------------------------------------------
    const statusEl = document.getElementById('render-status');
    const statusText = document.getElementById('render-status-text');
    const progEl = document.getElementById('render-progress');
    const progBar = progEl.querySelector('div');
    const video = document.getElementById('preview-video');
    const placeholder = document.getElementById('preview-placeholder');
    const renderBtn = document.getElementById('render-btn');
    let polling = null;

    function setStatus(cls, text) {
        statusEl.className = 'render-status ' + cls;
        statusText.textContent = text;
    }
    function setProgress(p) {
        if (p > 0) progEl.classList.remove('hidden');
        progBar.style.width = Math.max(0, Math.min(100, p)) + '%';
    }

    async function refreshStatus() {
        try {
            const s = await VI.get('/api/projects/' + projectId + '/status');
            const st = s.project && s.project.status;
            const rp = s.project && s.project.render_progress || 0;
            const rm = s.project && s.project.render_message || '';
            if (st === 'rendering' || st === 'queued') {
                setStatus('running', rm || 'Renderizando…');
                setProgress(rp);
            } else if (st === 'completed' || st === 'ready') {
                setStatus('ok', 'Render completado.');
                setProgress(100);
                if (s.render && s.render.video_url) {
                    video.src = s.render.video_url + '?v=' + Date.now();
                    video.classList.remove('hidden');
                    placeholder.classList.add('hidden');
                }
                stopPolling();
            } else if (st === 'failed') {
                setStatus('err', rm || 'Render fallido.');
                setProgress(0);
                stopPolling();
            } else {
                setStatus('', 'Listo para renderizar.');
            }
        } catch (ex) { /* ignore */ }
    }
    function startPolling() {
        refreshStatus();
        if (polling) clearInterval(polling);
        polling = setInterval(refreshStatus, 2000);
    }
    function stopPolling() {
        if (polling) { clearInterval(polling); polling = null; }
    }

    renderBtn.addEventListener('click', async () => {
        try {
            // Save first so the latest fields are used.
            await VI.put('/api/projects/' + projectId, collect());
            await VI.post('/api/projects/' + projectId + '/render', {});
            setStatus('running', 'Enviado al motor…');
            setProgress(5);
            startPolling();
        } catch (ex) {
            showErr('No se pudo lanzar el render: ' + (ex.message || 'error'));
        }
    });

    // On load: if there's already a render, show it.
    refreshStatus();
    if (PROJECT.status === 'rendering' || PROJECT.status === 'queued') startPolling();
})();
</script>
