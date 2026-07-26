<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=configuracion/index')) ?>"><i class="bi bi-arrow-left"></i> Configuración</a>
  <h1 class="mt-1">Roles y permisos</h1>
  <div class="sub">Definí qué módulos del menú ve cada rol. La Propietaria siempre tiene acceso total.</div>
</div>

<div class="spg-panel mb-3" style="max-width:560px">
  <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Crear rol</h2>
  <form method="post" action="<?= e(base_url('index.php?r=configuracion/rol_crear')) ?>">
    <?= csrf_field() ?>
    <div class="row g-2">
      <div class="col-md-5"><input class="form-control" name="nombre" placeholder="Nombre del rol" required></div>
      <div class="col-md-5"><input class="form-control" name="descripcion" placeholder="Descripción (opcional)"></div>
      <div class="col-md-2"><button class="btn btn-oro w-100"><i class="bi bi-plus-lg"></i></button></div>
    </div>
  </form>
</div>

<div class="spg-panel">
  <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.8rem;">Matriz de acceso a módulos</h2>
  <form method="post" action="<?= e(base_url('index.php?r=configuracion/permisos_guardar')) ?>">
    <?= csrf_field() ?>
    <div class="table-responsive">
      <table class="table align-middle mb-0">
        <thead><tr><th>Rol</th><?php foreach ($modulos as $lbl): ?><th style="font-size:.72rem"><?= e($lbl) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($roles as $r): if (!$r['es_personal']) continue; $idr = (int)$r['id_rol']; $prop = ($idr === 1); ?>
          <tr>
            <td>
              <strong><?= e($r['nombre']) ?></strong>
              <div class="text-muted-warm" style="font-size:.75rem"><?= (int)$r['usuarios'] ?> usuario(s)</div>
            </td>
            <?php foreach ($modulos as $mk => $lbl): $on = $prop || !empty($perm[$idr][$mk]); ?>
              <td class="text-center">
                <input type="checkbox" class="form-check-input" name="perm[<?= $idr ?>][<?= e($mk) ?>]" value="1"
                       <?= $on ? 'checked' : '' ?> <?= $prop ? 'disabled title="Acceso total (fijo)"' : '' ?>>
              </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="mt-3"><button class="btn btn-oro"><i class="bi bi-check-lg"></i> Guardar permisos</button></div>
    <p class="text-muted-warm mt-2 mb-0" style="font-size:.78rem">El rol <strong>Cliente</strong> no aparece: usa el portal, no el panel de gestión.</p>
  </form>
</div>
