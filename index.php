<?php
$currentUser = 'Operator';
$isAdminOrMercho = true;

require_once __DIR__ . '/flow.php';

// Helper functions
function loadUsers() {
    $file = __DIR__ . '/users.json';
    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);
        if (is_array($json)) return $json;
    }
    return [
        'JowoChk2026'    => 'JowoColCHK',
        'MerchoChk2026'  => 'MarchoColCHK',
        'AdminKrakenOFF' => 'ByKrakenOFF2026',
    ];
}

function saveUsers($users) {
    $file = __DIR__ . '/users.json';
    return file_put_contents($file, json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function getActivityData() {
    $file = __DIR__ . '/activity.json';
    $data = ['heartbeats' => [], 'links' => []];
    if (file_exists($file)) {
        $json = json_decode(file_get_contents($file), true);
        if (is_array($json)) $data = array_merge($data, $json);
    }
    return $data;
}

function saveActivityData($data) {
    $file = __DIR__ . '/activity.json';
    return file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function recordHeartbeat($username) {
    $data = getActivityData();
    $data['heartbeats'][$username] = time();
    saveActivityData($data);
}

function recordLinkGenerated($username, $email, $link, $status, $timeSeconds) {
    $data = getActivityData();
    $data['heartbeats'][$username] = time();
    if (!isset($data['links'])) $data['links'] = [];
    array_unshift($data['links'], [
        'user'       => $username,
        'email'      => $email,
        'link'       => $link,
        'status'     => $status,
        'time_sec'   => $timeSeconds,
        'timestamp'  => time(),
        'date_str'   => date('d/m/Y H:i:s'),
    ]);
    if (count($data['links']) > 150) {
        $data['links'] = array_slice($data['links'], 0, 150);
    }
    saveActivityData($data);
}

function tiempoRelativo($timestamp) {
    $diff = time() - $timestamp;
    if ($diff < 5) return 'Justo ahora';
    if ($diff < 60) return "Hace $diff seg";
    $mins = floor($diff / 60);
    if ($mins < 60) return "Hace $mins min" . ($mins > 1 ? 's' : '');
    $hours = floor($mins / 60);
    if ($hours < 24) return "Hace $hours hora" . ($hours > 1 ? 's' : '');
    $days = floor($hours / 24);
    return "Hace $days día" . ($days > 1 ? 's' : '');
}

recordHeartbeat($currentUser);

// AJAX Endpoints
if (isset($_POST['heartbeat'])) {
    header('Content-Type: application/json');
    recordHeartbeat($currentUser);
    echo json_encode(['ok' => true]);
    exit;
}

if (isset($_POST['api']) && isset($_POST['email'])) {
    header('Content-Type: application/json');
    $email = trim($_POST['email']);
    $t0 = microtime(true);
    $res = flujoCompleto($email);
    $elapsed = round(microtime(true) - $t0, 1);
    if (($res['status'] ?? '') === 'ok' && !empty($res['bank_link'])) {
        recordLinkGenerated($currentUser, $email, $res['bank_link'], 'ok', $elapsed);
    }
    echo json_encode($res, JSON_UNESCAPED_SLASHES);
    exit;
}

if (isset($_POST['subir_txt_correos'])) {
    header('Content-Type: application/json');
    if (!isset($_FILES['archivo_correos']) || $_FILES['archivo_correos']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Error al subir el archivo']);
        exit;
    }

    $filename = $_FILES['archivo_correos']['name'];
    if (strtolower(trim($filename)) !== 'correos.txt') {
        echo json_encode(['ok' => false, 'msg' => 'El archivo debe llamarse obligatoriamente "correos.txt"']);
        exit;
    }

    $content = file_get_contents($_FILES['archivo_correos']['tmp_name']);
    $rawLines = preg_split('/\r\n|\r|\n/', $content);
    $validEmails = [];
    foreach ($rawLines as $line) {
        $em = strtolower(trim($line));
        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $validEmails[$em] = true;
        }
    }

    if (empty($validEmails)) {
        echo json_encode(['ok' => false, 'msg' => 'No se encontraron correos válidos en el archivo']);
        exit;
    }

    $correosFile = __DIR__ . '/correos.txt';
    $existing = [];
    if (file_exists($correosFile)) {
        $lines = file($correosFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $l) {
            $em = strtolower(trim($l));
            if (filter_var($em, FILTER_VALIDATE_EMAIL)) $existing[$em] = true;
        }
    }

    $totalAdded = 0;
    foreach (array_keys($validEmails) as $em) {
        if (!isset($existing[$em])) {
            $existing[$em] = true;
            $totalAdded++;
        }
    }

    $finalList = array_keys($existing);
    file_put_contents($correosFile, implode("\n", $finalList) . (count($finalList) ? "\n" : ''), LOCK_EX);

    echo json_encode([
        'ok'     => true,
        'msg'    => "Se importaron $totalAdded correos nuevos. Total: " . count($finalList),
        'emails' => $finalList,
        'count'  => count($finalList)
    ]);
    exit;
}

if (isset($_POST['quitar_correo']) && isset($_POST['correo_a_quitar'])) {
    header('Content-Type: application/json');
    $correo = strtolower(trim($_POST['correo_a_quitar']));
    $correosFile = __DIR__ . '/correos.txt';
    if (file_exists($correosFile)) {
        $lines = file($correosFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $newLines = array_filter($lines, function($l) use ($correo) {
            return strtolower(trim($l)) !== $correo;
        });
        file_put_contents($correosFile, implode("\n", $newLines) . (count($newLines) ? "\n" : ''));
        echo json_encode(['ok' => true, 'msg' => 'Correo eliminado', 'count' => count($newLines)]);
    } else {
        echo json_encode(['ok' => false, 'msg' => 'No hay correos']);
    }
    exit;
}

if (isset($_POST['agregar_correo']) && isset($_POST['nuevo_correo'])) {
    header('Content-Type: application/json');
    $correo = strtolower(trim($_POST['nuevo_correo']));
    if (filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        $correosFile = __DIR__ . '/correos.txt';
        $existe = false;
        $existing = [];
        if (file_exists($correosFile)) {
            $existing = file($correosFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($existing as $line) {
                if (strtolower(trim($line)) === $correo) { $existe = true; break; }
            }
        }
        if ($existe) {
            echo json_encode(['ok' => false, 'msg' => 'Ese correo ya existe']);
        } else {
            $contenido = file_exists($correosFile) ? file_get_contents($correosFile) : '';
            if ($contenido !== '' && substr($contenido, -1) !== "\n") $contenido .= "\n";
            file_put_contents($correosFile, $contenido . $correo . "\n", LOCK_EX);
            echo json_encode(['ok' => true, 'msg' => 'Correo agregado', 'count' => count($existing) + 1]);
        }
    } else {
        echo json_encode(['ok' => false, 'msg' => 'Email inválido']);
    }
    exit;
}

$EMAILS = cargarCorreos();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KRAKEN OFF SYSTEM — Cyber HUD</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        @font-face{font-family:'Batman Forever';src:url('batman_forever.ttf') format('truetype')}
        *{margin:0;padding:0;box-sizing:border-box}
        
        :root{
            --theme-primary:#dc2626;
            --theme-primary-hover:#ef4444;
            --theme-primary-glow:rgba(220, 38, 38, 0.45);
            --theme-accent:#b91c1c;
            --theme-accent-glow:rgba(185, 28, 28, 0.35);
            --theme-beam:#dc2626;
            --theme-bg-gradient:radial-gradient(circle at 15% 10%, rgba(220, 38, 38, 0.08) 0%, transparent 40%), radial-gradient(circle at 85% 90%, rgba(185, 28, 28, 0.06) 0%, transparent 40%);
        }

        body{
            font-family:'Plus Jakarta Sans', sans-serif;
            background:#06080d;
            background-image:var(--theme-bg-gradient);
            color:#f1f5f9;
            min-height:100vh;
            display:flex;
            flex-direction:column;
            align-items:center;
            padding:0 0 24px;
            position:relative;
            overflow-x:hidden;
            perspective:1200px;
        }

        /* Fixed Top Header */
        .top-header{
            position:sticky;
            top:0;
            left:0;
            width:100%;
            z-index:50;
            padding:0;
            background:rgba(0, 0, 0, 0.92);
            backdrop-filter:blur(20px) saturate(180%);
            -webkit-backdrop-filter:blur(20px) saturate(180%);
            border-bottom:2px solid #dc2626;
            box-shadow:0 4px 30px rgba(220, 38, 38, 0.15);
        }

        /* 3D Canvas Background & Confetti */
        #bg-canvas, #sparks-canvas{
            position:fixed;
            top:0;left:0;
            width:100%;height:100%;
            pointer-events:none;
        }
        #bg-canvas{z-index:1}
        #sparks-canvas{z-index:99}

        /* Scanlines Overlay */
        .scanlines{
            position:fixed;
            top:0;left:0;width:100%;height:100%;
            background:linear-gradient(rgba(18, 16, 16, 0) 50%, rgba(0, 0, 0, 0.25) 50%), linear-gradient(90deg, rgba(220, 0, 0, 0.03), rgba(0, 0, 0, 0.01), rgba(220, 0, 0, 0.03));
            background-size:100% 3px, 6px 100%;
            pointer-events:none;
            z-index:2;
            opacity:0.65;
        }

        /* Dynamic Island Toast */
        .dynamic-island-toast{
            position:fixed;
            top:20px;
            left:50%;
            transform:translateX(-50%) translateY(-100px) scale(0.9);
            background:rgba(15, 23, 42, 0.95);
            backdrop-filter:blur(20px);
            border:1px solid var(--theme-primary);
            box-shadow:0 10px 30px rgba(0,0,0,0.8), 0 0 20px var(--theme-primary-glow);
            border-radius:50px;
            padding:10px 22px;
            font-size:13px;
            font-weight:700;
            color:#fff;
            display:flex;
            align-items:center;
            gap:10px;
            z-index:110;
            transition:all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            opacity:0;
            pointer-events:none;
        }
        .dynamic-island-toast.active{
            transform:translateX(-50%) translateY(0) scale(1);
            opacity:1;
        }

        /* Shockwave expanding ring */
        .shockwave-ring{
            position:fixed;
            border-radius:50%;
            border:2px solid var(--theme-primary);
            box-shadow:0 0 30px var(--theme-primary);
            pointer-events:none;
            transform:translate(-50%, -50%) scale(0);
            opacity:1;
            z-index:90;
        }
        @keyframes shockwaveAnim{
            to{
                transform:translate(-50%, -50%) scale(25);
                opacity:0;
            }
        }

        /* Container & 3D Tilt Card */
        .container{
            position:relative;
            z-index:3;
            text-align:center;
            width:100%;
            max-width:630px;
            margin-top:28px;
            padding:34px 28px;
            background:rgba(0, 0, 0, 0.95);
            backdrop-filter:blur(28px) saturate(200%);
            -webkit-backdrop-filter:blur(28px) saturate(200%);
            border:1px solid rgba(220, 38, 38, 0.25);
            border-radius:32px;
            box-shadow:
                0 30px 60px -15px rgba(0,0,0,0.9),
                0 0 40px rgba(0,0,0,0.5),
                inset 0 1px 0 rgba(255,255,255,0.12);
            transform-style:preserve-3d;
            transition:transform 0.15s ease-out;
            animation:fadeIn 0.7s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }

        @keyframes fadeIn{
            from{opacity:0;transform:translateY(20px) scale(0.98)}
            to{opacity:1;transform:translateY(0) scale(1)}
        }

        /* Border Beam Rotating Glow */
        .container::before{
            content:'';
            position:absolute;
            inset:-1px;
            border-radius:33px;
            padding:1.5px;
            background:conic-gradient(from var(--beam-angle, 0deg) at 50% 50%, transparent 60%, var(--theme-beam) 85%, transparent 100%);
            -webkit-mask:linear-gradient(#fff 0 0) content-box, linear-gradient(#fff 0 0);
            -webkit-mask-composite:xor;
            mask-composite:exclude;
            pointer-events:none;
            animation:beamRotate 4s linear infinite;
        }
        @property --beam-angle{
            syntax:'<angle>';
            initial-value:0deg;
            inherits:false;
        }
        @keyframes beamRotate{
            to{--beam-angle:360deg}
        }

        /* Tactical HUD Corner Brackets */
        .hud-bracket{
            position:absolute;
            width:14px;
            height:14px;
            border-color:var(--theme-primary);
            border-style:solid;
            pointer-events:none;
            opacity:0.75;
        }
        .hud-bracket.tl{top:12px;left:12px;border-width:2px 0 0 2px;border-top-left-radius:6px}
        .hud-bracket.tr{top:12px;right:12px;border-width:2px 2px 0 0;border-top-right-radius:6px}
        .hud-bracket.bl{bottom:12px;left:12px;border-width:0 0 2px 2px;border-bottom-left-radius:6px}
        .hud-bracket.br{bottom:12px;right:12px;border-width:0 2px 2px 0;border-bottom-right-radius:6px}

        /* Floating Top Bar */
        .user-bar{
            display:flex;
            justify-content:space-between;
            align-items:center;
            flex-wrap:wrap;
            gap:10px;
            background:transparent;
            border:none;
            border-radius:0;
            padding:10px 24px;
            width:100%;
            font-size:12.5px;
        }
        .user-tag{
            display:flex;
            align-items:center;
            gap:10px;
            font-weight:700;
            color:#f1f5f9;
            font-size:13px;
            letter-spacing:0.3px;
        }
        .header-brand{
            font-family:'Batman Forever', sans-serif;
            font-size:15px;
            color:#dc2626;
            letter-spacing:2px;
            text-shadow:0 0 10px rgba(220, 38, 38, 0.5);
        }
        .header-separator{
            color:rgba(255,255,255,0.15);
            font-weight:300;
            font-size:16px;
        }
        .role-badge{
            font-size:10px;
            padding:2px 8px;
            border-radius:12px;
            letter-spacing:0.5px;
            font-weight:800;
            text-transform:uppercase;
        }
        .role-admin{
            background:rgba(220, 38, 38, 0.15);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.4);
            box-shadow:0 0 10px rgba(220, 38, 38, 0.2);
        }
        .role-mercho{
            background:rgba(220, 38, 38, 0.15);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.4);
            box-shadow:0 0 10px rgba(220, 38, 38, 0.2);
        }
        .role-operador{
            background:rgba(220, 38, 38, 0.12);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.25);
        }

        .bar-actions{
            display:flex;
            align-items:center;
            gap:6px;
        }
        .btn-nav{
            background:rgba(220, 38, 38, 0.08);
            border:1px solid rgba(220, 38, 38, 0.2);
            color:#d4d4d8;
            padding:7px 14px;
            border-radius:8px;
            font-size:12px;
            font-weight:700;
            cursor:pointer;
            transition:all .2s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:4px;
            font-family:'Plus Jakarta Sans', sans-serif;
            letter-spacing:0.2px;
        }
        .btn-nav:hover{
            background:rgba(220, 38, 38, 0.2);
            color:#ffffff;
            transform:translateY(-1px);
            border-color:rgba(220, 38, 38, 0.5);
            box-shadow:0 4px 12px rgba(220, 38, 38, 0.2);
        }
        .btn-logout{
            color:#ef4444;
            background:rgba(239, 68, 68, 0.08);
            border:1px solid rgba(239, 68, 68, 0.25);
        }
        .btn-logout:hover{
            background:rgba(239, 68, 68, 0.2);
            color:#fca5a5;
            box-shadow:0 4px 12px rgba(239, 68, 68, 0.2);
        }

        /* Theme Selector - Removed */

        /* Logo Wrap with Orbital Ring */
        .logo-wrap{
            position:relative;
            display:inline-block;
            margin-bottom:16px;
            cursor:pointer;
        }
        .logo-kraken{
            width:115px;
            height:115px;
            border-radius:50%;
            object-fit:cover;
            border:2px solid rgba(255,255,255,0.15);
            box-shadow:0 0 35px var(--theme-primary-glow);
            display:block;
            transition:transform 0.4s ease;
        }
        .logo-wrap:hover .logo-kraken{
            transform:scale(1.06);
        }
        .logo-wrap::after{
            content:'';
            position:absolute;
            inset:-6px;
            border-radius:50%;
            border:1.5px dashed var(--theme-primary);
            animation:rotateRing 14s linear infinite;
        }
        @keyframes rotateRing{
            from{transform:rotate(0deg)}
            to{transform:rotate(360deg)}
        }

        /* Cyberpunk Glitch Effect on Title */
        .title-shark{
            font-family:'Batman Forever', sans-serif;
            font-size:clamp(24px,6vw,34px);
            color:#ffffff;
            margin-bottom:8px;
            letter-spacing:1.5px;
            text-shadow:0 2px 14px rgba(0,0,0,0.8);
            position:relative;
            display:inline-block;
            cursor:default;
        }
        .title-shark:hover{
            animation:glitch 0.3s cubic-bezier(0.25, 0.46, 0.45, 0.94) both;
        }
        @keyframes glitch{
            0%{transform:translate(0)}
            20%{transform:translate(-2px, 2px);text-shadow:2px 0 var(--theme-primary), -2px 0 var(--theme-accent)}
            40%{transform:translate(-2px, -2px);text-shadow:-2px 0 var(--theme-primary), 2px 0 var(--theme-accent)}
            60%{transform:translate(2px, 2px);text-shadow:2px 0 var(--theme-accent), -2px 0 var(--theme-primary)}
            80%{transform:translate(2px, -2px);text-shadow:-2px 0 var(--theme-accent), 2px 0 var(--theme-primary)}
            100%{transform:translate(0)}
        }

        /* Tactical Live HUD Bar */
        .hud-status-strip{
            display:flex;
            justify-content:center;
            align-items:center;
            gap:12px;
            margin-bottom:22px;
        }
        .race-badge{
            display:inline-flex;
            align-items:center;
            gap:6px;
            background:rgba(255, 255, 255, 0.04);
            border:1px solid rgba(255, 255, 255, 0.1);
            border-radius:20px;
            padding:4px 14px;
            font-size:11px;
            color:var(--theme-primary-hover);
            letter-spacing:1px;
            font-weight:800;
            text-transform:uppercase;
        }
        .ping-indicator{
            font-size:11px;
            font-family:'JetBrains Mono', monospace;
            color:#94a3b8;
            background:rgba(10, 15, 29, 0.6);
            border:1px solid rgba(255, 255, 255, 0.08);
            border-radius:20px;
            padding:4px 12px;
            display:inline-flex;
            align-items:center;
            gap:6px;
        }
        .ping-dot{
            width:6px;
            height:6px;
            border-radius:50%;
            background:#dc2626;
            box-shadow:0 0 6px #dc2626;
            animation:pulse .8s infinite alternate;
        }

        /* Giant Shimmer Button with Border Beam */
        .btn-giant{
            display:block;
            margin:0 auto;
            width:100%;
            max-width:400px;
            padding:20px 24px;
            background:linear-gradient(135deg, var(--theme-primary) 0%, #991b1b 100%);
            color:#ffffff;
            border:none;
            border-radius:50px;
            font-family:'Orbitron', sans-serif;
            font-size:clamp(19px,5vw,23px);
            font-weight:800;
            cursor:pointer;
            transition:all .3s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow:0 10px 30px var(--theme-primary-glow), 0 0 20px rgba(0,0,0,0.5);
            position:relative;
            overflow:hidden;
            letter-spacing:2px;
        }
        .btn-giant:hover:not(:disabled){
            transform:translateY(-2px) scale(1.02);
            box-shadow:0 15px 45px var(--theme-primary-glow);
            background:linear-gradient(135deg, var(--theme-primary-hover) 0%, var(--theme-primary) 100%);
        }
        .btn-giant:active:not(:disabled){
            transform:translateY(0) scale(0.98);
        }
        .btn-giant:disabled{
            background:rgba(30, 41, 59, 0.7);
            color:#64748b;
            box-shadow:none;
            cursor:not-allowed;
            transform:none;
        }
        .btn-giant::after{
            content:'';
            position:absolute;
            top:0;left:-100%;
            width:60%;height:100%;
            background:linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation:shimmer 3.5s infinite;
        }
        @keyframes shimmer{
            0%{left:-100%}
            30%, 100%{left:150%}
        }

        /* Scanning Progress Bar */
        .progress-bar-container{
            width:100%;
            max-width:400px;
            height:5px;
            background:rgba(255, 255, 255, 0.06);
            border-radius:10px;
            margin:16px auto 0;
            overflow:hidden;
            display:none;
        }
        .progress-bar-container.active{display:block}
        .progress-bar-fill{
            width:100%;
            height:100%;
            background:linear-gradient(90deg, var(--theme-primary), var(--theme-accent), var(--theme-primary));
            background-size:200% 100%;
            animation:moveGradient 1.1s linear infinite;
            border-radius:10px;
        }
        @keyframes moveGradient{
            0%{background-position:100% 0}
            100%{background-position:-100% 0}
        }

        /* Generated Link Result Card (Directly Below Main Button) */
        .link-result-card{
            margin:24px auto 0;
            width:100%;
            max-width:490px;
            background:rgba(0, 0, 0, 0.95);
            border:1.5px solid var(--theme-primary);
            border-radius:24px;
            padding:22px 20px;
            text-align:left;
            box-shadow:
                0 20px 40px -10px rgba(0,0,0,0.7),
                0 0 35px var(--theme-primary-glow);
            animation:fadeInUp 0.5s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            display:none;
            position:relative;
        }
        .link-result-card.show{display:block}
        @keyframes fadeInUp{
            from{opacity:0;transform:translateY(16px) scale(0.97)}
            to{opacity:1;transform:translateY(0) scale(1)}
        }
        .link-result-head{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:12px;
        }
        .link-badge-ok{
            background:linear-gradient(135deg, var(--theme-primary), #991b1b);
            color:#fff;
            font-size:11px;
            font-weight:800;
            padding:4px 12px;
            border-radius:20px;
            letter-spacing:0.5px;
            display:inline-flex;
            align-items:center;
            gap:5px;
            box-shadow:0 2px 10px var(--theme-primary-glow);
        }
        .link-time-info{
            font-size:11.5px;
            color:#94a3b8;
            font-weight:600;
        }
        .link-input-group{
            display:flex;
            gap:8px;
            margin-bottom:12px;
        }
        .link-input-group input{
            flex:1;
            padding:12px 14px;
            background:rgba(6, 10, 19, 0.9);
            border:1px solid rgba(255, 255, 255, 0.12);
            border-radius:12px;
            color:#ef4444;
            font-family:'JetBrains Mono', monospace;
            font-size:12.5px;
            outline:none;
            font-weight:600;
            transition:border-color .2s;
        }
        .btn-action-copy, .btn-action-open{
            padding:12px 16px;
            border-radius:12px;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
            transition:all .2s cubic-bezier(0.16, 1, 0.3, 1);
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:6px;
            border:none;
            font-family:'Plus Jakarta Sans', sans-serif;
        }
        .btn-action-copy{
            background:rgba(255, 255, 255, 0.08);
            color:#f1f5f9;
            border:1px solid rgba(255, 255, 255, 0.15);
        }
        .btn-action-copy:hover{
            background:rgba(255, 255, 255, 0.18);
            transform:translateY(-1px);
        }
        .btn-action-open{
            background:linear-gradient(135deg, #dc2626 0%, #991b1b 100%);
            color:#ffffff;
            flex:1;
            box-shadow:0 6px 20px rgba(220, 38, 38, 0.35);
        }
        .btn-action-open:hover{
            background:linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
            box-shadow:0 8px 25px rgba(220, 38, 38, 0.5);
            transform:translateY(-1px);
        }

        /* Modals */
        .modal-overlay{
            position:fixed;
            top:0;left:0;
            width:100%;height:100%;
            background:rgba(2, 6, 15, 0.85);
            backdrop-filter:blur(18px);
            -webkit-backdrop-filter:blur(18px);
            z-index:100;
            display:none;
            align-items:center;
            justify-content:center;
            padding:20px;
        }
        .modal-overlay.open{
            display:flex;
            animation:fadeIn 0.25s ease;
        }
        .modal-card{
            background:rgba(15, 23, 42, 0.95);
            backdrop-filter:blur(24px) saturate(180%);
            border:1px solid rgba(255, 255, 255, 0.1);
            border-top:1px solid rgba(255, 255, 255, 0.2);
            border-radius:28px;
            width:100%;
            max-width:590px;
            max-height:88vh;
            overflow-y:auto;
            padding:28px 24px;
            box-shadow:
                0 30px 60px -15px rgba(0,0,0,0.9),
                0 0 0 1px rgba(255,255,255,0.04);
            position:relative;
        }
        .modal-header{
            display:flex;
            justify-content:space-between;
            align-items:center;
            margin-bottom:18px;
            padding-bottom:14px;
            border-bottom:1px solid rgba(255, 255, 255, 0.08);
        }
        .modal-title{
            font-size:16px;
            font-weight:800;
            color:#f1f5f9;
            display:flex;
            align-items:center;
            gap:8px;
        }
        .modal-close{
            background:none;
            border:none;
            color:#94a3b8;
            font-size:24px;
            cursor:pointer;
            line-height:1;
            padding:4px;
            transition:color .2s;
        }
        .modal-close:hover{color:#fff}

        /* Segmented Tabs */
        .tab-nav{
            display:flex;
            gap:6px;
            background:rgba(10, 15, 29, 0.6);
            border:1px solid rgba(255, 255, 255, 0.06);
            border-radius:14px;
            padding:4px;
            margin-bottom:20px;
        }
        .tab-btn{
            flex:1;
            background:none;
            border:none;
            color:#94a3b8;
            font-size:12.5px;
            font-weight:700;
            padding:8px 12px;
            border-radius:10px;
            cursor:pointer;
            transition:all .2s ease;
            font-family:'Plus Jakarta Sans', sans-serif;
        }
        .tab-btn.active{
            background:rgba(220, 38, 38, 0.15);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.25);
            box-shadow:0 2px 8px rgba(0,0,0,0.2);
        }
        .tab-pane{display:none}
        .tab-pane.active{display:block}

        /* Forms in Modals */
        .add-mail-row{display:flex;gap:8px;margin-bottom:14px}
        .input-dark{
            flex:1;
            padding:12px 16px;
            border:1px solid rgba(255, 255, 255, 0.08);
            border-radius:12px;
            font-size:13.5px;
            outline:none;
            background:rgba(10, 15, 29, 0.85);
            color:#fff;
            font-family:'Plus Jakarta Sans', sans-serif;
            transition:all .2s;
        }
        .input-dark:focus{
            border-color:#ef4444;
            box-shadow:0 0 0 3px rgba(220, 38, 38, 0.35);
        }
        .btn-primary{
            padding:12px 20px;
            background:linear-gradient(135deg, #dc2626, #991b1b);
            color:#fff;
            border:none;
            border-radius:12px;
            font-size:13px;
            font-weight:700;
            cursor:pointer;
            transition:all .2s;
            white-space:nowrap;
            font-family:'Plus Jakarta Sans', sans-serif;
        }
        .btn-primary:hover{
            background:linear-gradient(135deg, #ef4444, #dc2626);
            transform:translateY(-1px);
        }

        .upload-box{
            border:2px dashed rgba(255, 255, 255, 0.15);
            border-radius:18px;
            padding:26px 18px;
            text-align:center;
            background:rgba(10, 15, 29, 0.4);
            transition:all .2s;
            margin-bottom:15px;
            cursor:pointer;
        }
        .upload-box:hover{
            border-color:#ef4444;
            background:rgba(220, 38, 38, 0.04);
        }
        .upload-badge-req{
            display:inline-block;
            background:rgba(220, 38, 38, 0.12);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.3);
            border-radius:8px;
            padding:4px 12px;
            font-size:11.5px;
            font-weight:800;
            margin-bottom:10px;
        }
        .file-input-custom{display:none}

        /* Email list chips */
        .add-mail-list{
            margin-top:16px;
            text-align:left;
            max-height:190px;
            overflow-y:auto;
            border-top:1px solid rgba(255, 255, 255, 0.08);
            padding-top:14px;
        }
        .add-mail-list-title{
            font-size:11px;
            color:#94a3b8;
            text-transform:uppercase;
            letter-spacing:1px;
            margin-bottom:10px;
            font-weight:800;
        }
        .mail-chip{
            display:flex;
            align-items:center;
            justify-content:space-between;
            background:rgba(10, 15, 29, 0.6);
            border:1px solid rgba(255, 255, 255, 0.06);
            border-radius:10px;
            padding:8px 12px;
            margin-bottom:6px;
            font-size:12.5px;
            color:#cbd5e1;
            font-family:'JetBrains Mono', monospace;
        }
        .mail-chip .mail-del{background:none;border:none;color:#f87171;cursor:pointer;font-size:16px;line-height:1;padding:0 4px}
        .mail-chip .mail-del:hover{color:#fca5a5}
        .add-mail-empty{text-align:center;color:#64748b;font-size:13px;padding:12px 0}
        
        .toast-msg{
            margin-top:10px;
            font-size:12.5px;
            display:none;
            padding:9px 14px;
            border-radius:10px;
            text-align:center;
            font-weight:600;
        }
        .toast-msg.ok{color:#ef4444;background:rgba(220, 38, 38, 0.12);border:1px solid rgba(220,38,38,0.25);display:block}
        .toast-msg.err{color:#f87171;background:rgba(239, 68, 68, 0.12);border:1px solid rgba(239,68,68,0.25);display:block}

        /* Activity / Users UI */
        .live-badge{
            background:rgba(220, 38, 38, 0.12);
            color:#ef4444;
            border:1px solid rgba(220, 38, 38, 0.3);
            border-radius:20px;
            padding:3px 10px;
            font-size:11px;
            font-weight:800;
            display:inline-flex;
            align-items:center;
            gap:5px;
        }
        .live-dot{
            width:6px;
            height:6px;
            border-radius:50%;
            background:#dc2626;
            box-shadow:0 0 8px #dc2626;
            animation:pulse .8s infinite alternate;
        }
        .users-grid{
            display:grid;
            grid-template-columns:repeat(auto-fill, minmax(160px, 1fr));
            gap:10px;
            margin-bottom:20px;
        }
        .user-card-stat{
            background:rgba(10, 15, 29, 0.6);
            border:1px solid rgba(255, 255, 255, 0.06);
            border-radius:14px;
            padding:12px;
            text-align:left;
        }
        .user-card-stat .u-name{
            font-weight:700;
            font-size:13px;
            color:#ffffff;
            display:flex;
            align-items:center;
            gap:6px;
            margin-bottom:4px;
        }
        .user-card-stat .u-seen{
            font-size:11px;
            color:#94a3b8;
        }
        .online-dot{
            width:7px;
            height:7px;
            border-radius:50%;
            display:inline-block;
        }
        .online-dot.on{background:#dc2626;box-shadow:0 0 6px #dc2626}
        .online-dot.off{background:#64748b}

        .links-table-wrap{
            max-height:230px;
            overflow-y:auto;
            border:1px solid rgba(255, 255, 255, 0.08);
            border-radius:14px;
            background:rgba(10, 15, 29, 0.5);
            scrollbar-width:thin;
            scrollbar-color:rgba(220,38,38,0.4) transparent;
        }
        .links-table-wrap::-webkit-scrollbar{width:6px}
        .links-table-wrap::-webkit-scrollbar-track{background:transparent}
        .links-table-wrap::-webkit-scrollbar-thumb{background:rgba(220,38,38,0.4);border-radius:3px}
        .links-table-wrap::-webkit-scrollbar-thumb:hover{background:rgba(220,38,38,0.6)}
        .links-table{
            width:100%;
            border-collapse:collapse;
            font-size:12px;
            text-align:left;
        }
        .links-table th{
            background:rgba(255, 255, 255, 0.04);
            padding:10px 12px;
            color:#94a3b8;
            font-weight:700;
            position:sticky;
            top:0;
            font-size:11px;
            text-transform:uppercase;
            letter-spacing:0.5px;
        }
        .links-table td{
            padding:10px 12px;
            border-bottom:1px solid rgba(255, 255, 255, 0.04);
            color:#cbd5e1;
        }
        .links-table tr:last-child td{border-bottom:none}
        .btn-table-action{
            padding:4px 8px;
            background:rgba(220, 38, 38, 0.12);
            border:1px solid rgba(220, 38, 38, 0.25);
            color:#ef4444;
            border-radius:6px;
            font-size:11px;
            font-weight:700;
            text-decoration:none;
            cursor:pointer;
            margin-right:4px;
            display:inline-block;
            font-family:'Plus Jakarta Sans', sans-serif;
            transition:all .15s ease;
        }
        .btn-table-action:hover{
            background:rgba(220, 38, 38, 0.25);
            color:#ffffff;
        }

        .ripple{
            position:absolute;
            border-radius:50%;
            transform:scale(0);
            animation:rippleAnim 0.6s linear;
            background-color:rgba(255,255,255,0.35);
            pointer-events:none;
        }
        @keyframes rippleAnim{
            to{transform:scale(4);opacity:0}
        }
        @keyframes pulse{from{opacity:.4}to{opacity:1}}
    </style>
</head>
<body>
<div class="scanlines"></div>
<canvas id="bg-canvas"></canvas>
<canvas id="sparks-canvas"></canvas>

<div class="dynamic-island-toast" id="dynamicToast">
    <span id="toastIcon">*</span>
    <span id="toastText">Notificacion</span>
</div>

<!-- Fixed Top Header -->
<header class="top-header">
    <div class="user-bar">
        <div class="user-tag">
            <span class="header-brand">KRAKEN OFF</span>
        </div>
        <div class="bar-actions">
            <button class="btn-nav" onclick="abrirModalCorreos()">Correos (<span id="btnMailCount"><?=count($EMAILS)?></span>)</button>
        </div>
    </div>
</header>

<div class="container" id="mainContainer">
    <!-- Tactical HUD Corners -->
    <div class="hud-bracket tl"></div>
    <div class="hud-bracket tr"></div>
    <div class="hud-bracket bl"></div>
    <div class="hud-bracket br"></div>

    <!-- Main Header with Glitch Title -->
    <div class="logo-wrap">
        <img src="https://i.pinimg.com/1200x/31/e8/ea/31e8ea04d09c05280bdfd5382cc12ae4.jpg" alt="Kraken" class="logo-kraken">
    </div>
    <br>
    <h1 class="title-shark" data-text="KRAKEN OFF SYSTEM">KRAKEN OFF SYSTEM</h1>

    <!-- Tactical Live Status Strip -->
    <div class="hud-status-strip">
        <div class="race-badge">RACE MODE V3.0</div>
        <div class="ping-indicator">
            <span class="ping-dot"></span>
            <span id="pingMs">14ms</span>
        </div>
    </div>

    <!-- Main Action Button -->
    <button class="btn-giant ripple-btn" id="btnMain" onclick="iniciarProcesoConEfectos(event)">GENERAR LINK</button>

    <!-- Scanning Progress Bar -->
    <div class="progress-bar-container" id="progressBar">
        <div class="progress-bar-fill"></div>
    </div>

    <!-- Generated Link Result Card (Directly Below Main Button) -->
    <div class="link-result-card" id="linkResultCard">
        <div class="link-result-head">
            <span class="link-badge-ok">LINK GENERADO CON EXITO</span>
            <span class="link-time-info" id="linkTimeInfo">en 0.0s</span>
        </div>
        <div class="link-input-group">
            <input type="text" id="linkInput" readonly placeholder="https://registro.pse.com.co/..." onclick="this.select()">
            <button class="btn-action-copy ripple-btn" onclick="copiarLinkGenerado()">Copiar Link</button>
        </div>
        <div style="display:flex;gap:8px;">
            <a href="#" id="linkOpenBtn" target="_blank" class="btn-action-open ripple-btn">Abrir Banco / PSE</a>
        </div>
    </div>
</div>

<!-- MODAL: DASHBOARD DE CORREOS -->
<div class="modal-overlay" id="modalCorreos">
    <div class="modal-card">
        <div class="modal-header">
            <div class="modal-title">Dashboard de Correos</div>
            <button class="modal-close" onclick="cerrarModalCorreos()">×</button>
        </div>

        <div class="tab-nav">
            <button class="tab-btn active" onclick="switchMailTab(event, 'tabMailUno')">Agregar 1 por 1</button>
            <button class="tab-btn" onclick="switchMailTab(event, 'tabMailTxt')">Subir correos.txt</button>
        </div>

        <!-- Tab 1: Manual -->
        <div class="tab-pane active" id="tabMailUno">
            <div class="add-mail-row">
                <input type="email" id="nuevoCorreo" class="input-dark" placeholder="correo@ejemplo.com">
                <button class="btn-primary ripple-btn" onclick="agregarCorreo()">Agregar</button>
            </div>
        </div>

        <!-- Tab 2: Upload TXT -->
        <div class="tab-pane" id="tabMailTxt">
            <div class="upload-box" onclick="document.getElementById('fileCorreos').click()">
                <div class="upload-badge-req">OBLIGATORIO: El archivo debe llamarse correos.txt</div>
                <p style="font-size:13px;color:#f1f5f9;margin-bottom:4px;font-weight:700;">Haz clic aquí para seleccionar tu archivo <b>correos.txt</b></p>
                <p style="font-size:11.5px;color:#94a3b8;">Se importarán automáticamente todos los correos válidos</p>
                <input type="file" id="fileCorreos" class="file-input-custom" accept=".txt" onchange="subirArchivoCorreos(this)">
            </div>
        </div>

        <div class="toast-msg" id="mailMsg"></div>

        <div class="add-mail-list">
            <div class="add-mail-list-title">Correos registrados (<span id="mailListCount"><?=count($EMAILS)?></span>)</div>
            <div id="mailList"></div>
        </div>
    </div>
</div>

<script>
// Dynamic Island Toast
let toastTimer = null;
function showToast(text, icon = '*'){
    const t = document.getElementById('dynamicToast');
    document.getElementById('toastIcon').textContent = icon;
    document.getElementById('toastText').textContent = text;
    t.classList.add('active');
    if(toastTimer) clearTimeout(toastTimer);
    toastTimer = setTimeout(()=>{ t.classList.remove('active'); }, 2600);
}

// 3D Card Tilt Effect
const card = document.getElementById('mainContainer');
let cardBounds = card.getBoundingClientRect();
window.addEventListener('resize', ()=>{ cardBounds = card.getBoundingClientRect(); });

document.addEventListener('mousemove', (e)=>{
    if(window.innerWidth < 768) return;
    const x = e.clientX - (cardBounds.left + cardBounds.width/2);
    const y = e.clientY - (cardBounds.top + cardBounds.height/2);
    const radX = (y / (window.innerHeight/2)) * -6;
    const radY = (x / (window.innerWidth/2)) * 6;
    card.style.transform = `perspective(1000px) rotateX(${radX.toFixed(2)}deg) rotateY(${radY.toFixed(2)}deg)`;
});
document.addEventListener('mouseleave', ()=>{
    card.style.transform = `perspective(1000px) rotateX(0deg) rotateY(0deg)`;
});

// Dynamic Ping Simulator
setInterval(()=>{
    const pingEl = document.getElementById('pingMs');
    if(pingEl) pingEl.textContent = Math.floor(Math.random() * 8 + 10) + 'ms';
}, 4000);

// Cyber Grid 3D Canvas Background + Interactive Particles
const bgCanvas = document.getElementById('bg-canvas');
const bgCtx = bgCanvas.getContext('2d');
let bgW, bgH, particles = [], mouseX = -999, mouseY = -999;

function resizeBg(){
    bgW = bgCanvas.width = window.innerWidth;
    bgH = bgCanvas.height = window.innerHeight;
}
window.addEventListener('resize', resizeBg);
resizeBg();

document.addEventListener('mousemove', (e)=>{ mouseX = e.clientX; mouseY = e.clientY; });

class Particle{
    constructor(){
        this.x = Math.random() * bgW;
        this.y = Math.random() * bgH;
        this.vx = (Math.random() - 0.5) * 0.4;
        this.vy = (Math.random() - 0.5) * 0.4;
        this.radius = Math.random() * 1.8 + 0.8;
        this.alpha = Math.random() * 0.35 + 0.1;
    }
    update(){
        this.x += this.vx; this.y += this.vy;
        if(this.x < 0) this.x = bgW; if(this.x > bgW) this.x = 0;
        if(this.y < 0) this.y = bgH; if(this.y > bgH) this.y = 0;

        // Mouse interaction (repel & trail)
        const dx = this.x - mouseX, dy = this.y - mouseY;
        const d = Math.sqrt(dx * dx + dy * dy);
        if(d < 90){
            this.x += (dx / d) * 1.5;
            this.y += (dy / d) * 1.5;
        }
    }
    draw(){
        bgCtx.fillStyle = `rgba(148, 163, 184, ${this.alpha})`;
        bgCtx.beginPath(); bgCtx.arc(this.x, this.y, this.radius, 0, Math.PI * 2); bgCtx.fill();
    }
}
for(let i = 0; i < 45; i++) particles.push(new Particle());

let gridOffset = 0;
function animateBackground(){
    bgCtx.clearRect(0, 0, bgW, bgH);

    // 3D Perspective Grid
    bgCtx.strokeStyle = 'rgba(220, 38, 38, 0.04)';
    bgCtx.lineWidth = 1;
    const horizon = bgH * 0.65;
    gridOffset = (gridOffset + 0.35) % 30;

    // Horizon lines
    for(let y = horizon; y < bgH; y += (y - horizon) * 0.3 + 12){
        bgCtx.beginPath();
        bgCtx.moveTo(0, y + gridOffset * ((y - horizon) / bgH));
        bgCtx.lineTo(bgW, y + gridOffset * ((y - horizon) / bgH));
        bgCtx.stroke();
    }
    // Perspective radial lines
    for(let x = -bgW * 0.5; x < bgW * 1.5; x += 60){
        bgCtx.beginPath();
        bgCtx.moveTo(bgW * 0.5, horizon);
        bgCtx.lineTo(x, bgH);
        bgCtx.stroke();
    }

    // Particles & Constellation lines
    for(let i = 0; i < particles.length; i++){
        particles[i].update();
        particles[i].draw();
        for(let j = i + 1; j < particles.length; j++){
            const dx = particles[i].x - particles[j].x, dy = particles[i].y - particles[j].y, dist = Math.sqrt(dx*dx + dy*dy);
            if(dist < 100){
                bgCtx.strokeStyle = `rgba(220, 38, 38, ${0.05 * (1 - dist / 100)})`;
                bgCtx.lineWidth = 0.6;
                bgCtx.beginPath();
                bgCtx.moveTo(particles[i].x, particles[i].y);
                bgCtx.lineTo(particles[j].x, particles[j].y);
                bgCtx.stroke();
            }
        }
    }
    requestAnimationFrame(animateBackground);
}
animateBackground();

// Neon Sparks Celebration Canvas
const sparksCanvas = document.getElementById('sparks-canvas');
const spCtx = sparksCanvas.getContext('2d');
let sparks = [];

function resizeSparks(){
    sparksCanvas.width = window.innerWidth;
    sparksCanvas.height = window.innerHeight;
}
window.addEventListener('resize', resizeSparks);
resizeSparks();

function triggerNeonSparks(originX, originY){
    const colors = ['#dc2626', '#ef4444', '#b91c1c', '#991b1b', '#7f1d1d'];
    for(let i = 0; i < 70; i++){
        const angle = Math.random() * Math.PI * 2;
        const speed = Math.random() * 7 + 3;
        sparks.push({
            x: originX, y: originY,
            vx: Math.cos(angle) * speed,
            vy: Math.sin(angle) * speed - 1.5,
            color: colors[Math.floor(Math.random() * colors.length)],
            radius: Math.random() * 3 + 1.5,
            alpha: 1,
            decay: Math.random() * 0.02 + 0.015
        });
    }
}

function animateSparks(){
    spCtx.clearRect(0, 0, sparksCanvas.width, sparksCanvas.height);
    for(let i = sparks.length - 1; i >= 0; i--){
        const s = sparks[i];
        s.x += s.vx; s.y += s.vy;
        s.vy += 0.12; // Gravity
        s.alpha -= s.decay;
        if(s.alpha <= 0){
            sparks.splice(i, 1);
            continue;
        }
        spCtx.fillStyle = s.color;
        spCtx.globalAlpha = s.alpha;
        spCtx.shadowBlur = 10;
        spCtx.shadowColor = s.color;
        spCtx.beginPath();
        spCtx.arc(s.x, s.y, s.radius, 0, Math.PI * 2);
        spCtx.fill();
        spCtx.shadowBlur = 0;
    }
    spCtx.globalAlpha = 1;
    requestAnimationFrame(animateSparks);
}
animateSparks();

// Shockwave animation trigger
function triggerShockwave(x, y){
    const ring = document.createElement('div');
    ring.className = 'shockwave-ring';
    ring.style.left = x + 'px';
    ring.style.top = y + 'px';
    ring.style.width = '30px';
    ring.style.height = '30px';
    ring.style.animation = 'shockwaveAnim 0.7s cubic-bezier(0.1, 0.8, 0.3, 1) forwards';
    document.body.appendChild(ring);
    setTimeout(()=>{ ring.remove(); }, 750);
}

// Matrix Decrypt Effect for generated URL
function decryptTextAnimation(element, finalUrl){
    const chars = '01#@!&%ABCDEFXYZabcdef';
    let iter = 0;
    const interval = setInterval(()=>{
        element.value = finalUrl.split('').map((char, index)=>{
            if(index < iter) return finalUrl[index];
            return chars[Math.floor(Math.random() * chars.length)];
        }).join('');
        if(iter >= finalUrl.length){
            clearInterval(interval);
            element.value = finalUrl;
        }
        iter += 3;
    }, 25);
}

// Ripple Click Effect
document.querySelectorAll('.ripple-btn').forEach(btn=>{
    btn.addEventListener('click', function(e){
        const circle = document.createElement('span');
        const diameter = Math.max(btn.clientWidth, btn.clientHeight), radius = diameter / 2;
        const rect = btn.getBoundingClientRect();
        circle.style.width = circle.style.height = `${diameter}px`;
        circle.style.left = `${e.clientX - rect.left - radius}px`;
        circle.style.top = `${e.clientY - rect.top - radius}px`;
        circle.classList.add('ripple');
        const ripple = btn.getElementsByClassName('ripple')[0];
        if(ripple) ripple.remove();
        btn.appendChild(circle);
    });
});

// Core Race State & Logic
const BLOCK_TIME = 1200000;
let emails = <?=json_encode($EMAILS)?>, blocked = [], running = false, totalOk = 0, totalFail = 0, raceNum = 0;
try{
    const s = JSON.parse(localStorage.getItem('bx_race') || '{}');
    if(s.emails) emails = s.emails;
    if(s.blocked) blocked = s.blocked;
    <?=json_encode($EMAILS)?>.forEach(e=>{ if(!emails.includes(e) && !blocked.some(b=>b.e===e)) emails.push(e); });
}catch(x){}
const save = () => localStorage.setItem('bx_race', JSON.stringify({emails, blocked}));
const btn = document.getElementById('btnMain'), pBar = document.getElementById('progressBar');
const linkCard = document.getElementById('linkResultCard'), linkInput = document.getElementById('linkInput'), linkOpenBtn = document.getElementById('linkOpenBtn'), linkTimeInfo = document.getElementById('linkTimeInfo');

const unblock = () => {
    const n = Date.now();
    blocked = blocked.filter(i=>{
        if(n >= i.t){
            if(!emails.includes(i.e)) emails.push(i.e);
            return false;
        }
        return true;
    });
    save();
};

window.addEventListener('pageshow', ()=>{
    running = false;
    btn.disabled = false;
    btn.textContent = 'GENERAR LINK';
    pBar.classList.remove('active');
});

function renderEmails(){
    const list = document.getElementById('mailList');
    const cnt = document.getElementById('mailListCount');
    const btnCnt = document.getElementById('btnMailCount');
    if(cnt) cnt.textContent = emails.length;
    if(btnCnt) btnCnt.textContent = emails.length;
    if(!emails.length){ list.innerHTML = '<div class="add-mail-empty">No hay correos agregados</div>'; return; }
    list.innerHTML = emails.map(e=>`<div class="mail-chip" data-email="${e}"><span class="mail-text">${e}</span><button class="mail-del" onclick="quitarCorreo('${e}')" title="Eliminar">×</button></div>`).join('');
}

async function quitarCorreo(email){
    const fd = new FormData(); fd.append('quitar_correo', '1'); fd.append('correo_a_quitar', email);
    const res = await fetch('index.php', { method: 'POST', body: fd }).then(r=>r.json());
    if(res.ok){
        emails = emails.filter(e=>e!==email);
        blocked = blocked.filter(b=>b.e!==email);
        save(); renderEmails();
        showToast('Correo eliminado', 'X');
    }
}

async function agregarCorreo(){
    const input = document.getElementById('nuevoCorreo');
    const msg = document.getElementById('mailMsg');
    const correo = input.value.trim();
    if(!correo){ msg.className = 'toast-msg err'; msg.textContent = 'Ingresa un correo válido'; return; }
    const fd = new FormData(); fd.append('agregar_correo', '1'); fd.append('nuevo_correo', correo);
    const res = await fetch('index.php', { method: 'POST', body: fd }).then(r=>r.json());
    if(res.ok){
        if(!emails.includes(correo)){ emails.push(correo); save(); }
        msg.className = 'toast-msg ok'; msg.textContent = res.msg; input.value = ''; renderEmails();
        showToast('Correo agregado', 'OK');
    }else{
        msg.className = 'toast-msg err'; msg.textContent = res.msg;
    }
    setTimeout(()=>{ msg.className = 'toast-msg'; }, 3000);
}

async function subirArchivoCorreos(input){
    const file = input.files[0];
    const msg = document.getElementById('mailMsg');
    if(!file) return;
    if(file.name.toLowerCase() !== 'correos.txt'){
        msg.className = 'toast-msg err'; msg.textContent = 'Error: El archivo debe llamarse obligatoriamente "correos.txt"';
        input.value = ''; setTimeout(()=>{ msg.className = 'toast-msg'; }, 4000); return;
    }
    const fd = new FormData(); fd.append('subir_txt_correos', '1'); fd.append('archivo_correos', file);
    const res = await fetch('index.php', { method: 'POST', body: fd }).then(r=>r.json());
    if(res.ok && res.emails){
        emails = res.emails; blocked = []; save(); renderEmails();
        msg.className = 'toast-msg ok'; msg.textContent = res.msg;
        showToast(`Importados ${res.count} correos`, 'OK');
    }else{
        msg.className = 'toast-msg err'; msg.textContent = res.msg || 'Error al procesar archivo';
    }
    input.value = '';
    setTimeout(()=>{ msg.className = 'toast-msg'; }, 4000);
}

renderEmails();

function copiarLinkGenerado(){
    if(!linkInput.value) return;
    navigator.clipboard.writeText(linkInput.value);
    const btnCopy = document.querySelector('.btn-action-copy');
    const originalText = btnCopy.textContent;
    btnCopy.textContent = 'Copiado!';
    showToast('Enlace copiado al portapapeles', 'OK');
    setTimeout(()=>{ btnCopy.textContent = originalText; }, 2000);
}

function copiarTextoDirecto(text, btnElement){
    navigator.clipboard.writeText(text);
    const orig = btnElement.textContent;
    btnElement.textContent = 'Copiado';
    showToast('Enlace copiado', 'OK');
    setTimeout(()=>{ btnElement.textContent = orig; }, 1500);
}

function iniciarProcesoConEfectos(e){
    const rect = btn.getBoundingClientRect();
    const x = e ? e.clientX : rect.left + rect.width / 2;
    const y = e ? e.clientY : rect.top + rect.height / 2;
    triggerShockwave(x, y);
    iniciarProceso();
}

async function lanzarRace(){
    unblock();
    const c = 8;
    const batch = []; for(let i = 0; i < c && emails.length > 0; i++) batch.push(emails.shift()); save();
    if(!batch.length){
        if(blocked.length){
            btn.textContent = 'GENERANDO LINK...';
            setTimeout(()=>{ if(running) lanzarRace(); }, 5000);
            return;
        }
        running = false; btn.disabled = false; btn.textContent = 'GENERAR LINK'; pBar.classList.remove('active');
        return;
    }
    raceNum++; pBar.classList.add('active'); btn.textContent = 'GENERANDO LINK...';
    const ctrl = new AbortController(), sig = ctrl.signal; let won = false; const t0 = Date.now();
    const proms = batch.map((email, idx)=>{
        const fd = new FormData(); fd.append('api', '1'); fd.append('email', email);
        return fetch('index.php', { method: 'POST', body: fd, signal: sig }).then(r=>r.json()).then(d=>{
            if(won) return null;
            const el = ((Date.now() - t0) / 1000).toFixed(1);
            if(d.status === 'ok' && d.bank_link){
                won = true; totalOk++;
                batch.forEach((e, i)=>{ if(i !== idx && !emails.includes(e)) emails.unshift(e); }); save();
                ctrl.abort();
                
                btn.textContent = 'LINK GENERADO';
                btn.disabled = false;
                pBar.classList.remove('active');
                
                // Show Generated Link Result Card
                linkOpenBtn.href = d.bank_link;
                linkTimeInfo.textContent = `en ${el}s`;
                linkCard.classList.add('show');
                
                // Matrix Decrypt Animation
                decryptTextAnimation(linkInput, d.bank_link);
                
                // Celebration Sparks
                const rect = linkCard.getBoundingClientRect();
                triggerNeonSparks(rect.left + rect.width / 2, rect.top + 30);
                showToast('Link generado con exito!', 'OK');
                
                return {won: true};
            }else if(d.status === 'rate_limit'){
                if(!emails.includes(email)) emails.unshift(email); save(); return {won: false, rl: true};
            }else{
                blocked.push({e: email, t: Date.now() + BLOCK_TIME}); totalFail++; save(); return {won: false};
            }
        }).catch(err=>{
            if(err.name === 'AbortError') return null;
            blocked.push({e: email, t: Date.now() + BLOCK_TIME}); totalFail++; save(); return {won: false};
        });
    });
    const res = await Promise.allSettled(proms);
    if(won){ running = false; return; }
    if(res.some(r=>r.status === 'fulfilled' && r.value && r.value.rl)){
        btn.textContent = 'GENERANDO LINK...';
        await new Promise(r=>setTimeout(r, 5000));
    } else {
        await new Promise(r=>setTimeout(r, 100));
    }
    if(running) lanzarRace();
}

function iniciarProceso(){
    unblock();
    if(!emails.length && !blocked.length){
        abrirModalCorreos();
        showToast('Agrega correos primero', '!!');
        return;
    }
    linkCard.classList.remove('show');
    running = true; btn.disabled = true; btn.textContent = 'GENERANDO LINK...'; pBar.classList.add('active'); lanzarRace();
}

// Modal Controllers
function abrirModalCorreos(){ document.getElementById('modalCorreos').classList.add('open'); }
function cerrarModalCorreos(){ document.getElementById('modalCorreos').classList.remove('open'); }
function switchMailTab(e, tabId){
    document.querySelectorAll('#modalCorreos .tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('#modalCorreos .tab-pane').forEach(p=>p.classList.remove('active'));
    e.target.classList.add('active');
    document.getElementById(tabId).classList.add('active');
}


</script>
</body>
</html>
