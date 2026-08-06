<?php
chdir(dirname(__FILE__));

// Suppress notices/warnings so they don't break GD client parsing
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);

include "../lib/connection.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

// 1. Validate Input Parameters
if (!isset($_POST["accountID"]) || !isset($_POST["targetAccountID"])) {
    exit("-1");
}

$accountID       = (int)ExploitPatch::number($_POST["accountID"]);
$targetAccountID = (int)ExploitPatch::number($_POST["targetAccountID"]);

if ($accountID <= 0 || $targetAccountID <= 0) {
    exit("-1");
}

// 2. Authenticate Requester Credentials
$requesterAccountID = GJPCheck::getAccountIDOrDie();

if ($requesterAccountID !== $accountID) {
    exit("-1");
}

// 3. Fetch Target User & Authorization Data
$stmt = $db->prepare("SELECT extID, isBanned FROM users WHERE extID = :targetAccountID LIMIT 1");
$stmt->execute([':targetAccountID' => $targetAccountID]);
$targetUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$targetUser || $targetUser["isBanned"] == 1) {
    exit("-1");
}

// 4. Check Existing Access Request
$checkReq = $db->prepare("SELECT id, status FROM requestaccess WHERE accountID = :accountID AND targetAccountID = :targetAccountID LIMIT 1");
$checkReq->execute([
    ':accountID'       => $accountID,
    ':targetAccountID' => $targetAccountID
]);
$existingReq = $checkReq->fetch(PDO::FETCH_ASSOC);

if ($existingReq) {
    // Return current request state status back to GD client
    echo $existingReq["status"];
    exit();
}

// 5. Create New Access Request
$insertReq = $db->prepare("INSERT INTO requestaccess (accountID, targetAccountID, timestamp, status) VALUES (:accountID, :targetAccountID, :time, 0)");
$success = $insertReq->execute([
    ':accountID'       => $accountID,
    ':targetAccountID' => $targetAccountID,
    ':time'            => time()
]);

if ($success) {
    echo "1"; // Success response
} else {
    echo "-1";
}
?>


