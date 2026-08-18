#!/usr/bin/env php
<?php
declare(strict_types=1);

define('CRON_MODE', true);
define('RECIPE_BACKEND_TEST_MODE', true);
require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/index.php';

foreach ([
    'GEMINI_API_KEY',
    'GOOGLE_API_KEY',
    'GOOGLE_GENERATIVE_AI_API_KEY',
] as $key) {
    putenv($key);
    unset($_ENV[$key], $_SERVER[$key]);
}
$attachmentDirectory = getenv(
    'EVERSHELF_COPILOT_ATTACHMENT_DIR'
);
if (
    $attachmentDirectory === false
    || !is_dir($attachmentDirectory)
    || !is_writable($attachmentDirectory)
) {
    throw new RuntimeException(
        'writable Copilot attachment directory is required'
    );
}
$GLOBALS['EXPIRY_COPILOT_ATTACHMENT_DIR'] =
    $attachmentDirectory;

$image = imagecreatetruecolor(1200, 420);
$white = imagecolorallocate($image, 255, 255, 255);
$black = imagecolorallocate($image, 0, 0, 0);
imagefilledrectangle($image, 0, 0, 1199, 419, $white);
$font = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';
if (function_exists('imagettftext') && is_file($font)) {
    imagettftext(
        $image,
        72,
        0,
        110,
        165,
        $black,
        $font,
        'BEST BY'
    );
    imagettftext(
        $image,
        96,
        0,
        110,
        315,
        $black,
        $font,
        '12/31/2027'
    );
} else {
    $label = imagecreatetruecolor(300, 105);
    $labelWhite = imagecolorallocate($label, 255, 255, 255);
    $labelBlack = imagecolorallocate($label, 0, 0, 0);
    imagefilledrectangle($label, 0, 0, 299, 104, $labelWhite);
    imagestring($label, 5, 20, 15, 'BEST BY', $labelBlack);
    imagestring($label, 5, 20, 55, '12/31/2027', $labelBlack);
    imagecopyresized(
        $image,
        $label,
        0,
        0,
        0,
        0,
        1200,
        420,
        300,
        105
    );
    imagedestroy($label);
}
ob_start();
imagepng($image);
$bytes = (string)ob_get_clean();
imagedestroy($image);

$result = readExpiryFromImage(base64_encode($bytes));
unset($GLOBALS['EXPIRY_COPILOT_ATTACHMENT_DIR']);
if (
    empty($result['success'])
    || empty($result['found'])
    || (string)($result['source'] ?? '') !== 'copilot_vision'
    || (string)($result['expiry_date'] ?? '') !== '2027-12-31'
) {
    throw new RuntimeException(
        'Copilot expiry vision did not return the expected date: '
        . json_encode($result, JSON_UNESCAPED_SLASHES)
    );
}
echo json_encode([
    'success' => true,
    'provider' => 'copilot_socket',
    'key_environment_empty' => true,
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
