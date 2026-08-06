<?php
chdir(dirname(__FILE__));

// Suppress PHP notices/warnings so they don't break the client response
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

include "../lib/connection.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

// 1. Validate required POST parameters
if (!isset($_POST["levelID"]) || !isset($_POST["stars"])) {
    exit("-1");
}

// 2. Strict type casting and bounds checking
$levelID = (int)ExploitPatch::number($_POST["levelID"]);
$stars   = (int)ExploitPatch::number($_POST["stars"]);

if ($levelID <= 0 || $stars < 0 || $stars > 10) {
    exit("-1");
}

// 3. Authenticate Account via GJP / GJP2
$accountID = GJPCheck::getAccountIDOrDie();

// 4. Verify Rate Stars Permission
$permState = $gs->checkPermission($accountID, "actionRateStars");
if (!$permState) {
    exit("-1");
}

// 5. Execute Rating & Log Action
$difficulty = $gs->getDiffFromStars($stars);
$gs->rateLevel($accountID, $levelID, 0, $difficulty["diff"], $difficulty["auto"], $difficulty["demon"]);

// Optional: Log action to modactions table for audit trails
$uploadDate = time();
$query = $db->prepare("INSERT INTO modactions (type, value, value2, value3, timestamp, account) VALUES ('10', :stars, :diff, :levelID, :timestamp, :accountID)");
$query->execute([
    ':stars'     => $stars,
    ':diff'      => $difficulty["diff"],
    ':levelID'   => $levelID,
    ':timestamp' => $uploadDate,
    ':accountID' => $accountID
]);

echo 1;
?>
