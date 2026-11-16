<?php
// Shared <head> and top bar. $page: 'dash' (default) or 'status'.
use App\Http;

$page ??= 'dash';
$css = '/assets/app.css?v=' . filemtime(APP_ROOT . '/public/assets/app.css');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="dark light">
<meta name="referrer" content="no-referrer">
<title><?= $page === 'status' ? 'Status · ' : '' ?>AdGuard Stats</title>
<link rel="icon" href="/assets/logo.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= Http::e($css) ?>">
</head>
<body>
<header class="top">
  <div class="top-inner">
    <a class="brand" href="/"><img src="/assets/logo.svg" alt="" width="26" height="26"><span>AdGuard <b>Stats</b></span></a>
    <?php if ($page === 'dash'): ?>
    <nav class="nav" aria-label="Filters">
      <div class="seg" role="group" aria-label="Range">
        <button type="button" data-preset="today">Today</button>
        <button type="button" data-preset="week">Week</button>
        <button type="button" data-preset="month">Month</button>
      </div>
      <div class="range">
        <input type="date" id="from" aria-label="From date"><span aria-hidden="true">–</span><input type="date" id="to" aria-label="To date">
      </div>
      <select id="client" aria-label="Client"><option value="">All clients</option></select>
    </nav>
    <?php endif; ?>
    <a class="nav-link<?= $page === 'status' ? ' active' : '' ?>" href="/status.php">Status</a>
  </div>
</header>
