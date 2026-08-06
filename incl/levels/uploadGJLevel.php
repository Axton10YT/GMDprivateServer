<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

// 1. Authenticate Request & Get Account ID
$accountID = GJPCheck::getAccountIDOrDie();

if (empty($_POST["levelName"]) || empty($_POST["levelString"])) {
    exit("-1");
}

// 2. Extract & Sanitize Core Inputs
$gameVersion     = (int)ExploitPatch::remove($_POST["gameVersion"] ?? 1);
$binaryVersion   = (int)ExploitPatch::remove($_POST["binaryVersion"] ?? 0);
$userName        = ExploitPatch::charclean($_POST["userName"] ?? '');
$levelName       = ExploitPatch::charclean($_POST["levelName"]);
$levelString     = ExploitPatch::remove($_POST["levelString"]);
$levelVersion    = (int)ExploitPatch::remove($_POST["levelVersion"] ?? 1);
$levelLength     = (int)ExploitPatch::remove($_POST["levelLength"] ?? 0);
$audioTrack      = (int)ExploitPatch::remove($_POST["audioTrack"] ?? 0);
$auto            = (int)ExploitPatch::remove($_POST["auto"] ?? 0);
$original        = (int)ExploitPatch::remove($_POST["original"] ?? 0);
$twoPlayer       = (int)ExploitPatch::remove($_POST["twoPlayer"] ?? 0);
$songID          = (int)ExploitPatch::remove($_POST["songID"] ?? 0);
$objects         = (int)ExploitPatch::remove($_POST["objects"] ?? 0);
$coins           = (int)ExploitPatch::remove($_POST["coins"] ?? 0);
$requestedStars  = (int)ExploitPatch::remove($_POST["requestedStars"] ?? 0);
$unlisted        = (int)ExploitPatch::remove($_POST["unlisted1"] ?? $_POST["unlisted"] ?? 0);
$unlisted2       = (int)ExploitPatch::remove($_POST["unlisted2"] ?? $unlisted);
$ldm             = (int)ExploitPatch::remove($_POST["ldm"] ?? 0);
$wt              = (int)ExploitPatch::remove($_POST["wt"] ?? 0);
$wt2             = (int)ExploitPatch::remove($_POST["wt2"] ?? 0);
$ts              = (int)ExploitPatch::number($_POST["ts"] ?? 0);
$secret          = ExploitPatch::remove($_POST["secret"] ?? '');
$levelInfo       = ExploitPatch::remove($_POST["levelInfo"] ?? '');
$settingsString  = ExploitPatch::remove($_POST["settingsString"] ?? '');
$songIDs         = ExploitPatch::numbercolon($_POST["songIDs"] ?? '');
$sfxIDs          = ExploitPatch::numbercolon($_POST["sfxIDs"] ?? '');
$extraString     = ExploitPatch::remove($_POST["extraString"] ?? "29_29_29_40_29_29_29_29_29_29_29_29_29_29_29_29");

// Password Resolution
if (isset($_POST["password"])) {
    $password = ExploitPatch::remove($_POST["password"]);
} else {
    $password = ($gameVersion > 17) ? "0" : "1";
}

// Format Description
$levelDesc = formatLevelDescription($_POST["levelDesc"] ?? '', $gameVersion);

// Rate-Limiting & User Verification
$hostname   = $gs->getIP();
$userID     = $gs->getUserID($accountID, $userName);
$uploadDate = time();

$rateCheck = $db->prepare("SELECT 1 FROM levels WHERE uploadDate > :time AND (userID = :userID OR hostname = :ip) LIMIT 1");
$rateCheck->execute([':time' => $uploadDate - 60, ':userID' => $userID, ':ip' => $hostname]);

if ($rateCheck->fetchColumn()) {
    exit("-1");
}

// 3. Upsert Logic (Check Existing Level by levelName + userID)
$checkQuery = $db->prepare("SELECT levelID FROM levels WHERE levelName = :levelName AND userID = :userID LIMIT 1");
$checkQuery->execute([':levelName' => $levelName, ':userID' => $userID]);
$existingLevelID = $checkQuery->fetchColumn();

try {
    if ($existingLevelID) {
        $levelID = (int)$existingLevelID;
        $updateStmt = $db->prepare("UPDATE levels SET 
            gameVersion = :gameVersion, binaryVersion = :binaryVersion, userName = :userName, levelDesc = :levelDesc, 
            levelVersion = :levelVersion, levelLength = :levelLength, audioTrack = :audioTrack, auto = :auto, 
            password = :password, original = :original, twoPlayer = :twoPlayer, songID = :songID, objects = :objects, 
            coins = :coins, requestedStars = :requestedStars, extraString = :extraString, levelString = '', 
            levelInfo = :levelInfo, secret = :secret, updateDate = :uploadDate, unlisted = :unlisted, hostname = :hostname, 
            isLDM = :ldm, wt = :wt, wt2 = :wt2, unlisted2 = :unlisted2, settingsString = :settingsString, 
            songIDs = :songIDs, sfxIDs = :sfxIDs, ts = :ts 
            WHERE levelID = :levelID");

        $updateStmt->execute([
            ':gameVersion' => $gameVersion, ':binaryVersion' => $binaryVersion, ':userName' => $userName,
            ':levelDesc' => $levelDesc, ':levelVersion' => $levelVersion, ':levelLength' => $levelLength,
            ':audioTrack' => $audioTrack, ':auto' => $auto, ':password' => $password, ':original' => $original,
            ':twoPlayer' => $twoPlayer, ':songID' => $songID, ':objects' => $objects, ':coins' => $coins,
            ':requestedStars' => $requestedStars, ':extraString' => $extraString, ':levelInfo' => $levelInfo,
            ':secret' => $secret, ':uploadDate' => $uploadDate, ':unlisted' => $unlisted, ':hostname' => $hostname,
            ':ldm' => $ldm, ':wt' => $wt, ':wt2' => $wt2, ':unlisted2' => $unlisted2, ':settingsString' => $settingsString,
            ':songIDs' => $songIDs, ':sfxIDs' => $sfxIDs, ':ts' => $ts, ':levelID' => $levelID
        ]);
    } else {
        $insertStmt = $db->prepare("INSERT INTO levels 
            (levelName, gameVersion, binaryVersion, userName, levelDesc, levelVersion, levelLength, audioTrack, auto, password, original, twoPlayer, songID, objects, coins, requestedStars, extraString, levelString, levelInfo, secret, uploadDate, userID, extID, updateDate, unlisted, hostname, isLDM, wt, wt2, unlisted2, settingsString, songIDs, sfxIDs, ts)
            VALUES 
            (:levelName, :gameVersion, :binaryVersion, :userName, :levelDesc, :levelVersion, :levelLength, :audioTrack, :auto, :password, :original, :twoPlayer, :songID, :objects, :coins, :requestedStars, :extraString, '', :levelInfo, :secret, :uploadDate, :userID, :accountID, :uploadDate, :unlisted, :hostname, :ldm, :wt, :wt2, :unlisted2, :settingsString, :songIDs, :sfxIDs, :ts)");

        $insertStmt->execute([
            ':levelName' => $levelName, ':gameVersion' => $gameVersion, ':binaryVersion' => $binaryVersion,
            ':userName' => $userName, ':levelDesc' => $levelDesc, ':levelVersion' => $levelVersion,
            ':levelLength' => $levelLength, ':audioTrack' => $audioTrack, ':auto' => $auto, ':password' => $password,
            ':original' => $original, ':twoPlayer' => $twoPlayer, ':songID' => $songID, ':objects' => $objects,
            ':coins' => $coins, ':requestedStars' => $requestedStars, ':extraString' => $extraString,
            ':levelInfo' => $levelInfo, ':secret' => $secret, ':uploadDate' => $uploadDate, ':userID' => $userID,
            ':accountID' => $accountID, ':unlisted' => $unlisted, ':hostname' => $hostname, ':ldm' => $ldm,
            ':wt' => $wt, ':wt2' => $wt2, ':unlisted2' => $unlisted2, ':settingsString' => $settingsString,
            ':songIDs' => $songIDs, ':sfxIDs' => $sfxIDs, ':ts' => $ts
        ]);

        $levelID = (int)$db->lastInsertId();
    }

    // Save level file
    $dir = "../../data/levels/";
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents($dir . $levelID, $levelString);

    echo $levelID;

} catch (PDOException $e) {
    // If database insertion fails, reject upload gracefully
    exit("-1");
}

function formatLevelDescription(string $inputDesc, int $gameVersion): string {
    $cleaned = ExploitPatch::remove($inputDesc);

    if ($gameVersion < 20) {
        $rawDesc = $cleaned;
    } else {
        $rawDesc = base64_decode(strtr($cleaned, '-_', '+/')) ?: '';
    }

    $openTags  = substr_count($rawDesc, '<c');
    $closeTags = substr_count($rawDesc, '</c>');

    if ($openTags > $closeTags) {
        $rawDesc .= str_repeat('</c>', $openTags - $closeTags);
    }

    return strtr(base64_encode($rawDesc), '+/', '-_');
}
?>
