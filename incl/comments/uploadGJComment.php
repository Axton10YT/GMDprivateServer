<?php
chdir(dirname(__FILE__));

include "../lib/connection.php";
require_once "../lib/mainLib.php";
require_once "../lib/GJPCheck.php";
require_once "../lib/exploitPatch.php";
require_once "../lib/commands.php";

$mainLib = new mainLib();

// 1. Core Parameter Extraction
$userName    = !empty($_POST['userName']) ? ExploitPatch::charclean($_POST['userName']) : "";
$gameVersion = !empty($_POST['gameVersion']) ? (int)ExploitPatch::number($_POST['gameVersion']) : 0;
$comment     = !empty($_POST['comment']) ? ExploitPatch::remove($_POST['comment']) : "";
$levelID     = isset($_POST['levelID']) ? ExploitPatch::numbercolon($_POST['levelID']) : 0;
$percent     = !empty($_POST['percent']) ? (int)ExploitPatch::number($_POST['percent']) : 0;

if (empty($comment)) {
    exit("-1");
}

// Get Authenticated Account/User Identifiers
$accountID = $mainLib->getIDFromPost();
if (empty($accountID) || $accountID <= 0) {
    exit("-1");
}

$userID     = $mainLib->getUserID($accountID, $userName);
$uploadDate = time();

// Decode comment based on version
if ($gameVersion >= 20) {
    $decodedComment = base64_decode(strtr($comment, '-_', '+/')) ?: $comment;
} else {
    $decodedComment = $comment;
    $comment = base64_encode($comment); // Normalizing storage string
}

$decodedComment = trim($decodedComment);

// 2. Command Execution Handling
// Checks if comment starts with !, ?, or standard command prefixes defined in commands.php
if (class_exists('Commands') && method_exists('Commands', 'doCommands')) {
    $commandResult = Commands::doCommands($accountID, $decodedComment, $levelID);
    if ($commandResult) {
        // If doCommands returns a string or true, display pop-up message in GD
        $msg = is_string($commandResult) ? $commandResult : "Command executed successfully!";
        exit($gameVersion >= 21 ? "temp_0_{$msg}" : "-1");
    }
}

// 3. Normal Comment Insertion
$query = $db->prepare("INSERT INTO comments (userName, comment, levelID, userID, timeStamp, percent) 
                       VALUES (:userName, :comment, :levelID, :userID, :uploadDate, :percent)");

$success = $query->execute([
    ':userName'   => $userName,
    ':comment'    => $comment,
    ':levelID'    => $levelID,
    ':userID'     => $userID,
    ':uploadDate' => $uploadDate,
    ':percent'    => $percent
]);

echo $success ? "1" : "-1";
?>
