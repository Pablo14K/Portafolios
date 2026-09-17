<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Repositorio de facturas. Recupera cabecera, cliente, items y actualiza estado fiscal de invoices.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */

namespace App\Repository;
use PDO;

/**
 * Comentario de codigo: clase InvoiceRepository. Agrupa la responsabilidad principal indicada en la cabecera del archivo.
 */
final class InvoiceRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function getInvoiceComplete(int $id): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT i.*,
                c.naturaleza_receptor, c.tipo_operacion, c.codigo_pais, c.descripcion_pais,
                c.tipo_contribuyente,
                c.ruc AS cliente_ruc, c.dv AS cliente_dv,
                c.tipo_documento_identidad_id AS cliente_tipo_documento,
                tdi.descripcion AS cliente_descripcion_tipo_documento,
                c.numero_documento AS cliente_numero_documento,
                COALESCE(
                    NULLIF(TRIM(CONCAT_WS(' ',p.nombre1,IFNULL(p.nombre2,''),p.apellido1,IFNULL(p.apellido2,''))), ''),
                    c.nombre_razon_social
                ) AS cliente_nombre,
                c.direccion   AS cliente_direccion,
                c.numero_casa AS cliente_numero_casa,
                c.codigo_departamento AS cliente_codigo_departamento,
                dep.nombre AS cliente_descripcion_departamento,
                c.codigo_ciudad AS cliente_codigo_ciudad,
                ciu.nombre AS cliente_descripcion_ciudad,
                c.telefono AS cliente_telefono,
                c.email    AS cliente_email,
                c.codigo_cliente AS cliente_codigo_cliente
            FROM invoices i
            INNER JOIN customers c ON c.id=i.customer_id
            LEFT  JOIN personas  p ON p.id=c.persona_id
            LEFT  JOIN tipos_documento_identidad tdi ON tdi.codigo=c.tipo_documento_identidad_id
            LEFT  JOIN departamentos dep ON dep.codigo=c.codigo_departamento
            LEFT  JOIN ciudades      ciu ON ciu.codigo=c.codigo_ciudad
            WHERE i.id=:id
        ");
        $stmt->execute(['id'=>$id]);
        $inv = $stmt->fetch();
        if (!is_array($inv)) return null;
        $s=$this->pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=:id ORDER BY id ASC');
        $s->execute(['id'=>$id]); $inv['items']=$s->fetchAll()?:[];
        $s=$this->pdo->prepare('SELECT * FROM payments WHERE invoice_id=:id ORDER BY id ASC');
        $s->execute(['id'=>$id]); $inv['payments']=$s->fetchAll()?:[];
        return $inv;
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function getPaidNotQueued(): array
    {
        return $this->pdo->query("
            SELECT i.id,i.numero,i.estado_pago,i.fe_emitida,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ',p.nombre1,IFNULL(p.nombre2,''),p.apellido1,IFNULL(p.apellido2,''))), ''),c.nombre_razon_social) AS cliente_nombre,
                c.email AS cliente_email
            FROM invoices i
            INNER JOIN customers c ON c.id=i.customer_id
            LEFT  JOIN personas  p ON p.id=c.persona_id
            LEFT  JOIN fe_queue  q ON q.invoice_id=i.id
            WHERE i.estado_pago='PAGADO' AND i.fe_emitida=0 AND q.id IS NULL
            ORDER BY i.id ASC
        ")->fetchAll()?:[];
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function markElectronicIssued(int $id,string $cdc,string $status,string $xml,string $kude,?string $qr=null): void
    {
        $this->pdo->prepare("UPDATE invoices SET fe_emitida=1,fe_estado=:s,fe_cdc=:c,fe_xml_path=:x,fe_kude_path=:k,fe_error=NULL,fecha_firma=NOW(),updated_at=NOW() WHERE id=:id")
            ->execute(['id'=>$id,'s'=>$status,'c'=>$cdc,'x'=>$xml,'k'=>$kude]);
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function markElectronicError(int $id,string $error): void
    {
        $this->pdo->prepare("UPDATE invoices SET fe_estado='ERROR',fe_error=:e,updated_at=NOW() WHERE id=:id")
            ->execute(['id'=>$id,'e'=>$error]);
    }

    /**
     * Marca una factura como CANCELADA tras evento aprobado por SIFEN (código 0600).
     * Persiste el motivo, idEvento y trackId para mantener trazabilidad con el portal.
     */
    public function markCancelled(int $id, string $motivo, string $idEvento, ?string $trackId = null): void
    {
        $this->pdo->prepare(
            "UPDATE invoices
                SET fe_estado='CANCELADO',
                    cancelado_at=NOW(),
                    motivo_cancelacion=:m,
                    evento_cancelacion_id=:ev,
                    sifen_track_id=:tr,
                    updated_at=NOW()
              WHERE id=:id"
        )->execute([
            'id' => $id,
            'm'  => $motivo,
            'ev' => $idEvento,
            'tr' => $trackId,
        ]);
    }

    /**
     * Busca una factura por su CDC. Útil para validar el plazo de cancelación
     * antes de invocar al servicio SIFEN.
     */
    public function findByCdc(string $cdc): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE fe_cdc=:c LIMIT 1');
        $stmt->execute(['c' => $cdc]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * Comentario de codigo: Metodo del flujo de negocio; leer parametros y retornos para ver que datos transforma.
     */
    public function allWithStatus(): array
    {
        return $this->pdo->query("
            SELECT i.id,i.numero,i.estado_pago,i.fe_emitida,i.fe_estado,i.fe_cdc,i.total_neto,i.created_at,
                i.motivo_cancelacion,i.cancelado_at,i.evento_cancelacion_id,
                COALESCE(NULLIF(TRIM(CONCAT_WS(' ',p.nombre1,IFNULL(p.nombre2,''),p.apellido1,IFNULL(p.apellido2,''))), ''),c.nombre_razon_social) AS cliente_nombre,
                c.email AS cliente_email, c.ruc AS cliente_ruc, c.dv AS cliente_dv
            FROM invoices i
            INNER JOIN customers c ON c.id=i.customer_id
            LEFT  JOIN personas  p ON p.id=c.persona_id
            ORDER BY i.id DESC
        ")->fetchAll()?:[];
    }
}
