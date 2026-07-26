<?php
/**
 * ======================================================
 * ADMIN_DISPUTES.PHP - Dispute & Refund Management API
 * Ludo Tournament Platform - Admin Dispute Resolution
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

// Prevent direct access
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

session_start();

// ==============================================
// AUTHENTICATION & AUTHORIZATION
// ==============================================
if (!isset($_SESSION['admin_id']) || !isset($_SESSION['admin_token'])) {
    jsonResponse(false, 'Unauthorized - Admin access required', [], 401);
}

try {
    $db = Database::getInstance();
    $conn = $db->getConnection();
    
    // Verify admin with database session token
    $stmt = $conn->prepare("
        SELECT u.id, u.username, u.is_admin, u.is_active 
        FROM users u
        JOIN sessions s ON u.id = s.user_id
        WHERE u.id = :admin_id 
        AND u.is_admin = 1 
        AND u.is_active = 1
        AND s.session_token = :token
        AND s.is_active = 1
        AND s.expires_at > NOW()
    ");
    $stmt->execute([
        ':admin_id' => $_SESSION['admin_id'],
        ':token' => $_SESSION['admin_token']
    ]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        http_response_code(401);
        jsonResponse(false, 'Unauthorized - Invalid admin session', [], 401);
    }
    
} catch (Exception $e) {
    jsonResponse(false, 'Authentication error: ' . $e->getMessage(), [], 500);
}

// ==============================================
// ROUTING
// ==============================================
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'get':
        handleGet();
        break;
    case 'get_messages':
        handleGetMessages();
        break;
    case 'add_message':
        handleAddMessage();
        break;
    case 'investigate':
        handleInvestigate();
        break;
    case 'resolve':
        handleResolve();
        break;
    case 'close':
        handleClose();
        break;
    case 'get_stats':
        handleStats();
        break;
    case 'declare_winner':
        handleDeclareWinner();
        break;
    case 'refund_match':
        handleRefundMatch();
        break;
    case 'send_reply':
        handleSendReply();
        break;
    default:
        jsonResponse(false, 'Invalid action specified', [], 400);
        break;
}

// ==============================================
// HANDLER: List Dispute Tickets
// ==============================================
function handleList() {
    global $db, $conn;
    
    $status = isset($_GET['status']) ? $_GET['status'] : '';
    $limit = intval($_GET['limit'] ?? 50);
    $offset = intval($_GET['offset'] ?? 0);
    $priority = isset($_GET['priority']) ? $_GET['priority'] : '';
    $search = isset($_GET['search']) ? '%' . $_GET['search'] . '%' : '';
    
    try {
        $where = "1=1";
        $params = [];
        
        if (!empty($status)) {
            $where .= " AND dt.status = :status";
            $params[':status'] = $status;
        }
        
        if (!empty($priority)) {
            $where .= " AND dt.priority = :priority";
            $params[':priority'] = $priority;
        }
        
        if (!empty($search)) {
            $where .= " AND (dt.ticket_number LIKE :search OR dt.subject LIKE :search OR dt.description LIKE :search OR u.username LIKE :search)";
            $params[':search'] = $search;
        }
        
        $stmt = $conn->prepare("SELECT COUNT(*) as total FROM dispute_tickets dt LEFT JOIN users u ON dt.user_id = u.id WHERE {$where}");
        $stmt->execute($params);
        $total = intval($stmt->fetchColumn());
        
        $stmt = $conn->prepare("
            SELECT 
                dt.id,
                dt.match_id,
                dt.user_id,
                dt.opponent_id,
                dt.ticket_number,
                dt.subject,
                dt.description,
                dt.priority,
                dt.status,
                dt.resolution_type,
                dt.resolution_notes,
                dt.refund_amount,
                dt.created_at,
                dt.updated_at,
                u.username as user_name,
                u.mobile as user_mobile,
                u.email as user_email,
                opp.username as opponent_name,
                m.room_code,
                m.entry_fee,
                m.prize_pool,
                m.status as match_status,
                m.winner_name,
                m.winning_amount,
                (SELECT COUNT(*) FROM ticket_messages WHERE ticket_id = dt.id) as message_count
            FROM dispute_tickets dt
            LEFT JOIN users u ON dt.user_id = u.id
            LEFT JOIN users opp ON dt.opponent_id = opp.id
            LEFT JOIN matches m ON dt.match_id = m.id
            WHERE {$where}
            ORDER BY 
                CASE dt.priority
                    WHEN 'urgent' THEN 1
                    WHEN 'high' THEN 2
                    WHEN 'medium' THEN 3
                    WHEN 'low' THEN 4
                END,
                dt.created_at ASC
            LIMIT :limit OFFSET :offset
        ");
        
        $params[':limit'] = $limit;
        $params[':offset'] = $offset;
        $stmt->execute($params);
        $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Tickets retrieved', [
            'tickets' => $tickets,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset
        ]);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Single Ticket
// ==============================================
function handleGet() {
    global $db, $conn;
    
    $id = intval($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(false, 'Invalid ticket ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                dt.*,
                u.username as user_name,
                u.mobile as user_mobile,
                u.email as user_email,
                u.wallet_balance as user_balance,
                opp.username as opponent_name,
                opp.mobile as opponent_mobile,
                m.room_code,
                m.entry_fee,
                m.prize_pool,
                m.status as match_status,
                m.player1_name,
                m.player2_name,
                m.winner_name,
                m.winning_amount,
                m.turn_number,
                m.started_at,
                m.completed_at,
                resolved_by_admin.username as resolved_by_name
            FROM dispute_tickets dt
            LEFT JOIN users u ON dt.user_id = u.id
            LEFT JOIN users opp ON dt.opponent_id = opp.id
            LEFT JOIN matches m ON dt.match_id = m.id
            LEFT JOIN users resolved_by_admin ON dt.resolved_by = resolved_by_admin.id
            WHERE dt.id = :id
        ");
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            jsonResponse(false, 'Ticket not found', [], 404);
        }
        
        jsonResponse(true, 'Ticket retrieved', $ticket);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Get Ticket Messages
// ==============================================
function handleGetMessages() {
    global $db, $conn;
    
    $ticketId = intval($_GET['ticket_id'] ?? 0);
    if ($ticketId <= 0) {
        jsonResponse(false, 'Invalid ticket ID', [], 400);
    }
    
    try {
        $stmt = $conn->prepare("
            SELECT 
                tm.id,
                tm.user_id,
                tm.message,
                tm.screenshot_url,
                tm.is_admin,
                tm.created_at,
                u.username,
                u.mobile
            FROM ticket_messages tm
            LEFT JOIN users u ON tm.user_id = u.id
            WHERE tm.ticket_id = :ticket_id
            ORDER BY tm.created_at ASC
        ");
        $stmt->execute([':ticket_id' => $ticketId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        jsonResponse(true, 'Messages retrieved', $messages);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Add Admin Message
// ==============================================
function handleAddMessage() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['ticket_id']) || !isset($input['message'])) {
        jsonResponse(false, 'Missing required fields', [], 400);
    }
    
    $ticketId = intval($input['ticket_id']);
    $message = trim($input['message']);
    $screenshot = $input['screenshot_url'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    if (empty($message)) {
        jsonResponse(false, 'Message cannot be empty', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Check ticket exists
        $stmt = $conn->prepare("SELECT id, status FROM dispute_tickets WHERE id = :id FOR UPDATE");
        $stmt->execute([':id' => $ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            $db->rollback();
            jsonResponse(false, 'Ticket not found', [], 404);
        }
        
        // Add message
        $stmt = $conn->prepare("
            INSERT INTO ticket_messages (
                ticket_id,
                user_id,
                message,
                screenshot_url,
                is_admin,
                created_at
            ) VALUES (
                :ticket_id,
                :user_id,
                :message,
                :screenshot,
                1,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':ticket_id' => $ticketId,
            ':user_id' => $_SESSION['admin_id'],
            ':message' => $message,
            ':screenshot' => $screenshot
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Message added successfully', [
            'message_id' => $conn->lastInsertId()
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Investigate Ticket
// ==============================================
function handleInvestigate() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing ticket ID', [], 400);
    }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            UPDATE dispute_tickets 
            SET 
                status = 'investigating',
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nInvestigation started: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            AND status = 'open'
        ");
        $stmt->execute([
            ':notes' => $notes,
            ':id' => $id
        ]);
        
        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Ticket not found or not in open status', [], 400);
        }
        
        // Add system message
        $stmt = $conn->prepare("
            INSERT INTO ticket_messages (
                ticket_id,
                user_id,
                message,
                is_admin,
                created_at
            ) VALUES (
                :ticket_id,
                :user_id,
                '🔍 Investigation started by admin.',
                1,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':ticket_id' => $id,
            ':user_id' => $_SESSION['admin_id']
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Ticket is now under investigation');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Resolve Ticket
// ==============================================
function handleResolve() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing ticket ID', [], 400);
    }
    
    $id = intval($input['id']);
    $resolutionType = $input['resolution_type'] ?? 'no_action';
    $resolutionNotes = trim($input['resolution_notes'] ?? '');
    $refundAmount = isset($input['refund_amount']) ? floatval($input['refund_amount']) : 0;
    $winnerId = isset($input['winner_id']) ? intval($input['winner_id']) : 0;
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    $validTypes = ['winner_declared', 'refund', 'cancelled', 'replay', 'no_action'];
    if (!in_array($resolutionType, $validTypes)) {
        jsonResponse(false, 'Invalid resolution type', [], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Get ticket details
        $stmt = $conn->prepare("
            SELECT user_id, match_id, status 
            FROM dispute_tickets 
            WHERE id = :id 
            FOR UPDATE
        ");
        $stmt->execute([':id' => $id]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            $db->rollback();
            jsonResponse(false, 'Ticket not found', [], 404);
        }
        
        if (!in_array($ticket['status'], ['open', 'investigating'])) {
            $db->rollback();
            jsonResponse(false, 'Ticket must be open or investigating to resolve', [], 400);
        }
        
        // Handle refund if needed
        if ($resolutionType === 'refund' && $refundAmount > 0) {
            // Refund user
            $stmt = $conn->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + :amount, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':amount' => $refundAmount,
                ':user_id' => $ticket['user_id']
            ]);
            
            // Record refund transaction
            $orderId = 'DISPUTE-REFUND-' . strtoupper(uniqid());
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id,
                    amount,
                    type,
                    source,
                    description,
                    order_id,
                    status,
                    balance_before,
                    balance_after,
                    metadata,
                    created_at
                ) VALUES (
                    :user_id,
                    :amount,
                    'credit',
                    'refund',
                    :description,
                    :order_id,
                    'success',
                    (SELECT wallet_balance FROM users WHERE id = :user_id) - :amount,
                    (SELECT wallet_balance FROM users WHERE id = :user_id),
                    :metadata,
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':user_id' => $ticket['user_id'],
                ':amount' => $refundAmount,
                ':description' => "Dispute refund for ticket #{$id}",
                ':order_id' => $orderId,
                ':metadata' => json_encode([
                    'ticket_id' => $id,
                    'resolution_type' => $resolutionType
                ])
            ]);
        }
        
        // Handle winner declaration
        if ($resolutionType === 'winner_declared' && $winnerId > 0) {
            // Update match winner
            $stmt = $conn->prepare("
                UPDATE matches 
                SET 
                    winner_id = :winner_id,
                    status = 'completed',
                    completed_at = CURRENT_TIMESTAMP
                WHERE id = :match_id
                AND status != 'completed'
            ");
            $stmt->execute([
                ':winner_id' => $winnerId,
                ':match_id' => $ticket['match_id']
            ]);
        }
        
        // Update ticket
        $stmt = $conn->prepare("
            UPDATE dispute_tickets 
            SET 
                status = 'resolved',
                resolution_type = :resolution_type,
                resolution_notes = :notes,
                refund_amount = :refund_amount,
                resolved_by = :admin_id,
                resolved_at = CURRENT_TIMESTAMP,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([
            ':resolution_type' => $resolutionType,
            ':notes' => $resolutionNotes,
            ':refund_amount' => $refundAmount,
            ':admin_id' => $_SESSION['admin_id'],
            ':id' => $id
        ]);
        
        // Add system message
        $stmt = $conn->prepare("
            INSERT INTO ticket_messages (
                ticket_id,
                user_id,
                message,
                is_admin,
                created_at
            ) VALUES (
                :ticket_id,
                :user_id,
                :message,
                1,
                CURRENT_TIMESTAMP
            )
        ");
        $stmt->execute([
            ':ticket_id' => $id,
            ':user_id' => $_SESSION['admin_id'],
            ':message' => "✅ Ticket resolved. Resolution: " . strtoupper($resolutionType) . ". " . $resolutionNotes
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Ticket resolved successfully', [
            'resolution_type' => $resolutionType,
            'refund_amount' => $refundAmount
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Close Ticket
// ==============================================
function handleClose() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['id'])) {
        jsonResponse(false, 'Missing ticket ID', [], 400);
    }
    
    $id = intval($input['id']);
    $notes = $input['notes'] ?? '';
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $conn->prepare("
            UPDATE dispute_tickets 
            SET 
                status = 'closed',
                admin_notes = CONCAT(COALESCE(admin_notes, ''), '\nClosed: ', :notes),
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            AND status = 'resolved'
        ");
        $stmt->execute([
            ':notes' => $notes,
            ':id' => $id
        ]);
        
        if ($stmt->rowCount() === 0) {
            $db->rollback();
            jsonResponse(false, 'Ticket not found or not resolved', [], 400);
        }
        
        $db->commit();
        
        jsonResponse(true, 'Ticket closed successfully');
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Dispute Statistics
// ==============================================
function handleStats() {
    global $db, $conn;
    
    try {
        $stats = [];
        
        $stmt = $conn->query("SELECT COUNT(*) as open FROM dispute_tickets WHERE status = 'open'");
        $stats['open'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as investigating FROM dispute_tickets WHERE status = 'investigating'");
        $stats['investigating'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as resolved FROM dispute_tickets WHERE status = 'resolved'");
        $stats['resolved'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as closed FROM dispute_tickets WHERE status = 'closed'");
        $stats['closed'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("SELECT COUNT(*) as total FROM dispute_tickets");
        $stats['total'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT COUNT(*) as high_priority 
            FROM dispute_tickets 
            WHERE priority IN ('high', 'urgent') 
            AND status IN ('open', 'investigating')
        ");
        $stats['high_priority'] = intval($stmt->fetchColumn());
        
        $stmt = $conn->query("
            SELECT SUM(refund_amount) as total_refunds 
            FROM dispute_tickets 
            WHERE status = 'resolved' 
            AND resolution_type = 'refund'
        ");
        $stats['total_refunds'] = floatval($stmt->fetchColumn());
        
        jsonResponse(true, 'Dispute statistics retrieved', $stats);
        
    } catch (PDOException $e) {
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Declare Winner (Additional)
// ==============================================
function handleDeclareWinner() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['match_id']) || !isset($input['winner_id'])) {
        jsonResponse(false, 'Missing required fields', [], 400);
    }
    
    $matchId = intval($input['match_id']);
    $winnerId = intval($input['winner_id']);
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Get match details
        $stmt = $conn->prepare("
            SELECT entry_fee, prize_pool, status, player1_id, player2_id 
            FROM matches 
            WHERE id = :match_id 
            FOR UPDATE
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        if ($match['status'] === 'completed') {
            $db->rollback();
            jsonResponse(false, 'Match already completed', [], 400);
        }
        
        // Update match
        $stmt = $conn->prepare("
            UPDATE matches 
            SET 
                winner_id = :winner_id,
                status = 'completed',
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([
            ':winner_id' => $winnerId,
            ':match_id' => $matchId
        ]);
        
        $db->commit();
        
        jsonResponse(true, 'Winner declared successfully', [
            'match_id' => $matchId,
            'winner_id' => $winnerId
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Refund Match (Additional)
// ==============================================
function handleRefundMatch() {
    global $db, $conn;
    
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || !isset($input['match_id'])) {
        jsonResponse(false, 'Missing match ID', [], 400);
    }
    
    $matchId = intval($input['match_id']);
    
    $csrfToken = $input['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!CSRFToken::validate($csrfToken)) {
        jsonResponse(false, 'Invalid CSRF token', [], 403);
    }
    
    try {
        $db->beginTransaction();
        
        // Get match details
        $stmt = $conn->prepare("
            SELECT entry_fee, player1_id, player2_id, player3_id, player4_id, status 
            FROM matches 
            WHERE id = :match_id 
            FOR UPDATE
        ");
        $stmt->execute([':match_id' => $matchId]);
        $match = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$match) {
            $db->rollback();
            jsonResponse(false, 'Match not found', [], 404);
        }
        
        $players = array_filter([
            $match['player1_id'],
            $match['player2_id'],
            $match['player3_id'],
            $match['player4_id']
        ]);
        
        $refundAmount = $match['entry_fee'];
        
        // Refund all players
        foreach ($players as $playerId) {
            $stmt = $conn->prepare("
                UPDATE users 
                SET wallet_balance = wallet_balance + :amount, updated_at = CURRENT_TIMESTAMP 
                WHERE id = :user_id
            ");
            $stmt->execute([
                ':amount' => $refundAmount,
                ':user_id' => $playerId
            ]);
            
            // Record refund transaction
            $orderId = 'REFUND-MATCH-' . strtoupper(uniqid());
            $stmt = $conn->prepare("
                INSERT INTO transactions (
                    user_id,
                    amount,
                    type,
                    source,
                    description,
                    order_id,
                    status,
                    balance_before,
                    balance_after,
                    metadata,
                    created_at
                ) VALUES (
                    :user_id,
                    :amount,
                    'credit',
                    'refund',
                    :description,
                    :order_id,
                    'success',
                    (SELECT wallet_balance FROM users WHERE id = :user_id) - :amount,
                    (SELECT wallet_balance FROM users WHERE id = :user_id),
                    :metadata,
                    CURRENT_TIMESTAMP
                )
            ");
            $stmt->execute([
                ':user_id' => $playerId,
                ':amount' => $refundAmount,
                ':description' => "Match refund for match #{$matchId}",
                ':order_id' => $orderId,
                ':metadata' => json_encode([
                    'match_id' => $matchId,
                    'action' => 'refund_match'
                ])
            ]);
        }
        
        // Update match status
        $stmt = $conn->prepare("
            UPDATE matches 
            SET 
                status = 'cancelled',
                completed_at = CURRENT_TIMESTAMP
            WHERE id = :match_id
        ");
        $stmt->execute([':match_id' => $matchId]);
        
        $db->commit();
        
        jsonResponse(true, 'Match refunded successfully', [
            'match_id' => $matchId,
            'players_refunded' => count($players),
            'amount_per_player' => $refundAmount
        ]);
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollback();
        }
        jsonResponse(false, 'Database error: ' . $e->getMessage(), [], 500);
    }
}

// ==============================================
// HANDLER: Send Reply (Alias for add_message)
// ==============================================
function handleSendReply() {
    // Alias for add_message
    handleAddMessage();
}
?>
