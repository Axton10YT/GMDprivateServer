<?php
// Turn on error reporting to debug any missing include issues instantly
ini_set('display_errors', 1);
error_reporting(E_ALL);

chdir(dirname(__FILE__));

// Cvolton Dashboard includes live inside incl/
include "incl/lib/connection.php";
require_once "incl/lib/GJPCheck.php";
require_once "incl/lib/exploitPatch.php";
require_once "incl/lib/mainLib.php";

$gs = new mainLib();
$message = "";
$messageType = "";

$gs = new mainLib();
$message = "";
$messageType = "";

// Handle Form Submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $userName = ExploitPatch::charclean($_POST["userName"] ?? '');
    $gjpInput = ExploitPatch::remove($_POST["gjp"] ?? '');
    $levelID  = (int)ExploitPatch::number($_POST["levelID"] ?? 0);
    $type     = (int)ExploitPatch::number($_POST["type"] ?? 0);

    if (empty($userName) || empty($gjpInput) || $levelID <= 0) {
        $message = "Please fill in all required fields properly.";
        $messageType = "danger";
    } else {
        // Authenticate User
        $accountID = $gs->checkPassword($userName, $gjpInput);
        
        if ($accountID <= 0) {
            $message = "Invalid username or password/GJP.";
            $messageType = "danger";
        } else {
            // Check Elder Moderator Permission (modLevel >= 2)
            $permQuery = $db->prepare("SELECT modLevel FROM accounts WHERE accountID = :accountID LIMIT 1");
            $permQuery->execute([':accountID' => $accountID]);
            $modLevel = (int)$permQuery->fetchColumn();

            if ($modLevel < 2) {
                $message = "Access Denied: You must be an Elder Moderator or higher.";
                $messageType = "danger";
            } else {
                // Check Level Existence
                $lvlQuery = $db->prepare("SELECT levelID FROM levels WHERE levelID = :levelID LIMIT 1");
                $lvlQuery->execute([':levelID' => $levelID]);

                if (!$lvlQuery->fetchColumn()) {
                    $message = "Level ID {$levelID} does not exist in the database.";
                    $messageType = "danger";
                } else {
                    // Get next feature ID
                    $feaQuery = $db->prepare("SELECT MAX(feaID) FROM dailyfeatures WHERE type = :type");
                    $feaQuery->execute([':type' => $type]);
                    $nextFeaID = ((int)$feaQuery->fetchColumn()) + 1;

                    // Insert Special Level
                    $insertStmt = $db->prepare("INSERT INTO dailyfeatures (levelID, timestamp, type, feaID) VALUES (:levelID, :timestamp, :type, :feaID)");
                    $inserted = $insertStmt->execute([
                        ':levelID'   => $levelID,
                        ':timestamp' => time(),
                        ':type'      => $type,
                        ':feaID'     => $nextFeaID
                    ]);

                    if ($inserted) {
                        $typeName = match($type) { 0 => 'Daily Level', 1 => 'Weekly Demon', 2 => 'Event Level', default => 'Special Level' };
                        $message = "Success! Level ID <strong>{$levelID}</strong> set as <strong>{$typeName}</strong>.";
                        $messageType = "success";
                    } else {
                        $message = "Database error while setting special level.";
                        $messageType = "danger";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Special Levels Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        .menubar { background-color: #1e2124; color: #d6ddde; }
        .content { background-color: #36393e; color: #a7a8aa; }
        html, body { height: 100%; background-color: #36393e; }
        .fill { flex: 1; }
        .btn-primary, .btn-primary:visited {
            background-color: #212529;
            border-color: #212529;
            color: #d6ddde;
        }
        .btn-primary:hover, .btn-primary:active, .btn-primary:focus {
            background-color: #47494e;
            border-color: #47494e;
            color: #ffffff;
        }
        .buffer { margin: 20px; }
        .container-box {
            height: 100%;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .black-dropdown { background-color: #e7e7e7; }
        .panel-card {
            background-color: #1e2124;
            border: 1px solid #2f3136;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.4);
            padding: 30px;
            width: 100%;
            max-width: 480px;
        }
        .form-control, .form-select {
            background-color: #2f3136;
            border: 1px solid #212529;
            color: #d6ddde;
        }
        .form-control:focus, .form-select:focus {
            background-color: #36393e;
            color: #ffffff;
            border-color: #47494e;
            box-shadow: none;
        }
        .form-label { color: #d6ddde; font-weight: 500; }
    </style>
</head>
<body class="content">

    <nav class="navbar navbar-expand-lg menubar px-4 border-bottom border-dark">
        <span class="navbar-brand mb-0 h1 text-light">Special Levels Settings</span>
        <div class="fill"></div>
        <span class="badge bg-danger">Elder Moderator Required</span>
    </nav>

    <div class="container-box buffer">
        <div class="panel-card">
            <h3 class="text-center mb-4" style="color: #d6ddde;">Set Special Level</h3>

            <?php if (!empty($message)): ?>
                <div class="alert alert-<?= $messageType ?> text-center py-2 mb-3" role="alert">
                    <?= $message ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="mb-3">
                    <label for="userName" class="form-label">Elder Mod Username</label>
                    <input type="text" class="form-control" id="userName" name="userName" required placeholder="Username">
                </div>

                <div class="mb-3">
                    <label for="gjp" class="form-label">Password / GJP</label>
                    <input type="password" class="form-control" id="gjp" name="gjp" required placeholder="Account Password">
                </div>

                <hr class="my-4" style="border-color: #47494e;">

                <div class="mb-3">
                    <label for="levelID" class="form-label">Level ID</label>
                    <input type="number" class="form-control" id="levelID" name="levelID" min="1" required placeholder="e.g. 123456">
                </div>

                <div class="mb-4">
                    <label for="type" class="form-label">Special Level Assignment</label>
                    <select class="form-select black-dropdown" id="type" name="type">
                        <option value="0" style="color: #000;">Daily Level</option>
                        <option value="1" style="color: #000;">Weekly Demon</option>
                        <option value="2" style="color: #000;">Event Level</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2">Set Special Level</button>
            </form>
        </div>
    </div>

</body>
</html>
