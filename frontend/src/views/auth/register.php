<?php
/** @var ?string $error */
/** @var string $csrf */
/** @var string $email */
/** @var string $name */
use App\Http\View;
?>
<div class="card narrow">
    <h1>Crear cuenta</h1>
    <p class="muted">Un minuto y listo.</p>
    <?php if (!empty($error)): ?>
        <div class="alert err"><?= View::e($error) ?></div>
    <?php endif; ?>
    <form method="post" action="/register" autocomplete="on">
        <input type="hidden" name="_csrf" value="<?= View::e($csrf) ?>">
        <label for="name">Tu nombre</label>
        <input id="name" type="text" name="name" value="<?= View::e($name ?? '') ?>" required maxlength="120" autofocus>
        <label for="email">Email</label>
        <input id="email" type="email" name="email" value="<?= View::e($email ?? '') ?>" required maxlength="190">
        <label for="password">Contraseña (mínimo 8 caracteres)</label>
        <input id="password" type="password" name="password" required minlength="8" autocomplete="new-password">
        <button type="submit">Crear cuenta</button>
    </form>
    <div class="row-links">
        <span>¿Ya tienes cuenta?</span>
        <a href="/login">Entrar</a>
    </div>
</div>
