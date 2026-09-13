<?php

declare(strict_types=1);

$returnToBilling = (string)($_GET['return'] ?? '') === 'billing';
$returnHref = $returnToBilling ? 'billing.php' : './';
$returnLabel = $returnToBilling ? 'Return to Venue Billing' : 'Return to FourBag';
$contextCopy = $returnToBilling
    ? 'Your host-fee invoice will update from Stripe’s verified server-to-server confirmation.'
    : 'Your order and any included league registration update from Stripe’s verified server-to-server confirmation.';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FourBag Payment Confirmation</title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<header class="topbar"><div><strong class="brand">FOURBAG</strong><span class="tag">Payment</span></div><nav><a href="<?= htmlspecialchars($returnHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') ?></a></nav></header>
<main>
<section class="section" style="max-width:760px;margin:70px auto">
<article class="card">
<span class="eyebrow">PAYMENT RECEIVED</span>
<h1>Thanks. We’re confirming your FourBag payment.</h1>
<p><?= htmlspecialchars($contextCopy, ENT_QUOTES, 'UTF-8') ?> This browser page is not treated as proof of payment.</p>
<div class="actions"><a class="btn primary" href="<?= htmlspecialchars($returnHref, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($returnLabel, ENT_QUOTES, 'UTF-8') ?></a></div>
</article>
</section>
</main>
</body>
</html>
