<?php
/* =========================================================
   TIMETABLE COMMITTEE LOGIN — MIT ADT Smart Timetable System
   ---------------------------------------------------------
   BACKEND NOTE (read before editing):
   There was no existing "Committee" login anywhere in the
   system — only admin_users / $_SESSION['admin'] existed.
   To give committee members their own portal without touching
   any admin logic, this file:
     - reuses the same DB connection pattern as index.php
     - creates a new table `committee_users` (only if it does
       not already exist) with: username, password_hash,
       full_name, department
     - authenticates with the exact same method as the admin
       login (sha256 password hash, prepared statement)
     - uses its own session key, $_SESSION['committee'], so it
       can never be confused with $_SESSION['admin']
   No existing table, query, or session variable is modified.
   Adjust the redirect target at the bottom of the login block
   if committee members should land somewhere other than the
   main portal dashboard.
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
function ct_table_exists($conn, $table){
    $safe = $conn->real_escape_string($table);
    $res = $conn->query("SHOW TABLES LIKE '$safe'");
    return $res && $res->num_rows > 0;
}

/* Create the committee_users table on first run only */
if(!ct_table_exists($conn, 'committee_users')){
    @$conn->query("
        CREATE TABLE IF NOT EXISTS committee_users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            full_name VARCHAR(150) NULL,
            department VARCHAR(50) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

/* ---------------------------------------------------------
   Ensure a default Timetable Committee account exists.
   Existence check first — never creates duplicates, never
   overwrites/deletes any existing committee_users row.
   Same sha256 hashing already used by the login query above.
   --------------------------------------------------------- */
$defaultCommitteeUser = "committee";
$check = $conn->prepare("SELECT id FROM committee_users WHERE username = ?");
$check->bind_param("s", $defaultCommitteeUser);
$check->execute();
if($check->get_result()->num_rows === 0){
    $defaultHash = hash("sha256", "committee123");
    $defaultName = "Timetable Committee";
    $defaultDept = "School of Computing";
    $ins = $conn->prepare("INSERT INTO committee_users (username, password_hash, full_name, department) VALUES (?, ?, ?, ?)");
    $ins->bind_param("ssss", $defaultCommitteeUser, $defaultHash, $defaultName, $defaultDept);
    $ins->execute();
}
$check->close();

/* Redirect straight to the portal if already logged in */
if(isset($_SESSION['committee'])){
    header("Location: index.php?view=dashboard");
    exit;
}

$message = "";
$messageType = "";

if(isset($_POST['login'])){
    $username = $_POST['username'] ?? '';
    $passwordHash = hash("sha256", $_POST['password'] ?? '');

    $stmt = $conn->prepare("SELECT * FROM committee_users WHERE username=? AND password_hash=?");
    $stmt->bind_param("ss", $username, $passwordHash);
    $stmt->execute();
    $result = $stmt->get_result();

    if($result->num_rows > 0){
        $row = $result->fetch_assoc();
        $_SESSION['committee'] = $username;
        $_SESSION['committee_name'] = $row['full_name'] ?? $username;
        $_SESSION['committee_department'] = $row['department'] ?? '';
        header("Location: index.php?view=dashboard");
        exit;
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
<title>Timetable Committee Portal — MIT ADT Smart Timetable System</title>
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
                    <linearGradient id="bld2" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#1a0033" stop-opacity=".55"/>
                        <stop offset="1" stop-color="#0e001d" stop-opacity=".85"/>
                    </linearGradient>
                </defs>
                <rect x="0" y="230" width="1200" height="130" fill="url(#bld2)"/>
                <rect x="40" y="150" width="70" height="180" fill="url(#bld2)"/>
                <rect x="130" y="190" width="55" height="140" fill="url(#bld2)"/>
                <rect x="950" y="170" width="60" height="160" fill="url(#bld2)"/>
                <rect x="1030" y="200" width="80" height="130" fill="url(#bld2)"/>
                <path d="M480 330 L480 230 Q480 150 600 150 Q720 150 720 230 L720 330 Z" fill="url(#bld2)"/>
                <path d="M540 150 Q600 60 660 150 Z" fill="url(#bld2)"/>
                <rect x="592" y="30" width="16" height="35" fill="url(#bld2)"/>
                <circle cx="600" cy="24" r="7" fill="url(#bld2)"/>
                <rect x="440" y="270" width="18" height="60" fill="url(#bld2)"/>
                <rect x="742" y="270" width="18" height="60" fill="url(#bld2)"/>
                <g fill="url(#bld2)">
                    <rect x="500" y="250" width="10" height="80"/>
                    <rect x="530" y="250" width="10" height="80"/>
                    <rect x="560" y="250" width="10" height="80"/>
                    <rect x="600" y="250" width="10" height="80"/>
                    <rect x="640" y="250" width="10" height="80"/>
                    <rect x="670" y="250" width="10" height="80"/>
                    <rect x="700" y="250" width="10" height="80"/>
                </g>
                <!-- small clock motif above the dome to signal scheduling -->
                <circle cx="600" cy="20" r="0" fill="none"/>
            </svg>
        </div>

        <div class="hero-content">
            <span class="hero-badge"><span class="dot"></span> Committee Access</span>
            <h1 class="hero-title">Shape the department timetable, together.</h1>
            <p class="hero-sub">Coordinate scheduling, faculty allocation and academic resources for your department as part of the Timetable Committee.</p>

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
                <div class="icon-badge">📅</div>
                <h1>Timetable Committee Portal</h1>
                <p>Sign in to manage timetable scheduling and departmental academic resources.</p>
            </div>

            <form class="auth-form" method="POST" autocomplete="on" data-auth-form novalidate>
                <div class="field-group">
                    <span class="field-icon">👤</span>
                    <input type="text" id="committee_username" name="username" placeholder=" " required autocomplete="username">
                    <label for="committee_username">Username</label>
                </div>

                <div class="field-group">
                    <span class="field-icon">🔒</span>
                    <input type="password" id="committee_password" name="password" placeholder=" " required autocomplete="current-password" data-caps-check="#committeeCapsWarning">
                    <label for="committee_password">Password</label>
                    <button type="button" class="field-toggle" data-toggle-password="committee_password" aria-label="Show password">👁</button>
                </div>
                <div class="caps-warning" id="committeeCapsWarning">⚠ Caps Lock is on</div>

                <div class="field-row">
                    <label class="remember-check">
                        <input type="checkbox" name="remember">
                        Remember me
                    </label>
                    <a class="forgot-link" href="mailto:helpdesk@mitadt.edu.in?subject=Committee%20Portal%20Password%20Reset">Forgot password?</a>
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
                    <div class="ic">🏫</div>
                    <div class="ic-label">Department<br>Access</div>
                </div>
                <div class="info-card">
                    <div class="ic">🗓️</div>
                    <div class="ic-label">Timetable<br>Planning</div>
                </div>
                <div class="info-card">
                    <div class="ic">🤝</div>
                    <div class="ic-label">Faculty<br>Coordination</div>
                </div>
            </div>

            <div class="portal-switch">
                System Administrator? <a href="admin_login.php">Sign in here</a>
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
