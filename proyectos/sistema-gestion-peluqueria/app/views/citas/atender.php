<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=citas/agenda&dia=' . substr($cita['fecha_hora'], 0, 10))) ?>"><i class="bi bi-arrow-left"></i> Agenda</a>
  <h1 class="mt-1">Registrar atención</h1>
  <div class="sub"><?= e($cita['cliente']) ?> · <?= e(fecha($cita['fecha_hora'])) ?> · con <?= e($cita['profesional']) ?></div>
</div>

<div class="spg-panel" style="max-width:760px">
  <form method="post" action="<?= e(base_url('index.php?r=citas/atender_guardar')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="id_cita" value="<?= (int)$cita['id_cita'] ?>">
    <input type="hidden" name="dia" value="<?= e(substr($cita['fecha_hora'], 0, 10)) ?>">

    <label class="form-label">Servicios realizados *</label>
    <div class="row g-2 mb-3">
      <?php foreach ($servicios as $s): ?>
        <div class="col-md-6">
          <label class="d-flex align-items-center gap-2 p-2" style="border:1px solid var(--gris-calido);border-radius:8px;cursor:pointer">
            <input class="form-check-input mt-0" type="checkbox" name="servicios[]" value="<?= (int)$s['id_servicio'] ?>" checked>
            <span><?= e($s['nombre']) ?>
              <span class="text-muted-warm" style="font-size:.8rem">· <?= money($s['precio']) ?></span>
              <?php if ($s['ya']): ?><span class="badge-estado e-ok" style="font-size:.65rem">ya registrado</span><?php endif; ?>
            </span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>

    <label class="form-label">Productos utilizados <span class="text-muted-warm" style="font-weight:400">(descuentan stock automáticamente)</span></label>
    <table class="table align-middle" id="tablaProd">
      <thead><tr><th style="width:60%">Producto</th><th>Cantidad</th><th></th></tr></thead>
      <tbody>
        <?php for ($i = 0; $i < 2; $i++): ?>
        <tr>
          <td>
            <select class="form-select" name="producto[]">
              <option value="">— ninguno —</option>
              <?php foreach ($productos as $p): ?>
                <option value="<?= (int)$p['id_producto'] ?>"><?= e($p['nombre']) ?> (<?= e($p['unidad_medida']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input type="number" min="0" step="0.01" class="form-control" name="cantidad[]" placeholder="0"></td>
          <td><button type="button" class="btn btn-sm btn-outline-neutro" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-outline-neutro btn-sm" id="addProd"><i class="bi bi-plus-lg"></i> Agregar producto</button>

    <div class="mt-3"><label class="form-label">Observaciones</label>
      <textarea class="form-control" name="observaciones" rows="2"></textarea></div>

    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Registrar y marcar atendida</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=citas/agenda&dia=' . substr($cita['fecha_hora'], 0, 10))) ?>">Cancelar</a>
    </div>
  </form>
</div>

<?php if ($usados): ?>
<div class="spg-panel mt-3" style="max-width:760px">
  <h2 style="font-size:.95rem;font-weight:500;margin-bottom:.6rem;">Productos ya consumidos en esta cita</h2>
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th>Producto</th><th>Cantidad</th></tr></thead>
    <tbody>
      <?php foreach ($usados as $x): ?>
        <tr><td><?= e($x['nombre']) ?></td><td><?= e(cant($x['cantidad'])) ?> <span class="text-muted-warm"><?= e($x['unidad_medida']) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
  document.getElementById('addProd').addEventListener('click', function () {
    var tb = document.querySelector('#tablaProd tbody');
    var tr = tb.rows[0].cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tr.querySelectorAll('select').forEach(function (s) { s.selectedIndex = 0; });
    tb.appendChild(tr);
  });
</script>
