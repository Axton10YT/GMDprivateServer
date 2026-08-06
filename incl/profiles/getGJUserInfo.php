<?php
chdir(dirname(__FILE__));
include "../lib/connection.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/mainLib.php";
$gs = new mainLib();

/**
 * Small helper to kill the repeated prepare/execute/fetch boilerplate.
 * $mode: 'col' = fetchColumn, 'row' = fetch (single row), 'all' = fetchAll, 'raw' = return the statement itself
 */
function q($db, $sql, $params = [], $mode = 'col') {
	$stmt = $db->prepare($sql);
	$stmt->execute($params);
	switch ($mode) {
		case 'row':  return $stmt->fetch();
		case 'all':  return $stmt->fetchAll();
		case 'raw':  return $stmt;
		default:     return $stmt->fetchColumn();
	}
}

$appendix = "";
$extid = ExploitPatch::number($_POST["targetAccountID"]);
$me = !empty($_POST["accountID"]) ? GJPCheck::getAccountIDOrDie() : 0;

// Blocked check
$blocked = q($db,
	"SELECT count(*) FROM blocks WHERE (person1 = :extid AND person2 = :me) OR (person2 = :extid AND person1 = :me)",
	[':extid' => $extid, ':me' => $me]
);
if ($blocked > 0) exit("-1");

// Target user
$userStmt = q($db, "SELECT * FROM users WHERE extID = :extid", [':extid' => $extid], 'raw');
if ($userStmt->rowCount() == 0) exit("-1");
$user = $userStmt->fetch();

// creatorPoints is already an int column — no need to round with a bogus mode arg
// (the old `round($val, PHP_ROUND_HALF_DOWN)` silently used PHP_ROUND_HALF_DOWN's
// int value (2) as the *precision* argument, which is not what was intended)
$creatorpoints = (int)$user["creatorPoints"];

// Star rank — count how many users have more stars than this one
$rank = 0;
if ($user['isBanned'] == 0) {
	$higherCount = q($db,
		"SELECT count(*) FROM users WHERE stars > :stars AND isBanned = 0",
		[':stars' => $user["stars"]]
	);
	$rank = $higherCount + 1;
}

// Account info
$accinfo = q($db,
	"SELECT youtubeurl,twitter,twitch,discord,instagram,tiktok,custom, frS, mS, cS FROM accounts WHERE accountID = :extID",
	[':extID' => $extid],
	'row'
);
$reqsstate     = $accinfo["frS"];
$msgstate      = $accinfo["mS"];
$commentstate  = $accinfo["cS"];
$badge         = $gs->getMaxValuePermission($extid, "modBadgeLevel");

if ($me == $extid) {
	// Viewing your own profile — include notification counters
	$pms      = q($db, "SELECT count(*) FROM messages WHERE toAccountID = :me AND isNew=0", [':me' => $me]);
	$requests = q($db, "SELECT count(*) FROM friendreqs WHERE toAccountID = :me", [':me' => $me]);
	$friends  = q($db,
		"SELECT count(*) FROM friendships WHERE (person1 = :me AND isNew2 = '1') OR (person2 = :me AND isNew1 = '1')",
		[':me' => $me]
	);

	$friendstate = 0;
	// 38/39/40 are notification counters
	$appendix = ":38:{$pms}:39:{$requests}:40:{$friends}";
} else {
	// Viewing someone else's profile — figure out friend state
	// 31 = 0 not friends / 1 already friends / 3 incoming request / 4 outgoing request
	$friendstate = 0;

	$incStmt = q($db,
		"SELECT ID,comment,uploadDate FROM friendreqs WHERE accountID = :extid AND toAccountID = :me",
		[':extid' => $extid, ':me' => $me],
		'raw'
	);
	$incoming = $incStmt->rowCount() > 0 ? $incStmt->fetch() : null;
	if ($incoming) {
		$friendstate = 3;
	}

	$outgoingCount = q($db,
		"SELECT count(*) FROM friendreqs WHERE toAccountID = :extid AND accountID = :me",
		[':extid' => $extid, ':me' => $me]
	);
	if ($outgoingCount > 0) {
		$friendstate = 4;
	}

	$alreadyFriends = q($db,
		"SELECT count(*) FROM friendships WHERE (person1 = :me AND person2 = :extID) OR (person2 = :me AND person1 = :extID)",
		[':me' => $me, ':extID' => $extid]
	);
	if ($alreadyFriends > 0) {
		$friendstate = 1;
	}

	// 32/35/37 = incoming request ID / comment / formatted date
	if ($incoming) {
		$uploaddate = date("d/m/Y G.i", $incoming["uploadDate"]);
		$appendix = ":32:{$incoming['ID']}:35:{$incoming['comment']}:37:{$uploaddate}";
	}
}

$user['extID'] = is_numeric($user['extID']) ? $user['extID'] : 0;

echo "1:" . $user["userName"] .
	":2:" . $user["userID"] .
	":13:" . $user["coins"] .
	":17:" . $user["userCoins"] .
	":10:" . $user["color1"] .
	":11:" . $user["color2"] .
	":51:" . $user["color3"] .
	":3:" . $user["stars"] .
	":46:" . $user["diamonds"] .
	":52:" . $user["moons"] .
	":4:" . $user["demons"] .
	":8:" . $creatorpoints .
	":18:" . $msgstate .
	":19:" . $reqsstate .
	":50:" . $commentstate .
	":20:" . $accinfo["youtubeurl"] .
	":21:" . $user["accIcon"] .
	":22:" . $user["accShip"] .
	":23:" . $user["accBall"] .
	":24:" . $user["accBird"] .
	":25:" . $user["accDart"] .
	":26:" . $user["accRobot"] .
	":28:" . $user["accGlow"] .
	":43:" . $user["accSpider"] .
	":48:" . $user["accExplosion"] .
	":53:" . $user["accSwing"] .
	":54:" . $user["accJetpack"] .
	":30:" . $rank .
	":16:" . $user["extID"] .
	":31:" . $friendstate .
	":44:" . $accinfo["twitter"] .
	":45:" . $accinfo["twitch"] .
	":49:" . $badge .
	":55:" . $user["dinfo"] .
	":56:" . $user["sinfo"] .
	":57:" . $user["pinfo"] .
	":58:" . $accinfo["discord"] .
	":59:" . $accinfo["instagram"] .
	":60:" . $accinfo["tiktok"] .
	":61:" . $accinfo["custom"] .
	$appendix .
	":29:1";
