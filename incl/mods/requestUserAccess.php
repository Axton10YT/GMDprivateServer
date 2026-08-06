<?php
chdir(dirname(__FILE__));
// error_reporting(0);

require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/mainLib.php";

$gs = new mainLib();

// 1. Rate Limiting / IP Block Check
$userIP = $gs->getIP();
if ($gs->isIPBanned($userIP)) {
    exit("-1");
}

// 2. Validate GJP/GJP2 Credentials and Extract Account ID
$accountID = (int)GJPCheck::getAccountIDOrDie();

if ($accountID <= 0) {
    exit("-1");
}

// 3. Verify Account Exists & Is Not Banned
if ($gs->isAccountBanned($accountID)) {
    exit("-1");
}

// 4. Moderator Access Check
$hasModAccess = (int)$gs->getMaxValuePermission($accountID, "actionRequestMod");

if ($hasModAccess >= 1) {
    $permState = (int)$gs->getMaxValuePermission($accountID, "modBadgeLevel");

    // GD Client Response:
    // 1 = Normal Mod, 2 = Elder Mod (Values > 2 cap at 2)
    if ($permState >= 2) {
        exit("2");
    }

    if ($permState === 1) {
        exit("1");
    }
}

// Default denial for unauthorized accounts
exit("-1");
?>

