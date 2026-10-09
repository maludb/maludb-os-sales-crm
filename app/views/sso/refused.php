<?php /** The one refusal page — never says why. */ ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign-on link expired · <?= e(app_name()) ?></title>
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
                    <div class="card-body p-sm-5 text-center" id="sso-refused">
                        <h4 class="fw-bold mb-3">This sign-on link has expired.</h4>
                        <p class="text-muted">Open <?= e(app_name()) ?> from <?= e(parse_url((string) env('OS_LAUNCHER_URL', ''), PHP_URL_HOST) ?: 'the launcher') ?> again.</p>
                        <a href="<?= e(launcher_url('app=' . rawurlencode(app_key()))) ?>" class="btn btn-primary mt-2" id="sso-refused-launcher-btn">Go to the launcher</a>
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
