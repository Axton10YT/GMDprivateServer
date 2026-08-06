<?php
chdir(dirname(__FILE__));

// Suppress notices/warnings so they don't break GD client parsing
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

include "../lib/connection.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/XORCipher.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

// 1. Authenticate Request
$accountID = GJPCheck::getAccountIDOrDie();

// 2. Input Validation & Bounds
if (!isset($_POST["levelID"]) || !isset($_POST["percent"])) {
    exit("-1");
}

$levelID    = (int)ExploitPatch::number($_POST["levelID"]);
$percent    = (int)ExploitPatch::number($_POST["percent"]);
$uploadDate = time();

if ($levelID <= 0) {
    exit("-1");
}

// Security Check: Auto-ban cheated score submissions (>100%)
if ($percent > 100 || $percent < 0) {
    $banStmt = $db->prepare("UPDATE users SET isBanned = 1 WHERE extID = :accountID");
    $banStmt->execute([':accountID' => $accountID]);
    exit("-1");
}

// 3. Decode & Sanitize Statistics Payload
$attempts   = isset($_POST["s1"]) ? max(0, (int)$_POST["s1"] - 8354) : 0;
$clicks     = isset($_POST["s2"]) ? max(0, (int)$_POST["s2"] - 3991) : 0;
$timePlayed = isset($_POST["s3"]) ? max(0, (int)$_POST["s3"] - 4085) : 0;
$coins      = isset($_POST["s9"]) ? max(0, (int)$_POST["s9"] - 5819) : 0;
$dailyID    = isset($_POST["s10"]) ? (int)$_POST["s10"] : 0;

$progresses = 0;
if (!empty($_POST["s6"])) {
    $decodedS6 = base64_decode(str_replace(["_", "-"], ["/", "+"], $_POST["s6"]));
    if ($decodedS6 !== false) {
        $progresses = XORCipher::cipher($decodedS6, 41274);
    }
}

// 4. Update Level Score
$dailyOperator = ($dailyID > 0) ? ">" : "=";

$checkQuery = $db->prepare("SELECT percent FROM levelscores WHERE accountID = :accountID AND levelID = :levelID AND dailyID $dailyOperator 0 LIMIT 1");
$checkQuery->execute([':accountID' => $accountID, ':levelID' => $levelID]);
$existingScore = $checkQuery->fetch(PDO::FETCH_ASSOC);

if (!$existingScore) {
    // Insert new score record
    $insStmt = $db->prepare("INSERT INTO levelscores 
        (accountID, levelID, percent, uploadDate, coins, attempts, clicks, time, progresses, dailyID)
        VALUES (:accountID, :levelID, :percent, :uploadDate, :coins, :attempts, :clicks, :time, :progresses, :dailyID)");
    $insStmt->execute([
        ':accountID'  => $accountID,
        ':levelID'    => $levelID,
        ':percent'    => $percent,
        ':uploadDate' => $uploadDate,
        ':coins'      => $coins,
        ':attempts'   => $attempts,
        ':clicks'     => $clicks,
        ':time'       => $timePlayed,
        ':progresses' => $progresses,
        ':dailyID'    => $dailyID
    ]);
} elseif ($percent >= (int)$existingScore["percent"]) {
    // Update existing highscore
    $updStmt = $db->prepare("UPDATE levelscores SET 
        percent = :percent, uploadDate = :uploadDate, coins = :coins, attempts = :attempts, 
        clicks = :clicks, time = :time, progresses = :progresses, dailyID = :dailyID 
        WHERE accountID = :accountID AND levelID = :levelID AND dailyID $dailyOperator 0");
    $updStmt->execute([
        ':accountID'  => $accountID,
        ':levelID'    => $levelID,
        ':percent'    => $percent,
        ':uploadDate' => $uploadDate,
        ':coins'      => $coins,
        ':attempts'   => $attempts,
        ':clicks'     => $clicks,
        ':time'       => $timePlayed,
        ':progresses' => $progresses,
        ':dailyID'    => $dailyID
    ]);
}

// 5. Leaderboard Fetching (Optimized JOIN Query)
$type = isset($_POST["type"]) ? (int)$_POST["type"] : 1;
$queryArgs = [':levelID' => $levelID];

$baseSql = "SELECT s.accountID, s.uploadDate, s.percent, s.coins, 
                   u.userName, u.userID, u.icon, u.color1, u.color2, u.color3, u.iconType, u.special, u.extID
            FROM levelscores s
            INNER JOIN users u ON s.accountID = u.extID
            WHERE u.isBanned = 0 AND s.levelID = :levelID AND s.dailyID $dailyOperator 0";

switch ($type) {
    case 0: // Friends
        $friendsList = $gs->getFriends($accountID);
        $friendsList[] = $accountID;
        
        // Build parameterized dynamic IN clause
        $inParams = [];
        foreach ($friendsList as $i => $fId) {
            $key = ":friend_" . $i;
            $inParams[] = $key;
            $queryArgs[$key] = (int)$fId;
        }
        $baseSql .= " AND s.accountID IN (" . implode(",", $inParams) . ")";
        break;

    case 1: // Top / Global
        // Default baseSql conditions apply
        break;

    case 2: // Weekly
        $baseSql .= " AND s.uploadDate > :weekAgo";
        $queryArgs[':weekAgo'] = time() - 604800;
        break;

    default:
        exit("-1");
}

$baseSql .= " ORDER BY s.percent DESC, s.uploadDate ASC LIMIT 100";

$scoreQuery = $db->prepare($baseSql);
$scoreQuery->execute($queryArgs);
$scores = $scoreQuery->fetchAll(PDO::FETCH_ASSOC);

// 6. Format Client Leaderboard String
$outputRows = [];
foreach ($scores as $score) {
    $timeFormatted = date("d/m/Y G.i", $score["uploadDate"]);

    // Calculate placement rank indicator
    if ($score["percent"] == 100) {
        $place = 1;
    } elseif ($score["percent"] > 75) {
        $place = 2;
    } else {
        $place = 3;
    }

    $outputRows[] = "1:" . $score["userName"] .
                    ":2:" . $score["userID"] .
                    ":9:" . $score["icon"] .
                    ":10:" . $score["color1"] .
                    ":11:" . $score["color2"] .
                    ":51:" . $score["color3"] .
                    ":14:" . $score["iconType"] .
                    ":15:" . $score["special"] .
                    ":16:" . $score["extID"] .
                    ":3:" . $score["percent"] .
                    ":6:" . $place .
                    ":13:" . $score["coins"] .
                    ":42:" . $timeFormatted;
}

echo implode("|", $outputRows);
?>
