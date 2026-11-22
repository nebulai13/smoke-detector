<?php

declare(strict_types=1);

// Front controller of the API. The web server sends every request below /api to this file.
//
// It is deliberately empty of logic, because it lives in two places (HLD §8):
//   repository:  backend/public/index.php            → vendor is ../vendor
//   release:     public/api/index.php (document root) → vendor is ../../app/vendor
// Everything it has to do is in SmokeDetector\Http\FrontController.

use SmokeDetector\Http\FrontController;

// Before anything else can fail: PHP never prints an error into a response of this API.
ini_set('display_errors', '0');

foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../app/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require $autoload;
        (new FrontController())->run();

        return;
    }
}

// The release is incomplete. Answer in the format of every other error, and say nothing more.
http_response_code(500);
header('Content-Type: application/problem+json');
header('Cache-Control: no-store');
echo '{"type":"internal_error","title":"Internal server error","status":500}';
