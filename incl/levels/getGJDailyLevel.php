<?php
chdir(dirname(__FILE__));
include "../lib/connection.php";
require_once "../lib/exploitPatch.php";

// 1. Resolve request type (0 = Daily, 1 = Weekly, 2 = Event)
$type = 0;
if (isset($_POST["type"])) {
    $type = (int)ExploitPatch::remove($_POST["type"]);
} elseif (!empty($_POST["weekly"])) {
    $type = (int)ExploitPatch::remove($_POST["weekly"]);
}

$current = time();

// 2. Fetch current active feature ID (and optional expiration timestamp)
$query = $db->prepare("SELECT feaID, timestamp FROM dailyfeatures WHERE timestamp <= :current AND type = :type ORDER BY timestamp DESC LIMIT 1");
$query->execute([
    ':current' => $current,
    ':type'    => $type
]);

if ($query->rowCount() === 0) {
    exit("-1");
}

$feature = $query->fetch(PDO::FETCH_ASSOC);
$feaID   = (int)$feature['feaID'];

// 3. Compute unique Geometry Dash daily/weekly/event ID offset
switch ($type) {
    case 1: // Weekly Demon
        $dailyID = $feaID + 100001;
        $midnight = strtotime("next monday 00:00:00");
        break;

    case 2: // Event Level
        $dailyID = $feaID + 200001;
        // If your table has a specific end time column, use it; otherwise default to event schedule (e.g. next Monday)
        $midnight = !empty($feature['timestamp_end']) ? (int)$feature['timestamp_end'] : strtotime("next monday 00:00:00");
        break;

    case 0: // Daily Level
    default:
        $dailyID = $feaID;
        $midnight = strtotime("tomorrow 00:00:00");
        break;
}

// 4. Calculate remaining time in seconds
$timeleft = max(0, $midnight - $current);

// Format output expected by client: [ID]|[seconds_left]
echo "{$dailyID}|{$timeleft}";
?>
