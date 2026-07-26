<?php

declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Simula respuestas aprobadas cuando SIFEN_MODE=mock. Permite probar sin conectarse a SET.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */


namespace App\Service;

use App\Repository\{EmailLogRepository, EmailQueueRepository, FeQueueRepository, InvoiceRepository};

/**
 * Comentario de codigo: clase DemoStatusService. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class DemoStatusService
{
    public function __construct(
        private InvoiceRepository    $invoiceRepository,
        private FeQueueRepository    $feQueueRepository,
        private EmailQueueRepository $emailQueueRepository,
        private EmailLogRepository   $emailLogRepository,
    ) {}

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function snapshot(): array
    {
        return [
            'invoices'    => $this->invoiceRepository->allWithStatus(),
            'fe_queue'    => $this->feQueueRepository->all(),
            'email_queue' => $this->emailQueueRepository->all(),
            'email_log'   => $this->emailLogRepository->all(),
        ];
    }
}
