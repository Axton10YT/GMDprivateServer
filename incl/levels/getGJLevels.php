<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/GJPCheck.php"; // replacing with GJP3 soon. 
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";
require_once "../lib/generateHash.php";

$gs = new mainLib();

// ── 1. Parameter Normalization ───────────────────────────────────────────────
$gameVersion = !empty($_POST["gameVersion"]) ? (int)ExploitPatch::number($_POST["gameVersion"]) : 0;
if ($gameVersion === 20) {
    $binaryVersion = !empty($_POST["binaryVersion"]) ? (int)ExploitPatch::number($_POST["binaryVersion"]) : 0;
    if ($binaryVersion > 27) {
        $gameVersion++;
    }
}

$type = !empty($_POST["type"]) ? (int)ExploitPatch::number($_POST["type"]) : 0;
$diff = !empty($_POST["diff"]) ? ExploitPatch::numbercolon($_POST["diff"]) : "-";
$str  = !empty($_POST["str"]) ? ExploitPatch::remove($_POST["str"]) : "";
$page = (isset($_POST["page"]) && is_numeric($_POST["page"])) ? max(0, (int)ExploitPatch::number($_POST["page"])) : 0;

$offset      = $page * 10;
$maxLevels   = 10;
$order       = "uploadDate";
$orderDir    = "DESC";
$isIDSearch  = false;
$params      = ["unlisted = 0"];
$queryParams = [];
$morejoins   = "";
$sug         = "";
$sugg        = "";
$gauntlet    = null;

// ── 2. Filters & Conditions ──────────────────────────────────────────────────

// Game Version Filter
if ($gameVersion === 0) {
    $params[] = "levels.gameVersion <= 18";
} else {
    $params[] = "levels.gameVersion <= :gameVersion";
    $queryParams[':gameVersion'] = $gameVersion;
}

if (!empty($_POST["original"]) && $_POST["original"] == 1) {
    $params[] = "original = 0";
}
if (!empty($_POST["coins"]) && $_POST["coins"] == 1) {
    $params[] = "starCoins = 1 AND NOT levels.coins = 0";
}

// Completed / Uncompleted Filters
if (!empty($_POST["uncompleted"]) && $_POST["uncompleted"] == 1 && !empty($_POST["completedLevels"])) {
    $completedList = array_map('intval', explode(',', ExploitPatch::numbercolon($_POST["completedLevels"])));
    if (!empty($completedList)) {
        $inClause = [];
        foreach ($completedList as $idx => $idVal) {
            $key = ":comp_{$idx}";
            $inClause[] = $key;
            $queryParams[$key] = $idVal;
        }
        $params[] = "NOT levelID IN (" . implode(',', $inClause) . ")";
    }
}

if (!empty($_POST["onlyCompleted"]) && $_POST["onlyCompleted"] == 1 && !empty($_POST["completedLevels"])) {
    $completedList = array_map('intval', explode(',', ExploitPatch::numbercolon($_POST["completedLevels"])));
    if (!empty($completedList)) {
        $inClause = [];
        foreach ($completedList as $idx => $idVal) {
            $key = ":onlycomp_{$idx}";
            $inClause[] = $key;
            $queryParams[$key] = $idVal;
        }
        $params[] = "levelID IN (" . implode(',', $inClause) . ")";
    }
}

// Song Filters
if (!empty($_POST["song"])) {
    $songVal = (int)ExploitPatch::number($_POST["song"]);
    if (empty($_POST["customSong"])) {
        $params[] = "audioTrack = :audioTrack AND songID = 0";
        $queryParams[':audioTrack'] = $songVal - 1;
    } else {
        $params[] = "songID = :songID";
        $queryParams[':songID'] = $songVal;
    }
}

if (!empty($_POST["twoPlayer"]) && $_POST["twoPlayer"] == 1) {
    $params[] = "twoPlayer = 1";
}
if (!empty($_POST["star"])) {
    $params[] = "NOT starStars = 0";
}
if (!empty($_POST["noStar"])) {
    $params[] = "starStars = 0";
}

// Gauntlet Filter
if (!empty($_POST["gauntlet"])) {
    $order    = "starStars";
    $orderDir = "ASC";
    $gauntlet = ExploitPatch::remove($_POST["gauntlet"]);

    $gQuery = $db->prepare("SELECT level1, level2, level3, level4, level5 FROM gauntlets WHERE ID = :gauntlet LIMIT 1");
    $gQuery->execute([':gauntlet' => $gauntlet]);
    $gData = $gQuery->fetch(PDO::FETCH_ASSOC);

    if ($gData) {
        $gLevels = array_map('intval', [$gData["level1"], $gData["level2"], $gData["level3"], $gData["level4"], $gData["level5"]]);
        $gKeys = [];
        foreach ($gLevels as $idx => $gLvl) {
            $key = ":gLvl_{$idx}";
            $gKeys[] = $key;
            $queryParams[$key] = $gLvl;
        }
        $params[] = "levelID IN (" . implode(',', $gKeys) . ")";
    }
    $type = -1;
}

// Length Filter
if (isset($_POST["len"]) && $_POST["len"] !== "-") {
    $lens = array_map('intval', explode(',', ExploitPatch::numbercolon($_POST["len"])));
    $lenKeys = [];
    foreach ($lens as $idx => $lVal) {
        $key = ":len_{$idx}";
        $lenKeys[] = $key;
        $queryParams[$key] = $lVal;
    }
    $params[] = "levelLength IN (" . implode(',', $lenKeys) . ")";
}

// Feature Rating Filters
$epicParams = [];
if (!empty($_POST["featured"]))  $epicParams[] = "starFeatured = 1";
if (!empty($_POST["epic"]))      $epicParams[] = "starEpic = 1";
if (!empty($_POST["mythic"]))    $epicParams[] = "starEpic = 2";
if (!empty($_POST["legendary"])) $epicParams[] = "starEpic = 3";
if (!empty($epicParams)) {
    $params[] = "(" . implode(" OR ", $epicParams) . ")";
}

// Difficulty Filters
switch ($diff) {
    case -1:
        $params[] = "starDifficulty = '0'";
        break;
    case -3:
        $params[] = "starAuto = '1'";
        break;
    case -2:
        $demonFilter = !empty($_POST["demonFilter"]) ? (int)ExploitPatch::number($_POST["demonFilter"]) : 0;
        $params[] = "starDemon = 1";
        $demonDiffMap = [1 => '3', 2 => '4', 3 => '0', 4 => '5', 5 => '6'];
        if (isset($demonDiffMap[$demonFilter])) {
            $params[] = "starDemonDiff = :demonDiff";
            $queryParams[':demonDiff'] = $demonDiffMap[$demonFilter];
        }
        break;
    case "-":
        break;
    default:
        if ($diff) {
            $diffList = array_map('intval', explode(',', $diff));
            $diffKeys = [];
            foreach ($diffList as $idx => $dVal) {
                $key = ":diff_{$idx}";
                $diffKeys[] = $key;
                $queryParams[$key] = $dVal * 10;
            }
            $params[] = "starDifficulty IN (" . implode(',', $diffKeys) . ") AND starAuto = '0' AND starDemon = '0'";
        }
        break;
}

// ── 3. Type Switch Handling ──────────────────────────────────────────────────
switch ($type) {
    case 0:
    case 15:
        $order = "likes";
        if (!empty($str)) {
            if (is_numeric($str)) {
                $params = ["levelID = :searchID"];
                $queryParams = [':searchID' => (int)$str];
                $isIDSearch = true;
            } else {
                $params[] = "levelName LIKE :searchStr";
                $queryParams[':searchStr'] = '%' . $str . '%';
            }
        }
        break;
    case 1:
        $order = "downloads";
        break;
    case 2:
        $order = "likes";
        break;
    case 3: // Trending
        $params[] = "uploadDate > :trendingDate";
        $queryParams[':trendingDate'] = time() - 604800; // 7 days
        $order = "likes";
        break;
    case 5:
        $params[] = "levels.userID = :targetUserID";
        $queryParams[':targetUserID'] = (int)$str;
        break;
    case 6:
    case 17:
        if ($gameVersion > 21) {
            $params[] = "(NOT starFeatured = 0 OR NOT starEpic = 0)";
        } else {
            $params[] = "NOT starFeatured = 0";
        }
        $order = "rateDate DESC, uploadDate";
        break;
    case 16: // Hall of Fame
        $params[] = "NOT starEpic = 0";
        $order = "rateDate DESC, uploadDate";
        break;
    case 7: // Magic
        $params[] = "objects > 9999";
        break;
    case 10:
    case 19: // Map Packs
        $order = false;
        $idList = array_map('intval', explode(',', $str));
        $keys = [];
        foreach ($idList as $idx => $id) {
            $k = ":mp_{$idx}";
            $keys[] = $k;
            $queryParams[$k] = $id;
        }
        $params[] = "levelID IN (" . implode(',', $keys) . ")";
        break;
    case 11: // Awarded
        $params[] = "NOT starStars = 0";
        $order = "rateDate DESC, uploadDate";
        break;
    case 12: // Followed
        if (!empty($_POST["followed"])) {
            $followedList = array_map('intval', explode(',', ExploitPatch::numbercolon($_POST["followed"])));
            $keys = [];
            foreach ($followedList as $idx => $fId) {
                $k = ":fol_{$idx}";
                $keys[] = $k;
                $queryParams[$k] = $fId;
            }
            $params[] = "users.extID IN (" . implode(',', $keys) . ")";
        }
        break;
    case 13: // Friends
        $accountID = GJPCheck::getAccountIDOrDie();
        $friends = array_map('intval', $gs->getFriends($accountID));
        if (!empty($friends)) {
            $keys = [];
            foreach ($friends as $idx => $fId) {
                $k = ":frd_{$idx}";
                $keys[] = $k;
                $queryParams[$k] = $fId;
            }
            $params[] = "users.extID IN (" . implode(',', $keys) . ")";
        } else {
            $params[] = "1 = 0"; // No friends
        }
        break;
    case 21: // Daily
    case 22: // Weekly
    case 23: // Event
        $featureTypes = [21 => 0, 22 => 1, 23 => 2];
        $morejoins = "INNER JOIN dailyfeatures ON levels.levelID = dailyfeatures.levelID";
        $params[] = "dailyfeatures.type = :featureType";
        $queryParams[':featureType'] = $featureTypes[$type];
        $order = "dailyfeatures.feaID";
        break;
    case 25: // List Levels
        $listLevels = array_map('intval', explode(',', $gs->getListLevels($str)));
        $keys = [];
        foreach ($listLevels as $idx => $lId) {
            $k = ":lst_{$idx}";
            $keys[] = $k;
            $queryParams[$k] = $lId;
        }
        $params = ["levelID IN (" . implode(',', $keys) . ")"];
        break;
    case 26: // Local List
        $maxLevels = 100;
        $order = false;
        $idList = array_map('intval', explode(',', $str));
        $keys = [];
        foreach ($idList as $idx => $lId) {
            $k = ":loc_{$idx}";
            $keys[] = $k;
            $queryParams[$k] = $lId;
        }
        $params[] = "levelID IN (" . implode(',', $keys) . ")";
        break;
    case 27: // Sent Levels
        $sug   = ", suggest.suggestLevelId, suggest.timestamp";
        $sugg  = "LEFT JOIN suggest ON levels.levelID = suggest.suggestLevelId";
        $params[] = "suggestLevelId > 0";
        $order = "suggest.timestamp";
        break;
}

// ── 4. Build Query Strings ───────────────────────────────────────────────────
$querybase = "FROM levels 
              LEFT JOIN songs ON levels.songID = songs.ID 
              LEFT JOIN users ON levels.userID = users.userID 
              $sugg $morejoins";

if (!empty($params)) {
    $querybase .= " WHERE (" . implode(" ) AND ( ", $params) . ")";
}

// Count Total Matching Levels
$countStmt = $db->prepare("SELECT COUNT(*) $querybase");
$countStmt->execute($queryParams);
$totallvlcount = (int)$countStmt->fetchColumn();

// Select Page Results
$querySql = "SELECT levels.*, songs.ID, songs.name, songs.authorID, songs.authorName, songs.size, songs.isDisabled, songs.download, users.userName, users.extID $sug $querybase";

if ($order) {
    $querySql .= " ORDER BY {$order} {$orderDir}";
}
$querySql .= " LIMIT :maxLevels OFFSET :offset";

$query = $db->prepare($querySql);

foreach ($queryParams as $param => $val) {
    $query->bindValue($param, $val);
}
$query->bindValue(':maxLevels', $maxLevels, PDO::PARAM_INT);
$query->bindValue(':offset', $offset, PDO::PARAM_INT);
$query->execute();

$result = $query->fetchAll(PDO::FETCH_ASSOC);

// ── 5. Format Geometry Dash Protocol Response ───────────────────────────────
$lvlChunks       = [];
$userChunks      = [];
$songChunks      = [];
$lvlsmultistring = [];

foreach ($result as $level1) {
    if (empty($level1["levelID"])) {
        continue;
    }

    if ($isIDSearch && (int)$level1['unlisted'] > 1) {
        if (!isset($accountID)) {
            $accountID = GJPCheck::getAccountIDOrDie();
        }
        if (!$gs->isFriends($accountID, $level1['extID']) && (int)$accountID !== (int)$level1['extID']) {
            continue;
        }
    }

    $lvlsmultistring[] = [
        "levelID" => $level1["levelID"], 
        "stars"   => $level1["starStars"], 
        "coins"   => $level1["starCoins"]
    ];

    $gauntletPrefix = !empty($gauntlet) ? "44:{$gauntlet}:" : "";

    $lvlChunks[] = $gauntletPrefix . "1:{$level1['levelID']}:2:{$level1['levelName']}:5:{$level1['levelVersion']}:6:{$level1['userID']}:8:10:9:{$level1['starDifficulty']}:10:{$level1['downloads']}:12:{$level1['audioTrack']}:13:{$level1['gameVersion']}:14:{$level1['likes']}:17:{$level1['starDemon']}:43:{$level1['starDemonDiff']}:25:{$level1['starAuto']}:18:{$level1['starStars']}:19:{$level1['starFeatured']}:42:{$level1['starEpic']}:45:{$level1['objects']}:3:{$level1['levelDesc']}:15:{$level1['levelLength']}:30:{$level1['original']}:31:{$level1['twoPlayer']}:37:{$level1['coins']}:38:{$level1['starCoins']}:39:{$level1['requestedStars']}:46:1:47:2:40:{$level1['isLDM']}:35:{$level1['songID']}";

    if ((int)$level1["songID"] !== 0) {
        $song = $gs->getSongString($level1);
        if ($song) {
            $songChunks[] = $song;
        }
    }

    $userChunks[] = $gs->getUserString($level1);
}

// Response Construction
$lvlString   = implode("|", $lvlChunks);
$userString  = implode("|", $userChunks);
$songsString = implode("~:~", $songChunks);
$multiHash   = GenerateHash::genMulti($lvlsmultistring);

$response = "{$lvlString}#{$userString}";

if ($gameVersion > 18) {
    $response .= "#{$songsString}";
}

$response .= "#{$totallvlcount}:{$offset}:{$maxLevels}#{$multiHash}";

echo $response;
?>
