<div class="spg-page-head">
  <a class="spg-back" href="<?= e(base_url('index.php?r=inventario/compras')) ?>"><i class="bi bi-arrow-left"></i> Compras</a>
  <h1 class="mt-1">Nueva compra</h1>
  <div class="sub">Al guardar, se genera el movimiento de inventario y se suma el stock. Si un producto no existe, se crea automáticamente.</div>
</div>
<div class="spg-panel">
  <form method="post" action="<?= e(base_url('index.php?r=inventario/compra_guardar')) ?>">
    <?= csrf_field() ?>
    <div class="row g-3 mb-2">
      <div class="col-md-4"><label class="form-label">Proveedor *</label>
        <select class="form-select" name="id_proveedor" required><option value="">—</option>
          <?php foreach ($proveedores as $p): ?><option value="<?= (int)$p['id_proveedor'] ?>"><?= e($p['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">Condición</label>
        <select class="form-select" name="id_condicion_venta">
          <?php foreach ($condiciones as $c): ?><option value="<?= (int)$c['id_condicion_venta'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label">N° factura proveedor</label>
        <input class="form-control" name="nro_factura_proveedor"></div>
      <div class="col-md-2"><label class="form-label">Categoría (nuevos)</label>
        <select class="form-select" name="id_categoria_nuevos" title="Categoría que se asigna a los productos nuevos">
          <?php foreach ($categorias as $c): ?><option value="<?= (int)$c['id_categoria'] ?>"><?= e($c['nombre']) ?></option><?php endforeach; ?></select></div>
    </div>

    <datalist id="listaProductos">
      <?php foreach ($productos as $p): ?><option value="<?= e($p['nombre']) ?>"></option><?php endforeach; ?>
    </datalist>

    <label class="form-label">Productos</label>
    <table class="table align-middle" id="tablaLineas">
      <thead><tr><th style="width:55%">Producto (escribí para buscar o crear)</th><th>Cantidad</th><th>Precio unitario</th><th></th></tr></thead>
      <tbody>
        <?php for ($i = 0; $i < 3; $i++): ?>
        <tr>
          <td><input class="form-control" name="nombre[]" list="listaProductos" placeholder="Nombre del producto"></td>
          <td><input type="number" min="0" step="0.01" class="form-control" name="cantidad[]" placeholder="0"></td>
          <td><input type="number" min="0" step="1" class="form-control" name="precio[]" placeholder="0"></td>
          <td><button type="button" class="btn btn-sm btn-outline-neutro" onclick="this.closest('tr').remove()"><i class="bi bi-x"></i></button></td>
        </tr>
        <?php endfor; ?>
      </tbody>
    </table>
    <button type="button" class="btn btn-outline-neutro btn-sm" id="addRow"><i class="bi bi-plus-lg"></i> Agregar fila</button>

    <div class="mt-3"><label class="form-label">Observaciones</label>
      <textarea class="form-control" name="observaciones" rows="2"></textarea></div>

    <div class="mt-4 d-flex gap-2">
      <button class="btn btn-oro"><i class="bi bi-check-lg"></i> Registrar compra</button>
      <a class="btn btn-outline-neutro" href="<?= e(base_url('index.php?r=inventario/compras')) ?>">Cancelar</a>
    </div>
  </form>
</div>
<script>
  document.getElementById('addRow').addEventListener('click', function () {
    var tb = document.querySelector('#tablaLineas tbody');
    var tr = tb.rows[0].cloneNode(true);
    tr.querySelectorAll('input').forEach(function (i) { i.value = ''; });
    tb.appendChild(tr);
  });
</script>
