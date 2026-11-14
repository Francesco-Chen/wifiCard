<?php
session_start();

// ── Config ────────────────────────────────────────────────────────────────
define('ADMIN_PASSWORD', 'admin123');   // ← Change this password for security
define('DATA_FILE', __DIR__ . '/data.json');

// ── Helpers ───────────────────────────────────────────────────────────────
function loadData(): array {
    if (!file_exists(DATA_FILE)) return [];
    $d = json_decode(file_get_contents(DATA_FILE), true);
    return is_array($d) ? $d : [];
}
function saveData(array $data): void {
    file_put_contents(DATA_FILE, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// ── Auth ──────────────────────────────────────────────────────────────────
if (isset($_POST['login'])) {
    if ($_POST['password'] === ADMIN_PASSWORD) {
        $_SESSION['admin'] = true;
        header('Location: admin.php'); exit;
    }
    $loginError = 'Incorrect password. Please try again.';
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php'); exit;
}

$authed = !empty($_SESSION['admin']);

// ── Actions (require auth) ────────────────────────────────────────────────
$message = '';
if ($authed) {
    // Export JSON
    if (isset($_GET['export'])) {
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="wifi-networks-' . date('Ymd-His') . '.json"');
        echo json_encode(loadData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
        $networks = loadData();

        if ($_POST['action'] === 'add') {
            $ssid     = trim($_POST['ssid'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $type     = in_array($_POST['type'] ?? '', ['WPA','WEP','nopass']) ? $_POST['type'] : 'WPA';
            $label    = trim($_POST['label'] ?? '');
            $color_choice = $_POST['color'] ?? 'random';
            if ($color_choice === 'random') {
                $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            } else if (preg_match('/^#[a-fA-F0-9]{6}$/', $color_choice)) {
                $color = $color_choice;
            } else if (isset($old_color_map[$color_choice])) {
                $color = $old_color_map[$color_choice];
            } else {
                $color = '#4f46e5';
            }

            if ($ssid && ($password || $type === 'nopass')) {
                $networks[] = [
                    'id'       => uniqid(),
                    'ssid'     => $ssid,
                    'password' => $password,
                    'type'     => $type,
                    'label'    => $label,
                    'color'    => $color,
                ];
                saveData($networks);
                $message = ['type' => 'ok', 'text' => '✅ Network [' . $ssid . '] added successfully!'];
            } else {
                $message = ['type' => 'err', 'text' => '❌ SSID and password cannot be empty.'];
            }
        }

        if ($_POST['action'] === 'edit') {
            $id       = $_POST['id'] ?? '';
            $ssid     = trim($_POST['ssid'] ?? '');
            $password = trim($_POST['password'] ?? '');
            $type     = in_array($_POST['type'] ?? '', ['WPA','WEP','nopass']) ? $_POST['type'] : 'WPA';
            $label    = trim($_POST['label'] ?? '');
            $color_choice = $_POST['color'] ?? 'random';
            if ($color_choice === 'random') {
                $color = sprintf('#%06X', mt_rand(0, 0xFFFFFF));
            } else if (preg_match('/^#[a-fA-F0-9]{6}$/', $color_choice)) {
                $color = $color_choice;
            } else if (isset($old_color_map[$color_choice])) {
                $color = $old_color_map[$color_choice];
            } else {
                $color = '#4f46e5';
            }

            if ($id && $ssid && ($password || $type === 'nopass')) {
                foreach ($networks as &$n) {
                    if ($n['id'] === $id) {
                        $n['ssid'] = $ssid;
                        $n['password'] = $password;
                        $n['type'] = $type;
                        $n['label'] = $label;
                        $n['color'] = $color;
                        break;
                    }
                }
                saveData($networks);
                $message = ['type' => 'ok', 'text' => '✅ Network [' . $ssid . '] updated successfully!'];
            } else {
                $message = ['type' => 'err', 'text' => '❌ SSID and password cannot be empty.'];
            }
        }

        if ($_POST['action'] === 'delete') {
            $id   = $_POST['id'] ?? '';
            $before = count($networks);
            $networks = array_filter($networks, fn($n) => $n['id'] !== $id);
            if (count($networks) < $before) {
                saveData($networks);
                $message = ['type' => 'ok', 'text' => '✅ Network deleted successfully.'];
            }
        }

        if ($_POST['action'] === 'reorder') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            if (is_array($order)) {
                $indexed = [];
                foreach ($networks as $n) $indexed[$n['id']] = $n;
                $reordered = [];
                foreach ($order as $id) {
                    if (isset($indexed[$id])) $reordered[] = $indexed[$id];
                }
                foreach ($networks as $n) {
                    if (!in_array($n['id'], $order)) $reordered[] = $n;
                }
                saveData($reordered);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]); exit;
        }

        if ($_POST['action'] === 'import' && isset($_FILES['import_file'])) {
            $file = $_FILES['import_file'];
            if ($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name'])) {
                $jsonStr = file_get_contents($file['tmp_name']);
                $parsed = json_decode($jsonStr, true);
                if (is_array($parsed)) {
                    $validNetworks = [];
                    foreach ($parsed as $item) {
                        if (!empty($item['ssid'])) {
                            $validNetworks[] = [
                                'id'       => $item['id'] ?? uniqid(),
                                'ssid'     => trim($item['ssid']),
                                'password' => trim($item['password'] ?? ''),
                                'type'     => in_array($item['type'] ?? '', ['WPA','WEP','nopass']) ? $item['type'] : 'WPA',
                                'label'    => trim($item['label'] ?? ''),
                                'color'    => $item['color'] ?? '#4f46e5'
                            ];
                        }
                    }
                    saveData($validNetworks);
                    $message = ['type' => 'ok', 'text' => '✅ Successfully imported ' . count($validNetworks) . ' networks!'];
                } else {
                    $message = ['type' => 'err', 'text' => '❌ Invalid JSON file format.'];
                }
            } else {
                $message = ['type' => 'err', 'text' => '❌ File upload failed.'];
            }
        }
    }

    $networks = loadData();
}

$old_color_map = [
    'indigo'  => '#4f46e5',
    'rose'    => '#e11d48',
    'emerald' => '#059669',
    'amber'   => '#d97706',
    'sky'     => '#0284c7',
    'violet'  => '#7c3aed',
    'fuchsia' => '#c026d3',
    'teal'    => '#0d9488',
    'orange'  => '#ea580c',
    'cyan'    => '#0891b2',
    'slate'   => '#475569',
    'pink'    => '#db2777',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WiFi Admin Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Noto+Sans+SC:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --bg: #0f172a;
            --surface: #1e293b;
            --surface2: #263347;
            --border: #334155;
            --text: #f1f5f9;
            --muted: #94a3b8;
            --accent: #4f46e5;
            --accent-light: #6366f1;
            --danger: #f43f5e;
            --ok: #10b981;
            --radius: 14px;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Noto Sans SC', sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
        }

        /* ── Login screen ── */
        .login-wrap {
            display: flex;
            align-items: center;
            justify-content: center;
            width: 100vw;
            min-height: 100vh;
        }
        .login-box {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 380px;
            text-align: center;
        }
        .login-box .icon { font-size: 2.5rem; margin-bottom: 1rem; }
        .login-box h1 { font-size: 1.3rem; font-weight: 700; margin-bottom: .4rem; }
        .login-box p  { font-size: .85rem; color: var(--muted); margin-bottom: 1.8rem; }
        .login-box input[type="password"] {
            width: 100%;
            padding: .75rem 1rem;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #fff;
            font-size: .95rem;
            outline: none;
            margin-bottom: 1rem;
            text-align: center;
            letter-spacing: .2em;
        }
        .login-box input[type="password"]:focus { border-color: var(--accent-light); }
        .btn-primary {
            width: 100%;
            padding: .75rem;
            background: var(--accent);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: .95rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-primary:hover { background: var(--accent-light); }
        .err-msg { color: var(--danger); font-size: .82rem; margin-top: .8rem; }

        /* ── Sidebar ── */
        .sidebar {
            width: 240px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 1.5rem 1rem;
            flex-shrink: 0;
            min-height: 100vh;
        }
        .sidebar .logo {
            font-family: 'Space Mono', monospace;
            font-size: 1rem;
            font-weight: 700;
            color: #fff;
            padding: 0 .6rem 1.5rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1.2rem;
        }
        .sidebar nav a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: .65rem .8rem;
            border-radius: 10px;
            color: var(--muted);
            text-decoration: none;
            font-size: .88rem;
            font-weight: 500;
            margin-bottom: .3rem;
            transition: all .15s;
        }
        .sidebar nav a:hover, .sidebar nav a.active {
            background: var(--surface2);
            color: #fff;
        }
        .sidebar .spacer { flex: 1; }
        .sidebar .logout {
            color: var(--danger);
            text-decoration: none;
            font-size: .85rem;
            padding: .65rem .8rem;
            border-radius: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background .15s;
        }
        .sidebar .logout:hover { background: rgba(244,63,94,.12); }

        /* ── Main content ── */
        .main {
            flex: 1;
            padding: 2rem 2.5rem;
            max-width: 960px;
            overflow-y: auto;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        .topbar h1 { font-size: 1.4rem; font-weight: 700; }
        .topbar-btns { display: flex; align-items: center; gap: 8px; }
        .view-link {
            font-size: .82rem;
            color: var(--accent-light);
            text-decoration: none;
            padding: .45rem 1rem;
            border: 1px solid rgba(99,102,241,.35);
            border-radius: 999px;
            background: rgba(99,102,241,.08);
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            font-weight: 600;
        }
        .view-link:hover { background: rgba(99,102,241,.2); border-color: var(--accent-light); }

        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1rem;
            margin-bottom: 2rem;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.2rem 1.4rem;
        }
        .stat-card .label { font-size: .75rem; color: var(--muted); text-transform: uppercase; letter-spacing: .08em; font-weight: 700; }
        .stat-card .val { font-family: 'Space Mono', monospace; font-size: 1.8rem; font-weight: 700; margin-top: .4rem; }

        /* Form */
        .section-title {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .add-form {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        .form-group { display: flex; flex-direction: column; gap: 5px; }
        .form-group.full { grid-column: 1 / -1; }
        .form-group label { font-size: .78rem; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
        .form-group input, .form-group select {
            padding: .65rem .85rem;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: #fff;
            font-size: .9rem;
            outline: none;
            transition: border-color .15s;
        }
        .form-group input:focus, .form-group select:focus { border-color: var(--accent-light); }
        .form-group select option { background: var(--surface2); }
        .color-picker {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-top: 4px;
        }
        .color-opt { display: none; }
        .color-dot {
            display: block;
            width: 28px; height: 28px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: transform .15s, border-color .15s;
        }
        .color-dot:hover { transform: scale(1.15); }
        .color-opt:checked + .color-dot {
            border-color: #fff;
            transform: scale(1.15);
        }
        .random-dot {
            background: linear-gradient(135deg, #6366f1, #ec4899, #f59e0b);
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-size: .75rem;
        }
        .custom-color-wrap { position: relative; }
        .form-actions {
            margin-top: 1.2rem;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }
        .btn-add {
            padding: .65rem 1.4rem;
            background: var(--accent);
            border: none;
            border-radius: 10px;
            color: #fff;
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-add:hover { background: var(--accent-light); }
        .btn-cancel {
            padding: .65rem 1.2rem;
            background: transparent;
            color: var(--muted);
            border: 1px solid var(--border);
            border-radius: 10px;
            font-size: .9rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .2s, color .2s;
            display: none;
        }
        .btn-cancel:hover { background: var(--surface2); color: var(--text); }

        /* ── Table ── */
        .table-wrap {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        table { width: 100%; border-collapse: collapse; }
        thead th {
            padding: .85rem 1.2rem;
            text-align: left;
            font-size: .72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
            background: var(--surface);
        }
        tbody tr { border-bottom: 1px solid var(--border); transition: background .15s; }
        tbody tr:last-child { border-bottom: none; }
        tbody tr:hover { background: var(--surface2); }
        tbody td { padding: 1rem 1.2rem; font-size: .88rem; vertical-align: middle; }
        .color-swatch {
            display: inline-block;
            width: 12px; height: 12px;
            border-radius: 50%;
            margin-right: .5rem;
            vertical-align: middle;
        }
        .ssid-cell { font-family: 'Space Mono', monospace; font-weight: 700; }
        .pass-cell { font-family: 'Space Mono', monospace; font-size: .85rem; color: var(--muted); }
        .badge {
            display: inline-block;
            padding: .2rem .6rem;
            border-radius: 6px;
            font-size: .72rem;
            font-weight: 700;
            background: var(--surface2);
            color: var(--muted);
            border: 1px solid var(--border);
        }
        .btn-del {
            padding: .4rem .75rem;
            background: rgba(244,63,94,.1);
            border: 1px solid rgba(244,63,94,.25);
            border-radius: 8px;
            color: var(--danger);
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-del:hover { background: rgba(244,63,94,.25); }
        .btn-edit {
            padding: .4rem .85rem;
            background: rgba(99,102,241,.1);
            border: 1px solid rgba(99,102,241,.25);
            border-radius: 8px;
            color: var(--accent-light);
            font-size: .78rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .15s;
        }
        .btn-edit:hover { background: rgba(99,102,241,.25); }
        .empty-row td { text-align: center; color: var(--muted); padding: 3rem; }
        .drag-handle { cursor: grab; color: var(--muted); font-size: 1.1rem; user-select: none; }
        tr.dragging { opacity: .4; }

        /* Alert */
        .alert {
            padding: .85rem 1.2rem;
            border-radius: var(--radius);
            font-size: .88rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
        }
        .alert-ok  { background: rgba(16,185,129,.15); border: 1px solid rgba(16,185,129,.35); color: #34d399; }
        .alert-err { background: rgba(244,63,94,.15);  border: 1px solid rgba(244,63,94,.35);  color: #fb7185; }

        /* Backup tools */
        .backup-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.2rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .backup-info h3 { font-size: .92rem; font-weight: 700; margin-bottom: .2rem; }
        .backup-info p { font-size: .78rem; color: var(--muted); }
        .backup-actions { display: flex; gap: 8px; align-items: center; }
        .btn-secondary {
            padding: .45rem 1rem;
            background: var(--surface2);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text);
            font-size: .82rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: background .15s;
        }
        .btn-secondary:hover { background: var(--border); }

        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main { margin-left: 0; padding: 1.2rem; }
            .stats { grid-template-columns: 1fr; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php if (!$authed): ?>
<!-- ══ LOGIN ══════════════════════════════════════════════════════════════ -->
<div class="login-wrap">
    <div class="login-box">
        <div class="icon">🔐</div>
        <h1>Admin Login</h1>
        <p>Enter your administrator password to manage WiFi cards</p>
        <form method="post" action="admin.php">
            <input type="password" name="password" placeholder="••••••••" autofocus required>
            <button type="submit" name="login" class="btn-primary">Log In</button>
            <?php if (!empty($loginError)): ?>
            <p class="err-msg"><?= htmlspecialchars($loginError) ?></p>
            <?php endif; ?>
        </form>
    </div>
</div>

<?php else: ?>
<!-- ══ ADMIN ══════════════════════════════════════════════════════════════ -->

<!-- Sidebar -->
<aside class="sidebar">
    <div class="logo">📶 WiFi Admin</div>
    <nav>
        <a href="admin.php" class="active">🗂 Networks</a>
        <a href="index.html" target="_blank">👁 Card View</a>
        <a href="admin.php?export=1">📤 Export JSON</a>
    </nav>
    <div class="spacer"></div>
    <a href="admin.php?logout=1" class="logout">🚪 Log Out</a>
</aside>

<!-- Main -->
<main class="main">
    <div class="topbar">
        <h1>WiFi Network Management</h1>
        <div class="topbar-btns">
            <a href="index.html" target="_blank" class="view-link">🔗 View Cards</a>
        </div>
    </div>

    <?php if (!empty($message)): ?>
    <div class="alert alert-<?= $message['type'] === 'ok' ? 'ok' : 'err' ?>">
        <?= htmlspecialchars($message['text']) ?>
    </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats">
        <div class="stat-card">
            <div class="label">Total Networks</div>
            <div class="val" style="color:var(--accent-light)"><?= count($networks) ?></div>
        </div>
        <div class="stat-card">
            <div class="label">Security Types</div>
            <div class="val" style="color:var(--ok);font-size:1.1rem;margin-top:.6rem">
                <?php
                $types = array_count_values(array_column($networks, 'type'));
                foreach ($types as $t => $c) echo "<span style='margin-right:.5rem'>$t: $c</span>";
                if (empty($types)) echo '—';
                ?>
            </div>
        </div>
        <div class="stat-card">
            <div class="label">Data Storage</div>
            <div class="val" style="color:var(--muted);font-size:.8rem;margin-top:.5rem;font-family:monospace">data.json</div>
        </div>
    </div>

    <!-- Add form -->
    <p class="section-title form-title">➕ Add New Network</p>
    <div class="add-form">
        <form method="post" action="admin.php" id="editForm">
            <input type="hidden" name="action" value="add" id="formAction">
            <input type="hidden" name="id" value="" id="formId">
            <div class="form-grid">
                <div class="form-group">
                    <label>Network Name (SSID) *</label>
                    <input type="text" name="ssid" placeholder="e.g. MyHome-WiFi" required>
                </div>
                <div class="form-group">
                    <label>WiFi Password *</label>
                    <input type="text" name="password" placeholder="e.g. SecretPass123">
                </div>
                <div class="form-group">
                    <label>Security Protocol</label>
                    <select name="type">
                        <option value="WPA">WPA / WPA2 / WPA3</option>
                        <option value="WEP">WEP</option>
                        <option value="nopass">None (Open Network)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Location Label (Optional)</label>
                    <input type="text" name="label" placeholder="e.g. Living Room, Meeting Room 2A">
                </div>
                <div class="form-group full">
                    <label>Card Theme Color</label>
                    <div class="color-picker">
                        <input type="hidden" name="color" id="finalColor" value="random">
                        <label>
                            <input class="color-opt" type="radio" name="color_opt" value="random" checked onchange="document.getElementById('finalColor').value = this.value">
                            <span class="color-dot random-dot" title="Random Color">🎲</span>
                        </label>
                        
                        <label class="custom-color-wrap">
                            <input class="color-opt" type="radio" name="color_opt" value="custom" id="optCustom">
                            <span class="color-dot" id="customDot" style="background: conic-gradient(red, yellow, lime, aqua, blue, magenta, red); display: flex; align-items: center; justify-content: center; color:#fff;" title="Custom Color Palette">🎨</span>
                            <input type="color" id="colorInput" style="opacity:0; position:absolute; inset:0; width:100%; height:100%; cursor:pointer;" 
                                   oninput="document.getElementById('optCustom').checked = true; document.getElementById('finalColor').value = this.value; document.getElementById('customDot').style.background = this.value;">
                        </label>

                        <?php 
                        $quick_colors = ['#4f46e5', '#e11d48', '#059669', '#d97706', '#0284c7', '#7c3aed', '#c026d3', '#0d9488', '#ea580c', '#0891b2', '#475569', '#db2777'];
                        foreach ($quick_colors as $hex): ?>
                        <label>
                            <input class="color-opt" type="radio" name="color_opt" value="<?= $hex ?>" onchange="document.getElementById('finalColor').value = this.value">
                            <span class="color-dot" style="background:<?= $hex ?>"></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <div class="form-actions">
                <button type="button" class="btn-cancel" onclick="cancelEdit()">Cancel</button>
                <button type="submit" class="btn-add" id="submitBtn">Add Network Card</button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <p class="section-title">📋 Saved Networks (Drag to Reorder)</p>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:40px"></th>
                    <th>SSID</th>
                    <th>Password</th>
                    <th>Label</th>
                    <th>Security</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="netTable">
            <?php if (empty($networks)): ?>
                <tr class="empty-row"><td colspan="6">No networks saved yet. Add your first network above.</td></tr>
            <?php else: ?>
                <?php foreach ($networks as $n): ?>
                <tr data-id="<?= htmlspecialchars($n['id']) ?>">
                    <td><span class="drag-handle" title="Drag to reorder">⠿</span></td>
                    <td class="ssid-cell">
                        <?php 
                            $c_hex = $n['color'] ?? '#4f46e5';
                            if (isset($old_color_map[$c_hex])) $c_hex = $old_color_map[$c_hex];
                        ?>
                        <span class="color-swatch" style="background:<?= htmlspecialchars($c_hex) ?>"></span>
                        <?= htmlspecialchars($n['ssid']) ?>
                    </td>
                    <td class="pass-cell"><?= htmlspecialchars($n['password'] ?: '(Open Network)') ?></td>
                    <td><?= htmlspecialchars($n['label'] ?: '—') ?></td>
                    <td><span class="badge"><?= htmlspecialchars($n['type'] ?? 'WPA') ?></span></td>
                    <td>
                        <div style="display:flex;gap:6px;">
                            <button type="button" class="btn-edit" onclick='editNetwork(this.dataset.net)' data-net='<?= htmlspecialchars(json_encode($n), ENT_QUOTES, "UTF-8") ?>'>Edit</button>
                            <form method="post" action="admin.php" onsubmit="return confirm('Delete this network card?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= htmlspecialchars($n['id']) ?>">
                                <button type="submit" class="btn-del">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Backup & Restore -->
    <p class="section-title">📦 Backup & Data Transfer</p>
    <div class="backup-card">
        <div class="backup-info">
            <h3>Export or Import JSON Data</h3>
            <p>Save a local backup file or restore networks from an existing data.json</p>
        </div>
        <div class="backup-actions">
            <a href="admin.php?export=1" class="btn-secondary">📥 Export JSON</a>
            <form method="post" action="admin.php" enctype="multipart/form-data" style="display:inline-flex;">
                <input type="hidden" name="action" value="import">
                <input type="file" name="import_file" id="importFileInput" accept=".json" style="display:none" onchange="if(confirm('Importing will replace current data.json. Continue?')) this.form.submit()">
                <button type="button" class="btn-secondary" onclick="document.getElementById('importFileInput').click()">📤 Import JSON</button>
            </form>
        </div>
    </div>
</main>

<script>
// Edit logic
function editNetwork(jsonStr) {
    const n = JSON.parse(jsonStr);
    document.getElementById('formAction').value = 'edit';
    document.getElementById('formId').value = n.id;
    document.querySelector('input[name="ssid"]').value = n.ssid;
    document.querySelector('input[name="password"]').value = n.password;
    document.querySelector('select[name="type"]').value = n.type || 'WPA';
    document.querySelector('input[name="label"]').value = n.label || '';
    
    let colorVal = n.color || 'random';
    const colorOpt = document.querySelector('input[name="color_opt"][value="' + colorVal + '"]');
    if (colorOpt) {
        colorOpt.checked = true;
        document.getElementById('finalColor').value = colorVal;
    } else {
        document.getElementById('optCustom').checked = true;
        document.getElementById('finalColor').value = colorVal;
        document.getElementById('customDot').style.background = colorVal;
        document.getElementById('colorInput').value = colorVal;
    }
    
    document.getElementById('submitBtn').textContent = 'Save Changes';
    document.querySelector('.btn-cancel').style.display = 'inline-block';
    document.querySelector('.section-title.form-title').textContent = '✏️ Edit Network';
    window.scrollTo({top: 0, behavior: 'smooth'});
}

function cancelEdit() {
    document.getElementById('formAction').value = 'add';
    document.getElementById('formId').value = '';
    document.getElementById('editForm').reset();
    document.querySelector('input[name="color_opt"][value="random"]').checked = true;
    document.getElementById('finalColor').value = 'random';
    document.getElementById('customDot').style.background = 'conic-gradient(red, yellow, lime, aqua, blue, magenta, red)';
    document.getElementById('submitBtn').textContent = 'Add Network Card';
    document.querySelector('.btn-cancel').style.display = 'none';
    document.querySelector('.section-title.form-title').textContent = '➕ Add New Network';
}

// Simple drag-to-reorder
(function() {
    const tbody = document.getElementById('netTable');
    if (!tbody) return;
    let dragged = null;

    tbody.addEventListener('dragstart', e => {
        dragged = e.target.closest('tr');
        if (dragged) { dragged.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; }
    });
    tbody.addEventListener('dragend', e => {
        const row = e.target.closest('tr');
        if (row) row.classList.remove('dragging');
        saveOrder();
    });
    tbody.addEventListener('dragover', e => {
        e.preventDefault();
        const over = e.target.closest('tr');
        if (over && over !== dragged && over.dataset.id) {
            const rows = [...tbody.querySelectorAll('tr[data-id]')];
            const di = rows.indexOf(dragged), oi = rows.indexOf(over);
            tbody.insertBefore(dragged, di < oi ? over.nextSibling : over);
        }
    });

    tbody.querySelectorAll('tr[data-id]').forEach(row => {
        row.draggable = true;
    });

    function saveOrder() {
        const ids = [...tbody.querySelectorAll('tr[data-id]')].map(r => r.dataset.id);
        fetch('admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=reorder&order=' + encodeURIComponent(JSON.stringify(ids))
        });
    }
})();
</script>

<?php endif; ?>
</body>
</html>
