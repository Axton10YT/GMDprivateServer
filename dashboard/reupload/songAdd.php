<?php
session_start();

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

// 2. Handle Form Submissions
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    // Validate CSRF token
    if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
            <p>' . $dl->getLocalizedString("errorGeneric") . ' (Invalid Request Token)</p>
            <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
        exit;
    }

    $uploadType = $_POST['upload_type'] ?? 'url';

    // OPTION A: DIRECT MP3 FILE UPLOAD
    if ($uploadType === 'file') {
        $songName   = trim($_POST['title'] ?? '');
        $authorName = trim($_POST['artist'] ?? '');

        if (empty($songName) || empty($authorName)) {
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Error: Song Title and Artist/Publisher are required.</p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
            exit;
        }

        if (!isset($_FILES['mp3_file']) || $_FILES['mp3_file']['error'] !== UPLOAD_ERR_OK) {
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Error uploading file. Please ensure a valid MP3 file was selected.</p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
            exit;
        }

        $fileTmpPath = $_FILES['mp3_file']['tmp_name'];
        $fileName    = $_FILES['mp3_file']['name'];
        $fileExt     = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if ($fileExt !== 'mp3') {
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Invalid file extension. Only <strong>.mp3</strong> files are permitted.</p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
            exit;
        }

        // Generate high unique Song ID and target file path
        $targetDir = __DIR__ . "/../../data/songs/";
        if (!file_exists($targetDir)) {
            mkdir($targetDir, 0777, true);
        }

        // Fetch highest song ID from database to prevent ID collision
        $idQuery = $db->query("SELECT MAX(ID) FROM songs");
        $maxId   = (int)$idQuery->fetchColumn();
        $songID  = max(9000000, $maxId + 1); // Starts ID allocation at 9,000,000+

        $destination = $targetDir . $songID . ".mp3";

        if (!move_uploaded_file($fileTmpPath, $destination)) {
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Failed to move uploaded file to song directory. Check folder permissions.</p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
            exit;
        }

        // Insert direct metadata entry into `songs` table
        $fileSizeMB = round(filesize($destination) / 1024 / 1024, 2);
        $downloadUrl = $gs->getDomain() . "/data/songs/" . $songID . ".mp3";

        $stmt = $db->prepare("INSERT INTO songs (ID, name, authorID, authorName, size, download, isDisabled) VALUES (:id, :name, 0, :author, :size, :dl, 0)");
        $success = $stmt->execute([
            ':id'     => $songID,
            ':name'   => $songName,
            ':author' => $authorName,
            ':size'   => $fileSizeMB,
            ':dl'     => $downloadUrl
        ]);

        if ($success) {
            $safeSongID = htmlspecialchars((string)$songID, ENT_QUOTES, 'UTF-8');
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Custom MP3 Uploaded Successfully!</p>
                <p>Song ID: <strong style="font-size: 1.3em;">' . $safeSongID . '</strong></p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("songAddAnotherBTN") . '</a>', "reupload");
        } else {
            $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
                <p>Database insertion failed.</p>
                <a class="btn btn-primary btn-block" href="' . $requestUri . '">' . $dl->getLocalizedString("tryAgainBTN") . '</a>', "reupload");
        }
        exit;
    }

    // OPTION B: NEWGROUNDS / URL REUPLOAD
    $rawUrl = trim($_POST["url"] ?? '');

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
    curl_close($ch);

    $meta = json_decode($metaJson, true);
    $songName    = $meta['title'] ?? 'Custom Song ' . $songID;
    $authorName  = $meta['artist'] ?? 'Unknown';
    $downloadUrl = "https://cdn.robtop.net/" . rawurlencode((string)$songID);

    $gdSongString = "1~|~{$songID}~|~2~|~{$songName}~|~3~|~0~|~4~|~{$authorName}~|~5~|~10.00~|~6~|~~|~10~|~{$downloadUrl}~|~7~|~~|~8~|~0";

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
    // 3. Render Form Interface
    $csrfToken = htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');

    $dl->printBox('<h1>' . $dl->getLocalizedString("songAdd") . '</h1>
        <div class="form-group">
            <label>Select Upload Method:</label>
            <select id="uploadTypeSelect" class="form-control" onchange="toggleUploadMethod(this.value)">
                <option value="file">Direct MP3 Upload</option>
                <option value="url">Newgrounds URL / ID Reupload</option>
            </select>
        </div>

        <!-- Direct MP3 File Form -->
        <form action="' . $requestUri . '" method="post" enctype="multipart/form-data" id="fileForm">
            <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
            <input type="hidden" name="upload_type" value="file">
            
            <div class="form-group">
                <label for="titleField">Song Title</label>
                <input type="text" class="form-control" id="titleField" name="title" placeholder="e.g. Isolation" required>
            </div>
            
            <div class="form-group">
                <label for="artistField">Artist / Publisher</label>
                <input type="text" class="form-control" id="artistField" name="artist" placeholder="e.g. Nighthawk22" required>
            </div>

            <div class="form-group">
                <label for="mp3Field">MP3 Audio File</label>
                <input type="file" class="form-control-file" id="mp3Field" name="mp3_file" accept=".mp3" required>
            </div>

            <button type="submit" class="btn btn-primary btn-block">Upload MP3 File</button>
        </form>

        <!-- Newgrounds / URL Form -->
        <form action="' . $requestUri . '" method="post" id="urlForm" style="display: none;">
            <input type="hidden" name="csrf_token" value="' . $csrfToken . '">
            <input type="hidden" name="upload_type" value="url">

            <div class="form-group">
                <label for="urlField">' . $dl->getLocalizedString("songAddUrlFieldLabel") . '</label>
                <input type="text" class="form-control" id="urlField" name="url" placeholder="' . $dl->getLocalizedString("songAddUrlFieldPlaceholder") . '">
            </div>

            <button type="submit" class="btn btn-primary btn-block">' . $dl->getLocalizedString("reuploadBTN") . '</button>
        </form>

        <script>
            function toggleUploadMethod(val) {
                if (val === "file") {
                    document.getElementById("fileForm").style.display = "block";
                    document.getElementById("urlForm").style.display = "none";
                } else {
                    document.getElementById("fileForm").style.display = "none";
                    document.getElementById("urlForm").style.display = "block";
                }
            }
        </script>', "reupload");
}
?>
