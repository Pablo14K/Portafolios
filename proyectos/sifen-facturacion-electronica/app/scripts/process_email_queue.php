<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Worker puntual de correos. Lee email_queue, envia mensajes con adjuntos y registra resultado en email_log.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */

require __DIR__ . '/../bootstrap.php';
use App\Database\Connection;
use App\Repository\{EmailLogRepository,EmailQueueRepository};
use App\Service\MailService;

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Connection::make($cfg['db']);
$eQ  = new EmailQueueRepository($pdo);
$ms  = new MailService($cfg['mail'], new EmailLogRepository($pdo));
$jobs = $eQ->getPending(20);

if (empty($jobs)) { echo "[Mail] Sin correos pendientes.\n"; return; }

foreach ($jobs as $job) {
    try {
        $eQ->markSending((int)$job['id']);
        $ms->send($job);
        $eQ->markSent((int)$job['id']);
        echo "[Mail] ✅ Enviado a {$job['cliente_email']} — {$job['asunto']}\n";
    } catch (Throwable $e) {
        $eQ->markError((int)$job['id'], $e->getMessage());
        echo "[Mail] ❌ Error #{$job['id']}: " . $e->getMessage() . "\n";
    }
}
