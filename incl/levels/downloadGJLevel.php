<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/mainLib.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";

$gs = new mainLib();

// 1. Sanitize Inputs
$levelID = isset($_POST["levelID"]) ? (int)ExploitPatch::number($_POST["levelID"]) : 0;
$inc     = isset($_POST["inc"]) ? (int)ExploitPatch::number($_POST["inc"]) : 0;
$extras  = isset($_POST["extras"]) ? (int)ExploitPatch::number($_POST["extras"]) : 0;

if ($levelID == 0) {
    exit("-1");
}

// 2. Resolve Daily / Weekly / Special Level IDs
// In GD, negative IDs or specific inc flags can refer to dailyfeatures
if ($levelID < 0) {
    $type = abs($levelID) - 1; // Or custom mapping depending on your client build
    $stmt = $db->prepare("SELECT levelID FROM dailyfeatures WHERE type = :type ORDER BY timestamp DESC LIMIT 1");
    $stmt->execute([':type' => $type]);
    $levelID = (int)$stmt->fetchColumn();
}

if ($levelID <= 0) {
    exit("-1");
}

// 3. Fetch Level Details
$query = $db->prepare("SELECT * FROM levels WHERE levelID = :levelID LIMIT 1");
$query->execute([':levelID' => $levelID]);
$level = $query->fetch(PDO::FETCH_ASSOC);

if (!$level) {
    exit("-1");
}

// 4. Retrieve Level String Data
$levelString = $level["levelString"] ?? "";

// Check external file if levelString in DB is empty
if (empty($levelString)) {
    $filePath = "../data/levels/" . $levelID;
    if (file_exists($filePath)) {
        $levelString = file_get_contents($filePath);
    }
}

if (empty($levelString)) {
    exit("-1");
}

// 5. Update Downloads Count (If non-author downloaded)
if ($inc == 1) {
    $updateDownloads = $db->prepare("UPDATE levels SET downloads = downloads + 1 WHERE levelID = :levelID");
    $updateDownloads->execute([':levelID' => $levelID]);
}

// 6. Format GD Download Response (Key-Value Array format)
// Standard GD 2.1 Response format: 1:ID:2:Name:3:Desc:4:LevelString:5:Version:6:UserID:8:Difficulty:9:Auto...
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
    37 => $level["coins"],
    38 => $level["starCoins"],
    39 => $level["requestedStars"],
    45 => $level["objectCount"] ?? 0
);

$output = "";
foreach ($response as $key => $value) {
    $output .= $key . ":" . $value . ":";
}

// Append Hash Checks (#2.1 Hash String)
$hash = $gs->genSolo2($levelString);
$output .= "#" . $hash;

// Optional: Extra user/creator metadata hash
$output .= "#" . $level["userID"] . ":" . $level["extID"];

echo $output;
?>
