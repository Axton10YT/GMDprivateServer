<?php
chdir(dirname(__FILE__));
include "../lib/connection.php";
include "../../config/dailyChests.php";
require "../lib/XORCipher.php";
require "../lib/GJPCheck.php";
require_once "../lib/mainLib.php";
require "../lib/generateHash.php";
require_once "../lib/exploitPatch.php";

$gs = new mainLib();

$extID       = $gs->getIDFromPost();
$rewardType  = (int) ExploitPatch::remove($_POST["rewardType"]);
$udid        = ExploitPatch::remove($_POST["udid"]);
$accountID   = ExploitPatch::remove($_POST["accountID"]);
$userid      = $gs->getUserID($extID);

$chk = ExploitPatch::remove($_POST["chk"]);
$chk = XORCipher::cipher(base64_decode(substr($chk, 5)), 59182);

if (!in_array($rewardType, [1, 2], true)) {
    exit("-1");
}

$query = $db->prepare("SELECT chest1time, chest1count, chest2time, chest2count FROM users WHERE extID = :extID");
$query->execute([':extID' => $extID]);
$user = $query->fetch();

if (!$user) {
    exit("-1");
}

$currenttime = time() + 100;

// per-chest config, keyed to match $rewardType
$chests = [
    1 => [
        'time'    => $user['chest1time'],
        'count'   => (int) $user['chest1count'],
        'wait'    => $chest1wait,
        'items'   => $chest1items ?? [1, 2, 3, 4, 5, 6],
        'minOrbs' => $chest1minOrbs, 'maxOrbs' => $chest1maxOrbs,
        'minDiamonds' => $chest1minDiamonds, 'maxDiamonds' => $chest1maxDiamonds,
        'minKeys' => $chest1minKeys, 'maxKeys' => $chest1maxKeys,
        'timeCol' => 'chest1time', 'countCol' => 'chest1count',
    ],
    2 => [
        'time'    => $user['chest2time'],
        'count'   => (int) $user['chest2count'],
        'wait'    => $chest2wait,
        'items'   => $chest2items ?? [1, 2, 3, 4, 5, 6],
        'minOrbs' => $chest2minOrbs, 'maxOrbs' => $chest2maxOrbs,
        'minDiamonds' => $chest2minDiamonds, 'maxDiamonds' => $chest2maxDiamonds,
        'minKeys' => $chest2minKeys, 'maxKeys' => $chest2maxKeys,
        'timeCol' => 'chest2time', 'countCol' => 'chest2count',
    ],
];

$results = [];

foreach ([1, 2] as $type) {
    $c = $chests[$type];
    $diff = $currenttime - $c['time'];
    $left = max(0, $c['wait'] - $diff);
    $stuff = rand($c['minOrbs'], $c['maxOrbs']) . ","
           . rand($c['minDiamonds'], $c['maxDiamonds']) . ","
           . $c['items'][array_rand($c['items'])] . ","
           . rand($c['minKeys'], $c['maxKeys']);
    $count = $c['count'];

    if ($type === $rewardType) {
        if ($left != 0) {
            exit("-1");
        }
        $count++;
        $stmt = $db->prepare("UPDATE users SET {$c['countCol']} = :count, {$c['timeCol']} = :currenttime WHERE userID = :userID");
        $stmt->execute([':count' => $count, ':userID' => $userid, ':currenttime' => $currenttime]);
        $left = $c['wait'];
    }

    $results[$type] = ['left' => $left, 'stuff' => $stuff, 'count' => $count];
}

$string = base64_encode(XORCipher::cipher(
    "1:{$userid}:{$chk}:{$udid}:{$accountID}:"
    . "{$results[1]['left']}:{$results[1]['stuff']}:{$results[1]['count']}:"
    . "{$results[2]['left']}:{$results[2]['stuff']}:{$results[2]['count']}:"
    . "{$rewardType}",
    59182
));
$string = str_replace(['/', '+'], ['_', '-'], $string);
$hash = GenerateHash::genSolo4($string);

echo "SaKuJ" . $string . "|" . $hash;
