<?php
chdir(dirname(__FILE__));
//error_reporting(0);
include "../lib/connection.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

$subject     = ExploitPatch::remove($_POST["subject"]);
$toAccountID = ExploitPatch::number($_POST["toAccountID"]);
$body        = ExploitPatch::remove($_POST["body"]);
$secret      = ExploitPatch::remove($_POST["secret"]);

$accID = GJPCheck::getAccountIDOrDie();

if ($accID == $toAccountID) {
    exit("-1");
}

// username lookup
$stmt = $db->prepare("SELECT userName FROM users WHERE extID = :accID LIMIT 1");
$stmt->execute([':accID' => $accID]);
$userName = $stmt->fetchColumn();

$userID = $gs->getUserID($accID);
$uploadDate = time();

// blocked check
$stmt = $db->prepare("SELECT 1 FROM blocks WHERE person1 = :toAccountID AND person2 = :accID LIMIT 1");
$stmt->execute([':toAccountID' => $toAccountID, ':accID' => $accID]);
$isBlocked = (bool) $stmt->fetchColumn();

// recipient's message-privacy setting (mS: 0=all, 1=friends only, 2=none)
$stmt = $db->prepare("SELECT mS FROM accounts WHERE accountID = :toAccountID LIMIT 1");
$stmt->execute([':toAccountID' => $toAccountID]);
$mS = (int) $stmt->fetchColumn();

if ($mS === 2) {
    exit("-1");
}

if ($isBlocked) {
    exit("-1");
}

if ($mS === 1) {
    $stmt = $db->prepare("SELECT 1 FROM friendships 
                           WHERE (person1 = :accID AND person2 = :toAccountID)
                              OR (person2 = :accID AND person1 = :toAccountID)
                           LIMIT 1");
    $stmt->execute([':accID' => $accID, ':toAccountID' => $toAccountID]);
    $isFriend = (bool) $stmt->fetchColumn();

    if (!$isFriend) {
        exit("-1");
    }
}

$stmt = $db->prepare("INSERT INTO messages (subject, body, accID, userID, userName, toAccountID, secret, timestamp)
                       VALUES (:subject, :body, :accID, :userID, :userName, :toAccountID, :secret, :uploadDate)");
$stmt->execute([
    ':subject'     => $subject,
    ':body'        => $body,
    ':accID'       => $accID,
    ':userID'      => $userID,
    ':userName'    => $userName,
    ':toAccountID' => $toAccountID,
    ':secret'      => $secret,
    ':uploadDate'  => $uploadDate,
]);

echo 1;
