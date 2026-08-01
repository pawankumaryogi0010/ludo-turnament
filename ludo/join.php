<?php
/**
 * ======================================================
 * JOIN.PHP - Join via Invite Link
 * Ludo Tournament Platform - Invite Join Page
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

require_once __DI<?php
/**
 * ======================================================
 * JOIN.PHP - Join via Invite Link (FIXED)
 * Ludo Tournament Platform - Invite Join Page
 * Version: 2.0.1 - API PATHS FIXED
 * ======================================================
 */

require_once __DIR__ . '/config/db.php';

$roomCode = isset($_GET['room']) ? trim($_GET['room']) : '';
$basePath = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
if ($basePath === '') $basePath = '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Game - Ludo Tournament Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/style.css">
    <style>
        .join-container { max-width: 480px; margin: 40px auto; padding: 20px; text-align: center; }
        .join-card { background: #1a1a2e; border-radius: 16px; padding: 32px; border: 1px solid rgba(255,255,255,0.04); margin-top: 20px; }
        .join-card .icon { font-size: 64px; display: block; margin-bottom: 16px; }
        .join-card h1 { font-size: 24px; font-weight: 800; color: #f1f5f9; margin-bottom: 8px; }
        .join-card p { color: #94a3b8; margin-bottom: 20px; line-height: 1.6; }
        .join-card .room-code-display {
            background: rgba(251,191,36,0.05); border: 1px solid rgba(251,191,36,0.15);
            border-radius: 10px; padding: 12px; font-size: 28px; font-weight: 800;
            color: #fbbf24; letter-spacing: 3px; margin-bottom: 20px;
        }
        .join-btn {
            padding: 14px 32px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e; font-weight: 700; font-size: 16px; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s; font-family: inherit; width: 100%;
        }
        .join-btn:hover { transform: scale(1.02); box-shadow: 0 0 30px rgba(251,191,36,0.2); }
        .join-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .error-message { color: #ef4444; background: rgba(239,68,68,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .success-message { color: #10b981; background: rgba(16,185,129,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .back-link { display: inline-block; margin-top: 16px; color: #94a3b8; text-decoration: none; font-size: 14px; }
        .back-link:hover { color: #f1f5f9; }
    </style>
</head>
<body>
    <div class="join-container">
        <div class="join-card">
            <span class="icon">🎲</span>
            <h1>Join Game</h1>
            <p>You have been invited to play Ludo!</p>
            <div class="room-code-display"><?php echo htmlspecialchars($roomCode ?: '------'); ?></div>
            <div id="message"></div>
            <button class="join-btn" id="joinBtn" onclick="handleJoin()">🎯 Join Game</button>
            <a href="index.php" class="back-link">← Back to Home</a>
        </div>
    </div>
    <script>
        const roomCode = '<?php echo htmlspecialchars($roomCode); ?>';
        const userId = <?php echo isLoggedIn() ? getCurrentUserId() : 'null'; ?>;
        // FIXED: Dynamic base path
        const BASE_PATH = '<?php echo $basePath; ?>';
        
        document.addEventListener('DOMContentLoaded', function() {
            if (!userId) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Please login to join this game.</div>`;
                document.getElementById('joinBtn').disabled = true;
                document.getElementById('joinBtn').textContent = '🔒 Login Required';
                return;
            }
            checkRoom();
        });
        
        function checkRoom() {
            if (!roomCode) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Invalid invite link.</div>`;
                document.getElementById('joinBtn').disabled = true;
                return;
            }
            document.getElementById('joinBtn').textContent = '⏳ Checking room...';
            document.getElementById('joinBtn').disabled = true;
            
            fetch(BASE_PATH + `/api/invite.php?action=check_room&room=${encodeURIComponent(roomCode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const room = data.data.room;
                        if (room.is_full) {
                            document.getElementById('message').innerHTML = `<div class="error-message">❌ This room is full.</div>`;
                            document.getElementById('joinBtn').disabled = true;
                            document.getElementById('joinBtn').textContent = '🚫 Room Full';
                        } else {
                            document.getElementById('message').innerHTML = `<div class="success-message">✅ Room is available! Click Join to enter.</div>`;
                            document.getElementById('joinBtn').disabled = false;
                            document.getElementById('joinBtn').textContent = '🎯 Join Game';
                        }
                    } else {
                        document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message || 'Room not found'}</div>`;
                        document.getElementById('joinBtn').disabled = true;
                        document.getElementById('joinBtn').textContent = '🚫 Room Not Found';
                    }
                })
                .catch(() => {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                    document.getElementById('joinBtn').disabled = true;
                });
        }
        
        function handleJoin() {
            const btn = document.getElementById('joinBtn');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Joining...';
            btn.disabled = true;
            
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
            
            fetch(BASE_PATH + '/api/invite.php?action=join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ room_code: roomCode, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('message').innerHTML = `<div class="success-message">✅ ${data.message}</div>`;
                    if (data.data.redirect_url) {
                        setTimeout(() => { window.location.href = data.data.redirect_url; }, 1500);
                    }
                } else {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message}</div>`;
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            })
            .catch(() => {
                document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>R__ . '/config/db.php';

$roomCode = isset($_GET['room']) ? trim($_GET['room']) : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Join Game - Ludo Tournament Pro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .join-container { max-width: 480px; margin: 40px auto; padding: 20px; text-align: center; }
        .join-card { background: #1a1a2e; border-radius: 16px; padding: 32px; border: 1px solid rgba(255,255,255,0.04); margin-top: 20px; }
        .join-card .icon { font-size: 64px; display: block; margin-bottom: 16px; }
        .join-card h1 { font-size: 24px; font-weight: 800; color: #f1f5f9; margin-bottom: 8px; }
        .join-card p { color: #94a3b8; margin-bottom: 20px; line-height: 1.6; }
        .join-card .room-code-display {
            background: rgba(251,191,36,0.05); border: 1px solid rgba(251,191,36,0.15);
            border-radius: 10px; padding: 12px; font-size: 28px; font-weight: 800;
            color: #fbbf24; letter-spacing: 3px; margin-bottom: 20px;
        }
        .join-btn {
            padding: 14px 32px; border: none; border-radius: 10px;
            background: linear-gradient(135deg, #fbbf24, #f59e0b);
            color: #1a1a2e; font-weight: 700; font-size: 16px; cursor: pointer;
            transition: transform 0.2s, box-shadow 0.2s; font-family: inherit; width: 100%;
        }
        .join-btn:hover { transform: scale(1.02); box-shadow: 0 0 30px rgba(251,191,36,0.2); }
        .join-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none !important; }
        .error-message { color: #ef4444; background: rgba(239,68,68,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .success-message { color: #10b981; background: rgba(16,185,129,0.1); padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px; }
        .back-link { display: inline-block; margin-top: 16px; color: #94a3b8; text-decoration: none; font-size: 14px; }
        .back-link:hover { color: #f1f5f9; }
    </style>
</head>
<body>
    <div class="join-container">
        <div class="join-card">
            <span class="icon">🎲</span>
            <h1>Join Game</h1>
            <p>You have been invited to play Ludo!</p>
            <div class="room-code-display"><?php echo htmlspecialchars($roomCode ?: '------'); ?></div>
            <div id="message"></div>
            <button class="join-btn" id="joinBtn" onclick="handleJoin()">🎯 Join Game</button>
            <a href="index.php" class="back-link">← Back to Home</a>
        </div>
    </div>
    <script>
        const roomCode = '<?php echo htmlspecialchars($roomCode); ?>';
        const userId = <?php echo isLoggedIn() ? getCurrentUserId() : 'null'; ?>;
        
        document.addEventListener('DOMContentLoaded', function() {
            if (!userId) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Please login to join this game.</div>`;
                document.getElementById('joinBtn').disabled = true;
                document.getElementById('joinBtn').textContent = '🔒 Login Required';
                return;
            }
            checkRoom();
        });
        
        function checkRoom() {
            if (!roomCode) {
                document.getElementById('message').innerHTML = `<div class="error-message">⚠️ Invalid invite link.</div>`;
                document.getElementById('joinBtn').disabled = true;
                return;
            }
            document.getElementById('joinBtn').textContent = '⏳ Checking room...';
            document.getElementById('joinBtn').disabled = true;
            
            fetch(`/api/invite.php?action=check_room&room=${encodeURIComponent(roomCode)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        const room = data.data.room;
                        if (room.is_full) {
                            document.getElementById('message').innerHTML = `<div class="error-message">❌ This room is full.</div>`;
                            document.getElementById('joinBtn').disabled = true;
                            document.getElementById('joinBtn').textContent = '🚫 Room Full';
                        } else {
                            document.getElementById('message').innerHTML = `<div class="success-message">✅ Room is available! Click Join to enter.</div>`;
                            document.getElementById('joinBtn').disabled = false;
                            document.getElementById('joinBtn').textContent = '🎯 Join Game';
                        }
                    } else {
                        document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message || 'Room not found'}</div>`;
                        document.getElementById('joinBtn').disabled = true;
                        document.getElementById('joinBtn').textContent = '🚫 Room Not Found';
                    }
                })
                .catch(() => {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                    document.getElementById('joinBtn').disabled = true;
                });
        }
        
        function handleJoin() {
            const btn = document.getElementById('joinBtn');
            const originalText = btn.textContent;
            btn.textContent = '⏳ Joining...';
            btn.disabled = true;
            
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
            
            fetch('/api/invite.php?action=join', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                body: JSON.stringify({ room_code: roomCode, csrf_token: csrfToken })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('message').innerHTML = `<div class="success-message">✅ ${data.message}</div>`;
                    if (data.data.redirect_url) {
                        setTimeout(() => { window.location.href = data.data.redirect_url; }, 1500);
                    }
                } else {
                    document.getElementById('message').innerHTML = `<div class="error-message">❌ ${data.message}</div>`;
                    btn.textContent = originalText;
                    btn.disabled = false;
                }
            })
            .catch(() => {
                document.getElementById('message').innerHTML = `<div class="error-message">❌ Network error. Please try again.</div>`;
                btn.textContent = originalText;
                btn.disabled = false;
            });
        }
    </script>
</body>
</html>
