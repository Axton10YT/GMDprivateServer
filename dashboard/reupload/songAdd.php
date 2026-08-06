<?php
session_start();
// error_reporting(0);

include "../../incl/lib/connection.php";
require_once "../incl/dashboardLib.php";
require_once "../../incl/lib/mainLib.php";
require_once "../../incl/lib/songReup.php";

$dl = new dashboardLib();
$gs = new mainLib();

// 1. Generate CSRF token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$requestUri = htmlspecialchars($_SERVER["REQUEST_URI"], ENT_QUOTES, 'UTF-8');

// 2. Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate CSRF token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
            <p>' . $dl->getLocalizedString("errorGeneric") . ' (Invalid Request Token)</p>
            <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
        exit;
    }

    $rawUrl = trim($_POST["url"] ?? '');

    // Extract Newgrounds ID or numeric song ID from URL
    if (preg_match('/(?:newgrounds\.com\/audio\/listen\/|audio\.ngfiles\.com\/|\/)(\d+)/i', $rawUrl, $matches)) {
        $songID = $matches[1];
    } elseif (is_numeric($rawUrl)) {
        $songID = $rawUrl;
    } else {
        $songID = null;
    }

    if (!$songID) {
        $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
            <p>' . $dl->getLocalizedString("errorGeneric") . ' (Could not parse valid Song ID from URL)</p>
            <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
        exit;
    }

    // 3. Query CDN metadata to form GD string for SongReup
    $cdnInfoUrl = "https://cdn.robtop.net/info/" . rawurlencode((string)$songID);
    $ch = curl_init($cdnInfoUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 4,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'GeometryDash-GDPS/1.0',
    ]);

    $metaJson = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $meta = json_decode($metaJson, true);
    $songName   = $meta['title'] ?? 'Custom Song ' . $songID;
    $authorName = $meta['artist'] ?? 'Unknown';
    $downloadUrl = "https://cdn.robtop.net/" . rawurlencode((string)$songID);

    // Format standard Geometry Dash song metadata string expected by SongReup::reup
    $gdSongString = "1~|~{$songID}~|~2~|~{$songName}~|~3~|~0~|~4~|~{$authorName}~|~5~|~10.00~|~6~|~~|~10~|~{$downloadUrl}~|~7~|~~|~8~|~0";

    // 4. Trigger SongReup
    $reupResult = SongReup::reup($gdSongString);

    if (!$reupResult) {
        $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
            <p>' . $dl->getLocalizedString("errorGeneric") . ' (Failed to upload song to server)</p>
            <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
    } else {
        $safeSongID = htmlspecialchars((string)$songID, ENT_QUOTES, 'UTF-8');
        $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
            <p>Song Reuploaded Successfully! ID: <strong>' . $safeSongID . '</strong></p>
            <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("songAddAnotherBTN") . '</a>', "reupload");
    }
} else {
    // 5. Render Form
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

    $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
        <form action="' . $requestUri . '" method="post">
            <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
            <div class="form-group">
                <label for="urlField">' . $dl->getLocalizedString("songAddUrlFieldLabel") . '</label>
                <input type="text" class="form-control" id="urlField" name="url" placeholder="' . $dl->getLocalizedString("songAddUrlFieldPlaceholder") . '" required autofocus>
            </div>
            <button type="submit" class="btn btn-primary btn-block">' . $dl->getLocalizedString("reuploadBTN") . '</button>
        </form>', "reupload");
}
?>
