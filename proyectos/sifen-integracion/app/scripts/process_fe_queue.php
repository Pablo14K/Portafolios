<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Worker puntual de factura electronica. Lee fe_queue, genera/emite DE mediante PipelineService y agenda el correo.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */

require __DIR__.'/../bootstrap.php';
use App\Database\Connection;
use App\Repository\{EmailQueueRepository,EmitterRepository,FeQueueRepository,InvoiceRepository};
use App\Service\{EmailTemplateService,InvoiceMapper,KudeService,PipelineService,SifenBridge};

$cfg = require __DIR__.'/../config/config.php';
$pdo = Connection::make($cfg['db']);
$feQ = new FeQueueRepository($pdo);
$jobs = $feQ->getPending(20);
if (empty($jobs)) { echo "[FE] Sin trabajos pendientes.\n"; return; }

$pipeline = new PipelineService(
    new InvoiceRepository($pdo),
    new EmitterRepository($pdo),
    $feQ,
    new EmailQueueRepository($pdo),
    new InvoiceMapper(),
    new SifenBridge($cfg['sifen']),
    new KudeService($cfg['storage']['kude_dir']),
    new EmailTemplateService(),
    $cfg['storage'],
    $cfg['sifen'],
);

foreach ($jobs as $job) {
    try {
        $r = $pipeline->processFeJob($job);
        echo sprintf("[FE] ✅ Factura #%s | CDC=%s | Estado=%s\n",
            $r['invoice_id'], substr($r['cdc'],0,20).'...', $r['status']);
    } catch (Throwable $e) {
        $feQ->markError((int)$job['id'], $e->getMessage());
        echo "[FE] ❌ Error job #{$job['id']}: ".$e->getMessage()."\n";
    }
}
