<?php
/** @var ?string $error */
/** @var string $csrf */
/** @var string $email */
use App\Http\View;
?>
<div class="card narrow">
    <h1>Entrar</h1>
    <p class="muted">Accede con tu cuenta.</p>
    <?php if (!empty($error)): ?>
        <div class="alert err"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/login" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="<?= View::e($email ?? '') ?>" required autofocus>
        <label for="password">Contraseña</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
        <button type="submit">Entrar</button>
    </form>
    <div class="row-links">
        <span>¿No tienes cuenta?</span>
        <a href="/register">Crea una</a>
    </div>
</div>
