<?php
/**
 * ======================================================
 * TOURNAMENT_SYSTEM.PHP - Complete Tournament API
 * Ludo Tournament Platform - Multiplayer Tournament System
 * Version: 1.0.0 - 1vs1 + 1vs4 + Bracket System
 * ======================================================
 */

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once dirname(__DIR__) . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Access-Control-Allow-Origin: ' . BASE_URL);
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// AUTHENTICATION
if (!isLoggedIn()) {
    jsonResponse(false, 'Please login first', [], 401);
}

$userId = getCurrentUserId();
if (!$userId) {
    jsonResponse(false, 'Invalid session', [], 401);
}

// ROUTING
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    // User actions
    case 'list_active':
        handleListActiveTournaments();
        break;
    case 'get_tournament':
        handleGetTournament();
        break;
    case 'register':
        handleRegisterForTournament();
        break;
    case 'my_registrations':
        handleMyRegistrations();
        break;
    
    // Admin actions
    case 'admin_create':
        handleAdminCreateTournament();
        break;
    case 'admin_update':
        handleAdminUpdateTournament();
        break;
    case 'admin_delete':
        handleAdminDeleteTournament();
        break;
    case 'admin_start':
        handleAdminStartTournament();
        break;
    case 'admin_end':
        handleAdminEndTournament();
        break;
    case 'admin_distribute_prizes':
        handleAdminDistributePrizes();
        break;
    
    default:
        jsonResponse(false, 'Invalid action', [], 400);
}

// ==============================================
// LIST ACTIVE TOURNAMENTS
// ==============================================
function handleListActiveTournaments() {
    global $conn;
    
    try {
        $stmt = $conn->prepare("
            SELECT t.*, u.username as created_by_name,
                   (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.id) as registered_count
            FROM tournaments t
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.status IN ('scheduled', 'active', 'in_progress')
            ORDER BY t.entry_fee ASC
        ");
        $stmt->execute();
        $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Active tournaments retrieved', [
            'tournaments' => $tournaments ?: []
        ]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// GET SINGLE TOURNAMENT DETAILS
// ==============================================
function handleGetTournament() {
    global $conn;
    
    $tournamentId = intval($_GET['id'] ?? 0);
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT t.*, u.username as created_by_name,
                   (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.id) as registered_count
            FROM tournaments t
            LEFT JOIN users u ON t.created_by = u.id
            WHERE t.id = :tid
        ");
        $stmt->execute([':tid' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            jsonResponse(false, 'Tournament not found', [], 404);
        }
        
        // Calculate prize distribution
        $totalPool = $tournament['entry_fee'] * $tournament['total_players'];
        $platformFee = $totalPool * (PLATFORM_FEE / 100);
        $netPool = $totalPool - $platformFee;
        
        $tournament['calculated_first_prize'] = round($netPool * ($tournament['first_prize_percent'] / 100), 2);
        $tournament['calculated_second_prize'] = round($netPool * ($tournament['second_prize_percent'] / 100), 2);
        $tournament['calculated_third_prize'] = round($netPool * ($tournament['third_prize_percent'] / 100), 2);
        $tournament['total_pool'] = round($totalPool, 2);
        $tournament['platform_fee_amount'] = round($platformFee, 2);
        
        jsonResponse(true, 'Tournament details retrieved', ['tournament' => $tournament]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// REGISTER FOR TOURNAMENT
// ==============================================
function handleRegisterForTournament() {
    global $db, $conn, $userId;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['tournament_id'])) {
        jsonResponse(false, 'Missing tournament ID', [], 400);
    }
    
    $tournamentId = intval($input['tournament_id']);
    $csrfToken = $input['csrf_token'] ?? '';
    
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Get tournament with lock
        $stmt = $conn->prepare("
            SELECT * FROM tournaments WHERE id = :tid FOR UPDATE
        ");
        $stmt->execute([':tid' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $db->rollback();
            jsonResponse(false, 'Tournament not found', [], 404);
        }
        
        if ($tournament['status'] !== 'active' && $tournament['status'] !== 'scheduled') {
            $db->rollback();
            jsonResponse(false, 'Registration is closed for this tournament', [], 400);
        }
        
        // Check if already registered
        $stmt = $conn->prepare("SELECT id FROM tournament_registrations WHERE tournament_id = :tid AND user_id = :uid");
        $stmt->execute([':tid' => $tournamentId, ':uid' => $userId]);
        if ($stmt->fetch()) {
            $db->rollback();
            jsonResponse(false, 'You are already registered', [], 409);
        }
        
        // Check if tournament is full
        $stmt = $conn->prepare("SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = :tid");
        $stmt->execute([':tid' => $tournamentId]);
        $registeredCount = intval($stmt->fetchColumn());
        
        if ($registeredCount >= $tournament['total_players']) {
            $db->rollback();
            jsonResponse(false, 'Tournament is full', [], 409);
        }
        
        // Check wallet balance
        $stmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = :uid FOR UPDATE");
        $stmt->execute([':uid' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user['wallet_balance'] < $tournament['entry_fee']) {
            $db->rollback();
            jsonResponse(false, 'Insufficient balance. Need ₹' . number_format($tournament['entry_fee'], 2), [], 400);
        }
        
        // Deduct entry fee
        $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance - :fee WHERE id = :uid");
        $stmt->execute([':fee' => $tournament['entry_fee'], ':uid' => $userId]);
        
        // Register user
        $stmt = $conn->prepare("
            INSERT INTO tournament_registrations (tournament_id, user_id, entry_fee_paid, status)
            VALUES (:tid, :uid, :fee, 'registered')
        ");
        $stmt->execute([':tid' => $tournamentId, ':uid' => $userId, ':fee' => $tournament['entry_fee']]);
        
        // Update registered count
        $stmt = $conn->prepare("UPDATE tournaments SET registered_players = registered_players + 1 WHERE id = :tid");
        $stmt->execute([':tid' => $tournamentId]);
        
        // Record transaction
        $orderId = 'TREG-' . strtoupper(bin2hex(random_bytes(6)));
        $stmt = $conn->prepare("
            INSERT INTO transactions (user_id, tournament_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
            VALUES (:uid, :tid, :amt, 'debit', 'match_fee', :desc, :oid, 'success', :bb, :ba, CURRENT_TIMESTAMP)
        ");
        $stmt->execute([
            ':uid' => $userId, ':tid' => $tournamentId, ':amt' => $tournament['entry_fee'],
            ':desc' => "Tournament registration: {$tournament['name']}",
            ':oid' => $orderId,
            ':bb' => $user['wallet_balance'],
            ':ba' => $user['wallet_balance'] - $tournament['entry_fee']
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Successfully registered for tournament!', [
            'tournament_name' => $tournament['name'],
            'entry_fee' => $tournament['entry_fee'],
            'game_mode' => $tournament['game_mode'],
            'registered_count' => $registeredCount + 1,
            'total_players' => $tournament['total_players']
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// MY REGISTRATIONS
// ==============================================
function handleMyRegistrations() {
    global $conn, $userId;
    
    try {
        $stmt = $conn->prepare("
            SELECT tr.*, t.name as tournament_name, t.game_mode, t.entry_fee, t.total_players,
                   t.status as tournament_status, t.first_prize_percent, t.second_prize_percent, t.third_prize_percent
            FROM tournament_registrations tr
            JOIN tournaments t ON tr.tournament_id = t.id
            WHERE tr.user_id = :uid
            ORDER BY tr.registered_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $registrations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Your registrations', ['registrations' => $registrations ?: []]);
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: CREATE TOURNAMENT
// ==============================================
function handleAdminCreateTournament() {
    global $db, $conn;
    
    // Verify admin
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        jsonResponse(false, 'Invalid data', [], 400);
    }
    
    $required = ['name', 'game_mode', 'entry_fee', 'total_players'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            jsonResponse(false, "Missing: {$field}", [], 400);
        }
    }
    
    $name = trim($input['name']);
    $gameMode = in_array($input['game_mode'], ['1vs1', '1vs4']) ? $input['game_mode'] : '1vs1';
    $entryFee = floatval($input['entry_fee']);
    $totalPlayers = intval($input['total_players']);
    $firstPrizePercent = floatval($input['first_prize_percent'] ?? 60);
    $secondPrizePercent = floatval($input['second_prize_percent'] ?? 30);
    $thirdPrizePercent = floatval($input['third_prize_percent'] ?? 10);
    
    // Validate percentages
    if (($firstPrizePercent + $secondPrizePercent + $thirdPrizePercent) > 100) {
        jsonResponse(false, 'Prize percentages exceed 100%', [], 400);
    }
    
    $maxPlayers = $gameMode === '1vs1' ? 2 : 4;
    $totalPool = $entryFee * $totalPlayers;
    $platformFee = $totalPool * (PLATFORM_FEE / 100);
    $prizePool = $totalPool - $platformFee;
    
    $tournamentCode = 'T' . strtoupper(bin2hex(random_bytes(4)));
    
    try {
        $stmt = $conn->prepare("
            INSERT INTO tournaments (
                tournament_code, name, game_mode, entry_fee, prize_pool, platform_fee,
                max_players, total_players, min_players,
                first_prize_percent, second_prize_percent, third_prize_percent,
                first_prize_amount, second_prize_amount, third_prize_amount,
                status, created_by, created_at, updated_at
            ) VALUES (
                :code, :name, :mode, :fee, :prize, :pf,
                :max, :total, 2,
                :fp, :sp, :tp,
                :fa, :sa, :ta,
                'scheduled', :admin, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':code' => $tournamentCode, ':name' => $name, ':mode' => $gameMode,
            ':fee' => $entryFee, ':prize' => $prizePool, ':pf' => $platformFee,
            ':max' => $maxPlayers, ':total' => $totalPlayers,
            ':fp' => $firstPrizePercent, ':sp' => $secondPrizePercent, ':tp' => $thirdPrizePercent,
            ':fa' => round($prizePool * ($firstPrizePercent/100), 2),
            ':sa' => round($prizePool * ($secondPrizePercent/100), 2),
            ':ta' => round($prizePool * ($thirdPrizePercent/100), 2),
            ':admin' => $_SESSION['admin_id']
        ]);
        
        $tournamentId = $conn->lastInsertId();
        
        jsonResponse(true, 'Tournament created successfully!', [
            'tournament_id' => $tournamentId,
            'tournament_code' => $tournamentCode,
            'name' => $name,
            'game_mode' => $gameMode,
            'entry_fee' => $entryFee,
            'total_players' => $totalPlayers,
            'prize_pool' => round($prizePool, 2),
            'first_prize' => round($prizePool * ($firstPrizePercent/100), 2),
            'second_prize' => round($prizePool * ($secondPrizePercent/100), 2),
            'third_prize' => round($prizePool * ($thirdPrizePercent/100), 2)
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// ADMIN: UPDATE TOURNAMENT
// ==============================================
function handleAdminUpdateTournament() {
    global $db, $conn;
    
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $tournamentId = intval($input['id'] ?? 0);
    
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $fields = [];
        $params = [':id' => $tournamentId];
        
        if (isset($input['status'])) $fields[] = "status = :status"; $params[':status'] = $input['status'];
        if (isset($input['name'])) $fields[] = "name = :name"; $params[':name'] = $input['name'];
        if (isset($input['entry_fee'])) $fields[] = "entry_fee = :fee"; $params[':fee'] = floatval($input['entry_fee']);
        if (isset($input['total_players'])) $fields[] = "total_players = :tp"; $params[':tp'] = intval($input['total_players']);
        
        if (empty($fields)) {
            jsonResponse(false, 'No fields to update', [], 400);
        }
        
        $sql = "UPDATE tournaments SET " . implode(', ', $fields) . ", updated_at = CURRENT_TIMESTAMP WHERE id = :id";
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        jsonResponse(true, 'Tournament updated successfully');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: DELETE TOURNAMENT
// ==============================================
function handleAdminDeleteTournament() {
    global $conn;
    
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $tournamentId = intval($_GET['id'] ?? 0);
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("DELETE FROM tournaments WHERE id = :id AND status = 'scheduled'");
        $stmt->execute([':id' => $tournamentId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tournament not found or cannot be deleted', [], 400);
        }
        
        jsonResponse(true, 'Tournament deleted');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: START TOURNAMENT
// ==============================================
function handleAdminStartTournament() {
    global $conn;
    
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $tournamentId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    
    try {
        $stmt = $conn->prepare("UPDATE tournaments SET status = 'in_progress', tournament_start = CURRENT_TIMESTAMP WHERE id = :id AND status IN ('scheduled', 'active')");
        $stmt->execute([':id' => $tournamentId]);
        
        if ($stmt->rowCount() === 0) {
            jsonResponse(false, 'Tournament not found or already started', [], 400);
        }
        
        jsonResponse(true, 'Tournament started!');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: END TOURNAMENT & DISTRIBUTE PRIZES
// ==============================================
function handleAdminEndTournament() {
    global $conn;
    
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $tournamentId = intval($_POST['id'] ?? $_GET['id'] ?? 0);
    
    try {
        $stmt = $conn->prepare("UPDATE tournaments SET status = 'completed', end_time = CURRENT_TIMESTAMP WHERE id = :id");
        $stmt->execute([':id' => $tournamentId]);
        
        jsonResponse(true, 'Tournament ended');
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error', [], 500);
    }
}

// ==============================================
// ADMIN: DISTRIBUTE PRIZES
// ==============================================
function handleAdminDistributePrizes() {
    global $db, $conn;
    
    if (!isAdminLoggedIn()) {
        jsonResponse(false, 'Admin access required', [], 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $tournamentId = intval($input['id'] ?? 0);
    $firstWinnerId = intval($input['first_winner_id'] ?? 0);
    $secondWinnerId = intval($input['second_winner_id'] ?? 0);
    $thirdWinnerId = intval($input['third_winner_id'] ?? 0);
    
    if ($tournamentId <= 0) {
        jsonResponse(false, 'Invalid tournament ID', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get tournament
        $stmt = $conn->prepare("SELECT * FROM tournaments WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $tournamentId]);
        $tournament = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$tournament) {
            $db->rollback();
            jsonResponse(false, 'Tournament not found', [], 404);
        }
        
        $totalPool = $tournament['entry_fee'] * $tournament['total_players'];
        $platformFee = $totalPool * (PLATFORM_FEE / 100);
        $netPool = $totalPool - $platformFee;
        
        $firstPrize = round($netPool * ($tournament['first_prize_percent'] / 100), 2);
        $secondPrize = round($netPool * ($tournament['second_prize_percent'] / 100), 2);
        $thirdPrize = round($netPool * ($tournament['third_prize_percent'] / 100), 2);
        
        // Credit winners
        $winners = [
            $firstWinnerId => ['prize' => $firstPrize, 'status' => 'winner'],
            $secondWinnerId => ['prize' => $secondPrize, 'status' => 'runner_up'],
            $thirdWinnerId => ['prize' => $thirdPrize, 'status' => 'third_place']
        ];
        
        foreach ($winners as $winnerId => $data) {
            if ($winnerId > 0) {
                // Credit wallet
                $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + :prize, total_earnings = total_earnings + :prize WHERE id = :uid");
                $stmt->execute([':prize' => $data['prize'], ':uid' => $winnerId]);
                
                // Update registration
                $stmt = $conn->prepare("UPDATE tournament_registrations SET status = :status, prize_won = :prize WHERE tournament_id = :tid AND user_id = :uid");
                $stmt->execute([':status' => $data['status'], ':prize' => $data['prize'], ':tid' => $tournamentId, ':uid' => $winnerId]);
                
                // Record transaction
                $orderId = 'PRIZE-' . strtoupper(bin2hex(random_bytes(6)));
                $stmt = $conn->prepare("
                    INSERT INTO transactions (user_id, tournament_id, amount, type, source, description, order_id, status, balance_before, balance_after, created_at)
                    VALUES (:uid, :tid, :amt, 'credit', 'match_win', :desc, :oid, 'success', 
                    (SELECT wallet_balance FROM users WHERE id = :uid) - :amt,
                    (SELECT wallet_balance FROM users WHERE id = :uid), CURRENT_TIMESTAMP)
                ");
                $stmt->execute([
                    ':uid' => $winnerId, ':tid' => $tournamentId, ':amt' => $data['prize'],
                    ':desc' => "Tournament prize: {$tournament['name']}",
                    ':oid' => $orderId
                ]);
            }
        }
        
        // Update tournament
        $stmt = $conn->prepare("
            UPDATE tournaments SET 
                first_prize_amount = :fp, second_prize_amount = :sp, third_prize_amount = :tp,
                winner_id = :wid, status = 'completed', end_time = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':fp' => $firstPrize, ':sp' => $secondPrize, ':tp' => $thirdPrize,
            ':wid' => $firstWinnerId, ':id' => $tournamentId
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Prizes distributed successfully!', [
            'first_prize' => $firstPrize,
            'second_prize' => $secondPrize,
            'third_prize' => $thirdPrize,
            'platform_fee' => round($platformFee, 2),
            'total_pool' => round($totalPool, 2)
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollback();
        jsonResponse(false, 'Database error', [], 500);
    }
}
?>
