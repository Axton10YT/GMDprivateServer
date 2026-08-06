<?php
class SongReup {
    public static function reup($result) {
        if (empty($result) || $result === "-1" || $result === "-2") {
            return false;
        }

        include dirname(__FILE__) . "/connection.php";
        $resultarray = explode('~|~', $result);

        // Map key-value array pairs from Geometry Dash song metadata string format
        $meta = [];
        for ($i = 0; $i < count($resultarray); $i += 2) {
            if (isset($resultarray[$i + 1])) {
                $meta[$resultarray[$i]] = $resultarray[$i + 1];
            }
        }

        $songId     = $meta['1'] ?? null;
        $songName   = $meta['2'] ?? 'Unknown';
        $authorID   = $meta['3'] ?? 0;
        $authorName = $meta['4'] ?? 'Unknown';
        $size       = $meta['5'] ?? '0.00';
        $download   = urldecode($meta['10'] ?? '');

        if (!$songId) {
            return false;
        }

        // 1. Insert/Update metadata in MySQL
        $query = $db->prepare("INSERT INTO songs (ID, name, authorID, authorName, size, download)
                               VALUES (:id, :name, :authorID, :authorName, :size, :download)
                               ON DUPLICATE KEY UPDATE name = :name, authorName = :authorName, size = :size, download = :download");
        $query->execute([
            ':id'         => $songId,
            ':name'       => $songName,
            ':authorID'   => $authorID,
            ':authorName' => $authorName,
            ':size'       => $size,
            ':download'   => $download
        ]);

        // 2. Trigger asynchronous background upload to Cloudflare Worker R2 endpoint
        $ch = curl_init("https://cdn.robtop.net/songUpload");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'songId'     => $songId,
                'title'      => $songName,
                'artist'     => $authorName
            ]),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2, // Low timeout so HTTP client response isn't blocked
            CURLOPT_USERAGENT      => 'GeometryDash-Reup/1.0'
        ]);

        curl_exec($ch);
        curl_close($ch);

        return $songId;
    }
}
?>
