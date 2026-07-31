<?php
/**
 * ======================================================
 * ADMIN TOURNAMENTS.PHP - Tournament Management Dashboard
 * Ludo Tournament Platform - Admin Tournament Control
 * Version: 4.0.0 - 1vs1 + 1vs4 + PRIZE SYSTEM
 * ======================================================
 */

if (!defined('BASE_PATH')) { define('BASE_PATH', dirname(__DIR__)); }
require_once dirname(__DIR__) . '/config/db.php';
SessionManager::init();

function validateAdminSession() {
    if (!SessionManager::has('admin_id') || !SessionManager::has('admin_token')) return false;
    try {
        $db = Database::getInstance(); $conn = $db->getConnection();
        $stmt = $conn->prepare("SELECT id FROM sessions WHERE user_id = :aid AND session_token = :token AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([':aid' => SessionManager::get('admin_id'), ':token' => SessionManager::get('admin_token')]);
        return $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}
if (!validateAdminSession()) { SessionManager::destroy(); header('Location: index.php'); exit; }

$csrf_token = CSRFToken::generate();
$basePath = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/');

try {
    $db = Database::getInstance(); $conn = $db->getConnection();
} catch (Exception $e) { die("Database connection failed"); }

$success = ''; $error = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_tournament'])) {
        $name = trim($_POST['name'] ?? '');
        $gameMode = $_POST['game_mode'] ?? '1vs1';
        $entryFee = floatval($_POST['entry_fee'] ?? 0);
        $totalPlayers = intval($_POST['total_players'] ?? 2);
        $firstPrize = floatval($_POST['first_prize_percent'] ?? 60);
        $secondPrize = floatval($_POST['second_prize_percent'] ?? 30);
        $thirdPrize = floatval($_POST['third_prize_percent'] ?? 10);
        
        if (empty($name)) $error = "Name required";
        elseif ($entryFee <= 0) $error = "Invalid entry fee";
        elseif ($totalPlayers < 2) $error = "Min 2 players";
        elseif (($firstPrize + $secondPrize + $thirdPrize) > 100) $error = "Prize % exceeds 100";
        else {
            $maxPlayers = $gameMode === '1vs1' ? 2 : 4;
            $totalPool = $entryFee * $totalPlayers;
            $platformFee = $totalPool * (PLATFORM_FEE / 100);
            $prizePool = $totalPool - $platformFee;
            $tournamentCode = 'T' . strtoupper(bin2hex(random_bytes(4)));
            
            try {
                $stmt = $conn->prepare("
                    INSERT INTO tournaments (tournament_code, name, game_mode, entry_fee, prize_pool, platform_fee, max_players, total_players, min_players, first_prize_percent, second_prize_percent, third_prize_percent, first_prize_amount, second_prize_amount, third_prize_amount, status, created_by, created_at, updated_at)
                    VALUES (:code, :name, :mode, :fee, :prize, :pf, :max, :total, 2, :fp, :sp, :tp, :fa, :sa, :ta, 'scheduled', :admin, NOW(), NOW())
                ");
                $stmt->execute([
                    ':code' => $tournamentCode, ':name' => $name, ':mode' => $gameMode,
                    ':fee' => $entryFee, ':prize' => $prizePool, ':pf' => $platformFee,
                    ':max' => $maxPlayers, ':total' => $totalPlayers,
                    ':fp' => $firstPrize, ':sp' => $secondPrize, ':tp' => $thirdPrize,
                    ':fa' => round($prizePool*($firstPrize/100), 2),
                    ':sa' => round($prizePool*($secondPrize/100), 2),
                    ':ta' => round($prizePool*($thirdPrize/100), 2),
                    ':admin' => SessionManager::get('admin_id')
                ]);
                $success = "✅ Tournament '{$name}' created!";
            } catch (Exception $e) { $error = $e->getMessage(); }
        }
    }
}

// Fetch tournaments
$tournaments = [];
try {
    $stmt = $conn->query("
        SELECT t.*, u.username as created_by_name,
               (SELECT COUNT(*) FROM tournament_registrations WHERE tournament_id = t.id) as registered_count
        FROM tournaments t LEFT JOIN users u ON t.created_by = u.id
        ORDER BY t.created_at DESC
    ");
    $tournaments = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $tournaments = []; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tournament Management - Admin</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:#0a0e1a;color:#f1f5f9;min-height:100vh}
        .admin-container{max-width:1400px;margin:0 auto;padding:20px}
        .admin-header{display:flex;justify-content:space-between;align-items:center;padding:16px 0;border-bottom:1px solid rgba(255,255,255,0.06);margin-bottom:24px;flex-wrap:wrap;gap:12px}
        .admin-header h1{font-size:24px;font-weight:700;background:linear-gradient(135deg,#fbbf24,#f59e0b);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
        .admin-header-actions{display:flex;align-items:center;gap:16px;flex-wrap:wrap}
        .admin-header-actions a,.admin-header-actions button{color:#94a3b8;text-decoration:none;font-weight:600;font-size:14px;padding:8px 16px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:transparent;cursor:pointer;font-family:inherit}
        .admin-header-actions a.logout{color:#ef4444;border-color:rgba(239,68,68,0.2)}
        .btn-primary{padding:10px 24px;border:none;border-radius:10px;background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e;font-weight:700;font-size:14px;cursor:pointer;font-family:inherit}
        .btn-success{padding:6px 14px;border:none;border-radius:6px;background:rgba(16,185,129,0.2);color:#10b981;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .btn-warning{padding:6px 14px;border:none;border-radius:6px;background:rgba(245,158,11,0.2);color:#f59e0b;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .btn-danger{padding:6px 14px;border:none;border-radius:6px;background:rgba(239,68,68,0.2);color:#ef4444;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .btn-info{padding:6px 14px;border:none;border-radius:6px;background:rgba(59,130,246,0.2);color:#3b82f6;font-weight:600;font-size:12px;cursor:pointer;font-family:inherit}
        .modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);backdrop-filter:blur(8px);z-index:1000;justify-content:center;align-items:center;padding:20px}
        .modal-overlay.active{display:flex}
        .modal-box{background:#1a1a2e;padding:32px;border-radius:16px;max-width:550px;width:100%;border:1px solid rgba(255,255,255,0.06);max-height:90vh;overflow-y:auto}
        .modal-box h2{font-size:20px;font-weight:700;margin-bottom:16px}
        .form-group{margin-bottom:14px}.form-group label{display:block;font-size:13px;font-weight:600;color:#94a3b8;margin-bottom:4px}
        .form-group input,.form-group select{width:100%;padding:10px 14px;border:1px solid rgba(255,255,255,0.06);border-radius:8px;background:rgba(255,255,255,0.04);color:#f1f5f9;font-size:14px;font-family:inherit}
        .form-group input:focus,.form-group select:focus{outline:none;border-color:#7c3aed}
        .form-group select option{background:#1a1a2e}
        .form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}
        .hint{font-size:11px;color:#64748b;margin-top:4px}
        .info-box{background:rgba(59,130,246,0.1);border:1px solid rgba(59,130,246,0.2);border-radius:8px;padding:12px;margin-bottom:16px;font-size:13px;color:#93c5fd}
        .modal-actions{display:flex;gap:12px;margin-top:20px}
        .modal-actions button{flex:1;padding:12px;border:none;border-radius:8px;font-weight:600;font-size:14px;cursor:pointer;font-family:inherit}
        .modal-actions .btn-confirm{background:linear-gradient(135deg,#fbbf24,#f59e0b);color:#1a1a2e}
        .modal-actions .btn-cancel{background:rgba(255,255,255,0.06);color:#94a3b8}
        .table-container{background:#1a1a2e;border-radius:14px;border:1px solid rgba(255,255,255,0.04);overflow-x:auto}
        table{width:100%;border-collapse:collapse;font-size:14px}table th{padding:12px 16px;text-align:left;color:#94a3b8;font-weight:600;font-size:12px;text-transform:uppercase}table td{padding:12px 16px;border-bottom:1px solid rgba(255,255,255,0.02)}
        .status-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
        .status-badge.scheduled{background:rgba(148,163,184,0.15);color:#94a3b8}
        .status-badge.active{background:rgba(16,185,129,0.15);color:#10b981}
        .status-badge.in_progress{background:rgba(59,130,246,0.15);color:#3b82f6}
        .status-badge.completed{background:rgba(16,185,129,0.15);color:#10b981}
        .game-mode-badge{display:inline-block;padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600}
        .game-mode-badge.vs1{background:rgba(139,92,246,0.15);color:#8b5cf6}
        .game-mode-badge.vs4{background:rgba(245,158,11,0.15);color:#f59e0b}
        .toast{position:fixed;bottom:24px;right:24px;padding:14px 24px;border-radius:12px;font-weight:600;font-size:14px;z-index:2000;transform:translateY(100px);opacity:0;transition:all 0.4s ease}
        .toast.show{transform:translateY(0);opacity:1}.toast.success{background:rgba(16,185,129,0.2);color:#10b981}.toast.error{background:rgba(239,68,68,0.2);color:#ef4444}
        .prize-modal .modal-box{max-width:650px}
        .winner-inputs{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:16px}
        @media(max-width:768px){.form-row{grid-template-columns:1fr}.winner-inputs{grid-template-columns:1fr}}
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="admin-header">
            <h1>🏆 Tournament Management</h1>
            <div class="admin-header-actions">
                <span>👋 <?php echo htmlspecialchars(SessionManager::get('admin_username', 'Admin')); ?></span>
                <a href="index.php">← Dashboard</a>
                <button class="btn-primary" onclick="openCreateModal()">➕ New Tournament</button>
                <a href="?logout=1" class="logout">🚪 Logout</a>
            </div>
        </div>
        
        <?php if($success): ?><div style="background:rgba(16,185,129,0.1);color:#10b981;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo $success; ?></div><?php endif; ?>
        <?php if($error): ?><div style="background:rgba(239,68,68,0.1);color:#ef4444;padding:12px;border-radius:8px;margin-bottom:16px"><?php echo $error; ?></div><?php endif; ?>
        
        <div class="table-container">
            <table><thead><tr><th>ID</th><th>Code</th><th>Name</th><th>Mode</th><th>Entry</th><th>Prize Pool</th><th>Players</th><th>1st/2nd/3rd %</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody><?php if(empty($tournaments)): ?><tr><td colspan="10" style="text-align:center;padding:40px;color:#94a3b8">No tournaments created yet</td></tr>
            <?php else: foreach($tournaments as $t): ?>
                <tr>
                    <td>#<?php echo $t['id']; ?></td>
                    <td><code><?php echo htmlspecialchars($t['tournament_code']); ?></code></td>
                    <td><?php echo htmlspecialchars($t['name']); ?></td>
                    <td><span class="game-mode-badge <?php echo $t['game_mode']==='1vs1'?'vs1':'vs4'; ?>"><?php echo $t['game_mode']; ?></span></td>
                    <td style="color:#fbbf24">₹<?php echo number_format($t['entry_fee'],2); ?></td>
                    <td style="color:#10b981">₹<?php echo number_format($t['prize_pool'],2); ?></td>
                    <td><?php echo $t['registered_count']; ?>/<?php echo $t['total_players']; ?></td>
                    <td><?php echo $t['first_prize_percent']; ?>%/<?php echo $t['second_prize_percent']; ?>%/<?php echo $t['third_prize_percent']; ?>%</td>
                    <td><span class="status-badge <?php echo $t['status']; ?>"><?php echo ucwords(str_replace('_',' ',$t['status'])); ?></span></td>
                    <td>
                        <?php if($t['status']==='scheduled'): ?><button class="btn-success" onclick="activateTournament(<?php echo $t['id']; ?>)">Activate</button><?php endif; ?>
                        <?php if(in_array($t['status'],['scheduled','active'])): ?><button class="btn-warning" onclick="startTournament(<?php echo $t['id']; ?>)">Start</button><?php endif; ?>
                        <?php if($t['status']==='in_progress'): ?><button class="btn-info" onclick="openPrizeModal(<?php echo $t['id']; ?>)">🏆 Prizes</button><?php endif; ?>
                        <button class="btn-danger" onclick="deleteTournament(<?php echo $t['id']; ?>)">Delete</button>
                    </td>
                </tr>
            <?php endforeach; endif; ?></tbody></table>
        </div>
    </div>
    
    <!-- CREATE TOURNAMENT MODAL -->
    <div class="modal-overlay" id="createModal">
        <div class="modal-box">
            <h2>➕ Create New Tournament</h2>
            <form method="POST">
                <div class="form-group"><label>Tournament Name *</label><input type="text" name="name" required placeholder="e.g., ₹50 Mega Cup"></div>
                <div class="form-row">
                    <div class="form-group"><label>Game Mode *</label><select name="game_mode" id="gameMode" onchange="updateMaxPlayers()"><option value="1vs1">1 vs 1 (Duel)</option><option value="1vs4">1 vs 4 (Battle Royale)</option></select></div>
                    <div class="form-group"><label>Entry Fee (₹) *</label><input type="number" name="entry_fee" id="entryFee" step="1" min="1" required onchange="calculatePrizes()"></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>Total Players *</label><input type="number" name="total_players" id="totalPlayers" min="2" max="1000" value="100" required onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>Max Players Per Match</label><input type="text" id="maxPlayersDisplay" value="2" disabled></div>
                </div>
                <div class="form-row">
                    <div class="form-group"><label>1st Prize %</label><input type="number" name="first_prize_percent" id="fp" value="60" min="1" max="100" onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>2nd Prize %</label><input type="number" name="second_prize_percent" id="sp" value="30" min="0" max="100" onchange="calculatePrizes()"></div>
                    <div class="form-group"><label>3rd Prize %</label><input type="number" name="third_prize_percent" id="tp" value="10" min="0" max="100" onchange="calculatePrizes()"></div>
                </div>
                <div class="info-box" id="prizeInfo">
                    <strong>Prize Calculation:</strong><br>
                    Total Pool: ₹<span id="calcTotal">0</span> | 
                    Platform Fee (<?php echo PLATFORM_FEE; ?>%): ₹<span id="calcFee">0</span><br>
                    1st: ₹<span id="calc1st">0</span> | 
                    2nd: ₹<span id="calc2nd">0</span> | 
                    3rd: ₹<span id="calc3rd">0</span>
                </div>
                <input type="hidden" name="create_tournament" value="1">
                <div class="modal-actions"><button type="submit" class="btn-confirm">✅ Create Tournament</button><button type="button" class="btn-cancel" onclick="closeModal('createModal')">Cancel</button></div>
            </form>
        </div>
    </div>
    
    <!-- PRIZE DISTRIBUTION MODAL -->
    <div class="modal-overlay prize-modal" id="prizeModal">
        <div class="modal-box">
            <h2>🏆 Distribute Prizes</h2>
            <input type="hidden" id="prizeTournamentId">
            <div class="info-box" id="prizeDistInfo"></div>
            <div class="winner-inputs">
                <div class="form-group"><label>🥇 1st Winner User ID</label><input type="number" id="firstWinnerId" placeholder="User ID"></div>
                <div class="form-group"><label>🥈 2nd Winner User ID</label><input type="number" id="secondWinnerId" placeholder="User ID"></div>
                <div class="form-group"><label>🥉 3rd Winner User ID</label><input type="number" id="thirdWinnerId" placeholder="User ID"></div>
            </div>
            <div class="modal-actions"><button class="btn-confirm" onclick="distributePrizes()">💸 Distribute Prizes</button><button class="btn-cancel" onclick="closeModal('prizeModal')">Cancel</button></div>
        </div>
    </div>
    
    <div class="toast" id="adminToast"></div>
    
    <script>
        const PLATFORM_FEE = <?php echo PLATFORM_FEE; ?>;
        
        function showToast(m,t){const toast=document.getElementById('adminToast');toast.textContent=m;toast.className='toast '+t+' show';setTimeout(()=>toast.classList.remove('show'),4000)}
        function closeModal(id){document.getElementById(id).classList.remove('active')}
        function openCreateModal(){document.getElementById('createModal').classList.add('active');calculatePrizes()}
        function updateMaxPlayers(){document.getElementById('maxPlayersDisplay').value=document.getElementById('gameMode').value==='1vs1'?'2':'4'}
        
        function calculatePrizes(){
            const fee=parseFloat(document.getElementById('entryFee').value)||0;
            const players=parseInt(document.getElementById('totalPlayers').value)||0;
            const fp=parseFloat(document.getElementById('fp').value)||0;
            const sp=parseFloat(document.getElementById('sp').value)||0;
            const tp=parseFloat(document.getElementById('tp').value)||0;
            const total=fee*players;
            const pfee=total*(PLATFORM_FEE/100);
            const net=total-pfee;
            document.getElementById('calcTotal').textContent=total.toFixed(2);
            document.getElementById('calcFee').textContent=pfee.toFixed(2);
            document.getElementById('calc1st').textContent=(net*(fp/100)).toFixed(2);
            document.getElementById('calc2nd').textContent=(net*(sp/100)).toFixed(2);
            document.getElementById('calc3rd').textContent=(net*(tp/100)).toFixed(2);
        }
        
        function activateTournament(id){if(!confirm('Activate this tournament?'))return;fetch('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_update',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id,status:'active'})}).then(r=>r.json()).then(d=>{if(d.success){showToast('Tournament activated!','success');setTimeout(()=>location.reload(),1000)}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function startTournament(id){if(!confirm('Start this tournament?'))return;fetch('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_start',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:id})}).then(r=>r.json()).then(d=>{if(d.success){showToast('Tournament started!','success');setTimeout(()=>location.reload(),1000)}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        function deleteTournament(id){if(!confirm('Delete this tournament?'))return;fetch(`<?php echo $basePath; ?>/api/tournament_system.php?action=admin_delete&id=${id}`).then(r=>r.json()).then(d=>{if(d.success){showToast('Deleted!','success');setTimeout(()=>location.reload(),1000)}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'))}
        
        function openPrizeModal(id){
            document.getElementById('prizeTournamentId').value=id;
            document.getElementById('firstWinnerId').value='';
            document.getElementById('secondWinnerId').value='';
            document.getElementById('thirdWinnerId').value='';
            fetch(`<?php echo $basePath; ?>/api/tournament_system.php?action=get_tournament&id=${id}`).then(r=>r.json()).then(d=>{
                if(d.success){
                    const t=d.data.tournament;
                    document.getElementById('prizeDistInfo').innerHTML=`<strong>${t.name}</strong><br>Mode: ${t.game_mode} | Entry: ₹${t.entry_fee} | Players: ${t.total_players}<br>1st (${t.first_prize_percent}%): ₹${t.calculated_first_prize} | 2nd (${t.second_prize_percent}%): ₹${t.calculated_second_prize} | 3rd (${t.third_prize_percent}%): ₹${t.calculated_third_prize}`;
                }
            });
            document.getElementById('prizeModal').classList.add('active');
        }
        
        function distributePrizes(){
            const id=document.getElementById('prizeTournamentId').value;
            const fw=document.getElementById('firstWinnerId').value;
            const sw=document.getElementById('secondWinnerId').value;
            const tw=document.getElementById('thirdWinnerId').value;
            if(!fw){showToast('Enter 1st winner ID','error');return}
            fetch('<?php echo $basePath; ?>/api/tournament_system.php?action=admin_distribute_prizes',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:parseInt(id),first_winner_id:parseInt(fw),second_winner_id:parseInt(sw||0),third_winner_id:parseInt(tw||0)})}).then(r=>r.json()).then(d=>{if(d.success){showToast('Prizes distributed!','success');closeModal('prizeModal');setTimeout(()=>location.reload(),1500)}else showToast(d.message||'Failed','error')}).catch(()=>showToast('Error','error'));
        }
    </script>
</body>
</html>
