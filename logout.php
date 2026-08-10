<?php
/* =========================================================
   LOGOUT — MIT ADT Smart Timetable & Resource Management
   ---------------------------------------------------------
   Works for BOTH session types ($_SESSION['admin'] and
   $_SESSION['committee']) without needing to know which one
   is active. Fully destroys the session, then sends the user
   back to the landing page (index.php).
   ========================================================= */
session_start();

// Clear all session variables
$_SESSION = [];

// Remove the session cookie itself, if the browser set one
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// Destroy the session data server-side
session_destroy();

header("Location: index.php");
exit;
