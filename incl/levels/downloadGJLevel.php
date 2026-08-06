<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/XORCipher.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";
require_once "../lib/generateHash.php";
require_once "../lib/GJPCheck.php";

$gs = new mainLib();

if (empty($_POST["levelID"])) {
    exit("-1");
}

$levelID       = ExploitPatch::remove($_POST["levelID"]);
$gameVersion   = !empty($_POST["gameVersion"]) ? (int)ExploitPatch::remove($_POST["gameVersion"]) : 1;
$binaryVersion = !empty($_POST["binaryVersion"]) ? (int)ExploitPatch::remove($_POST["binaryVersion"]) : 0;
$extras        = !empty($_POST["extras"]);
$inc           = !empty($_POST["inc"]);
$ip            = $gs->getIP();
$feaID         = 0;
$daily         = 0;

if (!is_numeric($levelID)) {
    exit("-1");
}

$levelID = (int)$levelID;

// 1. Resolve Special Daily/Weekly/Event Level IDs
if ($levelID < 0) {
    $typeMap = [-1 => 0, -2 => 1, -3 => 2];
    $offsetMap = [-1 => 0, -2 => 100001, -3 => 200001];

    if (!isset($typeMap[$levelID])) {
        exit("-1");
    }

    $type = $typeMap[$levelID];
    $query = $db->prepare("SELECT feaID, levelID FROM dailyfeatures WHERE timestamp <= :time AND type = :type ORDER BY timestamp DESC LIMIT 1");
    $query->execute([':time' => time(), ':type' => $type]);
    $feature = $query->fetch(PDO::FETCH_ASSOC);

    if (!$feature) {
        exit("-1");
    }

    $levelID = (int)$feature["levelID"];
    $feaID   = (int)$feature["feaID"] + $offsetMap[$levelID];
    $daily   = 1;
}

// 2. Query Level & Author Information
$query = $db->prepare("SELECT levels.*, users.userName, users.extID 
                        FROM levels 
                        LEFT JOIN users ON levels.userID = users.userID 
                        WHERE levels.levelID = :levelID 
                        LIMIT 1");
$query->execute([':levelID' => $levelID]);
$result = $query->fetch(PDO::FETCH_ASSOC);

if (!$result) {
    exit("-1");
}

// 3. Permission Check for Unlisted/Friends-Only Levels
if ((int)$result["unlisted2"] !== 0) {
    $accountID = GJPCheck::getAccountIDOrDie();
    $ownerExtID = (int)$result["extID"];

    if ((int)$accountID !== $ownerExtID && !$gs->isFriends($accountID, $ownerExtID)) {
        exit("-1");
    }
}

// 4. Track & Increment Download Count
if ($inc) {
    $query6 = $db->prepare("SELECT COUNT(*) FROM actions_downloads WHERE levelID = :levelID AND ip = INET6_ATON(:ip)");
    $query6->execute([':levelID' => $levelID, ':ip' => $ip]);

    if ((int)$query6->fetchColumn() < 2) {
        $db->prepare("UPDATE levels SET downloads = downloads + 1 WHERE levelID = :levelID")->execute([':levelID' => $levelID]);
        $db->prepare("INSERT INTO actions_downloads (levelID, ip) VALUES (:levelID, INET6_ATON(:ip))")->execute([':levelID' => $levelID, ':ip' => $ip]);
        $result["downloads"]++;
    }
}

// 5. Process Passwords, Descriptions & Level String
$pass = $result["password"];
$desc = $result["levelDesc"];

if ($gs->checkModIPPermission("actionFreeCopy") === 1) {
    $pass = "1";
}

$xorPass = $pass;
if ($gameVersion > 19) {
    if ($pass != "0") {
        $xorPass = base64_encode(XORCipher::cipher($pass, 26364));
    }
} else {
    $desc = ExploitPatch::remove(base64_decode($desc));
}

$filePath = "../../data/levels/{$levelID}";
if (file_exists($filePath)) {
    $levelstring = file_get_contents($filePath);
} else {
    $levelstring = $result["levelString"];
}

if ($gameVersion > 18 && str_starts_with($levelstring, 'kS1')) {
    $levelstring = strtr(base64_encode(gzcompress($levelstring)), '+/', '-_');
}

$uploadDate = date("d-m-Y G-i", $result["uploadDate"]);
$updateDate = date("d-m-Y G-i", $result["updateDate"]);

// 6. Assemble Main Response String
$responseMap = [
    "1" => $result["levelID"], "2" => $result["levelName"], "3" => $desc, "4" => $levelstring,
    "5" => $result["levelVersion"], "6" => $result["userID"], "8" => "10", "9" => $result["starDifficulty"],
    "10" => $result["downloads"], "11" => "1", "12" => $result["audioTrack"], "13" => $result["gameVersion"],
    "14" => $result["likes"], "17" => $result["starDemon"], "43" => $result["starDemonDiff"], "25" => $result["starAuto"],
    "18" => $result["starStars"], "19" => $result["starFeatured"], "42" => $result["starEpic"], "45" => $result["objects"],
    "15" => $result["levelLength"], "30" => $result["original"], "31" => $result["twoPlayer"], "28" => $uploadDate,
    "29" => $updateDate, "35" => $result["songID"], "36" => $result["extraString"], "37" => $result["coins"],
    "38" => $result["starCoins"], "39" => $result["requestedStars"], "46" => $result["wt"], "47" => $result["wt2"],
    "48" => $result["settingsString"], "40" => $result["isLDM"], "27" => $xorPass, "52" => $result["songIDs"],
    "53" => $result["sfxIDs"], "57" => $result["ts"]
];

if ($daily === 1) {
    $responseMap["41"] = $feaID;
}
if ($extras) {
    $responseMap["26"] = $result["levelInfo"];
}

$responseParts = [];
foreach ($responseMap as $key => $val) {
    $responseParts[] = "{$key}:{$val}";
}
$response = implode(":", $responseParts);

// 7. Generate Geometry Dash Hashes & Metadata
$hashSolo1 = GenerateHash::genSolo($levelstring);
$somestring = "{$result['userID']},{$result['starStars']},{$result['starDemon']},{$result['levelID']},{$result['starCoins']},{$result['starFeatured']},{$pass},{$feaID}";
$hashSolo2 = GenerateHash::genSolo2($somestring);

$response .= "#{$hashSolo1}#{$hashSolo2}";

if ($daily === 1) {
    $response .= "#" . $gs->getUserString($result);
} elseif ($binaryVersion === 30) {
    $response .= "#{$somestring}";
}

echo $response;
?>
