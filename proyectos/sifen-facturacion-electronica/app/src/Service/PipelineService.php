<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Orquestador PHP. Toma una factura, llama a SifenBridge/Node, actualiza colas y agenda correo.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */

namespace App\Service;
use App\Repository\{EmailQueueRepository,EmitterRepository,FeQueueRepository,InvoiceRepository};
use App\Support\FileStore;
use RuntimeException;

/**
 * Comentario de codigo: clase PipelineService. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class PipelineService
{
    public function __construct(
        private InvoiceRepository    $invoiceRepository,
        private EmitterRepository    $emitterRepository,
        private FeQueueRepository    $feQueueRepository,
        private EmailQueueRepository $emailQueueRepository,
        private InvoiceMapper        $invoiceMapper,
        private SifenBridge          $sifenBridge,
        private KudeService          $kudeService,    // fallback si Node no genera kude
        private EmailTemplateService $emailTemplateService,
        private array                $storageConfig,
        private array                $sifenConfig = [],
    ) {}

    /**
     * Comentario de codigo: Busca facturas pagadas y las agrega a la cola electronica.
     */
    public function enqueuePaidInvoices(): int
    {
        $count = 0;
        foreach ($this->invoiceRepository->getPaidNotQueued() as $inv) {
            $this->feQueueRepository->enqueue((int)$inv['id']);
            $count++;
        }
        return $count;
    }

    /**
     * Comentario de codigo: Procesa una factura electronica tomada desde la cola.
     */
    public function processFeJob(array $job): array
    {
        $invoiceId = (int)$job['invoice_id'];
        $invoice   = $this->invoiceRepository->getInvoiceComplete($invoiceId);
        if ($invoice === null) throw new RuntimeException("Factura no encontrada: $invoiceId");

        $emitter = $this->emitterRepository->getEmitter();
        if ($emitter === []) throw new RuntimeException('No existe configuración del emisor.');

        $payload = $this->invoiceMapper->buildPayload($emitter, $invoice, $this->sifenConfig);
        $this->feQueueRepository->markProcessing((int)$job['id'], $payload);

        try {
            $response = $this->sifenBridge->emit($payload);
            if (empty($response['ok'])) throw new RuntimeException((string)($response['message']??'SIFEN error.'));

            $cdc      = (string)$response['cdc'];
            $qrText   = (string)$response['qr_text'];
            $status   = (string)($response['estado']??'APROBADO');
            $xmlPath  = (string)($response['xml_path']??'');
            $kudePath = (string)($response['kude_path']??'');

            // Si el Node no generó el KuDE (xml vacío o kude_path vacío), usar fallback PHP
            if ($kudePath === '' || !file_exists($kudePath)) {
                // Leer XML del path o del campo xml
                $xml = ($response['xml']??'');
                if ($xml==='' && $xmlPath!=='' && file_exists($xmlPath)) {
                    $xml = file_get_contents($xmlPath);
                }
                if ($xml !== '' && $xmlPath === '') {
                    $xmlPath = rtrim($this->storageConfig['xml_dir'],'/').'/'.$cdc.'.xml';
                    FileStore::put($xmlPath, $xml);
                }
                $kudePath = $this->kudeService->generate([
                    'cdc'=>$cdc,'qr_text'=>$qrText,
                    'emisor'=>$payload['emisor'],'cliente'=>$payload['cliente'],
                    'documento'=>$payload['documento'],'items'=>$payload['items'],'totales'=>$payload['totales'],
                ]);
            }

            $this->invoiceRepository->markElectronicIssued($invoiceId,$cdc,$status,$xmlPath,$kudePath);
            $this->feQueueRepository->markApproved((int)$job['id'],$cdc,$xmlPath,$kudePath,$qrText,$response);

            $email = (string)($payload['cliente']['email']??'');
            if ($email !== '') {
                $template = $this->emailTemplateService->electronicInvoice($invoice,$cdc);
                $this->emailQueueRepository->enqueue($invoiceId,$email,'FACTURA_ELECTRONICA',$template['subject'],$template['html'],$kudePath,$xmlPath,null);
            }

            return ['invoice_id'=>$invoiceId,'cdc'=>$cdc,'xml_path'=>$xmlPath,'kude_path'=>$kudePath,'status'=>$status];

        } catch (\Throwable $e) {
            $this->invoiceRepository->markElectronicError($invoiceId,$e->getMessage());
            throw $e;
        }
    }
}
