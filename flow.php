<?php
// ============================================================
//  flow.php - Flujo BonoXS (Web)
// ============================================================

$SK = getenv('BONOXS_SK');
$AK = getenv('BONOXS_AK');
$_keysFile = __DIR__ . '/keys.local.php';
if ((!$SK || !$AK) && file_exists($_keysFile)) {
    $__k = require $_keysFile;
    if (is_array($__k)) {
        $SK = $SK ?: ($__k['SK'] ?? '');
        $AK = $AK ?: ($__k['AK'] ?? '');
    }
}
unset($_keysFile, $__k);
$API = 'https://api.bonoxs.com';
$TC  = 'https://gateway2.tucompra.com.co';

$nombres = ['Jose','Carlos','Andres','Luis','Juan','David','Santiago','Sebastian','Daniel','Felipe','Alejandro','Nicolas','Mateo','Samuel','Diego','Julian','Camilo'];
$apellidos = ['Rodriguez','Garcia','Martinez','Lopez','Hernandez','Gonzalez','Diaz','Torres','Ramirez','Flores','Cruz','Morales','Reyes','Gutierrez','Castillo','Romero'];

$debugBuffer = [];

function sign($body, $key) { return base64_encode(hash_hmac('sha256', $body, $key, true)); }

function debugLog($step, $message, $data = null) {
    global $debugBuffer;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [PASO $step] $message";
    if ($data !== null) {
        if (is_array($data) || is_object($data)) {
            $entry .= " | DATA: " . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $entry .= " | DATA: " . substr((string)$data, 0, 500);
        }
    }
    $entry .= "\n";
    $debugBuffer[] = $entry;
}

function flushDebugLog() {
    global $debugBuffer;
    if (!empty($debugBuffer)) {
        $logFile = __DIR__ . '/debug.log';
        $existing = '';
        if (file_exists($logFile)) {
            $existing = @file_get_contents($logFile);
            $lines = preg_split('/\R/', $existing);
            if (count($lines) > 500) {
                $lines = array_slice($lines, count($lines) - 500);
            }
            $existing = implode("\n", $lines);
            if ($existing !== '') $existing .= "\n";
        }
        @file_put_contents($logFile, $existing . implode('', $debugBuffer), FILE_APPEND | LOCK_EX);
        $debugBuffer = [];
    }
}
register_shutdown_function('flushDebugLog');

function poolGet($host) {
    global $_curlPool;
    if (!is_array($_curlPool)) $_curlPool = [];
    if (empty($_curlPool[$host])) {
        $_curlPool[$host] = curl_init();
    }
    return $_curlPool[$host];
}
function poolCloseAll() {
    global $_curlPool;
    if (is_array($_curlPool)) {
        foreach ($_curlPool as $ch) {
            if (is_resource($ch)) curl_close($ch);
        }
        $_curlPool = [];
    }
}
register_shutdown_function('poolCloseAll');

function bonoxsApi($url, $body = null, $extra = []) {
    global $AK, $SK;
    $h = [
        'Content-Type: application/json',
        'Store-Code: co',
        'Bonoxs-Authorization: ' . $AK,
        'app_os: WEB', 'app_version: V2',
        'Origin: https://bonoxs.com',
        'Referer: https://bonoxs.com/',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ];
    if ($body) $h[] = 'Signature: ' . sign($body, $SK);
    foreach ($extra as $e) $h[] = $e;

    $ch = poolGet('bonoxs');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_DNS_CACHE_TIMEOUT => 3600,
        CURLOPT_BUFFERSIZE => 16384,
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_HEADER => false,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    } elseif (defined('CURL_HTTP_VERSION_2_0')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
    }
    curl_setopt_array($ch, $opts);
    if ($body) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $r = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return ['code' => $c, 'data' => json_decode($r, true), 'raw' => $r];
}

function tcApi($url, $body = null, $method = 'GET') {
    $h = [
        'Accept: application/json, text/plain, */*',
        'Content-Type: application/json',
        'Origin: https://gateway2.tucompra.com.co',
        'Referer: https://gateway2.tucompra.com.co/tc/pagos/v1/compra',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ];
    $ch = poolGet('tc');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_NOSIGNAL => 1,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_DNS_CACHE_TIMEOUT => 3600,
        CURLOPT_BUFFERSIZE => 16384,
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    } elseif (defined('CURL_HTTP_VERSION_2_0')) {
        $opts[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
    }
    curl_setopt_array($ch, $opts);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($body) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } else {
        curl_setopt($ch, CURLOPT_HTTPGET, true);
    }
    $response = curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    return [
        'code'    => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'body'    => $hs > 0 && strlen($response) >= $hs ? substr($response, $hs) : $response,
        'url'     => curl_getinfo($ch, CURLINFO_EFFECTIVE_URL),
        'headers' => $hs > 0 ? substr($response, 0, $hs) : '',
    ];
}

function flujoCompleto($email, $bancoCodigo = '1051', $bancoNombre = 'BANCO DAVIVIENDA') {
    global $API, $TC, $nombres, $apellidos;

    debugLog('INICIO', "========== NUEVO FLUJO ==========", ['email' => $email]);

    $nombre   = $nombres[array_rand($nombres)];
    $apellido = $apellidos[array_rand($apellidos)];
    $cedula   = strval(rand(1000000000, 1999999999));
    $celular  = '3' . rand(100000000, 999999999);
    $direccion = 'Calle ' . rand(10, 150) . ' ' . rand(1, 99);

    $cb = json_encode(['sku' => 'Roblox-200 Robux-LAN', 'qty' => 1, 'face_value' => '200 Robux']);
    $cart = bonoxsApi("$API/api/v2/cart", $cb);
    if ($cart['code'] !== 200) {
        debugLog(1, "ERROR Cart", ['code' => $cart['code']]);
        return ['status' => 'error', 'error' => 'Cart fail'];
    }
    $cartId = $cart['data']['cart_id'];
    debugLog(1, "Cart OK", ['cart_id' => $cartId]);

    $maxRetries = 1;
    $res = null;

    for ($i = 0; $i <= $maxRetries; $i++) {
        if ($i > 0) {
            debugLog(2, "Retry $i/$maxRetries");
        }
        $ts  = round(microtime(true) * 1000);
        $rid = $ts . '-' . substr(str_shuffle('abcdefghijklmnopqrstuvwxyz0123456789'), 0, 12);
        $order = json_encode([
            'id' => $rid, 'payment_method' => 'PC', 'email' => $email,
            'name' => $nombre, 'last_name' => $apellido, 'dni' => $cedula,
            'document_type' => 'CC', 'communication_method' => 'email',
        ]);
        $res = bonoxsApi("$API/api/v3/orders", $order, ['X-Timestamp: '.$ts, 'X-Request-ID: '.$rid, 'cart-id: '.$cartId]);
        debugLog(2, "Order intento ".($i+1), ['code' => $res['code']]);
        if ($res['code'] !== 429) break;
        if ($i === $maxRetries) {
            debugLog(2, "429 MAX RETRIES");
            return ['status' => 'rate_limit', 'wait' => 30];
        }
    }

    if ($res['code'] !== 200) {
        debugLog(2, "ERROR Order", ['code' => $res['code'], 'raw' => substr($res['raw'],0,200)]);
        if (strpos($res['raw'], 'pending_limit') !== false) {
            return ['status' => 'rate_limit', 'wait' => 10];
        }
        return ['status' => 'blocked', 'error' => 'Order '.$res['code']];
    }
    $dlocalUrl = $res['data']['redirect_url'] ?? '';
    if (!$dlocalUrl) {
        debugLog(2, "NO redirect_url");
        return ['status' => 'error', 'error' => 'No redirect'];
    }
    debugLog(2, "Order OK", ['url' => $dlocalUrl]);

    $ch3 = curl_init($dlocalUrl);
    $opts3 = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_DNS_CACHE_TIMEOUT => 3600,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) {
        $opts3[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    } elseif (defined('CURL_HTTP_VERSION_2_0')) {
        $opts3[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
    }
    curl_setopt_array($ch3, $opts3);
    $dlocalHtml = curl_exec($ch3);
    curl_close($ch3);

    if (!preg_match('/providerFormContent\s*=\s*(\{.*?\});/s', $dlocalHtml, $m)) {
        debugLog(3, "BLOCKED dLocal");
        return ['status' => 'blocked', 'link' => $dlocalUrl, 'error' => 'dLocal cortado'];
    }
    $formData = json_decode($m[1], true);
    $tcUrl    = $formData['url'];
    $fields   = [];
    foreach ($formData['fields'] as $f) $fields[$f['name']] = $f['value'];
    debugLog(3, "dLocal OK", ['fields' => count($fields)]);

    $ch4 = curl_init($tcUrl);
    $opts4 = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TCP_KEEPALIVE => 1,
        CURLOPT_TCP_NODELAY => 1,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_DNS_CACHE_TIMEOUT => 3600,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    ];
    if (defined('CURL_HTTP_VERSION_2TLS')) {
        $opts4[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2TLS;
    } elseif (defined('CURL_HTTP_VERSION_2_0')) {
        $opts4[CURLOPT_HTTP_VERSION] = CURL_HTTP_VERSION_2_0;
    }
    curl_setopt_array($ch4, $opts4);
    $tcResp = curl_exec($ch4);
    $tcFinal = curl_getinfo($ch4, CURLINFO_EFFECTIVE_URL);
    curl_close($ch4);

    $hash = '';
    if (preg_match('/transaccionId=([a-f0-9]+)/', $tcFinal, $m2)) $hash = $m2[1];
    elseif (preg_match('/transaccionId=([a-f0-9]+)/', $tcResp, $m2)) $hash = $m2[1];
    if (!$hash) {
        debugLog(4, "BLOCKED no hash", ['url' => $tcFinal]);
        return ['status' => 'blocked', 'link' => $dlocalUrl, 'error' => 'TC rechazo'];
    }
    debugLog(4, "Hash OK", ['hash' => $hash]);

    $finger = md5($email . time());
    $step5 = tcApi("$TC/tc/app/inputs/compra", json_encode(['hash' => $hash, 'finger' => $finger]), 'POST');
    if ($step5['code'] !== 200) {
        debugLog(5, "BLOCKED confirmar", ['code' => $step5['code']]);
        return ['status' => 'blocked', 'link' => $dlocalUrl, 'error' => 'Confirmar hash fail'];
    }
    debugLog(5, "Confirm OK");

    $config     = tcApi("$TC/tc/app/config/getConfig/$hash");
    $configData = json_decode($config['body'], true);
    if (!$configData || ($configData['status'] ?? 0) !== 200) {
        debugLog(6, "BLOCKED config");
        return ['status' => 'blocked', 'link' => $dlocalUrl, 'error' => 'Config fail'];
    }
    $payload = $configData['payload'];
    $serial  = $payload['serialtucompra'];
    debugLog(6, "Config OK", ['serial' => $serial]);

    $medios = $payload['terminal']['metododepago'] ?? $payload['terminal']['metodosDePago'] ?? $payload['metodosDePago'] ?? [];
    $pse = null;
    foreach ($medios as $medio) {
        if (($medio['metidmetodo'] ?? 0) == 3 || stripos($medio['metnombremetodo'] ?? '', 'Corriente') !== false) {
            $pse = $medio; break;
        }
    }
    if (!$pse) $pse = ["camposrequeridos"=>null,"descripcionTipo"=>"Produccion","metcamposrequeridos"=>null,"metidmetodo"=>3,"metnombremetodo"=>"Cuenta Corriente/Ahorros","tipo"=>1,"tipopago"=>"Cuenta de Ahorros/Corriente"];

    $token = $payload['tokenTransaccion'] ?? $payload['token'] ?? '';
    tcApi("$TC/tc/app/config/actualizarMetodoDePago", json_encode(['hash'=>$hash,'metodoDePago'=>$pse,'token'=>$token]), 'POST');
    debugLog(7, "Metodo actualizado");

    $trans = [
        'usuario'=>$fields['usuario']??$payload['usuario']??'','factura'=>$fields['factura']??$payload['factura']??'',
        'valor'=>intval($fields['valor']??6459),'valorOriginal'=>intval($fields['valor']??6459),
        'descripcionFactura'=>$fields['descripcionFactura']??'Account balance','serialtucompra'=>$serial,
        'documentoComprador'=>$cedula,'tipoDocumento'=>'CC','nombreComprador'=>strtoupper($nombre),
        'apellidoComprador'=>strtoupper($apellido),'correoComprador'=>$email,'tipoMoneda'=>'COP',
        'metodoPago'=>3,'valorBase'=>0,'valorIva'=>0,'celularComprador'=>'+57'.$celular,
        'direccionComprador'=>$direccion,'campoExtra1'=>$fields['campoExtra1']??'',
        'valorAdicional'=>0,'tokenTransaccion'=>$token,
        'camposMetodoPago'=>['campo1'=>$bancoCodigo,'campo2'=>$bancoNombre,'campo3'=>'0','campo4'=>'Persona Natural'],
        'finger'=>$finger,'politicas'=>true,'codigoPais'=>'co',
    ];
    $completar = tcApi("$TC/tc/app/config/completarTransaccion?hash=$hash", json_encode($trans), 'POST');
    $completarData = json_decode($completar['body'], true);
    debugLog(8, "Completar", ['code'=>$completar['code'],'url'=>$completar['url']]);

    $bankUrl = null;

    if (strpos($completar['url'],'pse.com.co')!==false || strpos($completar['url'],'banco')!==false) $bankUrl = $completar['url'];
    if (!$bankUrl && preg_match('/Location:\s*(https?:\/\/[^\r\n]+)/i', $completar['headers'], $m4)) $bankUrl = trim($m4[1]);

    if (!$bankUrl && $completarData && isset($completarData['payload']) && is_string($completarData['payload']) && strpos($completarData['payload'],'eyJ')===0) {
        $parts = explode('.', $completarData['payload']);
        if (isset($parts[1])) {
            $dec = base64_decode(str_pad(strtr($parts[1],'-_','+/'),strlen($parts[1])%4,'=',STR_PAD_RIGHT));
            if ($dec) {
                $jwt = json_decode($dec, true);
                $bankUrl = $jwt['response']['urlbanco'] ?? $jwt['response']['urlPSE'] ?? $jwt['urlbanco'] ?? $jwt['urlPSE'] ?? null;
                if (!$bankUrl && preg_match('/(https?:\/\/registro\.pse\.com\.co[^"]+)/', $dec, $m3)) $bankUrl = $m3[1];
            }
        }
    }
    if (!$bankUrl && $completarData) {
        $bankUrl = $completarData['payload']['urlPSE'] ?? $completarData['payload']['url'] ?? $completarData['payload']['urlBanco'] ?? $completarData['payload']['redirect'] ?? $completarData['payload']['returnUrl'] ?? null;
        if (!$bankUrl && is_string($completarData['payload']??null) && filter_var($completarData['payload'],FILTER_VALIDATE_URL)) $bankUrl = $completarData['payload'];
        if (!$bankUrl && preg_match('/(https?:\/\/registro\.pse\.com\.co[^"]+)/', json_encode($completarData), $m3)) $bankUrl = $m3[1];
    }

    debugLog('FIN', $bankUrl ? "OK" : "BLOCKED", ['bank_link'=>$bankUrl,'email'=>$email]);
    return [
        'status'    => $bankUrl ? 'ok' : 'blocked',
        'email'     => $email,
        'nombre'    => "$nombre $apellido",
        'cedula'    => $cedula,
        'bank_link' => $bankUrl,
        'error'     => $bankUrl ? null : 'No bank link',
    ];
}

function cargarCorreos() {
    $correosFile = __DIR__ . '/correos.txt';
    $emails = [];
    if (file_exists($correosFile)) {
        $lines = file($correosFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '@') !== false) $emails[] = $line;
        }
    }
    return $emails;
}
