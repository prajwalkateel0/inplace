<?php
require_once '../includes/auth.php';
require_once '../config/db.php';
require_once '../config/app_config.php';

requireAuth('admin');

$pageTitle    = 'Settings';
$pageSubtitle = 'API & email configuration';
$activePage   = 'settings';
$userId       = authId();
$unreadCount  = 0;
$pendingRequests = 0;

// ── Default values (used on first install) ───────────────────────────────
$defaults = [
    // Gmail SMTP
    'smtp_host'             => 'smtp.gmail.com',
    'smtp_port'             => '587',
    'smtp_user'             => '',
    'smtp_pass'             => '',
    'from_email'            => '',
    'from_name'             => 'InPlace',
    // reCAPTCHA v2
    'recaptcha_site_key'    => '',
    'recaptcha_secret_key'  => '',
    // Google Calendar
    'google_calendar_key'   => '',
    'google_calendar_client_id'     => '',
    'google_calendar_client_secret' => '',
    // Leaflet
    'leaflet_tile_url'      => 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
];

// ── Pre-populate DB with defaults if keys are missing ────────────────────
$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO system_settings (setting_key, setting_value, updated_at)
    VALUES (?, ?, NOW())
");
foreach ($defaults as $k => $v) {
    $insertStmt->execute([$k, $v]);
}

$actionMsg  = '';
$actionType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $allowed = array_keys($defaults);
    try {
        $upsert = $pdo->prepare("
            INSERT INTO system_settings (setting_key, setting_value, updated_at)
            VALUES (?, ?, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");
        foreach ($allowed as $key) {
            $value = trim($_POST[$key] ?? '');
            $upsert->execute([$key, $value]);
        }
        // Reload config cache
        global $_APP_CONFIG;
        $_APP_CONFIG = null;
        loadAppConfig($pdo);

        $actionMsg  = "Settings saved successfully!";
        $actionType = 'success';
    } catch (Exception $e) {
        $actionMsg  = "Error saving settings: " . $e->getMessage();
        $actionType = 'danger';
    }
}

// ── Load current settings ─────────────────────────────────────────────────
loadAppConfig($pdo);

function s(string $key, string $default = ''): string {
    return htmlspecialchars(appConfig($key, $default));
}
?>
<?php include '../includes/header.php'; ?>

<div class="main">
    <?php include '../includes/topbar.php'; ?>

    <div class="page-content">

        <?php if ($actionMsg): ?>
        <div style="background:var(--<?= $actionType ?>-bg);
                    border:1px solid <?= $actionType==='success'?'#6ee7b7':'#fca5a5' ?>;
                    border-radius:var(--radius);padding:1.25rem 2rem;margin-bottom:1.5rem;">
            <p style="color:var(--<?= $actionType ?>);font-weight:500;"><?= htmlspecialchars($actionMsg) ?></p>
        </div>
        <?php endif; ?>

        <form method="POST">

            <!-- ── Gmail SMTP ─────────────────────────────────────────── -->
            <div class="panel" style="margin-bottom:2rem;">
                <div class="panel-header">
                    <div>
                        <h3>Gmail SMTP — Email Configuration</h3>
                        <p style="color:var(--muted);font-size:0.875rem;margin:0;">
                            Used for all outgoing emails (OTP, registration approval, notifications).
                            Requires a Gmail App Password — not your account password.
                        </p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="form-grid">

                        <div class="form-group">
                            <label>SMTP Host</label>
                            <input type="text" name="smtp_host"
                                   value="<?= s('smtp_host', 'smtp.gmail.com') ?>"
                                   placeholder="smtp.gmail.com">
                        </div>

                        <div class="form-group">
                            <label>SMTP Port</label>
                            <input type="number" name="smtp_port"
                                   value="<?= s('smtp_port', '587') ?>"
                                   placeholder="587">
                            <small style="color:var(--muted);">587 for STARTTLS (recommended)</small>
                        </div>

                        <div class="form-group">
                            <label>Gmail Address (Username)</label>
                            <input type="email" name="smtp_user"
                                   value="<?= s('smtp_user') ?>"
                                   placeholder="yourapp@gmail.com">
                        </div>

                        <div class="form-group">
                            <label>Gmail App Password</label>
                            <input type="password" name="smtp_pass"
                                   value="<?= s('smtp_pass') ?>"
                                   placeholder="xxxx xxxx xxxx xxxx"
                                   autocomplete="new-password">
                            <small style="color:var(--muted);">
                                Generate at
                                <a href="https://myaccount.google.com/apppasswords" target="_blank"
                                   style="color:var(--navy);">Google Account → App Passwords</a>
                            </small>
                        </div>

                        <div class="form-group">
                            <label>From Email</label>
                            <input type="email" name="from_email"
                                   value="<?= s('from_email') ?>"
                                   placeholder="noreply@yourapp.com">
                        </div>

                        <div class="form-group">
                            <label>From Name</label>
                            <input type="text" name="from_name"
                                   value="<?= s('from_name', 'InPlace') ?>"
                                   placeholder="InPlace">
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── reCAPTCHA v2 ──────────────────────────────────────── -->
            <div class="panel" style="margin-bottom:2rem;">
                <div class="panel-header">
                    <div>
                        <h3>Google reCAPTCHA v2</h3>
                        <p style="color:var(--muted);font-size:0.875rem;margin:0;">
                            Protects the login page from bots. Get keys at
                            <a href="https://www.google.com/recaptcha/admin/create" target="_blank"
                               style="color:var(--navy);">google.com/recaptcha</a>
                            — select <strong>reCAPTCHA v2 "I'm not a robot"</strong>.
                        </p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="form-grid">

                        <div class="form-group">
                            <label>Site Key <span style="color:var(--muted);font-weight:400;">(public — used in HTML)</span></label>
                            <input type="text" name="recaptcha_site_key"
                                   value="<?= s('recaptcha_site_key') ?>"
                                   placeholder="6Lc...">
                        </div>

                        <div class="form-group">
                            <label>Secret Key <span style="color:var(--muted);font-weight:400;">(server-side verification)</span></label>
                            <input type="password" name="recaptcha_secret_key"
                                   value="<?= s('recaptcha_secret_key') ?>"
                                   placeholder="6Lc..."
                                   autocomplete="new-password">
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── Google Calendar API ───────────────────────────────── -->
            <div class="panel" style="margin-bottom:2rem;">
                <div class="panel-header">
                    <div>
                        <h3>Google Calendar API</h3>
                        <p style="color:var(--muted);font-size:0.875rem;margin:0;">
                            Used to sync placement visits and meetings. Get credentials at
                            <a href="https://console.cloud.google.com/apis/credentials" target="_blank"
                               style="color:var(--navy);">Google Cloud Console</a>
                            — enable the Calendar API and create an OAuth 2.0 Client ID.
                        </p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="form-grid">

                        <div class="form-group">
                            <label>API Key</label>
                            <input type="password" name="google_calendar_key"
                                   value="<?= s('google_calendar_key') ?>"
                                   placeholder="AIza..."
                                   autocomplete="new-password">
                        </div>

                        <div class="form-group">
                            <label>OAuth Client ID</label>
                            <input type="text" name="google_calendar_client_id"
                                   value="<?= s('google_calendar_client_id') ?>"
                                   placeholder="xxxxxxxx.apps.googleusercontent.com">
                        </div>

                        <div class="form-group full-col">
                            <label>OAuth Client Secret</label>
                            <input type="password" name="google_calendar_client_secret"
                                   value="<?= s('google_calendar_client_secret') ?>"
                                   placeholder="GOCSPX-..."
                                   autocomplete="new-password">
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── Leaflet Map ───────────────────────────────────────── -->
            <div class="panel" style="margin-bottom:2rem;">
                <div class="panel-header">
                    <div>
                        <h3>Leaflet.js Map — Tile URL</h3>
                        <p style="color:var(--muted);font-size:0.875rem;margin:0;">
                            The tile layer URL used by the placement map view.
                            Default is OpenStreetMap (free, no key required).
                            Use <code>{s}</code>, <code>{z}</code>, <code>{x}</code>, <code>{y}</code> placeholders.
                        </p>
                    </div>
                </div>
                <div class="panel-body">
                    <div class="form-group">
                        <label>Tile URL</label>
                        <input type="text" name="leaflet_tile_url"
                               value="<?= s('leaflet_tile_url', 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png') ?>"
                               placeholder="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png"
                               style="font-family:'DM Mono',monospace;font-size:0.875rem;">
                        <small style="color:var(--muted);">
                            Alternatives: CartoDB Light —
                            <code style="font-size:0.8rem;">https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png</code>
                        </small>
                    </div>
                </div>
            </div>

            <div style="display:flex;justify-content:flex-end;gap:1rem;padding-bottom:2rem;">
                <a href="/inplace/admin/dashboard.php" class="btn btn-ghost">Cancel</a>
                <button type="submit" name="save_settings" class="btn btn-primary">Save Settings</button>
            </div>

        </form>

    </div>
</div>

<?php include '../includes/footer.php'; ?>
