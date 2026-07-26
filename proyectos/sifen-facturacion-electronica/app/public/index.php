<?php
declare(strict_types=1);
/*
 * DOCUMENTACION DEL ARCHIVO
 * Que hace: Interfaz web principal. Crea clientes/facturas, muestra colas, descarga XML/PDF y permite disparar el procesamiento.
 * Donde se usa: forma parte del flujo de facturacion electronica SIFEN documentado en docs/DOCUMENTACION_TECNICA_COMPLETA.md.
 * Nota: los detalles de variables, palabras reservadas y cambios posibles estan centralizados en docs/GUIA_COMENTARIOS_CODIGO.md para no duplicar ruido en cada linea.
 */

require __DIR__ . '/../bootstrap.php';
use App\Database\Connection;
use App\Repository\{InvoiceRepository,FeQueueRepository,EmailQueueRepository,EmailLogRepository,EmitterRepository,InvoiceEventRepository};
use App\Service\{DemoStatusService,CancellationService,SifenBridge,PipelineService,InvoiceMapper,KudeService,EmailTemplateService,MailService,MailConnectionException};

$cfg = require __DIR__ . '/../config/config.php';
$pdo = Connection::make($cfg['db']);

$status = (new DemoStatusService(
    new InvoiceRepository($pdo),
    new FeQueueRepository($pdo),
    new EmailQueueRepository($pdo),
    new EmailLogRepository($pdo)
))->snapshot();

$msg = null; $err = null;

/**
 * Comentario de codigo: Funcion auxiliar usada por este archivo para mantener el flujo legible.
 */
function h($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function num(string $v): string { return preg_replace('/\D+/', '', $v) ?? ''; }
function gs(float $v): string { return number_format($v, 0, ',', '.'); }
function localDateTimeValue(): string { return (new DateTimeImmutable('now'))->format('Y-m-d\TH:i'); }
/**
 * Comentario de codigo: Funcion auxiliar usada por este archivo para mantener el flujo legible.
 */
function nextNum(PDO $pdo): string {
    $r = $pdo->query("SELECT LPAD(IFNULL(MAX(CAST(numero AS UNSIGNED)),0)+1,7,'0') AS n FROM invoices")->fetch();
    return $r['n'] ?? '0000001';
}
/**
 * Comentario de codigo: Funcion auxiliar usada por este archivo para mantener el flujo legible.
 */
function badge(string $s): string {
    $map=['PAGADO'=>'ok','APROBADO'=>'ok','ENVIADO'=>'ok','PENDIENTE'=>'warn','PROCESANDO'=>'info','ERROR'=>'err'];
    $c=$map[strtoupper($s)]??'gray';
    return "<span class='badge badge-{$c}'>{$s}</span>";
}

// GET — descarga de adjuntos KuDE / XML referenciados desde email_queue
if ($_SERVER['REQUEST_METHOD']==='GET' && ($_GET['accion']??'')==='adjunto') {
    $eqId = (int)($_GET['eq']??0);
    $tipo = $_GET['t']??'';
    $disposition = ($_GET['dl']??'')==='1' ? 'attachment' : 'inline';
    if ($eqId<=0 || !in_array($tipo,['kude','xml'],true)) { http_response_code(400); echo 'Parámetros inválidos'; exit; }
    $stmt=$pdo->prepare('SELECT adjunto_1, adjunto_2 FROM email_queue WHERE id=:id LIMIT 1');
    $stmt->execute(['id'=>$eqId]);
    $row=$stmt->fetch();
    if (!$row) { http_response_code(404); echo 'Adjunto no encontrado'; exit; }
    $path = $tipo==='kude' ? (string)($row['adjunto_1']??'') : (string)($row['adjunto_2']??'');
    // Resolución portable: si la ruta guardada no existe (proyecto movido), se
    // reconstruye desde storage/ por nombre de archivo antes de servirla.
    $path = \App\Support\FileStore::resolveStorage($path);
    if ($path==='' || !is_file($path) || !is_readable($path)) { http_response_code(404); echo 'Archivo no disponible'; exit; }
    // Defensa: el path viene de la fila de email_queue seleccionada por id (origen confiable),
    // pero validamos igualmente que sea un archivo bajo cualquier carpeta storage/xml o storage/kude
    // y que el basename respete el patrón CDC.<ext>, para evitar que un INSERT manipulado en la cola
    // sirva archivos arbitrarios del sistema.
    $real = realpath($path) ?: '';
    $normalized = str_replace('\\','/',$real);
    $expectedExt = $tipo==='kude' ? 'pdf' : 'xml';
    $expectedDir = $tipo==='kude' ? '/storage/kude/' : '/storage/xml/';
    $name = basename($real);
    $okDir  = stripos($normalized, $expectedDir) !== false;
    $okName = (bool)preg_match('/^[0-9]{40,44}\.'.$expectedExt.'$/i', $name);
    if (!$okDir || !$okName) { http_response_code(403); echo 'Ruta no permitida'; exit; }
    $mime = $tipo==='kude' ? 'application/pdf' : 'application/xml; charset=UTF-8';
    header('Content-Type: '.$mime);
    header('Content-Length: '.filesize($real));
    header('Content-Disposition: '.$disposition.'; filename="'.$name.'"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($real);
    exit;
}

// AJAX — buscar cliente por RUC
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='buscar_ruc') {
    header('Content-Type: application/json; charset=UTF-8');
    $ruc=num($_POST['ruc']??''); $dv=num($_POST['dv']??'');
    if ($ruc===''||$dv==='') { echo json_encode(['found'=>false,'msg'=>'Ingresá RUC y DV.']); exit; }
    $stmt=$pdo->prepare("
        SELECT c.*,
               COALESCE(NULLIF(TRIM(CONCAT_WS(' ',p.nombre1,IFNULL(p.nombre2,''),p.apellido1,IFNULL(p.apellido2,''))), ''), c.nombre_razon_social) AS nombre_completo,
               p.nombre1,p.nombre2,p.apellido1,p.apellido2,p.numero_documento AS p_num_doc, p.tipo_documento_id AS p_tipo_doc
        FROM customers c LEFT JOIN personas p ON p.id=c.persona_id
        WHERE c.ruc=:r AND c.dv=:d LIMIT 1");
    $stmt->execute([':r'=>$ruc,':d'=>$dv]);
    $row=$stmt->fetch();
    if (!$row) { echo json_encode(['found'=>false,'msg'=>'No encontrado. Podés cargarlo como nuevo.']); exit; }
    echo json_encode(['found'=>true,'msg'=>'✅ Cliente encontrado — datos completados.','c'=>$row]);
    exit;
}

// AJAX — QR real de una factura: codifica el qr_text (dCarQR del Manual SIFEN, cap. 13.8)
// guardado en fe_queue al emitir, y lo devuelve como SVG escaneable + la URL de validación.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='qr_svg') {
    header('Content-Type: application/json; charset=UTF-8');
    $invId = (int)($_POST['inv_id'] ?? 0);
    $st = $pdo->prepare("SELECT qr_text FROM fe_queue WHERE invoice_id=:id AND qr_text IS NOT NULL AND qr_text<>'' ORDER BY id DESC LIMIT 1");
    $st->execute([':id'=>$invId]);
    $qrText = (string)($st->fetchColumn() ?: '');
    if ($qrText === '') { echo json_encode(['ok'=>false,'msg'=>'Comprobante sin QR (todavía no emitido).']); exit; }
    // Mismo codificador que el KuDE PDF: matriz QR válida ISO/IEC 18004, nivel L.
    $m = \App\Service\QrCode::matrix($qrText, 'L');
    $n = count($m); $quiet = 2; $size = $n + 2*$quiet;
    $path = '';
    for ($r=0; $r<$n; $r++) for ($c=0; $c<$n; $c++) if ($m[$r][$c]) $path .= 'M'.($c+$quiet).' '.($r+$quiet).'h1v1h-1z';
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$size.' '.$size.'" shape-rendering="crispEdges" width="100%" height="100%" style="display:block">'
         . '<rect width="'.$size.'" height="'.$size.'" fill="#fff"/><path d="'.$path.'" fill="#16202c"/></svg>';
    echo json_encode(['ok'=>true,'text'=>$qrText,'svg'=>$svg]);
    exit;
}

// ============================================================
// API JSON — Panel de ENVÍO (Fase 2/3): generar y enviar comprobantes
// UNO POR UNO, en orden, con estado SUSPENDIDO reanudable ante cortes.
// Mismo patrón early-exit que buscar_ruc: responde JSON y termina.
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['accion']??'', ['estado_colas','generar_uno','enviar_uno'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
    $accion = (string)$_POST['accion'];
    try {
        $feQ = new FeQueueRepository($pdo);
        $eQ  = new EmailQueueRepository($pdo);

        // Cantidad de correos que faltan enviar (PENDIENTE + SUSPENDIDO).
        $restantesEnvio = static function (EmailQueueRepository $q): int {
            $c = $q->counts();
            return $c['PENDIENTE'] + $c['SUSPENDIDO'];
        };

        // --- Contadores para pintar el panel ---
        if ($accion === 'estado_colas') {
            $c = $eQ->counts();
            echo json_encode([
                'ok'                  => true,
                'fe_pendientes'       => $feQ->countPending(),
                'correos_pendientes'  => $c['PENDIENTE'] + $c['SUSPENDIDO'],
                'correos_suspendidos' => $c['SUSPENDIDO'],
                'enviando'            => $c['ENVIANDO'],
                'enviados'            => $c['ENVIADO'],
                'errores'             => $c['ERROR'],
            ]);
            exit;
        }

        // --- Generar el comprobante de UNA factura pendiente (reemplaza al worker FE) ---
        if ($accion === 'generar_uno') {
            // Reencola jobs colgados (petición/worker caído) antes de tomar el próximo.
            $feQ->resetStuckJobs((int)(getenv('FE_STUCK_THRESHOLD_SEC') ?: 600));
            $job = $feQ->claimNext();
            if ($job === null) {
                echo json_encode(['ok'=>true,'done'=>true,'restantes'=>0]);
                exit;
            }
            $pipeline = new PipelineService(
                new InvoiceRepository($pdo),
                new EmitterRepository($pdo),
                $feQ,
                $eQ,
                new InvoiceMapper(),
                new SifenBridge($cfg['sifen']),
                new KudeService($cfg['storage']['kude_dir']),
                new EmailTemplateService(),
                $cfg['storage'],
                $cfg['sifen'],
            );
            try {
                $r = $pipeline->processFeJob($job);
                echo json_encode(['ok'=>true,'done'=>false,'factura'=>$r['invoice_id'],'cdc'=>$r['cdc'],'restantes'=>$feQ->countPending()]);
            } catch (Throwable $e) {
                $feQ->markError((int)$job['id'], $e->getMessage());
                echo json_encode(['ok'=>false,'done'=>false,'suspendido'=>false,'error'=>$e->getMessage(),'restantes'=>$feQ->countPending()]);
            }
            exit;
        }

        // --- Enviar UN correo (el más antiguo pendiente/suspendido), en orden ---
        if ($accion === 'enviar_uno') {
            // Reencola correos colgados en ENVIANDO (navegador cerrado a mitad).
            $eQ->resetStuckSending((int)(getenv('MAIL_STUCK_THRESHOLD_SEC') ?: 120));
            $job = $eQ->claimNext();
            if ($job === null) {
                echo json_encode(['ok'=>true,'done'=>true,'restantes'=>0]);
                exit;
            }
            $logRepo = new EmailLogRepository($pdo);

            // Idempotencia anti-doble-envío: si ese comprobante ya figura enviado, no reenviar.
            if ($logRepo->wasSent((int)$job['invoice_id'], (string)$job['cliente_email'], (string)$job['asunto'])) {
                $eQ->markSentDedup((int)$job['id']);
                echo json_encode(['ok'=>true,'done'=>false,'dedup'=>true,'email'=>$job['cliente_email'],'restantes'=>$restantesEnvio($eQ)]);
                exit;
            }

            try {
                (new MailService($cfg['mail'], $logRepo))->send($job);
                $eQ->markSent((int)$job['id']);
                echo json_encode(['ok'=>true,'done'=>false,'email'=>$job['cliente_email'],'restantes'=>$restantesEnvio($eQ)]);
            } catch (MailConnectionException $e) {
                // Se cortó internet → SUSPENDIDO (reanudable). El JS frena el envío acá.
                $eQ->markSuspended((int)$job['id'], $e->getMessage());
                echo json_encode(['ok'=>false,'done'=>false,'suspendido'=>true,'error'=>$e->getMessage(),'restantes'=>$restantesEnvio($eQ)]);
            } catch (Throwable $e) {
                // Error permanente (correo inválido, rechazo SMTP) → ERROR; se omite y se sigue.
                $eQ->markError((int)$job['id'], $e->getMessage());
                echo json_encode(['ok'=>false,'done'=>false,'suspendido'=>false,'error'=>$e->getMessage(),'restantes'=>$restantesEnvio($eQ)]);
            }
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false,'fatal'=>true,'error'=>$e->getMessage()]);
        exit;
    }
}

// ============================================================
// API JSON — "Prueba de Script": subir un .txt y dispararlo contra el
// automatizador SIFEN (endpoint HTTP independiente, NO se modifica). Dos fases:
//   ps_subir    → recibe el archivo, valida que sea un .txt cargado COMPLETO,
//                 lo guarda en storage/ps_tmp/ y devuelve un "ticket".
//   ps_ejecutar → toma el ticket y reenvía el .txt al automatizador por cURL
//                 (campo "archivo"), devolviendo su JSON de resultado.
// Mismo patrón early-exit que el panel de Envío.
// ============================================================
if ($_SERVER['REQUEST_METHOD']==='POST' && in_array($_POST['accion']??'', ['ps_subir','ps_ejecutar'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
    $accion = (string)$_POST['accion'];
    $tmpDir = APP_ROOT . '/storage/ps_tmp';
    try {
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        // ── Fase 1: recibir y validar la carga del .txt ──────────────────
        if ($accion === 'ps_subir') {
            if (empty($_FILES['archivo']['name'])) throw new RuntimeException('No se recibió ningún archivo.');
            $f = $_FILES['archivo'];
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errs = [
                    UPLOAD_ERR_INI_SIZE  => 'El archivo supera el tamaño permitido por el servidor (upload_max_filesize).',
                    UPLOAD_ERR_FORM_SIZE => 'El archivo es demasiado grande.',
                    UPLOAD_ERR_PARTIAL   => 'La carga quedó incompleta. Reintentá.',
                    UPLOAD_ERR_NO_FILE   => 'No se recibió ningún archivo.',
                    UPLOAD_ERR_NO_TMP_DIR=> 'Falta la carpeta temporal de PHP en el servidor.',
                    UPLOAD_ERR_CANT_WRITE=> 'El servidor no pudo escribir el archivo.',
                ];
                throw new RuntimeException($errs[$f['error']] ?? ('Error al subir el archivo (código '.$f['error'].').'));
            }
            // Solo .txt — por extensión.
            $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
            if ($ext !== 'txt') throw new RuntimeException('Solo se permiten archivos con extensión .txt');
            // Validar carga COMPLETA: el tamaño en disco debe coincidir con el
            // tamaño declarado por el navegador (detecta cortes a mitad de subida).
            $real = @filesize($f['tmp_name']);
            if ($real === false || $real <= 0) throw new RuntimeException('El archivo llegó vacío.');
            $declarado = isset($_POST['size']) ? (int)$_POST['size'] : $real;
            if ($declarado > 0 && $real !== $declarado) {
                throw new RuntimeException('La carga quedó incompleta (se recibieron '.$real.' de '.$declarado.' bytes). Reintentá.');
            }
            $contenido = file_get_contents($f['tmp_name']);
            if ($contenido === false || trim($contenido) === '') throw new RuntimeException('El archivo está vacío o no se pudo leer.');
            // Conteo informativo de facturas (líneas FAC|…).
            $facCount = (int)preg_match_all('/^\s*FAC\|/mi', $contenido);
            // Limpieza de temporales viejos (> 1 hora) para no acumular basura.
            foreach (glob($tmpDir.'/*.txt') ?: [] as $old) { if (@filemtime($old) < time()-3600) @unlink($old); }
            $ticket = bin2hex(random_bytes(16));
            if (file_put_contents($tmpDir.'/'.$ticket.'.txt', $contenido) === false) {
                throw new RuntimeException('No se pudo guardar la carga en el servidor (permisos de storage/).');
            }
            echo json_encode(['ok'=>true, 'ticket'=>$ticket, 'nombre'=>(string)$f['name'], 'bytes'=>$real, 'facturas'=>$facCount]);
            exit;
        }

        // ── Fase 2: disparar el automatizador con el archivo ya cargado ──
        if ($accion === 'ps_ejecutar') {
            $ticket = preg_replace('/[^a-f0-9]/', '', (string)($_POST['ticket'] ?? ''));
            if ($ticket === '') throw new RuntimeException('Falta el ticket de la carga.');
            $path = $tmpDir.'/'.$ticket.'.txt';
            if (!is_file($path)) throw new RuntimeException('La carga expiró o no existe. Volvé a subir el archivo.');

            $url   = (string)(env('AUTOMATIZADOR_URL', '') ?? '');
            $token = (string)(env('AUTOMATIZADOR_TOKEN', '') ?? '');
            if ($url === '') throw new RuntimeException('Falta configurar AUTOMATIZADOR_URL en el .env (URL del endpoint del automatizador).');
            if (!function_exists('curl_init')) throw new RuntimeException('La extensión cURL de PHP no está disponible en este servidor.');

            $cfile = new CURLFile($path, 'text/plain', 'prueba_'.date('YmdHis').'.txt');
            $headers = [];
            if ($token !== '') $headers[] = 'X-API-Token: '.$token;

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => ['archivo' => $cfile],
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body     = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($body === false) throw new RuntimeException('No se pudo contactar al automatizador: '.($curlErr ?: 'error de red').'. Verificá AUTOMATIZADOR_URL.');
            $data = json_decode((string)$body, true);
            if (!is_array($data)) {
                throw new RuntimeException('El automatizador respondió algo inesperado (HTTP '.$httpCode.'): '.mb_substr(strip_tags((string)$body), 0, 300));
            }
            // Éxito real → limpiamos el temporal (ya se procesó).
            if (!empty($data['ok'])) @unlink($path);
            echo json_encode(['ok'=>!empty($data['ok']), 'http'=>$httpCode, 'resultado'=>$data]);
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['ok'=>false, 'error'=>$e->getMessage()]);
        exit;
    }
}

// POST guardar factura
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='guardar') {
    try {
        $pdo->beginTransaction();
        $cNombre=trim($_POST['c_nombre']??'');
        $cRuc=num($_POST['c_ruc']??''); $cDv=num($_POST['c_dv']??'');
        $cEmail=trim($_POST['c_email']??'');
        $cTel=trim($_POST['c_tel']??''); $cCel=trim($_POST['c_cel']??'');
        $cDir=trim($_POST['c_dir']??''); $cCasa=trim($_POST['c_casa']??'');
        $pN1=trim($_POST['p_n1']??''); $pN2=trim($_POST['p_n2']??'');
        $pA1=trim($_POST['p_a1']??''); $pA2=trim($_POST['p_a2']??'');
        $pTd=(int)($_POST['p_td']??1); $pDoc=trim($_POST['p_doc']??'');

        if ($cNombre===''||$cRuc===''||$cDv===''||$cEmail==='')
            throw new RuntimeException('Nombre, RUC, DV y correo son obligatorios.');
        // SIFEN valida el formato del correo (campo D216/dEmailRec): exige etiquetas de
        // dominio de 2+ caracteres y TLD de 2..9 letras. Validar acá evita rechazos en homologación.
        if (!preg_match('/^[0-9A-Za-z][\w.\-]*@([0-9A-Za-z][\w\-]*[0-9A-Za-z]\.)+[A-Za-z]{2,9}$/', $cEmail))
            throw new RuntimeException('El correo del cliente no tiene un formato válido para SIFEN (ej: nombre@dominio.com).');

        // Upsert persona
        $personaId=null;
        if ($pN1!==''&&$pA1!==''&&$pDoc!=='') {
            $s=$pdo->prepare("SELECT id FROM personas WHERE tipo_documento_id=:td AND numero_documento=:nd LIMIT 1");
            $s->execute([':td'=>$pTd,':nd'=>$pDoc]);
            $pid=$s->fetchColumn();
            if ($pid) {
                $pdo->prepare("UPDATE personas SET nombre1=:n1,nombre2=:n2,apellido1=:a1,apellido2=:a2,telefono=:t,celular=:c,email=:e WHERE id=:id")
                    ->execute([':n1'=>$pN1,':n2'=>($pN2===''?null:$pN2),':a1'=>$pA1,':a2'=>($pA2===''?null:$pA2),':t'=>($cTel?:null),':c'=>($cCel?:null),':e'=>($cEmail?:null),':id'=>$pid]);
                $personaId=(int)$pid;
            } else {
                $pdo->prepare("INSERT INTO personas(nombre1,nombre2,apellido1,apellido2,tipo_documento_id,numero_documento,telefono,celular,email) VALUES(:n1,:n2,:a1,:a2,:td,:nd,:t,:c,:e)")
                    ->execute([':n1'=>$pN1,':n2'=>($pN2===''?null:$pN2),':a1'=>$pA1,':a2'=>($pA2===''?null:$pA2),':td'=>$pTd,':nd'=>$pDoc,':t'=>($cTel?:null),':c'=>($cCel?:null),':e'=>($cEmail?:null)]);
                $personaId=(int)$pdo->lastInsertId();
            }
        }

        // Upsert cliente
        $s=$pdo->prepare("SELECT id FROM customers WHERE ruc=:r AND dv=:d LIMIT 1");
        $s->execute([':r'=>$cRuc,':d'=>$cDv]); $custId=$s->fetchColumn();
        if ($custId) {
            $pdo->prepare("UPDATE customers SET persona_id=:pi,nombre_razon_social=:n,direccion=:dir,numero_casa=:ca,telefono=:t,celular=:c,email=:e WHERE id=:id")
                ->execute([':pi'=>$personaId,':n'=>$cNombre,':dir'=>($cDir?:null),':ca'=>($cCasa?:null),':t'=>($cTel?:null),':c'=>($cCel?:null),':e'=>$cEmail,':id'=>$custId]);
        } else {
            $pdo->prepare("INSERT INTO customers(persona_id,naturaleza_receptor,tipo_operacion,codigo_pais,descripcion_pais,tipo_contribuyente,ruc,dv,nombre_razon_social,direccion,numero_casa,codigo_departamento,codigo_ciudad,telefono,celular,email,codigo_cliente) VALUES(:pi,1,1,'PRY','Paraguay',2,:r,:d,:n,:dir,:ca,'11','1',:t,:c,:e,:cod)")
                ->execute([':pi'=>$personaId,':r'=>$cRuc,':d'=>$cDv,':n'=>$cNombre,':dir'=>($cDir?:null),':ca'=>($cCasa?:null),':t'=>($cTel?:null),':c'=>($cCel?:null),':e'=>$cEmail,':cod'=>'CLI'.date('His')]);
            $custId=(int)$pdo->lastInsertId();
        }

        // Items
        $descs=$_POST['i_desc']??[]; $codes=$_POST['i_cod']??[];
        $cants=$_POST['i_cant']??[]; $precs=$_POST['i_prec']??[]; $ivas=$_POST['i_iva']??[];
        $items=[]; $sub10=0.0; $sub5=0.0; $subEx=0.0; $iva10=0.0; $iva5=0.0; $b10=0.0; $b5=0.0;
        foreach ($descs as $i=>$d) {
            $d=trim($d); if($d==='') continue;
            $cant=max(0.01,(float)str_replace(',','.',$cants[$i]??'1'));
            $prec=max(0,(float)str_replace(',','.',$precs[$i]??'0'));
            // SIFEN rechaza ítems sin valor: cada ítem cargado debe tener precio > 0
            // y una descripción mínima (evita líneas basura que invalidan el DE).
            if ($prec<=0) throw new RuntimeException("El ítem \"$d\" tiene precio 0. Cada ítem debe tener un precio mayor a 0.");
            if (mb_strlen($d)<2) throw new RuntimeException("La descripción del ítem \"$d\" es muy corta (mínimo 2 caracteres).");
            $tasa=(int)($ivas[$i]??10);
            $tot=round($cant*$prec,8);
            $base=0.0; $iva=0.0; $afec=3;
            if($tasa===10){$afec=1;$base=round($tot/1.10,8);$iva=round($tot-$base,8);$sub10+=$tot;$iva10+=$iva;$b10+=$base;}
            elseif($tasa===5){$afec=1;$base=round($tot/1.05,8);$iva=round($tot-$base,8);$sub5+=$tot;$iva5+=$iva;$b5+=$base;}
            else{$subEx+=$tot;}
            $items[]=['cod'=>trim($codes[$i]??'ITEM'),'desc'=>$d,'cant'=>$cant,'prec'=>$prec,'tasa'=>$tasa,'tot'=>$tot,'afec'=>$afec,'base'=>$base,'iva'=>$iva];
        }
        if(empty($items)) throw new RuntimeException('Agregá al menos un ítem.');
        $totalNeto=round($sub10+$sub5+$subEx,8);
        $totalIva=round($iva10+$iva5,8);

        $fNum=trim($_POST['f_num']??'')?:nextNum($pdo);
        $fFecha=trim($_POST['f_fecha']??'')?:(new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $fFecha=str_replace('T',' ',$fFecha); if(strlen($fFecha)===16) $fFecha.=':00';
        $fDesc=trim($_POST['f_desc']??'Factura electrónica');
        $fEstado=in_array($_POST['f_estado']??'',['PENDIENTE','PAGADO'],true)?$_POST['f_estado']:'PENDIENTE';
        $fTipoPago=(int)($_POST['f_tipo_pago']??1);
        $fDescPago=trim($_POST['f_desc_pago']??'Efectivo');
        $procesar=isset($_POST['f_procesar']);

        $pdo->prepare("INSERT INTO invoices(customer_id,numero,establecimiento,punto,fecha_emision,fecha_firma,descripcion,subtotal_exenta,subtotal_5,subtotal_10,total_neto,iva_5,iva_10,total_iva,base_gravada_5,base_gravada_10,total_base_gravada,total,estado_pago) VALUES(:cid,:num,'001','001',:fe,:ff,:desc,:sex,:s5,:s10,:tn,:i5,:i10,:tiv,:b5,:b10,:tb,:tot,:ep)")
            ->execute([':cid'=>$custId,':num'=>$fNum,':fe'=>$fFecha,':ff'=>$fFecha,':desc'=>$fDesc,':sex'=>$subEx,':s5'=>$sub5,':s10'=>$sub10,':tn'=>$totalNeto,':i5'=>$iva5,':i10'=>$iva10,':tiv'=>$totalIva,':b5'=>$b5,':b10'=>$b10,':tb'=>round($b5+$b10,8),':tot'=>$totalNeto,':ep'=>($procesar?'PAGADO':$fEstado)]);
        $invId=(int)$pdo->lastInsertId();

        $si=$pdo->prepare("INSERT INTO invoice_items(invoice_id,codigo,descripcion,cantidad,precio_unitario,afectacion_iva,descripcion_afectacion_iva,proporcion_iva,tasa_iva,total_linea) VALUES(:iid,:cod,:des,:can,:pu,:afec,:dafec,100,:tasa,:tl)");
        foreach($items as $it){
            $da=match($it['afec']){1=>'Gravado IVA',2=>'Exonerado',3=>'Exento',default=>'Gravado IVA'};
            $si->execute([':iid'=>$invId,':cod'=>$it['cod'],':des'=>$it['desc'],':can'=>$it['cant'],':pu'=>$it['prec'],':afec'=>$it['afec'],':dafec'=>$da,':tasa'=>$it['tasa'],':tl'=>$it['tot']]);
        }
        $pdo->prepare("INSERT INTO payments(invoice_id,tipo_pago,descripcion_tipo_pago,monto,fecha_pago) VALUES(:iid,:tp,:dtp,:m,NOW())")
            ->execute([':iid'=>$invId,':tp'=>$fTipoPago,':dtp'=>$fDescPago,':m'=>$totalNeto]);
        $pdo->commit();
        $msg="✅ Factura N° $fNum guardada correctamente (ID=$invId)." . ($procesar?' Será procesada por el worker.':'');
        // Reload status
        $status=(new DemoStatusService(new InvoiceRepository($pdo),new FeQueueRepository($pdo),new EmailQueueRepository($pdo),new EmailLogRepository($pdo)))->snapshot();
    } catch(Throwable $e) {
        if($pdo->inTransaction()) $pdo->rollBack();
        $err=$e->getMessage();
    }
}

// POST cambiar estado
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='estado') {
    $id=(int)($_POST['inv_id']??0);
    $es=in_array($_POST['nuevo_estado']??'',['PENDIENTE','PAGADO'],true)?$_POST['nuevo_estado']:null;
    if($id>0&&$es){
        $pdo->prepare("UPDATE invoices SET estado_pago=:e,updated_at=NOW() WHERE id=:id")->execute([':e'=>$es,':id'=>$id]);
        $msg="Estado de factura #$id actualizado a $es.";
        $status=(new DemoStatusService(new InvoiceRepository($pdo),new FeQueueRepository($pdo),new EmailQueueRepository($pdo),new EmailLogRepository($pdo)))->snapshot();
    }
}

// POST cancelar DE — Manual SIFEN cap. 11. Solo facturas APROBADAS, dentro del plazo legal.
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='cancelar_de') {
    try {
        $invId  = (int)($_POST['inv_id']??0);
        $motivo = trim((string)($_POST['motivo']??''));
        if ($invId<=0) throw new RuntimeException('Factura inválida.');
        $cancel = new CancellationService(
            new InvoiceRepository($pdo),
            new InvoiceEventRepository($pdo),
            new EmitterRepository($pdo),
            new SifenBridge($cfg['sifen']),
        );
        $r = $cancel->cancelByInvoiceId($invId, $motivo);
        if ($r['ok']) {
            $msg = "✅ Factura #{$r['invoice_id']} cancelada en SIFEN. idEvento={$r['idEvento']}";
        } else {
            $err = "❌ SIFEN no aprobó la cancelación (cód. {$r['codigo']}): {$r['mensaje']}";
        }
        $status=(new DemoStatusService(new InvoiceRepository($pdo),new FeQueueRepository($pdo),new EmailQueueRepository($pdo),new EmailLogRepository($pdo)))->snapshot();
    } catch (Throwable $e) {
        $err = $e->getMessage();
    }
}

$nextNum=nextNum($pdo);
$tiposDI=$pdo->query("SELECT * FROM tipos_documento_identidad ORDER BY codigo")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIFEN v150 — Facturación Electrónica</title>
<style>/* ============================================================
   Facturación SIFEN — diseño hi-fi aplicado a la app real
   Sistema visual: institucional / fiscal · IBM Plex
   ============================================================ */
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');

:root{
  --bg:#eef2f7;
  --card:#ffffff;
  --sidebar:#13243d;
  --sidebar-hi:#1d3a5f;
  --sidebar-tx:#aebccf;
  --sidebar-tx-dim:#7488a3;
  --brand:#1d5fa3;
  --brand-strong:#174a80;
  --brand-soft:#e9f1fa;
  --brand-bd:#bcd5ee;
  --ink:#16202c;
  --muted:#5c6776;
  --line:#e2e8f0;
  --line-strong:#cbd4e0;
  --ok:#1c7d4d; --ok-soft:#e6f3ec; --ok-bd:#b5ddc6;
  --warn:#b07816; --warn-soft:#f8efdd; --warn-bd:#e7d09e;
  --err:#c0392b; --err-soft:#f9e8e6; --err-bd:#eabfba;
  --teal:#0f766e; --teal-soft:#e3f1f0; --teal-bd:#b3d8d4;
  --violet:#5b53a6; --violet-soft:#edecf7; --violet-bd:#cbc7e6;
  --font:'IBM Plex Sans', system-ui, sans-serif;
  --mono:'IBM Plex Mono', ui-monospace, monospace;
  --r:9px;
  --sh:0 1px 2px rgba(20,35,61,.05), 0 4px 14px rgba(20,35,61,.06);
  --sh-lg:0 18px 50px rgba(16,28,48,.28);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{font-family:var(--font);color:var(--ink);background:var(--bg);font-size:14.5px;line-height:1.5;-webkit-font-smoothing:antialiased}
button,input,select,textarea{font-family:inherit}
a{color:var(--brand);text-decoration:none}
a:hover{text-decoration:underline}
.num,.mono{font-variant-numeric:tabular-nums;font-feature-settings:"tnum"}

/* ---------------- App shell ---------------- */
.app{display:grid;grid-template-columns:248px 1fr;height:100vh;overflow:hidden}

/* Sidebar */
.side{background:var(--sidebar);color:var(--sidebar-tx);display:flex;flex-direction:column;padding:18px 14px;gap:6px;overflow-y:auto}
.side .logo{display:flex;align-items:center;gap:11px;padding:6px 8px 16px;border-bottom:1px solid rgba(255,255,255,.08);margin-bottom:10px}
.side .logo .mark{width:34px;height:34px;border-radius:8px;background:linear-gradient(150deg,var(--brand),#2b7fc9);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:15px;flex:0 0 auto;letter-spacing:.5px}
.side .logo .nm{font-weight:600;color:#fff;font-size:15px;line-height:1.15}
.side .logo .nm small{display:block;font-weight:400;font-size:11px;color:var(--sidebar-tx-dim);letter-spacing:.3px}
.side .sect{font-size:10.5px;text-transform:uppercase;letter-spacing:.7px;color:var(--sidebar-tx-dim);padding:12px 10px 5px;font-weight:600}
.nav-i{display:flex;align-items:center;gap:11px;padding:9px 11px;border-radius:7px;color:var(--sidebar-tx);cursor:pointer;font-size:14px;font-weight:500;border:none;background:transparent;width:100%;text-align:left;transition:.12s;position:relative}
.nav-i svg{width:18px;height:18px;flex:0 0 auto;opacity:.85}
.nav-i:hover{background:var(--sidebar-hi);color:#fff}
.nav-i.on{background:var(--brand);color:#fff}
.nav-i.on svg{opacity:1}
.nav-i .cnt{margin-left:auto;font-size:11px;font-weight:600;background:rgba(255,255,255,.16);color:#fff;border-radius:99px;padding:1px 8px;min-width:22px;text-align:center}
.nav-i.warnb .cnt{background:var(--warn)}
.side .spacer{flex:1}
.side .foot{border-top:1px solid rgba(255,255,255,.08);padding-top:10px;margin-top:6px}
.modo-tag{display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--warn);background:rgba(176,120,22,.14);border:1px solid rgba(176,120,22,.35);border-radius:7px;padding:7px 10px;font-weight:600;letter-spacing:.3px}
.modo-tag .d{width:7px;height:7px;border-radius:50%;background:var(--warn)}

/* Main column */
.main{display:flex;flex-direction:column;overflow:hidden}
.topbar{display:flex;align-items:center;gap:16px;padding:0 26px;height:62px;background:var(--card);border-bottom:1px solid var(--line);flex:0 0 auto}
.topbar h1{font-size:19px;font-weight:600;letter-spacing:-.2px}
.topbar .grow{flex:1}
.search{display:flex;align-items:center;gap:9px;background:var(--bg);border:1px solid var(--line);border-radius:8px;padding:8px 13px;width:300px;color:var(--muted);font-size:13.5px}
.search svg{width:16px;height:16px;opacity:.7;flex:0 0 auto}
.search input{border:none;background:transparent;outline:none;width:100%;font-size:13.5px;color:var(--ink)}
.avatar{width:36px;height:36px;border-radius:50%;background:var(--brand-soft);color:var(--brand-strong);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:14px;border:1px solid var(--brand-bd);flex:0 0 auto}
.content{overflow-y:auto;padding:26px;flex:1}
.view{display:none;max-width:1080px;margin:0 auto}
.view.on{display:block}

/* ---------------- Reusable ---------------- */
.page-head{display:flex;align-items:flex-end;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:22px}
.page-head .ttl{font-size:23px;font-weight:600;letter-spacing:-.3px}
.page-head .desc{font-size:13.5px;color:var(--muted);margin-top:3px}

.card{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);margin-bottom:18px}
.card.pad{padding:20px 22px}
.card-h{display:flex;align-items:center;gap:11px;padding:15px 22px;border-bottom:1px solid var(--line);flex-wrap:wrap}
.card-h .ct{font-size:15px;font-weight:600;display:flex;align-items:center;gap:9px}
.card-h .grow{flex:1}
.card-b{padding:18px 22px}
.step-num{background:var(--brand);color:#fff;width:24px;height:24px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex:0 0 auto}

.btn{display:inline-flex;align-items:center;gap:7px;font-size:13.5px;font-weight:600;cursor:pointer;background:var(--card);color:var(--ink);border:1px solid var(--line-strong);border-radius:8px;padding:9px 16px;transition:.12s;white-space:nowrap;text-decoration:none}
.btn svg{width:16px;height:16px}
.btn:hover{background:#f6f8fb;text-decoration:none}
.btn.pri,.btn-primary,.btn-blue{background:var(--brand);color:#fff;border-color:var(--brand-strong)}
.btn.pri:hover,.btn-primary:hover,.btn-blue:hover{background:var(--brand-strong)}
.btn.ok,.btn-green{background:var(--ok);color:#fff;border-color:#176a40}
.btn.ok:hover,.btn-green:hover{filter:brightness(.95)}
.btn-red{background:var(--err);color:#fff;border-color:#a5281c}
.btn-red:hover{filter:brightness(.96)}
.btn.ghost,.btn-ghost{background:transparent;border-color:var(--line-strong);color:var(--muted)}
.btn.ghost:hover,.btn-ghost:hover{background:var(--bg)}
.btn.lg{font-size:15px;padding:12px 22px}
.btn.sm,.btn-sm{font-size:12.5px;padding:6px 12px;border-radius:7px}
.btn[disabled]{opacity:.45;cursor:not-allowed}
.btn-full{width:100%;justify-content:center}
/* action-colored */
.btn.a-qr{color:var(--teal);border-color:var(--teal-bd);background:var(--teal-soft)}
.btn.a-qr:hover{background:#d6ebe9}
.btn.a-pdf{color:var(--err);border-color:var(--err-bd);background:var(--err-soft)}
.btn.a-pdf:hover{background:#f5dcd9}
.btn.a-xml{color:var(--brand);border-color:var(--brand-bd);background:var(--brand-soft)}
.btn.a-xml:hover{background:#dceaf7}
.btn.a-edit{color:var(--violet);border-color:var(--violet-bd);background:var(--violet-soft)}
.btn.a-cancel{color:var(--err);border-color:var(--err-bd);background:#fff}
.btn.a-cancel:hover{background:var(--err-soft)}

.lbl,.field label{display:block;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--muted);margin-bottom:5px}
.lbl .req,.field label .req{color:var(--err)}
.in,.field input,.field select,.field textarea{width:100%;height:40px;padding:0 13px;font-size:14px;color:var(--ink);background:#fff;border:1px solid var(--line-strong);border-radius:8px;transition:.12s}
.field textarea{height:auto;padding:10px 13px}
.in::placeholder,.field input::placeholder{color:#9aa6b4}
.in:focus,.field input:focus,.field select:focus,.field textarea:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}
.field input[readonly]{background:#f1f5f9;color:var(--muted);cursor:not-allowed}
select.in,.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%235c6776' stroke-width='2.5'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:34px}
.field{display:flex;flex-direction:column;margin-bottom:0}
.grid{display:grid;gap:14px}
.g2{grid-template-columns:1fr 1fr}
.g3{grid-template-columns:1fr 1fr 1fr}
.g4{grid-template-columns:repeat(4,1fr)}
.g-ruc{display:grid;grid-template-columns:1fr 90px auto auto;gap:10px;align-items:end}
.span2{grid-column:span 2}
.row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}

/* badges (PHP badge() outputs .badge.badge-xx) */
.bdg,.badge{display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:600;padding:3px 10px;border-radius:5px;border:1px solid transparent;letter-spacing:.2px}
.bdg .dot{width:6px;height:6px;border-radius:50%;background:currentColor}
.bdg.ok,.badge-ok{color:var(--ok);background:var(--ok-soft);border-color:var(--ok-bd)}
.bdg.warn,.badge-warn{color:var(--warn);background:var(--warn-soft);border-color:var(--warn-bd)}
.bdg.err,.badge-err{color:var(--err);background:var(--err-soft);border-color:var(--err-bd)}
.bdg.info,.badge-info{color:var(--brand);background:var(--brand-soft);border-color:var(--brand-bd)}
.bdg.gray,.badge-gray{color:var(--muted);background:#eef1f5;border-color:var(--line)}

/* tables */
.tbl,.dt,.it{width:100%;border-collapse:collapse;font-size:13.5px}
.tbl th,.dt th{text-align:left;font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);padding:11px 14px;border-bottom:1px solid var(--line-strong);background:#f7f9fc}
.tbl td,.dt td{padding:12px 14px;border-bottom:1px solid var(--line);vertical-align:middle}
.tbl tbody tr:last-child td,.dt tbody tr:last-child td{border-bottom:none}
.tbl tbody tr:hover,.dt tbody tr:hover{background:#f9fbfd}
.tbl .mono,.dt .mono,.mono{font-family:var(--mono);font-size:12.5px;color:var(--muted)}
.num{text-align:right}
.acts{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap;align-items:center}
.acts-td{vertical-align:middle}
.col-adj{width:104px;min-width:104px}
.col-acc{width:158px;min-width:158px}
.tbl-wrap{overflow-x:auto}

/* items editable table */
.it th{background:#f7f9fc}
.it td{padding:7px 9px;border-bottom:1px solid var(--line)}
.it input,.it select{height:36px;padding:0 9px;border:1px solid var(--line-strong);border-radius:7px;font-size:13.5px;width:100%;background:#fff}
.it input:focus,.it select:focus{outline:none;border-color:var(--brand);box-shadow:0 0 0 3px var(--brand-soft)}

/* stat tiles */
.tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(168px,1fr));gap:14px}
.stat{background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);padding:16px 18px;position:relative;overflow:hidden}
.stat::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--line-strong)}
.stat .k{font-size:28px;font-weight:700;letter-spacing:-.5px;line-height:1}
.stat .l{font-size:12px;color:var(--muted);margin-top:8px;font-weight:500}
.stat.b::before{background:var(--brand)} .stat.b .k{color:var(--brand)}
.stat.w::before{background:var(--warn)} .stat.w .k{color:var(--warn)}
.stat.e::before{background:var(--err)} .stat.e .k{color:var(--err)}
.stat.o::before{background:var(--ok)} .stat.o .k{color:var(--ok)}

/* envío counters (original ids env-stat) */
.env-row{display:flex;gap:12px;flex-wrap:wrap;margin:6px 0 18px}
.env-stat{flex:1;min-width:130px;background:var(--card);border:1px solid var(--line);border-radius:var(--r);box-shadow:var(--sh);padding:15px 17px;display:flex;flex-direction:column;gap:6px;position:relative;overflow:hidden}
.env-stat::before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--line-strong)}
.env-stat .env-k{font-size:26px;font-weight:700;font-variant-numeric:tabular-nums;line-height:1}
.env-stat .env-l{font-size:11px;text-transform:uppercase;letter-spacing:.4px;color:var(--muted);font-weight:600}
.env-stat.env-warn::before{background:var(--warn)} .env-stat.env-warn .env-k{color:var(--warn)}
.env-stat.env-ok::before{background:var(--ok)} .env-stat.env-ok .env-k{color:var(--ok)}
.env-stat.env-err::before{background:var(--err)} .env-stat.env-err .env-k{color:var(--err)}

/* wizard stepper */
.steps{display:flex;align-items:center;gap:4px;margin-bottom:22px}
.steps .st{display:flex;align-items:center;gap:10px;padding:8px 4px}
.steps .c{width:30px;height:30px;border-radius:50%;border:1.5px solid var(--line-strong);background:#fff;color:var(--muted);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:13.5px;flex:0 0 auto;transition:.15s}
.steps .nm{font-size:14px;color:var(--muted);font-weight:500}
.steps .st.on .c{background:var(--brand);border-color:var(--brand-strong);color:#fff;box-shadow:0 0 0 4px var(--brand-soft)}
.steps .st.on .nm{color:var(--ink);font-weight:600}
.steps .st.done .c{background:var(--ok);border-color:#176a40;color:#fff}
.steps .st.done .nm{color:var(--ink)}
.steps .ln{flex:1;height:2px;background:var(--line-strong);margin:0 6px;border-radius:2px;min-width:24px}
.steps .ln.done{background:var(--ok)}

/* callouts / alerts (PHP $msg/$err use .alert.alert-xx) */
.callout,.alert{display:flex;gap:11px;align-items:flex-start;font-size:13.5px;line-height:1.5;border-radius:8px;padding:12px 15px;border:1px solid;border-left-width:4px;margin-bottom:16px}
.callout svg{width:18px;height:18px;flex:0 0 auto;margin-top:1px}
.callout.info,.alert-info{color:var(--brand-strong);background:var(--brand-soft);border-color:var(--brand-bd)}
.callout.warn,.alert-warn{color:#7c531a;background:var(--warn-soft);border-color:var(--warn-bd)}
.callout.ok,.alert-ok{color:#155f3a;background:var(--ok-soft);border-color:var(--ok-bd)}
.callout.err,.alert-err{color:#8f2c20;background:var(--err-soft);border-color:var(--err-bd)}

/* found-box (RUC search result) */
.found-box{border-radius:8px;padding:10px 14px;margin-top:6px;display:none;font-size:13.5px}
.found-box.vis{display:block}

/* persona box */
.persona-box{background:#f7f9fc;border:1px solid var(--line);border-radius:8px;padding:16px;margin-top:16px}
.persona-box .pb-title{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:12px;display:flex;align-items:center;gap:8px}

/* totals bar */
.totals{display:flex;gap:30px;flex-wrap:wrap;align-items:center;background:var(--sidebar);color:#dce5f0;border-radius:var(--r);padding:16px 22px;margin-top:16px}
.totals .ti{display:flex;flex-direction:column;gap:3px}
.totals .tl{font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--sidebar-tx-dim);font-weight:500}
.totals .tv{font-size:17px;font-weight:600}
.totals .big{margin-left:auto}
.totals .big .tv{font-size:25px;color:#fff;font-weight:700}

/* QR modal */
.overlay{position:fixed;inset:0;background:rgba(15,24,40,.55);backdrop-filter:blur(2px);display:none;align-items:center;justify-content:center;z-index:100;padding:24px}
.overlay.on{display:flex}
.modal{width:min(620px,100%);background:var(--card);border-radius:14px;box-shadow:var(--sh-lg);overflow:hidden}
.modal .mh{display:flex;align-items:center;gap:12px;padding:17px 22px;border-bottom:1px solid var(--line)}
.modal .mh .mt{font-size:16px;font-weight:600}
.modal .mh .x{margin-left:auto;width:32px;height:32px;border-radius:7px;border:1px solid var(--line);background:#fff;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--muted)}
.modal .mh .x:hover{background:var(--bg)}
.modal .mb{display:flex;gap:24px;padding:24px 22px;flex-wrap:wrap}
.qr-box{width:168px;height:168px;flex:0 0 auto;border:1px solid var(--line-strong);border-radius:10px;background:#fff;padding:10px;display:flex;align-items:center;justify-content:center}
.qr-box svg{width:100%;height:100%}
.qr-data{flex:1;min-width:230px}
.drow{display:flex;justify-content:space-between;gap:16px;padding:9px 0;border-bottom:1px solid var(--line);font-size:13.5px}
.drow:last-child{border-bottom:none}
.drow .dk{color:var(--muted)}
.drow .dv{font-weight:600;text-align:right}
.modal .mf{display:flex;gap:9px;flex-wrap:wrap;align-items:center;padding:15px 22px;border-top:1px solid var(--line);background:#f7f9fc}

/* Prueba de Script — zona drag & drop */
.ps-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;text-align:center;
  border:2px dashed var(--line-strong);border-radius:11px;background:#f7f9fc;padding:34px 20px;cursor:pointer;transition:.15s}
.ps-drop:hover{border-color:var(--brand-bd);background:var(--brand-soft)}
.ps-drop.drag{border-color:var(--brand);background:var(--brand-soft);box-shadow:0 0 0 3px var(--brand-soft)}
.ps-drop .ps-drop-ic{width:46px;height:46px;color:var(--brand);opacity:.85}
.ps-drop .ps-drop-t{font-size:15px;font-weight:600;color:var(--ink)}
.ps-drop .ps-drop-s{font-size:12px;color:var(--muted);font-weight:600;letter-spacing:.5px}
.ps-drop .ps-drop-hint{font-size:12px;margin-top:2px}
.ps-drop code{font-family:var(--mono);font-size:12px;background:#fff;border:1px solid var(--line);border-radius:4px;padding:1px 5px;color:var(--muted)}

/* progress + log */
.prog{height:14px;background:#dde4ec;border-radius:99px;overflow:hidden;border:1px solid var(--line-strong)}
.prog>span{display:block;height:100%;background:var(--ok);border-radius:99px;transition:width .25s}
.log{background:#0f1b2d;color:#b8c4d6;border-radius:9px;padding:15px 17px;font-family:var(--mono);font-size:12.5px;line-height:1.7;max-height:280px;overflow:auto}
.log .env-log-line{white-space:pre-wrap;word-break:break-word}

.muted{color:var(--muted)}
.chips{display:flex;gap:8px;flex-wrap:wrap}
.chip{font-size:13px;font-weight:500;border:1px solid var(--line-strong);border-radius:99px;padding:5px 14px;background:#fff;color:var(--muted);cursor:pointer}
.chip:hover{border-color:var(--brand-bd)}
.chip.on{background:var(--ink);color:#fff;border-color:var(--ink)}
.flexb{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}

/* spinner */
.spin{display:inline-block;width:13px;height:13px;border:2px solid rgba(255,255,255,.45);border-top-color:#fff;border-radius:50%;animation:sp .6s linear infinite;vertical-align:-1px}
@keyframes sp{to{transform:rotate(360deg)}}

/* ===== Navegación móvil: botón hamburguesa + backdrop (ocultos en escritorio) ===== */
.menu-btn{display:none;align-items:center;justify-content:center;width:38px;height:38px;border-radius:8px;border:1px solid var(--line-strong);background:var(--card);color:var(--ink);cursor:pointer;flex:0 0 auto;padding:0}
.menu-btn svg{width:20px;height:20px}
.nav-backdrop{display:none;position:fixed;inset:0;background:rgba(15,24,40,.45);z-index:95}
.app.nav-open .nav-backdrop{display:block}

/* ===== Responsive — solo mejora el comportamiento en otros dispositivos, no el diseño ===== */
@media(max-width:1024px){
  .view{max-width:none}
  .content{padding:22px 20px}
}

/* Tablet vertical / abajo: el sidebar (única navegación) pasa a cajón deslizable */
@media(max-width:920px){
  .app{grid-template-columns:1fr}
  .menu-btn{display:inline-flex}
  .side{position:fixed;top:0;left:0;bottom:0;width:268px;max-width:84vw;z-index:100;
        transform:translateX(-100%);transition:transform .25s ease;box-shadow:0 18px 50px rgba(0,0,0,.35)}
  .app.nav-open .side{transform:translateX(0)}
  .topbar{padding:0 16px;gap:12px}
  .topbar .search{display:none}
  .content{padding:18px 16px}
  .g4{grid-template-columns:1fr 1fr}
  .g3,.g2{grid-template-columns:1fr}
  .g-ruc{grid-template-columns:1fr 80px auto auto}
}

/* Celulares */
@media(max-width:600px){
  .topbar{height:54px;padding:0 12px}
  .topbar h1{font-size:16.5px}
  .content{padding:14px 12px}
  .page-head{margin-bottom:16px}
  .page-head .ttl{font-size:20px}
  .card-h,.card-b{padding:13px 15px}
  .card-h input[data-filter]{width:100%!important;max-width:100%!important;flex-basis:100%}
  .g4,.g-ruc{grid-template-columns:1fr 1fr}
  .tiles{grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px}
  .stat .k{font-size:24px}
  .totals{gap:14px;padding:14px 16px}
  .totals .big{margin-left:0}
  .totals .big .tv{font-size:21px}
  .steps{gap:2px}
  .steps .nm{display:none}
  .steps .ln{min-width:14px;margin:0 3px}
  .acts{justify-content:flex-start}
  .btn.lg{padding:11px 16px;font-size:14px}
  .modal .mb{flex-direction:column;align-items:center;gap:16px}
  .qr-box{width:200px;height:200px}
}

/* Scroll táctil suave en tablas que desbordan */
@media(max-width:760px){
  .tbl-wrap{-webkit-overflow-scrolling:touch}
  .tbl th,.tbl td,.dt th,.dt td{padding:10px 11px}
}
</style>
</head>
<?php
// ---- Contadores derivados del snapshot para el panel Inicio / sidebar ----
$hoy = (new DateTimeImmutable('now'))->format('Y-m-d');
$cEmitHoy=0; $cFactHoy=0.0; $cAprob=0;
foreach (($status['invoices']??[]) as $iv){
    $fe = strtoupper((string)($iv['fe_estado'] ?? ''));
    if (in_array($fe, ['APROBADO','AUTORIZADO','ACEPTADO','EMITIDA'], true)) $cAprob++;
    if (strpos((string)($iv['created_at']??''), $hoy) === 0){ $cEmitHoy++; $cFactHoy += (float)($iv['total_neto']??0); }
}
$cPorEnviar=0; $cSusp=0;
foreach (($status['email_queue']??[]) as $eq){
    $es = strtoupper((string)($eq['estado']??''));
    if ($es==='PENDIENTE' || $es==='SUSPENDIDO') $cPorEnviar++;
    if ($es==='SUSPENDIDO') $cSusp++;
}
$totalFact = count($status['invoices']??[]);

// ---- Mapas por factura: adjuntos (KuDE/XML) y datos de correo, para fusionar vistas ----
$adjByInv = [];   // invoice_id => ['eq'=>id_email_queue, 'pdf'=>bool, 'xml'=>bool]
foreach (($status['email_queue']??[]) as $eq){
    $iid  = (int)($eq['invoice_id']??0);
    $hasP = !empty($eq['adjunto_1']) && is_file(\App\Support\FileStore::resolveStorage((string)$eq['adjunto_1']));
    $hasX = !empty($eq['adjunto_2']) && is_file(\App\Support\FileStore::resolveStorage((string)$eq['adjunto_2']));
    if (!isset($adjByInv[$iid]) || $hasP || $hasX){
        $adjByInv[$iid] = ['eq'=>(int)($eq['id']??0), 'pdf'=>$hasP, 'xml'=>$hasX];
    }
}
$mailByInv = [];  // invoice_id => ['dest'=>correo, 'estado'=>estado_cola]
foreach (($status['email_queue']??[]) as $eq){
    $mailByInv[(int)($eq['invoice_id']??0)] = ['dest'=>(string)($eq['cliente_email']??''), 'estado'=>(string)($eq['estado']??'')];
}
$sentByInv = []; // invoice_id => fecha del último envío registrado (email_log viene ASC, la última gana)
foreach (($status['email_log']??[]) as $el){
    $sentByInv[(int)($el['invoice_id']??0)] = (string)($el['created_at']??'');
}
?>
<body>
<div class="app">

  <!-- ============ SIDEBAR ============ -->
  <aside class="side">
    <div class="logo">
      <div class="mark">FS</div>
      <div class="nm">SIFEN v150<small>Facturación electrónica</small></div>
    </div>

    <button type="button" class="nav-i" data-go="inicio">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 4l9 6.5"/><path d="M5 9.5V20h14V9.5"/><path d="M9.5 20v-6h5v6"/></svg>
      Inicio
    </button>
    <button type="button" class="nav-i" data-go="nueva">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="3" width="14" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h3"/></svg>
      Nueva factura
    </button>
    <button type="button" class="nav-i" data-go="facturas">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16v16l-2.5-1.5L15 20l-3-1.5L9 20l-2.5-1.5L4 20z"/><path d="M8 9h8M8 13h5"/></svg>
      Facturas
    </button>
    <button type="button" class="nav-i" data-go="clientes">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3 2.9-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><path d="M16.5 7.5a3 3 0 0 1 0 5M18.5 19c-.3-1.8-1-3-2-3.8"/></svg>
      Clientes
    </button>
    <button type="button" class="nav-i warnb" data-go="envio">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12 20 4l-4 16-4.5-5.5z"/><path d="M11.5 14.5 20 4"/></svg>
      Envío
      <?php if($cPorEnviar>0): ?><span class="cnt"><?=$cPorEnviar?></span><?php endif; ?>
    </button>

    <div class="sect">Avanzado</div>
    <button type="button" class="nav-i" data-go="cola">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3M5 5l2 2M17 17l2 2M19 5l-2 2M7 17l-2 2"/></svg>
      Cola de FE
    </button>
    <button type="button" class="nav-i" data-go="prueba">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M5 8V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2"/><path d="M2 15h7"/><path d="m5 12-3 3 3 3"/></svg>
      Prueba de Script
    </button>

    <div class="spacer"></div>
    <div class="foot">
      <div class="modo-tag"><span class="d"></span> MODO PRUEBA · SIFEN test</div>
    </div>
  </aside>
  <div class="nav-backdrop" id="navBackdrop" aria-hidden="true"></div>

  <!-- ============ MAIN ============ -->
  <div class="main">
    <header class="topbar">
      <button type="button" class="menu-btn" id="menuBtn" aria-label="Abrir menú" aria-expanded="false">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
      </button>
      <h1 id="pageTitle">Inicio</h1>
      <div class="grow"></div>
      <div class="avatar">AC</div>
    </header>

    <div class="content">

      <?php if($msg): ?><div class="callout ok" style="max-width:1080px;margin:0 auto 16px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><div><?=h($msg)?></div></div><?php endif; ?>
      <?php if($err): ?><div class="callout err" style="max-width:1080px;margin:0 auto 16px"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><div><?=h($err)?></div></div><?php endif; ?>

      <!-- ============ INICIO ============ -->
      <section class="view" id="v-inicio" data-screen-label="Inicio">
        <div class="page-head">
          <div>
            <div class="ttl">Resumen</div>
            <div class="desc">Estado general de la facturación electrónica</div>
          </div>
          <button type="button" class="btn pri lg" data-go="nueva">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
            Nueva factura
          </button>
        </div>

        <div class="tiles" style="margin-bottom:22px">
          <div class="stat b"><div class="k"><?=$cEmitHoy?></div><div class="l">Facturas emitidas hoy</div></div>
          <div class="stat w"><div class="k"><?=$cPorEnviar?></div><div class="l">Pendientes de envío</div></div>
          <div class="stat e"><div class="k"><?=$cSusp?></div><div class="l">Envíos suspendidos</div></div>
          <div class="stat o"><div class="k"><?=$cAprob?></div><div class="l">Aprobadas en SIFEN</div></div>
          <div class="stat"><div class="k" style="font-size:22px">₲ <?=gs($cFactHoy)?></div><div class="l">Facturado en el día</div></div>
        </div>

        <?php if($cPorEnviar>0): ?>
        <div class="callout info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
          <div>Tenés <strong><?=$cPorEnviar?> comprobante(s) listos para enviar</strong>. Entrá a <a href="#" data-go="envio" style="font-weight:600">Envío</a> y enviálos en lote — se procesan uno por uno y podés frenar y reanudar sin duplicar.</div>
        </div>
        <?php endif; ?>

        <div class="card">
          <div class="card-h"><span class="ct">Últimas facturas</span><div class="grow"></div><button type="button" class="btn sm ghost" data-go="facturas">Ver todas</button></div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead><tr><th>N°</th><th>Cliente</th><th class="num">Total ₲</th><th>Pago</th><th>Electrónica</th></tr></thead>
              <tbody>
                <?php foreach(array_slice($status['invoices']??[],0,6) as $inv): ?>
                <tr>
                  <td><strong><?=h($inv['numero'])?></strong></td>
                  <td><?=h($inv['cliente_nombre'])?></td>
                  <td class="num">₲ <?=gs((float)($inv['total_neto']??0))?></td>
                  <td><?=badge($inv['estado_pago'])?></td>
                  <td><?=badge($inv['fe_estado']??($inv['fe_emitida']?'EMITIDA':'PENDIENTE'))?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($status['invoices'])): ?>
                <tr><td colspan="5" class="muted" style="text-align:center;padding:26px">Todavía no hay facturas. Creá la primera desde <a href="#" data-go="nueva">Nueva factura</a>.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ============ NUEVA FACTURA (asistente) ============ -->
      <section class="view" id="v-nueva" data-screen-label="Nueva Factura">
        <div class="page-head">
          <div>
            <div class="ttl">Nueva factura</div>
            <div class="desc">Asistente guiado · cargá cliente, ítems y emití el comprobante</div>
          </div>
          <div class="bdg gray">Comprobante N° <?=h($nextNum)?></div>
        </div>

        <div class="steps" id="steps">
          <div class="st on" data-step="1"><span class="c">1</span><span class="nm">Cliente</span></div>
          <div class="ln"></div>
          <div class="st" data-step="2"><span class="c">2</span><span class="nm">Ítems</span></div>
          <div class="ln"></div>
          <div class="st" data-step="3"><span class="c">3</span><span class="nm">Revisar y emitir</span></div>
        </div>

        <form method="POST" id="formFactura">
          <input type="hidden" name="accion" value="guardar">

          <!-- STEP 1 -->
          <div class="wstep" id="wstep-1">
            <div class="card">
              <div class="card-h"><span class="ct"><span class="step-num">1</span> Datos del cliente</span></div>
              <div class="card-b">
                <div class="callout info" style="margin-top:0">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.2-3.2"/></svg>
                  <div>Ingresá el RUC + DV y presioná <strong>Buscar</strong>. Si el cliente ya existe, sus datos se completan automáticamente.</div>
                </div>

                <div class="g-ruc" style="margin-bottom:16px">
                  <div class="field"><label>RUC <span class="req">*</span></label><input type="text" id="c_ruc" name="c_ruc" placeholder="Ej: 5502969" inputmode="numeric"></div>
                  <div class="field"><label>DV <span class="req">*</span></label><input type="text" id="c_dv" name="c_dv" maxlength="2" placeholder="8" inputmode="numeric"></div>
                  <button type="button" class="btn pri" id="btn_buscar"><span id="bi">🔍</span> Buscar</button>
                  <button type="button" class="btn ghost" onclick="clearC()">Limpiar</button>
                </div>

                <div id="found_box" class="found-box"></div>

                <div class="grid g3" style="margin-top:4px">
                  <div class="field span2"><label>Nombre / Razón social <span class="req">*</span></label><input type="text" id="c_nombre" name="c_nombre" placeholder="Juan Pérez o EMPRESA S.A."></div>
                  <div class="field"><label>Correo electrónico <span class="req">*</span></label><input type="email" id="c_email" name="c_email" placeholder="cliente@email.com"></div>
                  <div class="field"><label>Teléfono</label><input type="text" id="c_tel" name="c_tel" placeholder="021 555111"></div>
                  <div class="field"><label>Celular</label><input type="text" id="c_cel" name="c_cel" placeholder="0981 123456"></div>
                  <div class="field"><label>N° de casa</label><input type="text" id="c_casa" name="c_casa" placeholder="1234"></div>
                  <div class="field span2"><label>Dirección</label><input type="text" id="c_dir" name="c_dir" placeholder="Av. España"></div>
                </div>

                <div class="persona-box">
                  <div class="pb-title">Persona física — nombre desglosado (opcional si es empresa)</div>
                  <div class="grid g4">
                    <div class="field"><label>Primer nombre</label><input type="text" id="p_n1" name="p_n1" placeholder="Juan"></div>
                    <div class="field"><label>Segundo nombre</label><input type="text" id="p_n2" name="p_n2" placeholder="Carlos"></div>
                    <div class="field"><label>Primer apellido</label><input type="text" id="p_a1" name="p_a1" placeholder="Pérez"></div>
                    <div class="field"><label>Segundo apellido</label><input type="text" id="p_a2" name="p_a2" placeholder="González"></div>
                    <div class="field"><label>Tipo documento</label>
                      <select id="p_td" name="p_td">
                        <?php foreach($tiposDI as $t): ?><option value="<?=$t['codigo']?>"><?=h($t['descripcion'])?></option><?php endforeach; ?>
                      </select>
                    </div>
                    <div class="field span2"><label>Número de documento</label><input type="text" id="p_doc" name="p_doc" placeholder="5502969"></div>
                  </div>
                </div>
              </div>
            </div>

            <div class="card">
              <div class="card-h"><span class="ct">Datos del comprobante</span></div>
              <div class="card-b">
                <div class="grid g3">
                  <div class="field"><label>Número</label><input type="text" name="f_num" value="<?=h($nextNum)?>"></div>
                  <div class="field"><label>Fecha y hora de emisión</label><input type="datetime-local" id="f_fecha" name="f_fecha" value="<?=h(localDateTimeValue())?>"></div>
                  <div class="field"><label>Estado inicial</label>
                    <select name="f_estado"><option value="PENDIENTE">PENDIENTE</option><option value="PAGADO">PAGADO</option></select>
                  </div>
                  <div class="field"><label>Tipo de pago</label>
                    <select name="f_tipo_pago">
                      <option value="1">1 — Efectivo</option>
                      <option value="2">2 — Cheque</option>
                      <option value="3">3 — Tarjeta de crédito</option>
                      <option value="4">4 — Tarjeta de débito</option>
                      <option value="5">5 — Transferencia bancaria</option>
                      <option value="6">6 — Giro</option>
                      <option value="7">7 — Billetera electrónica</option>
                      <option value="8">8 — Tarjeta empresarial</option>
                    </select>
                  </div>
                  <div class="field"><label>Descripción del pago</label><input type="text" name="f_desc_pago" value="Efectivo"></div>
                  <div class="field"><label>Descripción / motivo</label><input type="text" name="f_desc" value="Factura electrónica"></div>
                </div>
              </div>
            </div>

            <div class="flexb">
              <span></span>
              <button type="button" class="btn pri lg" data-next="2">Continuar a Ítems →</button>
            </div>
          </div>

          <!-- STEP 2 -->
          <div class="wstep" id="wstep-2" style="display:none">
            <div class="card">
              <div class="card-h"><span class="ct"><span class="step-num">2</span> Ítems</span><div class="grow"></div><span class="muted" style="font-size:12.5px">El IVA y los totales se calculan automáticamente</span></div>
              <div class="tbl-wrap">
                <table class="it" id="tbl_items">
                  <thead><tr>
                    <th style="width:110px">Código</th><th>Descripción</th>
                    <th style="width:90px">Cant.</th><th style="width:140px">Precio unit. ₲</th>
                    <th style="width:120px">IVA</th><th style="width:130px" class="num">Total línea ₲</th><th style="width:44px"></th>
                  </tr></thead>
                  <tbody id="tbody">
                    <tr>
                      <td><input type="text" name="i_cod[]" value="ITEM-001"></td>
                      <td><input type="text" name="i_desc[]" value="Servicio mensual" required></td>
                      <td><input type="number" name="i_cant[]" value="1" min="0.01" step="0.01" class="cx num"></td>
                      <td><input type="number" name="i_prec[]" value="110000" min="0" step="1" class="cx num"></td>
                      <td><select name="i_iva[]" class="cx"><option value="10" selected>Gravado 10%</option><option value="5">Gravado 5%</option><option value="0">Exento</option></select></td>
                      <td class="num tl">₲ 0</td>
                      <td class="num"><button type="button" class="btn sm a-cancel" onclick="removeRow(this)" title="Quitar ítem">✕</button></td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div class="card-b" style="padding-top:14px">
                <button type="button" class="btn ghost sm" onclick="addRow()">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg> Agregar ítem
                </button>
                <div class="totals">
                  <div class="ti"><span class="tl">Exenta</span><span class="tv" id="t_ex">₲ 0</span></div>
                  <div class="ti"><span class="tl">Gravada 5%</span><span class="tv" id="t_5">₲ 0</span></div>
                  <div class="ti"><span class="tl">Gravada 10%</span><span class="tv" id="t_10">₲ 0</span></div>
                  <div class="ti"><span class="tl">IVA 5%</span><span class="tv" id="t_i5">₲ 0</span></div>
                  <div class="ti"><span class="tl">IVA 10%</span><span class="tv" id="t_i10">₲ 0</span></div>
                  <div class="ti big"><span class="tl">Total</span><span class="tv" id="t_tot">₲ 0</span></div>
                </div>
              </div>
            </div>
            <div class="flexb">
              <button type="button" class="btn ghost" data-next="1">← Volver a Cliente</button>
              <button type="button" class="btn pri lg" data-next="3">Continuar a Revisar →</button>
            </div>
          </div>

          <!-- STEP 3 -->
          <div class="wstep" id="wstep-3" style="display:none">
            <div class="callout info">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
              <div>Revisá que todo esté correcto. Al guardar, la factura queda registrada. Cuando la marques como <strong>PAGADA</strong>, generás y enviás el comprobante desde la pestaña <strong>Envío</strong>.</div>
            </div>
            <div class="card">
              <div class="card-h"><span class="ct">Resumen del comprobante</span><div class="grow"></div><span class="bdg gray">N° <?=h($nextNum)?></span></div>
              <div class="card-b">
                <div class="grid g2" style="margin-bottom:4px">
                  <div><div class="lbl">Cliente</div><div id="rvCliente" style="font-weight:600">—</div><div class="mono muted" id="rvRuc" style="font-size:12.5px;margin-top:2px">RUC —</div></div>
                  <div><div class="lbl">Correo de envío</div><div id="rvCorreo" style="font-weight:600">—</div></div>
                </div>
              </div>
              <div class="tbl-wrap">
                <table class="tbl"><thead><tr><th>Descripción</th><th class="num">Cant.</th><th class="num">Precio</th><th>IVA</th><th class="num">Total</th></tr></thead>
                  <tbody id="rvItems"></tbody></table>
              </div>
              <div class="card-b" style="padding-top:14px">
                <div class="totals"><div class="ti big"><span class="tl">Total a pagar</span><span class="tv" id="rvTotal">₲ 0</span></div></div>
              </div>
            </div>
            <div class="flexb">
              <button type="button" class="btn ghost" data-next="2">← Volver a Ítems</button>
              <button type="submit" class="btn ok lg">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                Guardar factura
              </button>
            </div>
          </div>
        </form>
      </section>

      <!-- ============ FACTURAS ============ -->
      <section class="view" id="v-facturas" data-screen-label="Facturas">
        <div class="page-head">
          <div><div class="ttl">Facturas</div><div class="desc">Consultá el estado, cambiá el pago o cancelá comprobantes aprobados</div></div>
          <button type="button" class="btn pri" data-go="nueva"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>Nueva factura</button>
        </div>
        <div class="card">
          <div class="card-h">
            <span class="ct">Todas las facturas</span>
            <div class="grow"></div>
            <input type="text" class="in" data-filter="#tbl-facturas" placeholder="Buscar N°, cliente, RUC…" style="height:32px;width:240px;max-width:48vw">
            <span class="bdg gray"><?=$totalFact?> en total</span>
          </div>
          <div class="tbl-wrap">
            <table class="tbl" id="tbl-facturas">
              <thead><tr><th>N°</th><th>Fecha</th><th>Cliente</th><th>RUC</th><th class="num">Total ₲</th><th>Pago</th><th>FE</th><th>CDC</th><th class="col-adj">Adjuntos</th><th class="col-acc">Acciones</th></tr></thead>
              <tbody>
                <?php foreach($status['invoices'] as $inv):
                  $estadoFE = strtoupper((string)($inv['fe_estado'] ?? ''));
                  $puedeCancelar = !empty($inv['fe_cdc']) && in_array($estadoFE, ['APROBADO','EMITIDA','AUTORIZADO','ACEPTADO'], true);
                  $adj = $adjByInv[(int)$inv['id']] ?? null;
                ?>
                <tr>
                  <td><strong><?=h($inv['numero'])?></strong></td>
                  <td class="mono"><?=h(substr($inv['created_at'],0,16))?></td>
                  <td><?=h($inv['cliente_nombre'])?></td>
                  <td class="mono"><?=h(($inv['cliente_ruc']??'—').'-'.($inv['cliente_dv']??''))?></td>
                  <td class="num">₲ <?=gs((float)($inv['total_neto']??0))?></td>
                  <td><?=badge($inv['estado_pago'])?></td>
                  <td><?=badge($inv['fe_estado']??($inv['fe_emitida']?'EMITIDA':'PENDIENTE'))?></td>
                  <td class="mono" title="<?=h($inv['fe_cdc']??'')?>"><?=h(substr($inv['fe_cdc']??'—',0,14))?><?=!empty($inv['fe_cdc'])?'…':''?></td>
                  <td class="acts-td col-adj">
                   <div class="acts" style="justify-content:flex-start">
                    <?php if(!empty($inv['fe_cdc'])): ?>
                      <button type="button" class="btn sm a-qr"
                        data-inv="<?=$inv['id']?>"
                        data-qr="<?=h($inv['numero'])?>"
                        data-cli="<?=h($inv['cliente_nombre'])?>"
                        data-ruc="<?=h(($inv['cliente_ruc']??'—').'-'.($inv['cliente_dv']??''))?>"
                        data-tot="₲ <?=gs((float)($inv['total_neto']??0))?>"
                        data-fecha="<?=h(substr($inv['created_at'],0,16))?>"
                        data-cdc="<?=h($inv['fe_cdc'])?>">QR</button>
                    <?php endif; ?>
                    <?php if($adj && $adj['pdf']): ?>
                      <a class="btn sm a-pdf" href="?accion=adjunto&eq=<?=$adj['eq']?>&t=kude" target="_blank" title="Ver / descargar KuDE PDF">PDF</a>
                    <?php endif; ?>
                    <?php if($adj && $adj['xml']): ?>
                      <a class="btn sm a-xml" href="?accion=adjunto&eq=<?=$adj['eq']?>&t=xml&dl=1" title="Descargar XML firmado (DTE)">XML</a>
                    <?php endif; ?>
                    <?php if(empty($inv['fe_cdc']) && !$adj): ?><span class="muted" style="font-size:12px">—</span><?php endif; ?>
                   </div>
                  </td>
                  <td class="acts-td col-acc">
                   <div class="acts" style="justify-content:flex-start">
                    <form method="POST" style="display:inline-flex;gap:5px;align-items:center">
                      <input type="hidden" name="accion" value="estado">
                      <input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                      <select name="nuevo_estado" class="in" style="height:32px;font-size:12.5px;padding:0 8px;width:auto">
                        <option value="PENDIENTE" <?=$inv['estado_pago']==='PENDIENTE'?'selected':''?>>PENDIENTE</option>
                        <option value="PAGADO"    <?=$inv['estado_pago']==='PAGADO'   ?'selected':''?>>PAGADO</option>
                      </select>
                      <button type="submit" class="btn sm pri">OK</button>
                    </form>
                    <?php if ($puedeCancelar): ?>
                      <form method="POST" style="display:inline" onsubmit="return cancelarDe(this)">
                        <input type="hidden" name="accion" value="cancelar_de">
                        <input type="hidden" name="inv_id" value="<?=$inv['id']?>">
                        <input type="hidden" name="motivo" value="">
                        <button type="submit" class="btn sm a-cancel" title="Cancelar este DE en SIFEN (Manual cap. 11)">Cancelar</button>
                      </form>
                    <?php elseif ($estadoFE === 'CANCELADO'): ?>
                      <span class="badge badge-err" title="<?=h($inv['motivo_cancelacion']??'')?>">CANCELADO</span>
                    <?php endif; ?>
                   </div>
                  </td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($status['invoices'])): ?>
                <tr><td colspan="10" class="muted" style="text-align:center;padding:30px">No hay facturas registradas.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ============ CLIENTES ============ -->
      <section class="view" id="v-clientes" data-screen-label="Clientes">
        <div class="page-head"><div><div class="ttl">Clientes</div><div class="desc">Registro reutilizable — cada cliente se carga una vez y aparece al buscar su RUC en una factura</div></div></div>
        <div class="card">
          <div class="card-h">
            <span class="ct">Clientes registrados</span>
            <div class="grow"></div>
            <input type="text" class="in" data-filter="#tbl-clientes" placeholder="Buscar nombre, RUC, correo…" style="height:32px;width:240px;max-width:48vw">
          </div>
          <div class="tbl-wrap">
            <table class="tbl" id="tbl-clientes">
              <thead><tr><th>ID</th><th>RUC-DV</th><th>Nombre / Razón social</th><th>Correo</th><th>Teléfono</th><th>Persona física</th></tr></thead>
              <tbody>
                <?php
                $clis=$pdo->query("SELECT c.*,p.nombre1,p.nombre2,p.apellido1,p.apellido2 FROM customers c LEFT JOIN personas p ON p.id=c.persona_id ORDER BY c.id DESC")->fetchAll();
                foreach($clis as $c): ?>
                <tr>
                  <td class="mono"><?=$c['id']?></td>
                  <td class="mono"><?=h(($c['ruc']??'—').'-'.($c['dv']??''))?></td>
                  <td><strong><?=h($c['nombre_razon_social'])?></strong></td>
                  <td><?=h($c['email']??'')?></td>
                  <td><?=h($c['telefono']??$c['celular']??'')?></td>
                  <td><?php if($c['nombre1']): ?><?=h(trim($c['nombre1'].' '.($c['nombre2']??'').' '.$c['apellido1'].' '.($c['apellido2']??'')))?><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($clis)): ?>
                <tr><td colspan="6" class="muted" style="text-align:center;padding:30px">Todavía no hay clientes. Se crean automáticamente al guardar una factura.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ============ ENVÍO ============ -->
      <section class="view" id="v-envio" data-screen-label="Envío">
        <div class="page-head">
          <div><div class="ttl">Envío manual de comprobantes</div><div class="desc">Se procesan uno por uno, en orden. Si se corta la conexión, podés reanudar sin duplicar.</div></div>
        </div>

        <div class="callout info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
          <div>Los comprobantes <strong>no se envían solos</strong>. Cargá tus facturas (marcándolas <strong>PAGADO</strong>) y presioná el botón: el sistema genera y envía los comprobantes <strong>uno por uno, en orden</strong>. Si se corta internet, el envío queda <strong>suspendido</strong> y podés <strong>reanudarlo</strong> sin reenviar lo ya enviado.</div>
        </div>

        <div class="env-row">
          <div class="env-stat"><span class="env-k" id="env_fe">0</span><span class="env-l">Por generar</span></div>
          <div class="env-stat"><span class="env-k" id="env_pend">0</span><span class="env-l">Por enviar</span></div>
          <div class="env-stat env-warn"><span class="env-k" id="env_susp">0</span><span class="env-l">Suspendidos</span></div>
          <div class="env-stat env-ok"><span class="env-k" id="env_sent">0</span><span class="env-l">Enviados</span></div>
          <div class="env-stat env-err"><span class="env-k" id="env_err">0</span><span class="env-l">Con error</span></div>
        </div>

        <div id="env_bar_wrap" style="display:none;margin-bottom:16px">
          <div class="prog"><span id="env_bar" style="width:0%"></span></div>
          <div id="env_bar_txt" class="muted" style="font-size:12.5px;margin-top:6px"></div>
        </div>

        <div id="env_estado" class="callout" style="display:none"></div>

        <div class="flexb" style="justify-content:flex-start;margin-bottom:6px">
          <button type="button" id="btn_enviar" class="btn ok lg" onclick="procesarEnvio()"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12 20 4l-4 16-4.5-5.5z"/><path d="M11.5 14.5 20 4"/></svg>Enviar comprobantes</button>
          <button type="button" id="btn_refrescar" class="btn ghost" onclick="refrescarEnvio()">Actualizar</button>
        </div>

        <div id="env_log" class="log" style="display:none;margin-top:14px"></div>
      </section>

      <!-- ============ COLA DE FE ============ -->
      <section class="view" id="v-cola" data-screen-label="Cola de FE">
        <div class="page-head"><div><div class="ttl">Cola de FE</div><div class="desc">Vista técnica para diagnóstico — generación, envío y resultado de cada comprobante</div></div><span class="bdg gray">Avanzado</span></div>

        <div class="card">
          <div class="card-h">
            <span class="ct">Cola de facturación electrónica</span>
            <div class="grow"></div>
            <input type="text" class="in" data-filter="#tbl-cola" placeholder="Buscar factura, CDC, correo…" style="height:32px;width:240px;max-width:48vw">
          </div>
          <div class="tbl-wrap">
            <table class="tbl" id="tbl-cola">
              <thead><tr><th>ID</th><th>Factura</th><th>Estado FE</th><th class="num">Intentos</th><th>CDC</th><th>Destinatario</th><th>Correo</th><th>Enviado</th><th>Error</th></tr></thead>
              <tbody>
                <?php foreach($status['fe_queue'] as $q):
                  $iid=(int)$q['invoice_id'];
                  $mail=$mailByInv[$iid]??null; $sent=$sentByInv[$iid]??'';
                ?>
                <tr>
                  <td class="mono"><?=$q['id']?></td>
                  <td><?=$q['invoice_id']?></td>
                  <td><?=badge($q['estado'])?></td>
                  <td class="num"><?=$q['intentos']?></td>
                  <td class="mono" title="<?=h($q['cdc']??'')?>"><?=h(substr($q['cdc']??'—',0,18))?><?=!empty($q['cdc'])?'…':''?></td>
                  <td><?=h($mail['dest']??'—')?></td>
                  <td><?=$mail?badge($mail['estado']):'<span class="muted">—</span>'?></td>
                  <td class="mono"><?=h($sent!==''?$sent:'—')?></td>
                  <td class="muted" style="max-width:200px;color:var(--err);font-size:12px"><?=h($q['ultimo_error']??'')?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(empty($status['fe_queue'])): ?><tr><td colspan="9" class="muted" style="text-align:center;padding:24px">Cola vacía.</td></tr><?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </section>

      <!-- ============ PRUEBA DE SCRIPT ============ -->
      <section class="view" id="v-prueba" data-screen-label="Prueba de Script">
        <div class="page-head">
          <div><div class="ttl">Prueba de Script</div><div class="desc">Subí un archivo <strong>.txt</strong> de facturas y dispará el automatizador SIFEN para procesarlo.</div></div>
          <span class="bdg gray">Avanzado</span>
        </div>

        <div class="callout info">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
          <div>Cargá un archivo <strong>.txt</strong> con el formato de facturas (líneas <code>FAC|…</code>, <code>CLI|…</code>, <code>ITM|…</code>). Cuando termine de subir, presioná <strong>Continuar</strong>: el automatizador arma el XML SIFEN, lo firma, calcula el QR y lo envía a la DNIT.</div>
        </div>

        <div class="card pad">
          <!-- Zona de carga: drag & drop + botón -->
          <div id="ps_drop" class="ps-drop">
            <svg class="ps-drop-ic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="M7 9l5-5 5 5"/><path d="M12 4v12"/></svg>
            <div class="ps-drop-t">Arrastrá tu archivo <strong>.txt</strong> acá</div>
            <div class="ps-drop-s">— o —</div>
            <button type="button" class="btn pri" id="ps_pick">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M5 12h14"/></svg>
              Subir archivo
            </button>
            <input type="file" id="ps_file" accept=".txt,text/plain" hidden>
            <div class="ps-drop-hint muted">Solo se permiten archivos con extensión .txt</div>
          </div>

          <!-- Archivo seleccionado + barra de progreso -->
          <div id="ps_file_info" style="display:none;margin-top:18px">
            <div class="flexb" style="margin-bottom:9px">
              <div style="display:flex;align-items:center;gap:11px;min-width:0">
                <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="var(--brand)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" style="flex:0 0 auto"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M9 13h6M9 17h4"/></svg>
                <div style="min-width:0">
                  <div id="ps_fname" class="mono" style="font-weight:600;color:var(--ink);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"></div>
                  <div id="ps_fmeta" class="muted" style="font-size:12px"></div>
                </div>
              </div>
              <button type="button" class="btn sm ghost" id="ps_clear">Quitar</button>
            </div>
            <div class="prog"><span id="ps_bar" style="width:0%"></span></div>
            <div id="ps_bar_txt" class="muted" style="font-size:12.5px;margin-top:6px"></div>
          </div>

          <div id="ps_estado" class="callout" style="display:none;margin-top:16px"></div>
        </div>

        <!-- Botón Continuar (se habilita al completar la carga) -->
        <div class="flexb" style="justify-content:flex-start;margin-bottom:6px">
          <button type="button" id="ps_continuar" class="btn ok lg" disabled onclick="psEjecutar()">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
            Continuar
          </button>
        </div>

        <!-- Resultado del automatizador -->
        <div id="ps_result" class="card" style="display:none;margin-top:16px">
          <div class="card-h"><span class="ct">Resultado del automatizador</span></div>
          <div class="card-b" id="ps_result_body"></div>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- ============ QR MODAL ============ -->
<div class="overlay" id="qrOverlay">
  <div class="modal">
    <div class="mh">
      <span class="mt">Comprobante N° <span id="qrNum">—</span></span>
      <span class="bdg ok"><span class="dot"></span>Aprobada en SIFEN</span>
      <button type="button" class="x" id="qrClose"><svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
    </div>
    <div class="mb">
      <div class="qr-box" id="qrCanvas"></div>
      <div class="qr-data">
        <div class="drow"><span class="dk">Cliente</span><span class="dv" id="qrCli">—</span></div>
        <div class="drow"><span class="dk">RUC</span><span class="dv mono" id="qrRuc">—</span></div>
        <div class="drow"><span class="dk">Fecha de emisión</span><span class="dv" id="qrFecha">—</span></div>
        <div class="drow"><span class="dk">Total</span><span class="dv" id="qrTot">—</span></div>
        <div class="drow"><span class="dk">CDC</span><span class="dv mono" id="qrCdc" style="font-size:11px;word-break:break-all;max-width:230px">—</span></div>
      </div>
    </div>
    <div class="mf">
      <a class="btn a-qr" id="qrValidar" href="#" target="_blank"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M15 3h6v6M21 3l-9 9"/><path d="M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/></svg>Validar en SIFEN</a>
      <div class="grow" style="flex:1"></div>
      <button type="button" class="btn ghost" id="qrClose2">Cerrar</button>
    </div>
  </div>
</div>

<script>/* ============================================================
   Facturación SIFEN — JS de la app real con diseño hi-fi
   Combina: navegación lateral, asistente Nueva factura,
   búsqueda de RUC (AJAX real), calculadora de ítems,
   panel de Envío (API real) y modal QR.
   Todo está protegido con guardas: si una vista no existe,
   no rompe el resto.
   ============================================================ */
(function(){
  'use strict';
  const $  = (s,r=document)=>r.querySelector(s);
  const $$ = (s,r=document)=>[...r.querySelectorAll(s)];
  const fmtGs = n => '₲ ' + Math.round(n||0).toLocaleString('es-PY');

  const TITLES = {
    inicio:'Inicio', nueva:'Nueva factura', facturas:'Facturas',
    clientes:'Clientes', envio:'Envío de comprobantes', cola:'Cola de FE',
    prueba:'Prueba de Script'
  };

  /* ---------- Navegación entre vistas ---------- */
  function go(name){
    $$('.view').forEach(v=>v.classList.remove('on'));
    const v = $('#v-'+name); if(v) v.classList.add('on');
    $$('.nav-i').forEach(b=>b.classList.toggle('on', b.dataset.go===name));
    const t = $('#pageTitle'); if(t) t.textContent = TITLES[name] || '';
    const c = $('.content'); if(c) c.scrollTop = 0;
    if(name==='nueva') gotoStep(1);
    if(name==='envio' && typeof refrescarEnvio==='function') refrescarEnvio();
    try{ history.replaceState(null,'','#'+name); }catch(e){}
  }
  document.addEventListener('click', e=>{
    const t = e.target.closest('[data-go]');
    if(t){ e.preventDefault(); go(t.dataset.go); closeNav(); }
  });

  /* ---------- Menú móvil: sidebar como cajón deslizable ---------- */
  const appEl = $('.app'), menuBtn = $('#menuBtn'), navBackdrop = $('#navBackdrop');
  function closeNav(){ if(appEl) appEl.classList.remove('nav-open'); if(menuBtn) menuBtn.setAttribute('aria-expanded','false'); }
  function toggleNav(){ if(!appEl) return; const o=appEl.classList.toggle('nav-open'); if(menuBtn) menuBtn.setAttribute('aria-expanded', o?'true':'false'); }
  if(menuBtn) menuBtn.addEventListener('click', toggleNav);
  if(navBackdrop) navBackdrop.addEventListener('click', closeNav);
  window.addEventListener('resize', ()=>{ if(window.innerWidth>920) closeNav(); });
  document.addEventListener('keydown', e=>{ if(e.key==='Escape') closeNav(); });

  /* ---------- Asistente Nueva factura ---------- */
  let step = 1;
  function gotoStep(n){
    step = n;
    $$('.wstep').forEach(w=>w.style.display='none');
    const w = $('#wstep-'+n); if(w) w.style.display='';
    $$('#steps .st').forEach(s=>{
      const sn = +s.dataset.step;
      s.classList.toggle('on', sn===n);
      s.classList.toggle('done', sn<n);
      const c = $('.c', s); if(c) c.textContent = sn<n ? '✓' : sn;
    });
    $$('#steps .ln').forEach((ln,i)=> ln.classList.toggle('done', i < n-1));
    if(n===3) renderReview();
    const cont = $('.content'); if(cont) cont.scrollTop = 0;
  }
  document.addEventListener('click', e=>{
    const t = e.target.closest('[data-next]');
    if(t){ e.preventDefault(); gotoStep(+t.dataset.next); }
  });

  /* ---------- Paso 1: búsqueda de RUC (AJAX real) ---------- */
  const rucI=$('#c_ruc'), dvI=$('#c_dv');
  const foundBox=$('#found_box'), buscarBtn=$('#btn_buscar'), bi=$('#bi');

  window.clearC = function(){
    ['c_nombre','c_email','c_tel','c_cel','c_dir','c_casa','p_n1','p_n2','p_a1','p_a2','p_doc']
      .forEach(id=>{const e=$('#'+id);if(e)e.value='';});
    if(foundBox) foundBox.className='found-box';
  };

  async function buscar(){
    if(!rucI||!dvI) return;
    const ruc=rucI.value.replace(/\D/g,''), dv=dvI.value.replace(/\D/g,'');
    if(!ruc||!dv){alert('Ingresá RUC y DV primero.');return;}
    if(bi) bi.innerHTML='<span class="spin"></span>'; if(buscarBtn) buscarBtn.disabled=true;
    const fd=new FormData(); fd.set('accion','buscar_ruc'); fd.set('ruc',ruc); fd.set('dv',dv);
    try{
      const res=await fetch(location.pathname,{method:'POST',body:fd});
      const d=await res.json();
      if(!d.found){
        foundBox.className='found-box vis alert-warn';
        foundBox.innerHTML='⚠️ '+d.msg+' Completá los datos y se dará de alta al guardar.';
        return;
      }
      const c=d.c;
      const sv=(id,v)=>{const e=$('#'+id);if(e)e.value=v||'';};
      sv('c_nombre',c.nombre_razon_social); sv('c_email',c.email);
      sv('c_tel',c.telefono); sv('c_cel',c.celular);
      sv('c_dir',c.direccion); sv('c_casa',c.numero_casa);
      sv('p_n1',c.nombre1); sv('p_n2',c.nombre2);
      sv('p_a1',c.apellido1); sv('p_a2',c.apellido2);
      sv('p_doc',c.p_num_doc||c.numero_documento);
      if(c.p_tipo_doc){const s=$('#p_td');if(s)s.value=c.p_tipo_doc;}
      foundBox.className='found-box vis alert-ok';
      foundBox.innerHTML='✅ '+d.msg;
    }catch(e){alert('Error: '+e.message);}
    finally{if(bi) bi.textContent='🔍'; if(buscarBtn) buscarBtn.disabled=false;}
  }
  if(buscarBtn) buscarBtn.addEventListener('click',buscar);
  if(dvI) dvI.addEventListener('blur',()=>{if(rucI.value&&dvI.value)buscar();});
  if(rucI) rucI.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();buscar();}});

  /* ---------- Fecha de emisión = ahora ---------- */
  function nowLocal(){
    const d=new Date(), p=n=>String(n).padStart(2,'0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
  }
  const fFecha=$('#f_fecha'); if(fFecha) fFecha.value=nowLocal();

  /* ---------- Paso 2: ítems + totales ---------- */
  function recalc(){
    let ex=0,s5=0,s10=0,i5=0,i10=0;
    $$('#tbody tr').forEach(tr=>{
      const cant=parseFloat(tr.querySelector('[name="i_cant[]"]')?.value)||0;
      const prec=parseFloat(tr.querySelector('[name="i_prec[]"]')?.value)||0;
      const iva =parseInt(tr.querySelector('[name="i_iva[]"]')?.value)||0;
      const tot=cant*prec;
      if(iva===10){s10+=tot;i10+=tot-tot/1.10;}
      else if(iva===5){s5+=tot;i5+=tot-tot/1.05;}
      else ex+=tot;
      const c=tr.querySelector('.tl'); if(c) c.textContent=fmtGs(tot);
    });
    const set=(id,v)=>{const e=$('#'+id);if(e)e.textContent=fmtGs(v);};
    set('t_ex',ex); set('t_5',s5); set('t_10',s10);
    set('t_i5',i5); set('t_i10',i10); set('t_tot',ex+s5+s10);
  }
  window.recalc = recalc;
  function bindRow(tr){tr.querySelectorAll('.cx').forEach(e=>e.addEventListener('input',recalc));}
  window.addRow = function(){
    const tb=$('#tbody'); if(!tb) return;
    const tr=document.createElement('tr');
    tr.innerHTML=`<td><input type="text" name="i_cod[]" value="ITEM-NEW"></td>
    <td><input type="text" name="i_desc[]" placeholder="Producto o servicio…" required></td>
    <td><input type="number" name="i_cant[]" value="1" min="0.01" step="0.01" class="cx num"></td>
    <td><input type="number" name="i_prec[]" value="0" min="0" step="1" class="cx num"></td>
    <td><select name="i_iva[]" class="cx"><option value="10" selected>Gravado 10%</option><option value="5">Gravado 5%</option><option value="0">Exento</option></select></td>
    <td class="num tl">₲ 0</td>
    <td class="num"><button type="button" class="btn sm a-cancel" onclick="removeRow(this)" title="Quitar ítem">✕</button></td>`;
    tb.appendChild(tr); bindRow(tr); recalc();
    tr.querySelector('[name="i_desc[]"]').focus();
  };
  window.removeRow = function(btn){
    if($$('#tbody tr').length<=1){alert('Debe haber al menos un ítem.');return;}
    btn.closest('tr').remove(); recalc();
  };
  $$('#tbody tr').forEach(bindRow); recalc();

  /* ---------- Paso 3: revisión ---------- */
  function renderReview(){
    const v=(id)=>($('#'+id)?.value||'');
    const set=(id,val)=>{const e=$('#'+id);if(e)e.textContent=val;};
    set('rvCliente', v('c_nombre')||'—');
    set('rvRuc', 'RUC '+(v('c_ruc')||'—')+'-'+(v('c_dv')||''));
    set('rvCorreo', v('c_email')||'—');
    let total=0, rows='';
    $$('#tbody tr').forEach(tr=>{
      const desc=tr.querySelector('[name="i_desc[]"]')?.value||'';
      if(!desc.trim()) return;
      const cant=parseFloat(tr.querySelector('[name="i_cant[]"]')?.value)||0;
      const prec=parseFloat(tr.querySelector('[name="i_prec[]"]')?.value)||0;
      const iva =parseInt(tr.querySelector('[name="i_iva[]"]')?.value)||0;
      const t=cant*prec; total+=t;
      rows+=`<tr><td>${esc(desc)}</td><td class="num">${cant}</td><td class="num">${prec.toLocaleString('es-PY')}</td><td>${iva==0?'Exento':iva+'%'}</td><td class="num">${t.toLocaleString('es-PY')}</td></tr>`;
    });
    const body=$('#rvItems'); if(body) body.innerHTML=rows||'<tr><td colspan="5" class="muted" style="text-align:center;padding:20px">Sin ítems cargados</td></tr>';
    set('rvTotal', fmtGs(total));
  }
  function esc(s){return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}

  /* ---------- Cancelación de DE (Manual SIFEN cap. 11) ---------- */
  window.cancelarDe = function(form){
    const motivo = window.prompt('Motivo de la cancelación (5 a 500 caracteres):\n\nEjemplo: "Operación no concretada por desistimiento del cliente."', '');
    if (motivo === null) return false;
    const m = motivo.trim();
    if (m.length < 5)  { alert('El motivo debe tener al menos 5 caracteres.'); return false; }
    if (m.length > 500){ alert('El motivo no puede superar los 500 caracteres.'); return false; }
    if (!confirm('Esta cancelación se enviará a SIFEN y NO se puede deshacer.\n¿Continuar?')) return false;
    form.elements['motivo'].value = m;
    return true;
  };

  /* ---------- Modal QR (QR real codificado del qr_text en el servidor) ---------- */
  function openQr(btn){
    const num=btn.dataset.qr||'—', cli=btn.dataset.cli||'—', tot=btn.dataset.tot||'—',
          ruc=btn.dataset.ruc||'—', fecha=btn.dataset.fecha||'—', cdc=btn.dataset.cdc||'', inv=btn.dataset.inv||'';
    const set=(id,val)=>{const e=$('#'+id);if(e)e.textContent=val;};
    set('qrNum',num); set('qrCli',cli); set('qrTot',tot); set('qrRuc',ruc); set('qrFecha',fecha);
    set('qrCdc', cdc||'—');
    const canvas=$('#qrCanvas'), val=$('#qrValidar');
    if(canvas) canvas.innerHTML='<div class="muted" style="padding:34px 10px;text-align:center;font-size:12px">Generando QR…</div>';
    if(val){ val.removeAttribute('href'); val.style.opacity='.45'; val.style.pointerEvents='none'; }
    $('#qrOverlay')?.classList.add('on');
    // Pedimos al servidor el SVG del QR real (codifica el qr_text = dCarQR, Manual SIFEN 13.8).
    const fd=new FormData(); fd.set('accion','qr_svg'); fd.set('inv_id',inv);
    fetch(location.pathname,{method:'POST',body:fd}).then(r=>r.json()).then(d=>{
      if(d.ok){
        if(canvas) canvas.innerHTML=d.svg;
        if(val){ val.href=d.text; val.style.opacity=''; val.style.pointerEvents=''; }  // la URL del QR ES la de validación
      } else {
        if(canvas) canvas.innerHTML='<div class="muted" style="padding:30px 10px;text-align:center;font-size:12px">QR no disponible<br>'+esc(d.msg||'')+'</div>';
      }
    }).catch(()=>{ if(canvas) canvas.innerHTML='<div class="muted" style="padding:30px;text-align:center">Error al generar el QR</div>'; });
  }
  function closeQr(){ $('#qrOverlay')?.classList.remove('on'); }
  document.addEventListener('click', e=>{
    const b=e.target.closest('[data-qr]'); if(b){ e.preventDefault(); openQr(b); }
  });
  $('#qrClose')?.addEventListener('click',closeQr);
  $('#qrClose2')?.addEventListener('click',closeQr);
  $('#qrOverlay')?.addEventListener('click',e=>{ if(e.target===$('#qrOverlay')) closeQr(); });
  document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeQr(); });

  /* ================================================================
     Envío manual de comprobantes (Fase 2/3) — uno por uno, reanudable
     ================================================================ */
  let envEnEjecucion = false;
  async function envApi(accion){
    const fd = new FormData(); fd.set('accion', accion);
    const res = await fetch(location.pathname, { method:'POST', body:fd });
    return res.json();
  }
  function envLog(html){
    const box = $('#env_log'); if(!box) return;
    box.style.display='block';
    const line=document.createElement('div'); line.className='env-log-line'; line.innerHTML=html;
    box.appendChild(line); box.scrollTop=box.scrollHeight;
  }
  function envSetEstado(tipo, html){
    const el=$('#env_estado'); if(!el) return;
    if(!html){ el.style.display='none'; return; }
    el.style.display='flex';
    el.className='callout '+(tipo==='err'?'err':tipo==='ok'?'ok':tipo==='warn'?'warn':'info');
    el.innerHTML=html;
  }
  function envPintarContadores(e){
    if(!e || !e.ok) return;
    const set=(id,v)=>{const el=$('#'+id);if(el)el.textContent=v;};
    set('env_fe',e.fe_pendientes); set('env_pend',e.correos_pendientes);
    set('env_susp',e.correos_suspendidos); set('env_sent',e.enviados); set('env_err',e.errores);
    if(envEnEjecucion) return;
    const btn=$('#btn_enviar'); if(!btn) return;
    const totalPorHacer=(e.fe_pendientes||0)+(e.correos_pendientes||0);
    if(e.correos_suspendidos>0){
      btn.innerHTML='▶️ Reanudar envío ('+e.correos_pendientes+')';
      btn.className='btn pri lg';
      envSetEstado('err','<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><div>Hay un envío <strong>suspendido</strong>: '+e.correos_suspendidos+' comprobante(s) esperando que vuelva la conexión. Presioná <strong>Reanudar</strong>.</div>');
    } else {
      btn.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12 20 4l-4 16-4.5-5.5z"/><path d="M11.5 14.5 20 4"/></svg>Enviar comprobantes'+(totalPorHacer?' ('+totalPorHacer+')':'');
      btn.className='btn ok lg';
    }
    btn.disabled = totalPorHacer===0;
  }
  window.refrescarEnvio = async function(){
    try { envPintarContadores(await envApi('estado_colas')); } catch(e){}
  };
  function envProgreso(hechos,total){
    const wrap=$('#env_bar_wrap'); if(!wrap) return;
    if(total<=0){ wrap.style.display='none'; return; }
    wrap.style.display='block';
    const pct=Math.min(100,Math.round(hechos/total*100));
    const bar=$('#env_bar'); if(bar) bar.style.width=pct+'%';
    const txt=$('#env_bar_txt'); if(txt) txt.textContent=hechos+' / '+total+' pasos';
  }
  window.procesarEnvio = async function(){
    if(envEnEjecucion) return;
    envEnEjecucion=true;
    const btn=$('#btn_enviar'); if(btn){ btn.disabled=true; btn.innerHTML='<span class="spin"></span> Procesando…'; }
    envSetEstado('info','<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg><div>Generando y enviando comprobantes, uno por uno…</div>');
    const logBox=$('#env_log'); if(logBox) logBox.innerHTML='';
    let suspendido=false, hechos=0;
    try{
      const est=await envApi('estado_colas');
      const total=(est.fe_pendientes||0)*2+(est.correos_pendientes||0);
      envProgreso(0,total);
      while(true){
        const g=await envApi('generar_uno');
        if(g.done) break;
        envLog(g.ok?('✅ Comprobante generado — factura #'+g.factura):('⚠️ No se pudo generar una factura: '+(g.error||'')));
        hechos++; envProgreso(hechos,total);
        envPintarContadores(await envApi('estado_colas'));
      }
      while(true){
        const s=await envApi('enviar_uno');
        if(s.done) break;
        if(s.ok){
          envLog('✅ Enviado a '+s.email+(s.dedup?' <span style="color:#fbbf24">(ya estaba enviado, no se reenvió)</span>':''));
        } else if(s.suspendido){
          envLog('⏸️ <span style="color:#fbbf24">Sin conexión — envío SUSPENDIDO.</span> Quedan '+s.restantes+' por enviar.');
          suspendido=true; break;
        } else {
          envLog('❌ <span style="color:#f87171">Error (se omite):</span> '+(s.error||''));
        }
        hechos++; envProgreso(hechos,total);
        envPintarContadores(await envApi('estado_colas'));
      }
    }catch(err){
      envLog('⏸️ <span style="color:#fbbf24">Se perdió la conexión.</span> Envío suspendido; reanudá cuando vuelva internet.');
      suspendido=true;
    }finally{
      envEnEjecucion=false; if(btn) btn.disabled=false;
    }
    const fin=await envApi('estado_colas').catch(()=>null);
    if(suspendido){
      envSetEstado('err','<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg><div>Envío <strong>suspendido</strong> por falta de conexión. Cuando vuelva internet, presioná <strong>Reanudar</strong>: continúa desde donde quedó, sin reenviar lo ya enviado.</div>');
    } else {
      envSetEstado('ok','<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg><div>Listo. Todos los comprobantes pendientes fueron procesados.</div>');
      envLog('🏁 Proceso finalizado.');
    }
    if(fin){ envPintarContadores(fin); }
    else if(btn){ btn.innerHTML='🔄 Reintentar envío'; btn.className='btn pri lg'; btn.disabled=false; }
  };

  /* ---------- Buscadores de tablas (Facturas, Clientes, Cola de FE) ---------- */
  $$('[data-filter]').forEach(inp=>{
    inp.addEventListener('input', ()=>{
      const q=inp.value.toLowerCase().trim();
      const tbl=$(inp.dataset.filter); if(!tbl) return;
      $$('tbody tr', tbl).forEach(tr=>{
        if(tr.querySelector('td[colspan]')) return;            // no ocultar la fila "vacío"
        tr.style.display = (!q || tr.textContent.toLowerCase().includes(q)) ? '' : 'none';
      });
    });
  });

  /* ================================================================
     Prueba de Script — subir un .txt (con barra de progreso real) y
     disparar el automatizador SIFEN. Validamos la carga completa antes
     de habilitar "Continuar".
     ================================================================ */
  (function(){
    const drop=$('#ps_drop'), fileInput=$('#ps_file'), pickBtn=$('#ps_pick');
    if(!drop || !fileInput) return;            // guarda: si la vista no existe, no rompe nada
    let psTicket=null, psSubiendo=false;

    const setBar = pct => { const b=$('#ps_bar'); if(b) b.style.width=pct+'%'; };
    const setBarTxt = (html, asHtml) => { const t=$('#ps_bar_txt'); if(t){ if(asHtml) t.innerHTML=html; else t.textContent=html; } };
    function fmtBytes(b){ b=+b||0; if(b<1024) return b+' B'; if(b<1048576) return (b/1024).toFixed(1)+' KB'; return (b/1048576).toFixed(1)+' MB'; }
    function psEstado(tipo, html){
      const el=$('#ps_estado'); if(!el) return;
      if(!html){ el.style.display='none'; el.innerHTML=''; return; }
      el.style.display='flex';
      el.className='callout '+(tipo==='err'?'err':tipo==='ok'?'ok':tipo==='warn'?'warn':'info');
      el.innerHTML='<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+
        (tipo==='ok'?'<path d="M20 6 9 17l-5-5"/>':tipo==='err'?'<circle cx="12" cy="12" r="9"/><path d="M15 9l-6 6M9 9l6 6"/>':'<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>')+
        '</svg><div>'+html+'</div>';
    }

    window.psLimpiar = function(){
      psTicket=null; fileInput.value='';
      const fi=$('#ps_file_info'); if(fi) fi.style.display='none';
      setBar(0); setBarTxt('');
      const c=$('#ps_continuar'); if(c) c.disabled=true;
      const r=$('#ps_result'); if(r) r.style.display='none';
      psEstado(null);
    };

    function validar(file){
      const nombre=(file.name||'').toLowerCase();
      if(!nombre.endsWith('.txt') && file.type!=='text/plain'){
        psEstado('err','Solo se permiten archivos con extensión <strong>.txt</strong>.');
        return false;
      }
      return true;
    }

    function subir(file){
      if(psSubiendo) return;
      if(!validar(file)) return;
      psTicket=null;
      const c=$('#ps_continuar'); if(c) c.disabled=true;
      const r=$('#ps_result'); if(r) r.style.display='none';
      psEstado(null);
      const fi=$('#ps_file_info'); if(fi) fi.style.display='block';
      const fn=$('#ps_fname'); if(fn) fn.textContent=file.name;
      const fm=$('#ps_fmeta'); if(fm) fm.textContent=fmtBytes(file.size);
      setBar(0); setBarTxt('Subiendo… 0%');

      const fd=new FormData();
      fd.set('accion','ps_subir');
      fd.set('size', String(file.size));      // para validar la carga completa en el servidor
      fd.set('archivo', file, file.name);

      const xhr=new XMLHttpRequest();
      xhr.open('POST', location.pathname);
      psSubiendo=true;
      xhr.upload.onprogress=function(e){
        if(!e.lengthComputable) return;
        const pct=Math.round(e.loaded/e.total*100);
        setBar(pct);
        setBarTxt('Subiendo… '+pct+'%  ('+fmtBytes(e.loaded)+' / '+fmtBytes(e.total)+')');
      };
      xhr.onload=function(){
        psSubiendo=false;
        let d=null; try{ d=JSON.parse(xhr.responseText); }catch(e){}
        if(!d){ setBar(0); psEstado('err','Respuesta inválida del servidor al subir.'); return; }
        if(!d.ok){ setBar(0); setBarTxt(''); psEstado('err', esc(d.error||'No se pudo subir el archivo.')); return; }
        psTicket=d.ticket;
        setBar(100);
        setBarTxt('✅ Carga completa — '+fmtBytes(d.bytes)+(d.facturas?(' · '+d.facturas+' factura(s) detectada(s)'):''), true);
        if(c) c.disabled=false;
        psEstado('ok','Archivo cargado correctamente. Presioná <strong>Continuar</strong> para ejecutar el automatizador.');
      };
      xhr.onerror=function(){ psSubiendo=false; setBar(0); psEstado('err','Error de red al subir el archivo. Reintentá.'); };
      xhr.send(fd);
    }

    pickBtn && pickBtn.addEventListener('click', e=>{ e.stopPropagation(); fileInput.click(); });
    drop.addEventListener('click', e=>{ if(e.target.closest('button')) return; fileInput.click(); });
    fileInput.addEventListener('change', ()=>{ if(fileInput.files[0]) subir(fileInput.files[0]); });
    $('#ps_clear') && $('#ps_clear').addEventListener('click', e=>{ e.stopPropagation(); window.psLimpiar(); });

    ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); drop.classList.add('drag'); }));
    ['dragleave','dragend'].forEach(ev=>drop.addEventListener(ev, e=>{ e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag'); }));
    drop.addEventListener('drop', e=>{
      e.preventDefault(); e.stopPropagation(); drop.classList.remove('drag');
      const f=e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0];
      if(f) subir(f);
    });
    // Evitar que el navegador abra el archivo si se suelta fuera de la zona
    ['dragover','drop'].forEach(ev=>window.addEventListener(ev, e=>{ if(!e.target.closest('#ps_drop')) e.preventDefault(); }));

    function estadoBadge(estado){
      const e=String(estado||'').toUpperCase();
      const cls=(e==='APROBADO'||e==='AUTORIZADO'||e==='ACEPTADO')?'ok':(e==='RECHAZADO'||e==='ERROR')?'err':'info';
      return '<span class="bdg '+cls+'"><span class="dot"></span>'+esc(estado||'—')+'</span>';
    }

    function psRenderResultado(d){
      const box=$('#ps_result'), body=$('#ps_result_body');
      const r = d && d.resultado;
      if(d && d.ok && r && r.ok){
        const fs=r.facturas||[];
        psEstado('ok','<strong>Automatizador ejecutado con éxito.</strong> Se procesó '+fs.length+' factura(s).');
        let rows='';
        fs.forEach(f=>{
          const links=[];
          if(f.kude_url) links.push('<a class="btn sm a-pdf" target="_blank" rel="noopener" href="'+esc(f.kude_url)+'">PDF</a>');
          if(f.xml_url)  links.push('<a class="btn sm a-xml" target="_blank" rel="noopener" href="'+esc(f.xml_url)+'">XML</a>');
          rows+='<tr><td class="num">'+esc(f.factura||'')+'</td>'+
                '<td class="mono" style="word-break:break-all;max-width:280px">'+esc(f.cdc||'—')+'</td>'+
                '<td>'+estadoBadge(f.estado)+'</td>'+
                '<td>'+(f.mail_enviado?'✅ Enviado':'<span class="muted">—</span>')+'</td>'+
                '<td><div class="acts">'+(links.join('')||'<span class="muted">—</span>')+'</div></td></tr>';
        });
        if(body) body.innerHTML='<div class="tbl-wrap"><table class="tbl"><thead><tr><th>#</th><th>CDC</th><th>Estado</th><th>Correo</th><th>Descargas</th></tr></thead><tbody>'+
          (rows||'<tr><td colspan="5" class="muted" style="text-align:center;padding:20px">Sin facturas en la respuesta.</td></tr>')+'</tbody></table></div>';
        if(box) box.style.display='block';
        psTicket=null;                                  // ya se consumió la carga
        const c=$('#ps_continuar'); if(c) c.disabled=true;
      } else {
        const err=(r && r.error) || (d && d.error) || 'El automatizador no pudo procesar el archivo.';
        psEstado('err','<strong>Falló la ejecución:</strong> '+esc(err));
        if(box) box.style.display='none';
      }
    }

    window.psEjecutar = async function(){
      if(!psTicket){ psEstado('err','Subí primero un archivo .txt.'); return; }
      const btn=$('#ps_continuar'); if(!btn) return;
      const orig=btn.innerHTML;
      btn.disabled=true; btn.innerHTML='<span class="spin"></span> Ejecutando automatizador…';
      psEstado('info','Ejecutando el automatizador (arma XML, firma, QR y envío a la DNIT). Esto puede tardar unos segundos…');
      const r0=$('#ps_result'); if(r0) r0.style.display='none';
      try{
        const fd=new FormData(); fd.set('accion','ps_ejecutar'); fd.set('ticket', psTicket);
        const res=await fetch(location.pathname,{method:'POST',body:fd});
        const d=await res.json();
        psRenderResultado(d);
      }catch(err){
        psEstado('err','Error al ejecutar el automatizador: '+esc(err.message||err));
      }finally{
        btn.innerHTML=orig;
        // Continuar queda deshabilitado salvo que siga habiendo una carga válida
        btn.disabled = !psTicket;
      }
    };
  })();

  /* ---------- init ---------- */
  const initial = (location.hash||'').replace('#','');
  go(TITLES[initial] ? initial : 'inicio');
  refrescarEnvio();
})();
</script>
</body>
</html>
