<?php /** The unsubscribe door's page (a stranger reads it; no menu): ask — the masked address and ONE button; done — they will not be emailed; invalid — the link is not good. Data: state, address */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><meta name="referrer" content="no-referrer">
    <title>Unsubscribe · <?= e(business_name(db())) ?></title>
    <link rel="shortcut icon" type="image/png" href="/assets/images/favicon.png">
    <link rel="stylesheet" type="text/css" href="/assets/css/bootstrap.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/vendors/css/vendors.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/theme.min.css">
    <link rel="stylesheet" type="text/css" href="/assets/css/app-overrides.css">
</head>
<body>
    <main class="auth-minimal-wrapper">
        <div class="auth-minimal-inner">
            <div class="minimal-card-wrapper">
                <div class="card mb-4 mt-5 mx-4 mx-sm-0 position-relative">
                    <div class="wd-50 bg-white p-2 rounded-circle shadow-lg position-absolute translate-middle top-0 start-50">
                        <img src="/assets/images/logo-abbr.png" alt="" class="img-fluid">
                    </div>
                    <div class="card-body p-sm-5 text-center" id="unsubscribe">
                    <?php if ($state === 'ask'): ?>
                        <h4 class="fw-bold mb-3" id="unsubscribe-title">Stop emails from <?= e(business_name(db())) ?>?</h4>
                        <p class="text-muted" id="unsubscribe-address">We will stop writing to <strong><?= e($address) ?></strong>. You can still reply to anything you have already received.</p>
                        <form method="post" id="unsubscribe-form"><?= csrf_field() ?><button type="submit" class="btn btn-primary btn-lg mt-2" id="unsubscribe-btn">Unsubscribe me</button></form>
                    <?php elseif ($state === 'done'): ?>
                        <h4 class="fw-bold mb-3" id="unsubscribe-title">You are unsubscribed.</h4>
                        <p class="text-muted" id="unsubscribe-done"><?= e(business_name(db())) ?> will not email <strong><?= e($address) ?></strong> again.</p>
                    <?php else: ?>
                        <h4 class="fw-bold mb-3" id="unsubscribe-title">This link is not good.</h4>
                        <p class="text-muted" id="unsubscribe-invalid">It may have been copied wrongly. Use the link in the email itself, or reply to it and ask to be taken off.</p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>
    <script src="/assets/vendors/js/vendors.min.js"></script>
    <script src="/assets/js/common-init.min.js"></script>
    <script src="/assets/js/theme-customizer-init.min.js"></script>
</body>
</html>
