<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8">
<title><?= e($titulo ?? 'Error') ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="<?= e(base_url('assets/css/app.css')) ?>" rel="stylesheet">
</head><body>
<div class="container py-5 text-center">
  <h1 style="font-size:3rem;color:var(--oro-oscuro)">404</h1>
  <p class="text-muted-warm"><?= e($titulo ?? 'Página no encontrada') ?></p>
  <a class="btn btn-oro mt-2" href="<?= e(base_url('index.php')) ?>">Volver al inicio</a>
</div>
</body></html>
