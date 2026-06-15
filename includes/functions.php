<?php
// includes/functions.php
function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function badge_class_for_status(string $status): string {
  return match ($status) {
    'approved', 'active', 'completed' => 'badge-approved',
    'rejected', 'terminated' => 'badge-rejected',
    'awaiting_provider', 'awaiting_tutor', 'submitted' => 'badge-pending',
    default => 'badge-open',
  };
}

function initials(string $fullName): string {
  $parts = preg_split('/\s+/', trim($fullName));
  $a = strtoupper(substr($parts[0] ?? '', 0, 1));
  $b = strtoupper(substr($parts[1] ?? '', 0, 1));
  $ini = ($a . $b);
  return $ini !== '' ? $ini : 'U';
}

function role_label(string $role): string {
  return match ($role) {
    'student' => 'Student',
    'tutor' => 'Placement Tutor',
    'provider' => 'Placement Provider',
    'admin' => 'Administrator',
    default => ucfirst($role),
  };
}