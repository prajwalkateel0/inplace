<?php
// includes/auth.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Call this at the top of every protected page.
 * $requiredRole = 'student' | 'tutor' | 'admin' | 'provider' | null (any logged-in user)
 */
function requireAuth($requiredRole = null) {
    if (empty($_SESSION['user'])) {
        header("Location: /inplace/index.php");
        exit;
    }
    if ($requiredRole && $_SESSION['user']['role'] !== $requiredRole) {
        // Wrong role — send them to their own dashboard
        header("Location: /inplace/dashboard.php");
        exit;
    }
}

// Handy shortcut helpers so you don't repeat $_SESSION['user'][...] everywhere
function authUser()     { return $_SESSION['user'] ?? null; }
function authId()       { return $_SESSION['user']['id'] ?? null; }
function authRole()     { return $_SESSION['user']['role'] ?? null; }
function authName()     { return $_SESSION['user']['full_name'] ?? ''; }
function authInitials() { return $_SESSION['user']['avatar_initials'] ?? 'U'; }