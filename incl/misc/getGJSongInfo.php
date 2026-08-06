<?php
chdir(dirname(__FILE__));
include "../lib/connection.php";
require_once "../lib/songReup.php";
require_once "../lib/exploitPatch.php";

if (empty($_POST["songID"])) {
    exit("-1");
}

$songid = ExploitPatch::remove($_POST["songID"]);

if (!is_numeric($songid) || (int)$songid <= 0) {
    exit("-1");
}

function cheese_cdn_song_url($songid): string {
    return "https://cheesecdn.com/" . rawurlencode((string)$songid);
}

// ── 1. Check Local Database Cache First ──────────────────────────────────────
$query3 = $db->prepare("SELECT ID, name, authorID, authorName, size, isDisabled FROM songs WHERE ID = :songid LIMIT 1");
$query3->execute([':songid' => $songid]);

if ($query3->rowCount() > 0) {
    $song = $query3->fetch(PDO::FETCH_ASSOC);

    if ((int)$song["isDisabled"] === 1) {
        exit("-2");
    }

    // Do NOT urlencode key 10 — GD expects plain URL
    $dl = cheese_cdn_song_url($song["ID"]);

    echo "1~|~{$song['ID']}~|~2~|~{$song['name']}~|~3~|~{$song['authorID']}~|~4~|~{$song['authorName']}~|~5~|~{$song['size']}~|~6~|~~|~10~|~{$dl}~|~7~|~~|~8~|~0";
    exit;
}

// ── 2. Query this endpoint: /info/{songId} JSON Endpoint for Missing Songs ───────────
$cdnInfoUrl = "https://cheesecdn.com/info/" . rawurlencode((string)$songid);

$ch = curl_init($cdnInfoUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 4,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    CURLOPT_USERAGENT      => 'GeometryDash-GDPS/1.0',
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    exit("-1");
}

$meta = json_decode($response, true);

if (!$meta || empty($meta['title']) || $meta['title'] === "Unknown") {
    exit("-1");
}

$songName   = $meta['title'];
$authorName = $meta['artist'] ?? 'Unknown';
$authorID   = 0;
$size       = "10.00";
// Raw URL without urlencode()
$dlUrl      = cheese_cdn_song_url($songid);

$gdResponse = "1~|~{$songid}~|~2~|~{$songName}~|~3~|~{$authorID}~|~4~|~{$authorName}~|~5~|~{$size}~|~6~|~~|~10~|~{$dlUrl}~|~7~|~~|~8~|~0";

echo $gdResponse;

// Deferred background reupload processing
SongReup::reup($gdResponse);
?>



