<?php
require_once __DIR__ . "/XORCipher.php";
require_once __DIR__ . "/generatePass.php";
require_once __DIR__ . "/mainLib.php";

class GJPCheck {
    
    /**
     * Validates GJP password string against accountID
     *
     * @param string $gjp
     * @param int|string $accountID
     * @return int 1 on success, 0 or negative on failure
     */
    public static function check($gjp, $accountID) {
        if (empty($gjp) || empty($accountID)) {
            return 0;
        }

        global $db, $sessionGrants;
        
        if (!isset($db)) {
            include __DIR__ . "/connection.php";
        }
        if (!isset($sessionGrants)) {
            include_once __DIR__ . "/../../config/security.php";
        }

        $ml = new mainLib();

        // 1. Session Grants Check (Fast Path)
        if (!empty($sessionGrants)) {
            $ip = $ml->getIP();
            $cutoff = time() - 3600;

            $query = $db->prepare("SELECT 1 FROM actions WHERE type = 16 AND value = :accountID AND value2 = :ip AND timestamp > :timestamp LIMIT 1");
            $query->execute([
                ':accountID' => $accountID,
                ':ip'        => $ip,
                ':timestamp' => $cutoff
            ]);

            if ($query->fetchColumn()) {
                return 1;
            }
        }

        // 2. Decode GJP Cipher String
        $gjpDecoded = base64_decode(strtr($gjp, '-_', '+/'));
        if ($gjpDecoded === false) {
            return 0;
        }

        $password = XORCipher::cipher($gjpDecoded, 37526);

        // 3. Password Verification
        $validationResult = GeneratePass::isValid($accountID, $password);

        // 4. Cache Valid Session Action
        if ($validationResult === 1 && !empty($sessionGrants)) {
            $ip = $ml->getIP();
            $query = $db->prepare("INSERT INTO actions (type, value, value2, timestamp) VALUES (16, :accountID, :ip, :timestamp)");
            $query->execute([
                ':accountID' => $accountID,
                ':ip'        => $ip,
                ':timestamp' => time()
            ]);
        }

        return $validationResult;
    }

    public static function validateGJPOrDie($gjp, $accountID) {
        if (self::check($gjp, $accountID) !== 1) {
            exit("-1");
        }
    }

    public static function validateGJP2OrDie($gjp2, $accountID) {
        if (GeneratePass::isGJP2Valid($accountID, $gjp2) !== 1) {
            exit("-1");
        }
    }

    /**
     * Extracts accountID from POST parameters and validates GJP / GJP2
     *
     * @return string|int $accountID
     */
    public static function getAccountIDOrDie() {
        require_once __DIR__ . "/exploitPatch.php";

        if (empty($_POST['accountID'])) {
            exit("-1");
        }

        $accountID = ExploitPatch::remove($_POST["accountID"]);

        if (!empty($_POST['gjp'])) {
            self::validateGJPOrDie($_POST['gjp'], $accountID);
        } elseif (!empty($_POST['gjp2'])) {
            self::validateGJP2OrDie($_POST['gjp2'], $accountID);
        } else {
            exit("-1");
        }

        return $accountID;
    }
}
?>
