<?php
chdir(dirname(__FILE__));
include "../lib/connection.php";
require_once "../lib/exploitPatch.php";

if (!isset($_POST["str"])) {
    exit("-1");
}

$str  = ExploitPatch::remove($_POST["str"]);
$page = isset($_POST["page"]) ? max(0, (int)ExploitPatch::remove($_POST["page"])) : 0;
$offset = $page * 10; // optimised by removing duplicates

// 1. Fetch Users
$sql = "SELECT userName, userID, coins, userCoins, icon, color1, color2, color3, iconType, special, extID, stars, creatorPoints, demons, diamonds, moons 
        FROM users 
        WHERE userID = :str OR userName LIKE CONCAT('%', :str, '%') 
        ORDER BY stars DESC 
        LIMIT 10 OFFSET :offset";

$query = $db->prepare($sql);
$query->bindValue(':str', $str);
$query->bindValue(':offset', $offset, PDO::PARAM_INT);
$query->execute();
$result = $query->fetchAll(PDO::FETCH_ASSOC);

if (empty($result)) {
    exit("-1");
}

// 2. Count Total Matching Users
$countquery = $db->prepare("SELECT COUNT(*) FROM users WHERE userID = :str OR userName LIKE CONCAT('%', :str, '%')");
$countquery->execute([':str' => $str]);
$usercount = (int)$countquery->fetchColumn();

// 3. Format GD Response String
$userChunks = [];

foreach ($result as $user) {
    $extID = is_numeric($user['extID']) ? $user['extID'] : 0;
    $creatorPoints = (int)$user["creatorPoints"];

    $userChunks[] = "1:{$user['userName']}:2:{$user['userID']}:13:{$user['coins']}:17:{$user['userCoins']}:9:{$user['icon']}:10:{$user['color1']}:11:{$user['color2']}:51:{$user['color3']}:14:{$user['iconType']}:15:{$user['special']}:16:{$extID}:3:{$user['stars']}:8:{$creatorPoints}:4:{$user['demons']}:46:{$user['diamonds']}:52:{$user['moons']}";
}

echo implode("|", $userChunks) . "#{$usercount}:{$offset}:10";
?>
