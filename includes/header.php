<?php
// includes/header.php
// auth.php must be included BEFORE this file in every page
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>InPlace — <?= htmlspecialchars($pageTitle ?? 'Dashboard') ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/inplace/assets/css/style.css?v=<?= time() ?>">
</head>
<body>

<div class="app active">   <!-- ← THIS OPENS the whole app layout -->

  <?php include __DIR__ . '/sidebar.php'; ?>  <!-- ← sidebar goes INSIDE .app -->