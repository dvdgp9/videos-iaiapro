<?php
/** @var string $content */
/** @var string $title */
use App\Http\View;
use App\Auth\Auth;

$title ??= 'videos.iaiapro.com';
$user = Auth::currentUser();
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= View::e($title) ?></title>
<style>
    :root { --bg:#0f172a; --card:#1e293b; --border:#334155; --text:#e2e8f0; --muted:#94a3b8; --accent:#f59e0b; --danger:#ef4444; --ok:#10b981; }
    * { box-sizing: border-box; }
    body { margin: 0; font-family: -apple-system, "Segoe UI", Inter, Roboto, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; }
    header { display: flex; justify-content: space-between; align-items: center; padding: 1rem 1.5rem; border-bottom: 1px solid var(--border); }
    header a.brand { color: var(--text); text-decoration: none; font-weight: 700; font-size: 1.1rem; }
    header nav { display: flex; gap: 1rem; align-items: center; font-size: .95rem; }
    header nav a { color: var(--muted); text-decoration: none; }
    header nav a:hover { color: var(--text); }
    main { max-width: 960px; margin: 2rem auto; padding: 0 1.5rem; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 2rem; }
    .card.narrow { max-width: 420px; margin: 3rem auto; }
    h1 { margin: 0 0 .5rem; font-size: 1.5rem; }
    p.muted { color: var(--muted); margin: 0 0 1.5rem; }
    label { display: block; font-size: .9rem; color: var(--muted); margin-bottom: .3rem; }
    input[type=text], input[type=email], input[type=password] {
        width: 100%; padding: .7rem .9rem; border-radius: 8px; border: 1px solid var(--border);
        background: #0b1222; color: var(--text); font-size: 1rem; margin-bottom: 1rem;
    }
    input:focus { outline: 2px solid var(--accent); outline-offset: -1px; }
    button, .btn {
        display: inline-block; padding: .7rem 1.2rem; border-radius: 8px; border: 0;
        background: var(--accent); color: #0b1222; font-weight: 700; font-size: 1rem; cursor: pointer;
        text-decoration: none;
    }
    button:hover { filter: brightness(1.05); }
    .btn.secondary { background: transparent; color: var(--muted); border: 1px solid var(--border); }
    .alert { padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; }
    .alert.err { background: rgba(239,68,68,.1); border: 1px solid var(--danger); color: #fecaca; }
    .alert.ok  { background: rgba(16,185,129,.1); border: 1px solid var(--ok); color: #bbf7d0; }
    .row-links { display: flex; justify-content: space-between; font-size: .9rem; margin-top: 1rem; color: var(--muted); }
    .row-links a { color: var(--accent); text-decoration: none; }
</style>
</head>
<body>
<header>
    <a class="brand" href="/">videos.iaiapro.com</a>
    <nav>
        <?php if ($user): ?>
            <span style="color:var(--muted)"><?= View::e($user['name'] ?: $user['email']) ?></span>
            <a href="/dashboard">Panel</a>
            <form method="post" action="/logout" style="display:inline; margin:0">
                <input type="hidden" name="_csrf" value="<?= View::e(\App\Auth\SessionStore::csrfToken(\App\Auth\SessionStore::currentId() ?? '')) ?>">
                <button class="btn secondary" type="submit" style="padding:.4rem .8rem; font-size:.9rem">Salir</button>
            </form>
        <?php else: ?>
            <a href="/login">Entrar</a>
            <a class="btn" href="/register" style="padding:.4rem .8rem; font-size:.9rem">Crear cuenta</a>
        <?php endif; ?>
    </nav>
</header>
<main>
<?= $content ?>
</main>
</body>
</html>
