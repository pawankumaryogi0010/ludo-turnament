/**
 * ======================================================
 * INVITE-SYSTEM.JS - Complete Invite System
 * Ludo Tournament Platform - Invite Management
 * Version: 1.0.0
 * ======================================================
 */

class InviteSystem {
    constructor() {
        this.inviteCode = null;
        this.roomCode = null;
        this.matchId = null;
        this.userId = null;
        this.init();
    }

    init() {
        // Get user ID from global or localStorage
        this.userId = window.app?.userData?.id || localStorage.getItem('userId') || null;
        this.loadInviteData();
        this.bindEvents();
    }

    loadInviteData() {
        // Check URL for invite params
        const urlParams = new URLSearchParams(window.location.search);
        this.roomCode = urlParams.get('room') || urlParams.get('room_code') || null;
        this.matchId = urlParams.get('match_id') || null;
        this.inviteCode = urlParams.get('invite') || null;
        
        if (this.roomCode) {
            console.log('📨 Invite room code:', this.roomCode);
            this.handleInviteLink();
        }
    }

    bindEvents() {
        // Share button
        const shareBtn = document.getElementById('shareReferBtn');
        if (shareBtn) {
            shareBtn.addEventListener('click', () => this.shareInvite());
        }

        // Copy code button
        const copyBtn = document.getElementById('copyCodeBtn');
        if (copyBtn) {
            copyBtn.addEventListener('click', () => this.copyInviteCode());
        }

        // Referral button on dashboard
        const referBtn = document.getElementById('referralBtn');
        if (referBtn) {
            referBtn.addEventListener('click', () => this.openReferPage());
        }
    }

    async handleInviteLink() {
        if (!this.roomCode) return;

        const result = await this.checkRoom(this.roomCode);
        
        if (result.success) {
            const room = result.data.room;
            if (room.is_full) {
                this.showMessage('❌ This room is full.', 'error');
                return;
            }
            this.showMessage('✅ Room is available! Click Join to enter.', 'success');
            this.showJoinButton(room);
        } else {
            this.showMessage('❌ ' + (result.message || 'Room not found'), 'error');
        }
    }

    async checkRoom(roomCode) {
        try {
            const response = await fetch(`/api/invite.php?action=check_room&room=${encodeURIComponent(roomCode)}`);
            return await response.json();
        } catch (error) {
            console.error('❌ Error checking room:', error);
            return { success: false, message: 'Network error' };
        }
    }

    async joinRoom(roomCode) {
        if (!this.userId) {
            this.showMessage('⚠️ Please login to join.', 'warning');
            if (window.app) {
                window.app.openAuthModal('login');
            }
            return;
        }

        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        
        try {
            const response = await fetch('/api/invite.php?action=join', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    room_code: roomCode,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showMessage('✅ ' + data.message, 'success');
                if (data.data.redirect_url) {
                    setTimeout(() => {
                        window.location.href = data.data.redirect_url;
                    }, 1500);
                }
            } else {
                this.showMessage('❌ ' + data.message, 'error');
            }
        } catch (error) {
            console.error('❌ Error joining room:', error);
            this.showMessage('❌ Network error. Please try again.', 'error');
        }
    }

    async createInvite(roomCode, matchId) {
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        
        try {
            const response = await fetch('/api/invite.php?action=create', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    room_code: roomCode,
                    match_id: matchId,
                    csrf_token: csrfToken
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.inviteCode = data.data.invite_code;
                this.showMessage('✅ Invite created!', 'success');
                this.copyShareLink(data.data.invite_url);
            } else {
                this.showMessage('❌ ' + data.message, 'error');
            }
        } catch (error) {
            console.error('❌ Error creating invite:', error);
            this.showMessage('❌ Network error.', 'error');
        }
    }

    shareInvite() {
        const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
        const shareText = `🎲 Join Ludo Tournament Pro and win real rewards! Use my referral code: ${code}`;
        const shareUrl = window.location.href;
        
        if (navigator.share) {
            navigator.share({
                title: 'Ludo Tournament Pro',
                text: shareText,
                url: shareUrl
            }).catch(() => {});
        } else {
            // Fallback: Copy to clipboard
            this.copyShareLink(shareUrl, shareText);
        }
    }

    copyInviteCode() {
        const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(code).then(() => {
                this.showMessage('✅ Referral code copied!', 'success');
            }).catch(() => this.fallbackCopy(code));
        } else {
            this.fallbackCopy(code);
        }
    }

    copyShareLink(url, text) {
        const shareText = text || `🎲 Join my Ludo game! ${url}`;
        
        if (navigator.clipboard) {
            navigator.clipboard.writeText(shareText).then(() => {
                this.showMessage('✅ Invite link copied!', 'success');
            }).catch(() => this.fallbackCopy(shareText));
        } else {
            this.fallbackCopy(shareText);
        }
    }

    fallbackCopy(text) {
        const input = document.createElement('input');
        input.value = text;
        document.body.appendChild(input);
        input.select();
        document.execCommand('copy');
        document.body.removeChild(input);
        this.showMessage('✅ Copied!', 'success');
    }

    showJoinButton(room) {
        const container = document.querySelector('.join-card');
        if (!container) return;
        
        const existingBtn = container.querySelector('.join-btn');
        if (existingBtn) {
            existingBtn.textContent = '🎯 Join Game';
            existingBtn.disabled = false;
            existingBtn.onclick = () => this.joinRoom(this.roomCode);
        }
    }

    showMessage(message, type = 'info') {
        const container = document.querySelector('#message') || document.createElement('div');
        if (!container.id) {
            container.id = 'message';
            document.querySelector('.join-card')?.appendChild(container);
        }
        
        const colors = {
            success: '#10b981',
            error: '#ef4444',
            warning: '#f59e0b',
            info: '#3b82f6'
        };
        
        container.className = type + '-message';
        container.style.cssText = `
            color: ${colors[type] || colors.info};
            background: ${colors[type] || colors.info}15;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 16px;
            font-size: 14px;
        `;
        container.textContent = message;
    }

    openReferPage() {
        const referPage = document.getElementById('page-refer');
        if (referPage) {
            window.app?.showPage('refer');
        } else {
            window.location.href = '/?page=refer';
        }
    }
}

// Auto-initialize
document.addEventListener('DOMContentLoaded', () => {
    window.inviteSystem = new InviteSystem();
});

console.log('📨 Invite System loaded');
