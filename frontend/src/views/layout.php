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
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/assets/app.css">
<style>
    :root {
        --bg: #f4f8f6;
        --bg-soft: #e8efeb;
        --card: #ffffff;
        --card-soft: #f1f6f3;
        --border: #d4dfd9;
        --text: #15231e;
        --muted: #4f5f58;
        --accent: #147a5a;
        --accent-strong: #0f6148;
        --danger: #bd463b;
        --ok: #1f8f61;
        --ring: rgba(20,122,90,.28);
        --shadow: 0 24px 48px -36px rgba(20, 55, 42, 0.45);
        color-scheme: light;
    }
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: "Outfit", "Segoe UI", Roboto, sans-serif;
        background:
            radial-gradient(circle at 8% -8%, #cfe4da 0%, transparent 42%),
            radial-gradient(circle at 100% 0%, #dfebe5 0%, transparent 48%),
            var(--bg);
        color: var(--text);
        min-height: 100dvh;
        letter-spacing: 0;
    }
    header {
        position: sticky;
        top: 0;
        z-index: 8;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 1rem;
        padding: .95rem 1.2rem;
        border-bottom: 1px solid var(--border);
        background: color-mix(in srgb, var(--bg) 85%, white 15%);
        backdrop-filter: blur(8px);
    }
    header a.brand {
        color: var(--text);
        text-decoration: none;
        font-weight: 800;
        font-size: 1rem;
        letter-spacing: .02em;
    }
    header nav {
        display: flex;
        gap: .7rem;
        align-items: center;
        font-size: .94rem;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    header nav a {
        color: var(--muted);
        text-decoration: none;
        border: 1px solid transparent;
        border-radius: 8px;
        padding: .42rem .7rem;
        transition: color .2s ease, border-color .2s ease, background-color .2s ease;
    }
    header nav a:hover {
        color: var(--text);
        border-color: var(--border);
        background: var(--card);
    }
    .chip {
        color: var(--muted);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: .35rem .65rem;
        background: color-mix(in srgb, var(--card) 85%, var(--bg-soft) 15%);
        font-size: .86rem;
    }
    main { max-width: 1180px; margin: 1.8rem auto 2.5rem; padding: 0 1.2rem; }
    .card { background: var(--card); border: 1px solid var(--border); border-radius: 8px; padding: 1.5rem; box-shadow: var(--shadow); }
    .card.narrow { max-width: 420px; margin: 3rem auto; }
    h1 { margin: 0 0 .5rem; font-size: clamp(1.7rem, 2.8vw, 2.3rem); line-height: 1.04; letter-spacing: -.02em; }
    p.muted { color: var(--muted); margin: 0 0 1.5rem; line-height: 1.58; }
    label { display: block; font-size: .9rem; color: var(--muted); margin-bottom: .35rem; font-weight: 500; }
    input[type=text], input[type=email], input[type=password] {
        width: 100%; padding: .72rem .86rem; border-radius: 8px; border: 1px solid var(--border);
        background: var(--card); color: var(--text); font-size: 1rem; margin-bottom: 1rem;
        transition: border-color .2s ease, box-shadow .2s ease;
    }
    input:focus { outline: 0; border-color: var(--accent); box-shadow: 0 0 0 3px var(--ring); }
    button, .btn {
        display: inline-block;
        padding: .68rem 1.15rem;
        border-radius: 8px;
        border: 1px solid transparent;
        background: var(--accent);
        color: #f8fffc;
        font-weight: 700;
        font-size: .97rem;
        cursor: pointer;
        text-decoration: none;
        letter-spacing: 0;
        transition: transform .16s ease, background-color .2s ease, border-color .2s ease, color .2s ease;
    }
    button:hover, .btn:hover { background: var(--accent-strong); }
    button:active, .btn:active { transform: translateY(1px) scale(.99); }
    .btn.secondary { background: var(--card); color: var(--text); border: 1px solid var(--border); }
    .btn.secondary:hover { background: var(--card-soft); color: var(--text); }
    .alert { padding: .7rem 1rem; border-radius: 8px; margin-bottom: 1rem; font-size: .9rem; }
    .alert.err { background: rgba(189,70,59,.12); border: 1px solid rgba(189,70,59,.55); color: #8b251f; }
    .alert.ok  { background: rgba(31,143,97,.12); border: 1px solid rgba(31,143,97,.48); color: #14573a; }
    .row-links { display: flex; justify-content: space-between; font-size: .9rem; margin-top: 1rem; color: var(--muted); }
    .row-links a { color: var(--accent-strong); text-decoration: none; font-weight: 600; }
    @media (max-width: 760px) {
        main { padding: 0 .9rem; margin-top: 1.1rem; }
        header { padding: .75rem .8rem; }
        .card { padding: 1.05rem; }
    }
</style>
</head>
<body>
<header>
    <a class="brand" href="/">videos.iaiapro.com</a>
    <nav>
        <?php if ($user): ?>
            <span class="chip"><?= View::e($user['name'] ?: $user['email']) ?></span>
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
