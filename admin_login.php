<?php
/* =========================================================
   ADMIN LOGIN — MIT ADT Smart Timetable & Resource Management
   ---------------------------------------------------------
   BACKEND NOTE (read before editing):
   The authentication block below is copied exactly from the
   existing admin login in index.php:
     - same DB connection variables ($host,$user,$password,$database)
     - same query: SELECT * FROM admin_users WHERE username=? AND password_hash=?
     - same sha256 password hashing
     - same session key: $_SESSION['admin']
   Nothing about how a login is validated has changed. The only
   addition is a redirect to index.php once $_SESSION['admin'] is
   set, since this is now a dedicated login page instead of an
   inline panel. If you don't want the redirect, remove the
   header()/exit block marked below.
   ========================================================= */
session_start();

$conn = new mysqli(
    "sql209.infinityfree.com",
    "if0_42102472",
    "qfHgzrTdk9BM",
    "if0_42102472_university_timetable"
);
if ($conn->connect_error) die("Database connection failed: " . $conn->connect_error);

function e($v){ return htmlspecialchars($v ?? '', ENT_QUOTES); }

/* ---------------------------------------------------------
   Ensure a default Administrator account exists.
   - Does NOT create/alter the admin_users table structure —
     that table already exists in the live system and this
     file only ever SELECTs from it (unchanged above).
   - Only runs if admin_users exists.
   - Uses an existence check (not blind INSERT) so it never
     creates duplicates and never touches other admin rows.
   - Same sha256 hashing already used by the login query.
   --------------------------------------------------------- */
function admin_table_exists($conn, $table){
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

if(admin_table_exists($conn, 'admin_users')){
    $check = $conn->prepare("SELECT id FROM admin_users WHERE username = ?");
    $defaultAdminUser = "admin";
    $check->bind_param("s", $defaultAdminUser);
    $check->execute();
    if($check->get_result()->num_rows === 0){
        $defaultHash = hash("sha256", "admin123");
        $defaultName = "System Administrator";
        /* full_name column may or may not exist on every install;
           try the richer insert first, fall back if it fails */
        $ins = @$conn->prepare("INSERT INTO admin_users (username, password_hash, full_name) VALUES (?, ?, ?)");
        if($ins){
            $ins->bind_param("sss", $defaultAdminUser, $defaultHash, $defaultName);
            @$ins->execute();
        } else {
            $ins2 = $conn->prepare("INSERT INTO admin_users (username, password_hash) VALUES (?, ?)");
            $ins2->bind_param("ss", $defaultAdminUser, $defaultHash);
            $ins2->execute();
        }
    }
    $check->close();
}

/* Redirect straight to the dashboard if already logged in */
if(isset($_SESSION['admin'])){
    header("Location: index.php?view=admin_dashboard");
    exit;
}

$message = "";
$messageType = "";

if(isset($_POST['login'])){
    $username = $_POST['username'] ?? '';
    $password = hash("sha256", $_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM admin_users WHERE username=? AND password_hash=?");
    $stmt->bind_param("ss", $username, $password);
    $stmt->execute();

    if($stmt->get_result()->num_rows > 0){
        $_SESSION['admin'] = $username;
        /* ---- redirect added for the dedicated login page ---- */
        header("Location: index.php?view=admin_dashboard");
        exit;
        /* ------------------------------------------------------ */
    } else {
        $message = "Invalid username or password. Please try again.";
        $messageType = "error";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administrator Portal — MIT ADT Smart Timetable System</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&family=Cinzel:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/login.css">
</head>
<body>

<div class="auth-shell">

    <!-- ================= LEFT — HERO ================= -->
    <section class="auth-hero" aria-hidden="true">
        <div class="hero-glow g1"></div>
        <div class="hero-glow g2"></div>
        <div class="hero-glow g3"></div>
        <canvas id="heroParticles"></canvas>
        <div class="hero-overlay"></div>

        <div class="hero-illustration">
            <svg viewBox="0 0 1200 360" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <linearGradient id="bld1" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#1a0033" stop-opacity=".55"/>
                        <stop offset="1" stop-color="#0e001d" stop-opacity=".85"/>
                    </linearGradient>
                </defs>
                <rect x="0" y="230" width="1200" height="130" fill="url(#bld1)"/>
                <rect x="40" y="150" width="70" height="180" fill="url(#bld1)"/>
                <rect x="130" y="190" width="55" height="140" fill="url(#bld1)"/>
                <rect x="950" y="170" width="60" height="160" fill="url(#bld1)"/>
                <rect x="1030" y="200" width="80" height="130" fill="url(#bld1)"/>
                <!-- central dome silhouette -->
                <path d="M480 330 L480 230 Q480 150 600 150 Q720 150 720 230 L720 330 Z" fill="url(#bld1)"/>
                <path d="M540 150 Q600 60 660 150 Z" fill="url(#bld1)"/>
                <rect x="592" y="30" width="16" height="35" fill="url(#bld1)"/>
                <circle cx="600" cy="24" r="7" fill="url(#bld1)"/>
                <rect x="440" y="270" width="18" height="60" fill="url(#bld1)"/>
                <rect x="742" y="270" width="18" height="60" fill="url(#bld1)"/>
                <!-- columns -->
                <g fill="url(#bld1)">
                    <rect x="500" y="250" width="10" height="80"/>
                    <rect x="530" y="250" width="10" height="80"/>
                    <rect x="560" y="250" width="10" height="80"/>
                    <rect x="600" y="250" width="10" height="80"/>
                    <rect x="640" y="250" width="10" height="80"/>
                    <rect x="670" y="250" width="10" height="80"/>
                    <rect x="700" y="250" width="10" height="80"/>
                </g>
            </svg>
        </div>

        <div class="hero-content">
            <span class="hero-badge"><span class="dot"></span> Administrator Access</span>
            <h1 class="hero-title">Run the entire Smart Timetable &amp; Resource System from one console.</h1>
            <p class="hero-sub">Master data, divisions, faculty, physical resources and every timetable across the School of Computing — governed centrally, updated instantly.</p>

            <div class="hero-footer-brand">
                <div class="l1">MIT Art, Design &amp; Technology University</div>
                <div class="l2">School of Computing</div>
                <div class="l3">Smart Timetable &amp; Resource Management System</div>
            </div>
        </div>
    </section>

    <!-- ================= RIGHT — FORM ================= -->
    <section class="auth-panel">
        <div class="auth-card">

            <div class="auth-logo">
                <img src="assets/mitadt_logo.jpg" alt="MIT ADT University" onerror="this.style.display='none'">
            </div>

            <div class="auth-identity">
                <div class="icon-badge">🛡️</div>
                <h1>Administrator Portal</h1>
                <p>Sign in to manage the complete Smart Timetable &amp; Resource Management System.</p>
            </div>

            <form class="auth-form" method="POST" autocomplete="on" data-auth-form novalidate>
                <div class="field-group">
                    <span class="field-icon">👤</span>
                    <input type="text" id="admin_username" name="username" placeholder=" " required autocomplete="username">
                    <label for="admin_username">Username</label>
                </div>

                <div class="field-group">
                    <span class="field-icon">🔒</span>
                    <input type="password" id="admin_password" name="password" placeholder=" " required autocomplete="current-password" data-caps-check="#adminCapsWarning">
                    <label for="admin_password">Password</label>
                    <button type="button" class="field-toggle" data-toggle-password="admin_password" aria-label="Show password">👁</button>
                </div>
                <div class="caps-warning" id="adminCapsWarning">⚠ Caps Lock is on</div>

                <div class="field-row">
                    <label class="remember-check">
                        <input type="checkbox" name="remember">
                        Remember me
                    </label>
                    <a class="forgot-link" href="mailto:helpdesk@mitadt.edu.in?subject=Admin%20Portal%20Password%20Reset">Forgot password?</a>
                </div>

                <button type="submit" name="login" class="auth-submit">
                    <span class="btn-label">
                        <span class="spinner"></span>
                        <span class="btn-text">Sign In</span>
                    </span>
                </button>
            </form>

            <div class="auth-info-cards">
                <div class="info-card">
                    <div class="ic">🛡️</div>
                    <div class="ic-label">Secure<br>Authentication</div>
                </div>
                <div class="info-card">
                    <div class="ic">⚙️</div>
                    <div class="ic-label">Full System<br>Control</div>
                </div>
                <div class="info-card">
                    <div class="ic">🏛️</div>
                    <div class="ic-label">University<br>Administration</div>
                </div>
            </div>

            <div class="portal-switch">
                Timetable Committee member? <a href="committee_login.php">Sign in here</a>
            </div>

            <div class="auth-footnote">
                © 2026 MIT Art, Design &amp; Technology University — School of Computing<br>
                Smart Timetable &amp; Resource Management System
            </div>
        </div>
    </section>
</div>

<!-- Toasts -->
<div class="toast-stack">
    <?php if($message){ ?>
    <div class="toast <?= e($messageType) ?>">
        <span class="t-icon"><?= $messageType === 'error' ? '⚠️' : '✅' ?></span>
        <span><?= e($message) ?></span>
    </div>
    <?php } ?>
</div>

<!-- Loading overlay -->
<div class="auth-overlay" id="authOverlay">
    <div class="ov-box">
        <span class="ov-spinner"></span>
        Signing you in…
    </div>
</div>

<script src="assets/js/login.js"></script>
</body>
</html>
