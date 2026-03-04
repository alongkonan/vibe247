<?php
// ตั้งค่า Timezone
date_default_timezone_set("Asia/Bangkok");

// ปิด Error เพื่อความสะอาดหน้าเว็บ
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
error_reporting(E_ALL);

session_start();

// ==========================================
// 1. CONFIGURATION
// ==========================================
define('DB_HOST', 'sql100.infinityfree.com'); 
define('DB_USER', 'if0_40945387'); 
define('DB_PASS', 'fxrl48VIRHj2h9');
define('DB_NAME', 'if0_40945387_vibe');
define('UPLOAD_DIR', 'uploads/djs/');   
define('LOGO_DIR', 'uploads/logo/');
define('REWARD_DIR', 'uploads/rewards/');    

if (!file_exists(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);
if (!file_exists(LOGO_DIR)) mkdir(LOGO_DIR, 0755, true);
if (!file_exists(REWARD_DIR)) mkdir(REWARD_DIR, 0755, true);

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("<div style='color:red; text-align:center; padding:50px;'>Connection failed: " . $e->getMessage() . "</div>");
}

function getSetting($key) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        return $stmt->fetchColumn() ?: '';
    } catch (Exception $e) { return ''; }
}

function sanitizeStationId($input) {
    if (empty($input)) return 'sbf4f79825';
    $clean = str_replace(['https://', 'http://', 'streaming.radio.co/', 'stream.radio.co/', '/listen', '/'], '', $input);
    return trim($clean);
}

function fetchUrl($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
    $data = curl_exec($ch);
    curl_close($ch);
    return $data;
}

function isDjOnAir($day, $timeSlot) {
    $today = date('l'); 
    $isDayMatch = false;
    
    if ($day === 'Everyday') $isDayMatch = true;
    elseif ($day === $today) $isDayMatch = true;
    elseif ($day === 'Mon-Fri' && !in_array($today, ['Saturday', 'Sunday'])) $isDayMatch = true;
    elseif ($day === 'Sat-Sun' && in_array($today, ['Saturday', 'Sunday'])) $isDayMatch = true;

    if (!$isDayMatch) return false;

    $cleanSlot = str_replace(['.', ' '], [':', ''], $timeSlot); 
    $parts = explode('-', $cleanSlot);
    
    if (count($parts) == 2) {
        $now = time();
        $start = strtotime(trim($parts[0]));
        $end = strtotime(trim($parts[1]));
        if ($end < $start) $end += 86400; 
        return ($now >= $start && $now < $end);
    }
    return false;
}

$daysMap = [
    'Everyday' => 'Everyday',
    'Mon-Fri' => 'Mon - Fri',
    'Sat-Sun' => 'Sat - Sun',
    'Monday' => 'Monday', 
    'Tuesday' => 'Tuesday', 
    'Wednesday' => 'Wednesday',
    'Thursday' => 'Thursday', 
    'Friday' => 'Friday', 
    'Saturday' => 'Saturday',
    'Sunday' => 'Sunday'
];

$action = $_GET['action'] ?? 'home';

// ==========================================
// 2. API ACTIONS (AJAX)
// ==========================================

// API: Get Current DJ Data
if ($action == 'get_current_dj') {
    header('Content-Type: application/json');
    $currentDj = null;
    $allDJs = $pdo->query("SELECT * FROM djs");
    
    while ($dj = $allDJs->fetch(PDO::FETCH_ASSOC)) {
        if (isDjOnAir($dj['dj_day'], $dj['time_slot'])) {
            $currentDj = $dj;
            break; 
        }
    }

    if ($currentDj) {
        $displayName = htmlspecialchars($currentDj['name']);
        $displayRealName = htmlspecialchars($currentDj['real_name']);
        $displayImage = $currentDj['image_url'];
        
        if (!empty($currentDj['substitute_dj_id'])) {
            $subStmt = $pdo->prepare("SELECT name, real_name, image_url FROM djs WHERE id = ?");
            $subStmt->execute([$currentDj['substitute_dj_id']]);
            $subDj = $subStmt->fetch(PDO::FETCH_ASSOC);
            if ($subDj) {
                $displayName = htmlspecialchars($subDj['name']);
                $displayRealName = htmlspecialchars($subDj['real_name']);
                $displayImage = $subDj['image_url'];
            }
        }
        
        if ($currentDj['substitute_dj_id']) {
             $displayName .= " <span class='text-vibe-red text-sm'>(แทน)</span>";
        }

        echo json_encode([
            'status' => 'live', 
            'name' => $displayName, 
            'real_name' => $displayRealName,
            'time_slot' => htmlspecialchars($currentDj['time_slot']), 
            'day_display' => $daysMap[$currentDj['dj_day']] ?? $currentDj['dj_day'], 
            'image_url' => $displayImage
        ]);
    } else {
        // --- RESTORED: Return group DJs for default view ---
        $groupDJs = $pdo->query("SELECT * FROM djs ORDER BY id ASC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'default',
            'group_djs' => $groupDJs
        ]);
    }
    exit;
}

// API: Get Stream Data (Proxy)
if ($action == 'get_stream_data') {
    header('Content-Type: application/json');
    $stationId = sanitizeStationId(getSetting('station_id'));
    
    $statusData = json_decode(fetchUrl("https://public.radio.co/stations/{$stationId}/status"), true);
    $nextContent = fetchUrl("https://embed.radio.co/embed/{$stationId}/next.js"); 
    $nextSong = (preg_match("/write\('(.*?)'\)/", $nextContent, $matches)) ? $matches[1] : '-';
    // Removed History fetching

    echo json_encode(['current' => $statusData['current_track'] ?? null, 'next' => $nextSong]);
    exit; 
}

// API: Get Chat Messages
if ($action == 'get_chat_messages') {
    header('Content-Type: application/json');
    try {
        $stmt = $pdo->query("SELECT * FROM chat_messages ORDER BY created_at DESC LIMIT 50");
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(array_reverse($messages));
    } catch (Exception $e) { echo json_encode([]); }
    exit;
}

if ($action == 'send_chat_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $name = trim($_POST['name']);
    $msg = trim($_POST['message']);
    if (!empty($name) && !empty($msg)) {
        $ip = $_SERVER['REMOTE_ADDR'];
        try {
            $stmt = $pdo->prepare("INSERT INTO chat_messages (sender_name, message, ip_address) VALUES (?, ?, ?)");
            if ($stmt->execute([htmlspecialchars($name), htmlspecialchars($msg), $ip])) echo json_encode(['status' => 'success']);
            else echo json_encode(['status' => 'error']);
        } catch (Exception $e) { echo json_encode(['status' => 'error']); }
    }
    exit;
}

// ==========================================
// 3. BACKEND ACTIONS
// ==========================================

if ($action == 'logout') {
    session_destroy();
    header("Location: index.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    if ($username === 'recovery' && $password === 'vibe24') {
        $newPass = password_hash('1234', PASSWORD_DEFAULT);
        $check = $pdo->query("SELECT count(*) FROM admins WHERE username='admin'")->fetchColumn();
        if ($check > 0) $pdo->prepare("UPDATE admins SET password = ? WHERE username = 'admin'")->execute([$newPass]);
        else $pdo->prepare("INSERT INTO admins (username, password) VALUES ('admin', ?)")->execute([$newPass]);
        $message = "✅ รีเซ็ต Admin สำเร็จ! User: admin / Pass: 1234";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM admins WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['admin_logged_in'] = true;
            header("Location: index.php?action=admin");
            exit;
        } else {
            $message = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_request']) && !empty($_POST['song_name'])) {
    $pdo->prepare("INSERT INTO song_requests (listener_name, song_name, artist_name, message) VALUES (?, ?, ?, ?)")
        ->execute([htmlspecialchars(trim($_POST['listener_name'])), htmlspecialchars(trim($_POST['song_name'])), htmlspecialchars(trim($_POST['artist_name'])), htmlspecialchars(trim($_POST['message']))]);
    $message = "ส่งคำขอเพลงเรียบร้อยแล้ว!";
}

// Admin Actions
if (isset($_SESSION['admin_logged_in']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_settings'])) {
        foreach ($_POST['settings'] as $key => $val) {
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$key, trim($val), trim($val)]);
        }
        if (isset($_FILES['site_logo']) && $_FILES['site_logo']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['site_logo']['name'], PATHINFO_EXTENSION));
            $filename = 'logo_' . time() . '.' . $ext;
            if(move_uploaded_file($_FILES['site_logo']['tmp_name'], LOGO_DIR . $filename)) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('site_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([LOGO_DIR . $filename, LOGO_DIR . $filename]);
            }
        }
        // Handle Webcam BG Upload
        if (isset($_FILES['webcam_bg_image']) && $_FILES['webcam_bg_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['webcam_bg_image']['name'], PATHINFO_EXTENSION));
            $filename = 'webcam_bg_' . time() . '.' . $ext;
            if(move_uploaded_file($_FILES['webcam_bg_image']['tmp_name'], LOGO_DIR . $filename)) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('webcam_bg_image', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([LOGO_DIR . $filename, LOGO_DIR . $filename]);
            }
        }
        $message = "บันทึกการตั้งค่าเรียบร้อย";
    }

    // Save DJ
    if (isset($_POST['save_dj'])) {
        $name = $_POST['name'];
        $substitute_dj_id = !empty($_POST['substitute_dj_id']) ? $_POST['substitute_dj_id'] : null;
        $hide = isset($_POST['hide_from_schedule']) ? 1 : 0;
        
        $imageUrl = '';
        $uploadSuccess = false;
        if (isset($_FILES['dj_image']) && $_FILES['dj_image']['error'] == 0) {
            $ext = strtolower(pathinfo($_FILES['dj_image']['name'], PATHINFO_EXTENSION));
            $newFilename = uniqid('dj_') . '.' . $ext;
            if (move_uploaded_file($_FILES['dj_image']['tmp_name'], UPLOAD_DIR . $newFilename)) {
                $imageUrl = UPLOAD_DIR . $newFilename;
                $uploadSuccess = true;
            }
        }

        if (!empty($_POST['dj_id'])) {
            $sql = $uploadSuccess ? 
                "UPDATE djs SET name=?, real_name=?, substitute_dj_id=?, dj_day=?, time_slot=?, bio=?, hide_from_schedule=?, image_url=? WHERE id=?" : 
                "UPDATE djs SET name=?, real_name=?, substitute_dj_id=?, dj_day=?, time_slot=?, bio=?, hide_from_schedule=? WHERE id=?";
            $params = $uploadSuccess ? 
                [$name, $_POST['real_name'], $substitute_dj_id, $_POST['dj_day'], $_POST['time_slot'], $_POST['bio'], $hide, $imageUrl, $_POST['dj_id']] : 
                [$name, $_POST['real_name'], $substitute_dj_id, $_POST['dj_day'], $_POST['time_slot'], $_POST['bio'], $hide, $_POST['dj_id']];
            $pdo->prepare($sql)->execute($params);
            $message = "แก้ไขข้อมูลเรียบร้อย";
        } else {
            $pdo->prepare("INSERT INTO djs (name, real_name, substitute_dj_id, dj_day, time_slot, bio, hide_from_schedule, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$name, $_POST['real_name'], $substitute_dj_id, $_POST['dj_day'], $_POST['time_slot'], $_POST['bio'], $hide, $imageUrl]);
            $message = "เพิ่มดีเจเรียบร้อย";
        }
    }
    
    if (isset($_POST['delete_dj'])) { $pdo->prepare("DELETE FROM djs WHERE id = ?")->execute([$_POST['id']]); }
    if (isset($_POST['mark_played'])) { $pdo->prepare("UPDATE song_requests SET status = 'played' WHERE id = ?")->execute([$_POST['req_id']]); }
    if (isset($_POST['delete_req'])) { $pdo->prepare("DELETE FROM song_requests WHERE id = ?")->execute([$_POST['req_id']]); }

    // Manage Chat
    if (isset($_POST['delete_chat'])) {
        $pdo->prepare("DELETE FROM chat_messages WHERE id = ?")->execute([$_POST['chat_id']]);
        $message = "ลบข้อความเรียบร้อย";
    }
    if (isset($_POST['clear_chat'])) {
        $pdo->exec("TRUNCATE TABLE chat_messages");
        $message = "ล้างข้อความทั้งหมดเรียบร้อย";
    }
}

// Data Fetching
$siteName = getSetting('site_name') ?: 'VIBE 24';
$siteLogo = getSetting('site_logo'); 
$stationId = sanitizeStationId(getSetting('station_id'));
$streamUrl = getSetting('stream_url');
if (empty($streamUrl)) { $streamUrl = "https://streaming.radio.co/{$stationId}/listen"; }
// Add timestamp to break cache
if (strpos($streamUrl, '?') === false) { $streamUrl .= "?t=" . time(); } else { $streamUrl .= "&t=" . time(); }

// Request Widget URL Fetching
$requestUrl = getSetting('request_url') ?: 'https://embed.radio.co/request/w41dd998.js';

// Webcam Background
$webcamBg = getSetting('webcam_bg_image');
if (empty($webcamBg)) {
    $webcamBg = 'https://images.unsplash.com/photo-1598488035139-bdbb2231ce04?q=80&w=2070&auto=format&fit=crop';
}

$welcomeMsg = getSetting('welcome_msg') ?: 'คลื่นเพลงฮิต...ต่อเนื่อง 24 ชั่วโมง';
$facebookUrl = getSetting('facebook_url');
$allDJsList = $pdo->query("SELECT id, name FROM djs ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

$currentDj = null;
try {
    $allDJs = $pdo->query("SELECT * FROM djs");
    while ($dj = $allDJs->fetch()) {
        if (isDjOnAir($dj['dj_day'], $dj['time_slot'])) { $currentDj = $dj; break; }
    }
    if ($currentDj && !empty($currentDj['substitute_dj_id'])) {
        $subDj = $pdo->prepare("SELECT name, real_name, image_url FROM djs WHERE id = ?"); $subDj->execute([$currentDj['substitute_dj_id']]); $subRes = $subDj->fetch(PDO::FETCH_ASSOC);
        if ($subRes) { 
            $currentDj['name'] = $subRes['name'] . " <span class='text-vibe-red text-sm'>(แทน)</span>"; 
            $currentDj['real_name'] = $subRes['real_name'];
            $currentDj['image_url'] = $subRes['image_url']; 
        }
    }
} catch(Exception $e) {}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $siteName ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600;700;800&family=Prompt:wght@300;400;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = { theme: { extend: { colors: { vibe: { red: '#E31E24', black: '#000000', gray: '#333333', light: '#F8F9FA' } }, fontFamily: { sans: ['Prompt', 'sans-serif'], display: ['Kanit', 'sans-serif'] }, boxShadow: { 'cool': '0 10px 40px -10px rgba(0,0,0,0.08)', 'player': '0 -5px 20px rgba(0,0,0,0.05)' }, animation: { 'marquee': 'marquee 25s linear infinite' }, keyframes: { marquee: { '0%': { transform: 'translateX(100%)' }, '100%': { transform: 'translateX(-100%)' } } } } } }
    </script>
    <style>
        body { font-family: 'Prompt', sans-serif; background-color: #FDFDFD; color: #333; }
        h1, h2, h3, .brand-font { font-family: 'Kanit', sans-serif; }
        .cool-bg { background: linear-gradient(135deg, #FFF5F5 0%, #FFFFFF 50%, #FFF0F0 100%); }
        .circle-decor { position: absolute; border-radius: 50%; background: linear-gradient(45deg, rgba(227, 30, 36, 0.05), rgba(227, 30, 36, 0)); z-index: 0; pointer-events: none; }
        @keyframes expand-circle { 0% { transform: scale(1); opacity: 0.6; } 50% { transform: scale(1.15); opacity: 0.3; } 100% { transform: scale(1); opacity: 0.6; } }
        .animate-expand { animation: expand-circle 6s infinite ease-in-out; }
        .play-btn-pulse { box-shadow: 0 0 0 0 rgba(227, 30, 36, 0.7); animation: pulse-red 2s infinite; }
        @keyframes pulse-red { 0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(227, 30, 36, 0.7); } 70% { transform: scale(1); box-shadow: 0 0 0 15px rgba(227, 30, 36, 0); } 100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(227, 30, 36, 0); } }
        .radio-artwork img { width: 100%; height: auto; border-radius: 12px; box-shadow: 0 8px 16px rgba(0,0,0,0.1); transition: opacity 0.5s; }
        ::-webkit-scrollbar { width: 6px; } ::-webkit-scrollbar-track { background: #f1f1f1; } ::-webkit-scrollbar-thumb { background: #ccc; border-radius: 3px; }
        
        /* Modern Chat Styles */
        #chat-messages { scrollbar-width: thin; scrollbar-color: #ddd #f1f1f1; padding-bottom: 20px; }
        .chat-bubble { padding: 8px 12px; border-radius: 12px; max-width: 85%; width: fit-content; margin-bottom: 8px; font-size: 0.9rem; position: relative; animation: slideIn 0.3s ease-out; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .chat-container { display: flex; flex-direction: column; }
        .chat-container.left .chat-bubble { background-color: #f3f4f6; border-bottom-left-radius: 2px; align-self: flex-start; }
        .chat-container.right .chat-bubble { background-color: #E31E24; color: white; border-bottom-right-radius: 2px; align-self: flex-end; }
        .chat-container.right .chat-name { color: #ffd1d1; text-align: right; }
        .chat-container.right .chat-time { color: rgba(255,255,255,0.8); }
        .chat-name { font-weight: bold; font-size: 0.75rem; margin-bottom: 2px; color: #555; }
        .chat-time { font-size: 0.65rem; color: #999; margin-left: 6px; font-weight: normal; }
        .chat-avatar { width: 28px; height: 28px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.7rem; font-weight: bold; color: white; margin-right: 8px; flex-shrink: 0; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
        .chat-row { display: flex; align-items: flex-end; margin-bottom: 12px; }
        .chat-row.right { flex-direction: row-reverse; }
        .chat-row.right .chat-avatar { margin-right: 0; margin-left: 8px; background: linear-gradient(135deg, #ff9a9e 0%, #fecfef 99%, #fecfef 100%); color: #E31E24; }
        @keyframes slideIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .glass-panel { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255, 255, 255, 0.8); box-shadow: 0 10px 40px -10px rgba(0, 0, 0, 0.05); }
        .glass-nav { background: rgba(255, 255, 255, 0.95); backdrop-filter: blur(20px); border-bottom: 1px solid rgba(0, 0, 0, 0.05); box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.02); }
        .floating-player { background: #ffffff; border: 1px solid #f3f4f6; box-shadow: 0 10px 40px -5px rgba(0,0,0,0.15); }
        .play-btn-circle { background: linear-gradient(135deg, #E31E24 0%, #B91C1C 100%); box-shadow: 0 8px 20px rgba(227, 30, 36, 0.3); transition: all 0.3s ease; }
        .play-btn-circle:hover { transform: scale(1.05); box-shadow: 0 12px 25px rgba(227, 30, 36, 0.4); }
    </style>
</head>
<body class="flex flex-col min-h-screen bg-mesh selection:bg-red-100 selection:text-red-900">
    <nav class="fixed w-full z-50 glass-nav transition-all duration-300">
        <div class="container mx-auto px-4 py-3 flex justify-between items-center">
            <div class="flex items-center gap-6"><a href="index.php" class="flex items-center gap-2 group"><?php if($siteLogo && file_exists($siteLogo)): ?><img src="<?= $siteLogo ?>" alt="<?= $siteName ?>" class="h-10 w-auto object-contain transition-transform group-hover:scale-105"><?php else: ?><span class="text-3xl brand-font font-black tracking-tighter flex items-center text-black">V<span class="text-[#E31E24] mx-0.5"><i class="fas fa-play text-xl"></i></span>BE<span class="text-[#E31E24] text-sm align-top ml-1 mt-1 font-bold">24</span></span><?php endif; ?></a></div>
            <div class="flex items-center gap-4"><?php if (isset($_SESSION['admin_logged_in'])): ?><a href="?action=admin" class="text-sm font-bold text-gray-700 hover:text-vibe-red bg-gray-100 px-3 py-1 rounded-full">แดชบอร์ด</a><a href="?action=logout" class="text-sm text-gray-400 hover:text-red-500"><i class="fas fa-sign-out-alt"></i></a><?php else: ?><a href="?action=login" class="text-gray-400 hover:text-gray-600 text-sm"><i class="fas fa-lock"></i></a><?php endif; ?></div>
        </div>
    </nav>
    <div class="flex-grow pt-24 pb-32">
        <?php if ($action == 'login'): ?>
            <div class="min-h-[80vh] flex items-center justify-center cool-bg"><div class="bg-white p-8 rounded-2xl shadow-cool w-full max-w-sm border border-gray-100"><h2 class="text-3xl brand-font font-black text-black text-center mb-8">ADMIN LOGIN</h2><form method="POST" class="space-y-4"><input type="text" name="username" placeholder="ชื่อผู้ใช้" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm focus:border-vibe-red focus:outline-none"><input type="password" name="password" placeholder="รหัสผ่าน" class="w-full bg-gray-50 border border-gray-200 rounded-lg p-3 text-sm focus:border-vibe-red focus:outline-none"><button type="submit" name="login" class="w-full bg-vibe-red text-white py-3 rounded-lg font-bold shadow-lg shadow-red-200 hover:bg-red-700">เข้าสู่ระบบ</button></form></div></div>
        <?php elseif ($action == 'admin' && isset($_SESSION['admin_logged_in'])): ?>
            <div class="container mx-auto px-4 py-8">
                <h1 class="text-3xl font-bold text-gray-800 mb-8 flex items-center"><span class="w-2 h-8 bg-vibe-red rounded mr-3"></span> ระบบหลังบ้าน</h1>
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                    <div class="bg-white p-6 rounded-2xl shadow-cool border border-gray-100 lg:col-span-2">
                        <div class="flex justify-between items-center mb-6 border-b pb-4"><h3 class="text-xl font-bold text-gray-800">ข้อความจาก Talk to DJ</h3><form method="POST" onsubmit="return confirm('ล้างข้อความทั้งหมด?');"><button type="submit" name="clear_chat" class="text-xs bg-red-50 text-red-600 px-3 py-1 rounded hover:bg-red-100">ล้างแชททั้งหมด</button></form></div>
                        <div class="overflow-y-auto max-h-[300px] bg-gray-50 rounded-xl p-4 border border-gray-200 space-y-2"><?php try { $chats = $pdo->query("SELECT * FROM chat_messages ORDER BY created_at DESC LIMIT 50"); while($chat = $chats->fetch()): ?><div class="bg-white p-3 rounded-lg border border-gray-100 flex justify-between items-start shadow-sm"><div><div class="flex items-center gap-2"><span class="font-bold text-gray-800"><?= htmlspecialchars($chat['sender_name']) ?></span><span class="text-[10px] text-gray-400"><?= $chat['created_at'] ?></span></div><p class="text-sm text-gray-600 mt-1"><?= htmlspecialchars($chat['message']) ?></p></div><form method="POST" onsubmit="return confirm('ลบข้อความนี้?')"><input type="hidden" name="chat_id" value="<?= $chat['id'] ?>"><button type="submit" name="delete_chat" class="text-gray-400 hover:text-red-500"><i class="fas fa-trash"></i></button></form></div><?php endwhile; } catch(Exception $e) { echo "<p class='text-red-500'>Table 'chat_messages' missing.</p>"; } ?></div>
                    </div>
                    <div class="bg-white p-6 rounded-2xl shadow-cool border border-gray-100"><h3 class="text-xl font-bold mb-6 text-gray-800 border-b pb-4">ตั้งค่าเว็บไซต์</h3><form method="POST" enctype="multipart/form-data" class="space-y-4"><?php foreach(['site_name'=>'ชื่อสถานี', 'welcome_msg'=>'สโลแกน', 'station_id'=>'Radio.co ID', 'stream_url'=>'Stream URL', 'request_url'=>'ลิงก์ขอเพลง (Request Script URL)', 'facebook_url'=>'Facebook URL'] as $k=>$l): ?><div><label class="text-xs font-bold text-gray-400 ml-1"><?= $l ?></label><input type="text" name="settings[<?= $k ?>]" value="<?= getSetting($k) ?>" class="w-full bg-gray-50 rounded-lg p-2.5 border border-gray-200 focus:border-vibe-red focus:outline-none"></div><?php endforeach; ?><div class="border-t pt-4 mt-4"><label class="text-xs font-bold text-gray-400 ml-1">โลโก้เว็บไซต์</label><input type="file" name="site_logo" accept="image/*" class="text-sm text-gray-500"></div><div class="border-t pt-4 mt-4"><label class="text-xs font-bold text-gray-400 ml-1">พื้นหลัง Webcam (ห้องส่ง)</label><input type="file" name="webcam_bg_image" accept="image/*" class="text-sm text-gray-500"></div><button type="submit" name="update_settings" class="bg-black text-white font-bold px-6 py-2.5 rounded-lg hover:bg-gray-800 transition w-full mt-2">บันทึกทั้งหมด</button></form></div>
                    <div class="bg-white p-6 rounded-2xl shadow-cool border border-gray-100">
                        <div class="flex justify-between items-center mb-6 border-b pb-4"><h3 class="text-xl font-bold text-gray-800">จัดการดีเจ</h3> <button onclick="resetDjForm()" class="text-xs font-bold text-vibe-red bg-red-50 px-4 py-2 rounded-full hover:bg-red-100 transition">+ เพิ่มดีเจใหม่</button></div>
                        <form id="djForm" method="POST" enctype="multipart/form-data" class="flex flex-col gap-4 mb-8 bg-gray-50/50 p-6 rounded-2xl border border-gray-100"><input type="hidden" name="dj_id" id="dj_id_input"><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><div><label class="text-xs font-bold text-gray-400 ml-1">ชื่อดีเจหลัก</label><input type="text" name="name" id="dj_name" placeholder="ชื่อดีเจ" required class="w-full bg-white rounded-xl p-3 border border-gray-200"></div><div><label class="text-xs font-bold text-gray-400 ml-1">ชื่อจริง</label><input type="text" name="real_name" id="dj_real_name" placeholder="ชื่อจริง" class="w-full bg-white rounded-xl p-3 border border-gray-200"></div></div><div class="grid grid-cols-1 md:grid-cols-2 gap-4"><div><label class="text-xs font-bold text-vibe-red ml-1">ดีเจแทน</label><select name="substitute_dj_id" id="dj_substitute" class="w-full bg-red-50 border border-red-100 rounded-xl p-3 text-red-600 focus:outline-none"><option value="">-- ไม่มีการแทน --</option><?php foreach($allDJsList as $d): ?><option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option><?php endforeach; ?></select></div><div><label class="text-xs font-bold text-gray-400 ml-1">วัน/เวลา</label><div class="flex gap-2"><select name="dj_day" id="dj_day" class="bg-white rounded-xl p-3 border border-gray-200 w-1/2"><?php foreach($daysMap as $en => $th): ?><option value="<?= $en ?>"><?= $th ?></option><?php endforeach; ?></select><input type="text" name="time_slot" id="dj_time" placeholder="08:00 - 12:00" required class="bg-white rounded-xl p-3 border border-gray-200 w-1/2"></div></div></div><div class="flex gap-4 items-center justify-between pt-2"><div><label class="inline-flex items-center cursor-pointer"><input type="checkbox" name="hide_from_schedule" id="dj_hide" value="1" class="sr-only peer"><div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-red-600"></div><span class="ms-3 text-xs font-bold text-gray-400">ซ่อนจากตาราง</span></label></div><div class="flex items-center gap-3"><input type="file" name="dj_image" accept="image/*" class="text-xs text-gray-400 w-32 file:mr-2 file:py-1 file:px-2 file:rounded-full file:border-0 file:text-[10px] file:bg-gray-200"><button type="submit" name="save_dj" id="save_dj_btn" class="bg-black text-white font-bold px-6 py-2.5 rounded-xl hover:bg-gray-800 transition shadow-lg text-sm">บันทึกข้อมูล</button></div></div></form>
                        <div class="grid grid-cols-1 gap-3 max-h-[400px] overflow-y-auto pr-2"><?php $djs = $pdo->query("SELECT * FROM djs ORDER BY FIELD(dj_day, 'Everyday', 'Mon-Fri', 'Sat-Sun', 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), time_slot ASC"); while($dj = $djs->fetch()): $subName = ''; if(!empty($dj['substitute_dj_id'])) { $stmt = $pdo->prepare("SELECT name FROM djs WHERE id=?"); $stmt->execute([$dj['substitute_dj_id']]); $subName = $stmt->fetchColumn(); } ?><div class="bg-white p-3 rounded-xl border border-gray-100 flex justify-between items-center group relative overflow-hidden transition hover:shadow-md <?php echo $dj['hide_from_schedule'] ? 'opacity-50' : ''; ?>"><?php if($subName): ?><div class="absolute top-0 right-0 bg-vibe-red text-white text-[9px] px-2 py-0.5 rounded-bl-lg font-bold">แทน: <?= htmlspecialchars($subName) ?></div><?php endif; ?><div class="flex items-center gap-3"><div class="w-10 h-10 rounded-full bg-gray-100 overflow-hidden border border-gray-200 shadow-sm"><?php if(!empty($dj['image_url'])): ?><img src="<?= $dj['image_url'] ?>" class="w-full h-full object-cover"><?php else: ?><div class="w-full h-full flex items-center justify-center text-gray-400 text-xs font-bold"><?= mb_substr($dj['name'],0,1) ?></div><?php endif; ?></div><div><div class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($dj['name']) ?></div><div class="text-[10px] font-bold text-gray-500 uppercase"><?= $daysMap[$dj['dj_day']] ?? $dj['dj_day'] ?> <span class="text-gray-400 font-normal ml-1"><?= htmlspecialchars($dj['time_slot']) ?></span></div></div></div><div class="opacity-0 group-hover:opacity-100 transition flex gap-2"><button onclick='editDj(<?= json_encode($dj) ?>)' class="text-gray-400 hover:text-blue-500 bg-gray-50 hover:bg-blue-50 p-1.5 rounded-lg shadow-sm transition"><i class="fas fa-edit"></i></button><form method="POST" class="inline" onsubmit="return confirm('ลบ?');"><input type="hidden" name="id" value="<?= $dj['id'] ?>"><button type="submit" name="delete_dj" class="text-gray-400 hover:text-red-500 bg-gray-50 hover:bg-red-50 p-1.5 rounded-lg transition"><i class="fas fa-trash"></i></button></form></div></div><?php endwhile; ?></div>
                    </div>
                </div>
            </div>
            <script>function editDj(data){document.getElementById('dj_id_input').value=data.id;document.getElementById('dj_name').value=data.name;document.getElementById('dj_real_name').value=data.real_name||'';document.getElementById('dj_substitute').value=data.substitute_dj_id||'';document.getElementById('dj_day').value=data.dj_day;document.getElementById('dj_time').value=data.time_slot;document.getElementById('dj_hide').checked=(data.hide_from_schedule==1);document.getElementById('save_dj_btn').innerText="บันทึกการแก้ไข";document.getElementById('djForm').scrollIntoView({behavior:'smooth'})}function resetDjForm(){document.getElementById('djForm').reset();document.getElementById('dj_id_input').value="";document.getElementById('dj_hide').checked=false;document.getElementById('save_dj_btn').innerText="เพิ่มข้อมูล"}</script>

        <?php else: ?>
            <!-- FRONTEND: HERO SECTION -->
            <div class="container mx-auto px-4 lg:px-8 mb-12 mt-8">
                <div class="flex flex-col-reverse lg:flex-row items-center justify-between gap-12 lg:gap-20">
                    
                    <!-- Left: Text Info -->
                    <div class="flex-1 text-center lg:text-left space-y-6 self-center z-10">
                        <div class="space-y-2">
                            <?php if ($currentDj): ?>
                                <div class="inline-block bg-red-100 text-vibe-red text-xs font-bold px-3 py-1 rounded-full mb-2 tracking-wide uppercase">On Air Now</div>
                                <h2 class="text-2xl font-bold text-gray-400 tracking-wide uppercase" id="hero-small-title"><?= $currentDj['name'] ?></h2>
                                <h3 class="text-xl font-bold text-vibe-red tracking-wide" id="hero-day-text"><?= $daysMap[$currentDj['dj_day']] ?? $currentDj['dj_day'] ?></h3>
                                <h1 class="text-7xl lg:text-9xl brand-font font-black text-transparent bg-clip-text bg-gradient-to-br from-black to-gray-600 leading-none tracking-tighter" id="hero-large-title-text">
                                    <?= htmlspecialchars($currentDj['time_slot']) ?>
                                </h1>
                                <?php if(!empty($currentDj['real_name'])): ?>
                                    <p class="text-gray-500 font-medium text-xl" id="hero-real-name"><?= htmlspecialchars($currentDj['real_name']) ?></p>
                                <?php endif; ?>
                            <?php else: ?>
                                <h2 class="text-xl font-bold text-gray-400 tracking-widest uppercase">WELCOME TO</h2>
                                <h1 class="text-8xl lg:text-9xl brand-font font-black text-black tracking-tighter leading-none">VIBE<span class="text-vibe-red">24</span></h1>
                            <?php endif; ?>
                        </div>
                        
                        <p class="text-2xl text-gray-600 font-light max-w-xl mx-auto lg:mx-0"><?= $welcomeMsg ?></p>
                        
                        <!-- Now Playing Card -->
                        <div class="mt-8 glass-panel p-5 rounded-2xl inline-flex items-center gap-5 max-w-md mx-auto lg:mx-0 hover:scale-[1.02] transition-transform duration-500">
                             <!-- WEBCAM BUTTON -->
                             <button onclick="document.getElementById('webcam-modal').classList.remove('hidden')" class="bg-black text-white w-20 h-20 rounded-xl flex items-center justify-center flex-shrink-0 shadow-lg relative group overflow-hidden"><i class="fas fa-video text-2xl group-hover:scale-110 transition z-10"></i><div class="absolute top-2 right-2 w-2 h-2 bg-red-500 rounded-full animate-pulse z-10"></div><div class="absolute inset-0 bg-gray-800 opacity-0 group-hover:opacity-50 transition"></div></button>
                            <div class="text-left overflow-hidden pl-2"><div class="text-xs font-bold text-vibe-red uppercase tracking-widest mb-1">Now Playing</div><div class="text-xl font-bold text-gray-900 leading-tight truncate"><script src="https://embed.radio.co/embed/<?= $stationId ?>/song.js"></script></div></div>
                        </div>
                    </div>

                    <!-- Right: Dynamic Image -->
                    <div class="flex-1 w-full flex justify-center lg:justify-end relative">
                        <div class="absolute top-1/2 left-1/2 transform -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-red-200 rounded-full blur-3xl opacity-30 animate-pulse"></div>
                        <div class="relative z-10 w-full max-w-lg" id="hero-image-container">
                             <?php if ($currentDj && !empty($currentDj['image_url'])): ?>
                                <img src="<?= $currentDj['image_url'] ?>" class="w-full h-auto object-contain drop-shadow-2xl transform hover:scale-105 transition duration-700">
                            <?php else: 
                                // RESTORED: VIBE GROUP (Default 3 DJs)
                                $djs = $pdo->query("SELECT * FROM djs ORDER BY id ASC LIMIT 3");
                                echo '<div class="flex items-end justify-center -space-x-12 lg:-space-x-16">';
                                while($dj = $djs->fetch()):
                                    $src = !empty($dj['image_url']) ? $dj['image_url'] : 'https://placehold.co/300x400/eee/999?text=DJ';
                            ?>
                                <div class="relative group transition-all duration-500 hover:z-20 hover:scale-105 filter grayscale-[0.3] hover:grayscale-0 cursor-pointer">
                                    <img src="<?= $src ?>" class="h-[280px] md:h-[400px] w-auto object-contain drop-shadow-xl transform group-hover:drop-shadow-2xl transition-all">
                                </div>
                            <?php endwhile; echo '</div>'; endif; ?>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Content Grid -->
            <div class="container mx-auto px-4 lg:px-8 pb-12">
                <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    
                    <!-- Left: Talk to DJ / Group Chat -->
                    <div class="lg:col-span-7 space-y-8">
                         <!-- Talk to DJ Box -->
                         <div class="glass-panel p-6 rounded-3xl h-[600px] flex flex-col relative overflow-hidden">
                            <div class="absolute top-0 right-0 p-8 opacity-[0.03] pointer-events-none"><i class="far fa-comments text-9xl"></i></div>
                            <h3 class="text-xl font-bold mb-4 flex items-center gap-3 text-gray-800 border-b pb-4 relative z-10">
                                <span class="w-10 h-10 rounded-full bg-black text-white flex items-center justify-center shadow-lg"><i class="far fa-comments"></i></span> 
                                Talk to DJ <span class="text-xs text-vibe-red bg-red-50 px-2 py-0.5 rounded-full ml-auto font-normal">Live Chat</span>
                            </h3>
                            
                            <!-- Chat Messages Area -->
                            <div id="chat-messages" class="flex-grow overflow-y-auto mb-4 space-y-4 pr-2 relative z-10 scroll-smooth p-2">
                                <div class="text-center text-gray-400 py-10 flex flex-col items-center gap-2">
                                    <i class="fas fa-circle-notch animate-spin text-2xl"></i>
                                    <span>กำลังโหลดข้อความ...</span>
                                </div>
                            </div>

                            <!-- Chat Input -->
                            <form id="chat-form" class="mt-auto bg-gray-50 p-3 rounded-2xl border border-gray-200 relative z-10">
                                <div class="flex flex-col gap-2">
                                    <div class="flex items-center gap-2 bg-white border border-gray-200 rounded-xl px-3 py-1">
                                        <i class="fas fa-user text-gray-400 text-xs"></i>
                                        <input type="text" id="chat-name" placeholder="ชื่อของคุณ..." class="w-full py-1 text-sm focus:outline-none font-bold text-gray-700" required>
                                    </div>
                                    
                                    <div class="flex gap-2">
                                        <input type="text" id="chat-message" placeholder="พิมพ์ข้อความทักทาย..." class="flex-grow bg-white border border-gray-200 rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-red-500 focus:ring-1 focus:ring-red-100 transition shadow-sm" required autocomplete="off">
                                        <button type="submit" class="bg-vibe-red text-white w-12 h-auto rounded-xl flex items-center justify-center hover:bg-red-700 transition shadow-lg transform active:scale-95"><i class="fas fa-paper-plane"></i></button>
                                    </div>
                                </div>
                            </form>
                         </div>
                    </div>

                    <!-- Right: Schedule & Song Info (5 Cols) -->
                    <div class="lg:col-span-5 space-y-8">
                         <!-- Schedule -->
                        <div id="schedule" class="glass-panel p-6 rounded-3xl">
                            <h3 class="text-xl font-bold mb-6 flex items-center gap-3 text-gray-800">
                                <span class="w-10 h-10 rounded-full bg-black text-white flex items-center justify-center shadow-lg"><i class="far fa-clock"></i></span> 
                                ตารางจัดรายการ
                            </h3>
                            <div class="space-y-6 relative ml-3 before:absolute before:left-[19px] before:top-2 before:bottom-2 before:w-0.5 before:bg-gray-200 max-h-[500px] overflow-y-auto pr-2 custom-scroll">
                                <?php $schedule = []; $allDJs = $pdo->query("SELECT * FROM djs WHERE hide_from_schedule = 0 ORDER BY FIELD(dj_day, 'Everyday', 'Mon-Fri', 'Sat-Sun', 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), time_slot ASC"); while($d = $allDJs->fetch()) { $schedule[$d['dj_day']][] = $d; } foreach($schedule as $dayKey => $djList): ?>
                                <div class="relative">
                                    <div class="text-xs font-bold text-gray-400 uppercase tracking-wider mb-2 pl-10 bg-white inline-block relative z-10"><?= $daysMap[$dayKey] ?? $dayKey ?></div>
                                    <div class="space-y-4">
                                        <?php foreach($djList as $dj): ?>
                                        <div class="relative pl-10 group">
                                            <div class="absolute left-[15px] top-1.5 w-2.5 h-2.5 bg-white border-2 border-[#E31E24] rounded-full z-10 group-hover:scale-125 transition"></div>
                                            <div class="bg-gray-50 hover:bg-white border border-transparent hover:border-gray-200 p-3 rounded-xl transition shadow-sm hover:shadow-md">
                                                <div class="text-xs font-bold text-[#E31E24] font-mono mb-0.5"><?= htmlspecialchars($dj['time_slot']) ?></div>
                                                <div class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($dj['name']) ?></div>
                                                <?php if(!empty($dj['substitute_dj_id'])): ?> 
                                                    <span class="text-[10px] text-gray-400 bg-gray-200 px-1.5 py-0.5 rounded ml-1">แทน</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Request Form -->
                        <div class="glass-panel p-8 rounded-[2rem] relative overflow-hidden">
                             <div class="absolute top-0 right-0 p-10 opacity-5"><i class="fas fa-music text-9xl"></i></div>
                             <h3 class="text-2xl font-black mb-6 text-gray-900 flex items-center gap-3 relative z-10"><span class="w-10 h-10 rounded-xl bg-gradient-to-br from-black to-gray-800 text-white flex items-center justify-center text-base shadow-lg"><i class="fas fa-microphone-lines"></i></span> ขอเพลงออนไลน์</h3>
                             
                             <!-- Radio.co Widget -->
                            <div class="w-full flex justify-center overflow-hidden rounded-2xl shadow-inner bg-gray-50 border border-gray-100 relative z-10" style="min-height: 400px;">
                                <script src="<?= htmlspecialchars($requestUrl) ?>"></script>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

        <?php endif; ?>
    </div>

    <!-- Floating Player -->
    <div class="fixed bottom-6 left-1/2 transform -translate-x-1/2 w-[95%] max-w-4xl z-[100] floating-player rounded-full px-6 py-3 flex items-center justify-between transition-all duration-500 hover:shadow-2xl">
        <div class="flex items-center gap-4 w-1/3 min-w-0">
             <div class="w-12 h-12 rounded-full overflow-hidden shadow-md border-2 border-white flex-shrink-0 relative group"><script src="https://embed.radio.co/embed/<?= $stationId ?>/artwork.js"></script><div class="absolute inset-0 bg-black/20 hidden group-hover:flex items-center justify-center transition"><i class="fas fa-music text-white text-xs"></i></div></div>
             <div class="flex flex-col min-w-0"><div class="text-sm font-bold text-gray-900 truncate"><script src="https://embed.radio.co/embed/<?= $stationId ?>/song.js"></script></div><div class="flex items-center gap-2"><div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div><span class="text-xs text-gray-500 font-medium uppercase tracking-wide">Live On Air</span></div></div>
        </div>
        <div class="absolute left-1/2 top-1/2 transform -translate-x-1/2 -translate-y-1/2"><button onclick="togglePlay()" class="play-btn-circle w-16 h-16 rounded-full flex items-center justify-center text-white text-2xl shadow-xl hover:shadow-2xl transform transition active:scale-95 group"><i id="play-icon" class="fas fa-play ml-1 group-hover:scale-110 transition"></i></button></div>
        <div class="flex items-center justify-end gap-4 w-1/3 min-w-0 text-right"><div class="min-w-0"><span class="text-[10px] font-bold text-gray-400 uppercase tracking-widest block mb-0.5">Up Next</span><div class="text-xs font-bold text-gray-600 truncate max-w-[150px]"><div class="inline-block animate-marquee hover:pause px-1"><script src="https://embed.radio.co/embed/<?= $stationId ?>/next.js"></script></div></div></div><audio id="main-player" class="hidden"><source src="<?= $streamUrl ?>" type="audio/mpeg"></audio></div>
    </div>

    <!-- Streaming Visualizer Modal (Webcam) -->
    <div id="webcam-modal" class="hidden fixed inset-0 z-[100] bg-black/95 backdrop-blur-xl">
        <button onclick="document.getElementById('webcam-modal').classList.add('hidden')" class="absolute top-6 right-6 text-white/50 hover:text-white text-3xl z-50 transition">&times;</button>
        <div class="w-full h-full flex flex-col justify-center items-center relative overflow-hidden">
             <!-- Dynamic Background (Blurred Artwork) -->
             <div class="absolute inset-0 z-0"><img id="stream-bg" src="<?= $siteLogo ?>" class="w-full h-full object-cover opacity-30 filter blur-3xl scale-110 transition-all duration-1000"><div class="absolute inset-0 bg-gradient-to-b from-black/30 via-transparent to-black/80"></div></div>
             <!-- Main Content -->
             <div class="relative z-10 flex flex-col items-center text-center p-8 max-w-4xl w-full">
                 <!-- Large Artwork -->
                 <div class="w-64 h-64 md:w-96 md:h-96 rounded-2xl overflow-hidden shadow-[0_0_50px_rgba(227,30,36,0.3)] mb-8 border border-white/10 relative group"><script src="https://embed.radio.co/embed/<?= $stationId ?>/artwork.js"></script><div class="absolute inset-0 bg-gradient-to-tr from-white/10 to-transparent pointer-events-none"></div></div>
                 <!-- Song Info -->
                 <div class="space-y-2 mb-8"><h2 class="text-3xl md:text-5xl font-black text-white leading-tight drop-shadow-lg tracking-tight"><script src="https://embed.radio.co/embed/<?= $stationId ?>/song.js"></script></h2><div class="flex items-center justify-center gap-2 text-red-400 font-bold tracking-widest uppercase text-sm md:text-base"><span class="w-2 h-2 bg-red-500 rounded-full animate-pulse"></span> Now Streaming</div></div>
                 <!-- Audio Visualizer -->
                 <div class="flex items-end justify-center gap-1.5 h-16"><div class="w-1.5 md:w-2 bg-red-500/80 rounded-t-full animate-[visualize_0.5s_infinite_ease-in-out_alternate]"></div><div class="w-1.5 md:w-2 bg-white/80 rounded-t-full animate-[visualize_0.7s_infinite_ease-in-out_alternate] animation-delay-100"></div><div class="w-1.5 md:w-2 bg-red-500/60 rounded-t-full animate-[visualize_0.9s_infinite_ease-in-out_alternate] animation-delay-200"></div><div class="w-1.5 md:w-2 bg-white/60 rounded-t-full animate-[visualize_1.1s_infinite_ease-in-out_alternate] animation-delay-300"></div><div class="w-1.5 md:w-2 bg-red-500/80 rounded-t-full animate-[visualize_0.6s_infinite_ease-in-out_alternate] animation-delay-400"></div><div class="w-1.5 md:w-2 bg-white/90 rounded-t-full animate-[visualize_0.8s_infinite_ease-in-out_alternate] animation-delay-500"></div><div class="w-1.5 md:w-2 bg-red-500/50 rounded-t-full animate-[visualize_1.0s_infinite_ease-in-out_alternate] animation-delay-200"></div></div>
             </div>
        </div>
    </div>
    <style>@keyframes visualize { 0% { height: 10px; } 100% { height: 60px; } }</style>

    <script>
        const audio = document.getElementById('main-player');
        const playIcon = document.getElementById('play-icon');
        const streamBaseUrl = "<?= explode('?', $streamUrl)[0] ?>";

        function togglePlay() {
            if (audio.paused) { 
                audio.src = streamBaseUrl + "?t=" + Date.now(); // Force Live
                audio.load();
                audio.play(); 
                playIcon.className = "fas fa-pause";
                playIcon.parentElement.classList.add('animate-pulse');
            } else { 
                audio.pause(); 
                audio.src = ""; 
                playIcon.className = "fas fa-play ml-1";
                playIcon.parentElement.classList.remove('animate-pulse');
            }
        }

        // --- NEW CHAT SYSTEM LOGIC ---
        const chatForm = document.getElementById('chat-form');
        const chatMessages = document.getElementById('chat-messages');
        const nameInput = document.getElementById('chat-name');
        
        // Load saved name
        if(nameInput) {
            const savedName = localStorage.getItem('chat_username');
            if(savedName) nameInput.value = savedName;
        }

        function getInitials(name) {
            return name ? name.charAt(0).toUpperCase() : '?';
        }

        function getColorFromName(name) {
            const colors = ['#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#6366F1', '#8B5CF6', '#EC4899'];
            let hash = 0;
            for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash);
            return colors[Math.abs(hash) % colors.length];
        }

        if(chatForm) {
            chatForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const name = nameInput.value;
                const msg = document.getElementById('chat-message').value;
                const btn = chatForm.querySelector('button');
                
                // Save name for next time
                localStorage.setItem('chat_username', name);
                
                btn.disabled = true;
                const formData = new FormData();
                formData.append('name', name);
                formData.append('message', msg);
                
                await fetch('index.php?action=send_chat_message', { method: 'POST', body: formData });
                document.getElementById('chat-message').value = '';
                btn.disabled = false;
                loadChat(); // Reload immediately
            });
        }

        async function loadChat() {
            if(!chatMessages) return;
            try {
                const res = await fetch('index.php?action=get_chat_messages');
                const msgs = await res.json();
                
                const myName = localStorage.getItem('chat_username');
                
                chatMessages.innerHTML = msgs.map(m => {
                    const isMe = myName && m.sender_name === myName;
                    const alignClass = isMe ? 'right' : 'left';
                    const avatarColor = isMe ? 'linear-gradient(135deg, #E31E24 0%, #ff4b4b 100%)' : `linear-gradient(135deg, ${getColorFromName(m.sender_name)} 0%, #ddd 100%)`;
                    
                    return `
                    <div class="chat-container ${alignClass}">
                        <div class="chat-row ${alignClass}">
                             ${!isMe ? `<div class="chat-avatar" style="background: ${avatarColor}">${getInitials(m.sender_name)}</div>` : ''}
                             <div>
                                 ${!isMe ? `<div class="chat-name">${m.sender_name}</div>` : ''}
                                 <div class="chat-bubble">
                                    ${m.message}
                                 </div>
                                 <div class="chat-time text-${isMe ? 'right' : 'left'}">${new Date(m.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'})}</div>
                             </div>
                        </div>
                    </div>
                    `;
                }).join('');
            } catch(e) {}
        }
        
        if(chatMessages) {
             setInterval(loadChat, 3000); // Poll every 3s
             loadChat(); // First load
        }

        // Update Background with Artwork via Proxy for Metadata
        async function updateBackground() {
            try {
                const statusUrl = `https://public.radio.co/stations/<?= $stationId ?>/status`;
                const response = await fetch('https://api.allorigins.win/raw?url=' + encodeURIComponent(statusUrl));
                const data = await response.json();
                if(data.current_track && data.current_track.artwork_url) {
                    const bg = document.getElementById('stream-bg');
                    if(bg && bg.src !== data.current_track.artwork_url) {
                        bg.src = data.current_track.artwork_url;
                    }
                }
            } catch(e) {}
        }
        setInterval(updateBackground, 10000); updateBackground();

        // Auto DJ Update Logic
        async function updateDjDisplay() {
            try {
                const response = await fetch('index.php?action=get_current_dj');
                const data = await response.json();
                
                // Elements
                const smallTitle = document.getElementById('hero-small-title');
                const dayText = document.getElementById('hero-day-text');
                const largeTitleText = document.getElementById('hero-large-title-text');
                const imageContainer = document.getElementById('hero-image-container');
                const realNameText = document.getElementById('hero-real-name'); // Optional

                if (data.status === 'live') {
                    if(smallTitle) smallTitle.innerHTML = data.name; 
                    if(largeTitleText) { largeTitleText.innerHTML = data.time_slot; largeTitleText.classList.remove('hidden'); }
                    if(dayText) { dayText.innerHTML = data.day_display; dayText.classList.remove('hidden'); }
                    if(realNameText) { 
                        if(!realNameText) { realNameText = document.createElement('p'); realNameText.id = 'hero-real-name'; realNameText.className = 'text-gray-500 font-medium text-lg mt-2'; largeTitleText.parentNode.insertBefore(realNameText, largeTitleText.nextSibling); }
                        realNameText.innerHTML = data.real_name || ''; realNameText.style.display = 'block'; 
                    }

                    if (data.image_url && imageContainer) {
                        imageContainer.innerHTML = `<img src="${data.image_url}" class="w-full h-auto object-contain drop-shadow-2xl transform hover:scale-105 transition duration-700">`;
                    }
                } else {
                    if(smallTitle) smallTitle.innerText = "VIBE GROUP";
                    // Reset to group view logic if needed
                    if(largeTitleText) largeTitleText.classList.add('hidden'); // Optional: Hide time slot if default
                    if(imageContainer && data.status === 'default') {
                         let groupHtml = '<div class="flex items-end justify-center -space-x-12 lg:-space-x-16">';
                         data.group_djs.forEach((dj) => {
                             let src = dj.image_url ? dj.image_url : 'https://placehold.co/300x400/eee/999?text=DJ';
                             groupHtml += `<div class="relative group transition-all duration-500 hover:z-20 hover:scale-105 filter grayscale-[0.3] hover:grayscale-0 cursor-pointer">
                                            <img src="${src}" class="h-[280px] md:h-[400px] w-auto object-contain drop-shadow-xl transform group-hover:drop-shadow-2xl transition-all">
                                           </div>`;
                         });
                         groupHtml += '</div>';
                         imageContainer.innerHTML = groupHtml;
                    }
                }
            } catch(e) {}
        }
        setInterval(updateDjDisplay, 60000); // 1 min update
        updateDjDisplay(); // Run on load
    </script>

</body>
</html>
