<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/mainLib.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";

$gs = new mainLib();

// Sanitization
$levelID = !empty($_POST["levelID"]) ? (int)ExploitPatch::number($_POST["levelID"]) : 0;
$inc     = !empty($_POST["inc"]) ? (int)ExploitPatch::number($_POST["inc"]) : 0;
$extras  = !empty($_POST["extras"]) ? (int)ExploitPatch::number($_POST["extras"]) : 0;

if ($levelID == 0) {
    exit("-1");
}

// Handle Daily/Weekly negative level IDs
if ($levelID < 0) {
    $type = abs($levelID) - 1;
    $stmt = $db->prepare("SELECT levelID FROM dailyfeatures WHERE type = :type ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute([':type' => $type]);
    $levelID = (int)$stmt->fetchColumn();
}

if ($levelID <= 0) {
    exit("-1");
}

// Fetch Level Data
$query = $db->prepare("SELECT * FROM levels WHERE levelID = :levelID LIMIT 1");
$query->execute([':levelID' => $levelID]);
$level = $query->fetch(PDO::FETCH_ASSOC);

if (!$level) {
    exit("-1");
}

// Level String Handling
$levelString = $level["levelString"] ?? "";
if (empty($levelString)) {
    $filePath = "../data/levels/" . $levelID;
    if (file_exists($filePath)) {
        $levelString = file_get_contents($filePath);
    }
}

if (empty($levelString)) {
    exit("-1");
}

// Update downloads count
if ($inc == 1) {
    $update = $db->prepare("UPDATE levels SET downloads = downloads + 1 WHERE levelID = :levelID");
    $update->execute([':levelID' => $levelID]);
}

// Build Level Array
$response = array(
    1  => $level["levelID"],
    2  => $level["levelName"],
    3  => $level["levelDesc"],
    4  => $levelString,
    5  => $level["levelVersion"],
    6  => $level["userID"],
    8  => 10,
    9  => $level["starDifficulty"],
    10 => $level["downloads"],
    12 => $level["audioTrack"],
    13 => $level["gameVersion"],
    14 => $level["likes"],
    15 => $level["starLength"],
    17 => $level["starDemon"] ? 1 : 0,
    18 => $level["starStars"],
    19 => $level["starFeatured"],
    27 => $level["password"],
    28 => $level["uploadDate"],
    29 => $level["updateDate"],
    35 => $level["songID"],
    36 => $level["extraString"] ?? "",
    37 => $level["coins"] ?? 0,
    38 => $level["starCoins"] ?? 0,
    39 => $level["requestedStars"] ?? 0,
    42 => $level["starEpic"] ?? 0,
    45 => $level["objectCount"] ?? 0
);

$output = "";
foreach ($response as $key => $value) {
    $output .= $key . ":" . $value . ":";
}

// Remove trailing colon
$output = rtrim($output, ":");

// Hash 1: Solo2 Hash for Level String
$hash = $gs->genSolo2($levelString);

// Hash 2: Pass/Password Hash (or xor pass string depending on core)
$passHash = $gs->genSolo2($level["password"] . "1");

// Append Hash Blocks strictly as GD client expects
echo $output . "#" . $hash . "#" . $passHash;
?>
