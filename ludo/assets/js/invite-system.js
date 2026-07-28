/**
 * INVITE-SYSTEM.JS - Complete Invite System (FIXED)
 * Version: 2.0.0 - API PATHS FIXED
 */

class InviteSystem {
    constructor() {
        this.inviteCode = null; this.roomCode = null; this.matchId = null; this.userId = null;
        this.apiBase = window.location.origin + '/ludo/api/invite.php';
        this.init();
    }

    init() {
        this.userId = window.app?.userData?.id || localStorage.getItem('userId') || null;
        this.loadInviteData();
        this.bindEvents();
    }

    loadInviteData() {
        const params = new URLSearchParams(window.location.search);
        this.roomCode = params.get('room') || params.get('room_code') || null;
        if (this.roomCode) this.handleInviteLink();
    }

    bindEvents() {
        document.getElementById('shareReferBtn')?.addEventListener('click', () => this.shareInvite());
        document.getElementById('copyCodeBtn')?.addEventListener('click', () => this.copyInviteCode());
        document.getElementById('referralBtn')?.addEventListener('click', () => window.app?.showPage('refer'));
    }

    async handleInviteLink() {
        if (!this.roomCode) return;
        const result = await this.checkRoom(this.roomCode);
        if (result.success) {
            const room = result.data.room;
            if (room.is_full) { this.showMessage('❌ Room is full', 'error'); return; }
            this.showMessage('✅ Room available!', 'success');
        } else {
            this.showMessage('❌ Room not found', 'error');
        }
    }

    async checkRoom(roomCode) {
        try {
            const r = await fetch(`${this.apiBase}?action=check_room&room=${encodeURIComponent(roomCode)}`);
            return await r.json();
        } catch (e) { return { success: false, message: 'Network error' }; }
    }

    async joinRoom(roomCode) {
        if (!this.userId) { window.app?.openAuthModal('login'); return; }
        const csrf = document.querySelector('input[name="csrf_token"]')?.value || '';
        try {
            const r = await fetch(`${this.apiBase}?action=join`, {
                method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
                body: JSON.stringify({ room_code: roomCode, csrf_token: csrf })
            });
            const d = await r.json();
            if (d.success && d.data.redirect_url) setTimeout(() => window.location.href = d.data.redirect_url, 1500);
            this.showMessage(d.success ? '✅ ' + d.message : '❌ ' + d.message, d.success ? 'success' : 'error');
        } catch (e) { this.showMessage('❌ Network error', 'error'); }
    }

    shareInvite() {
        const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
        const text = `🎲 Join Ludo Pro! Use code: ${code}`;
        if (navigator.share) { navigator.share({ title: 'Ludo Pro', text, url: window.location.href }).catch(() => {}); }
        else this.copyText(text);
    }

    copyInviteCode() {
        const code = document.getElementById('referCodeText')?.textContent || 'REF123456';
        this.copyText(code);
    }

    copyText(text) {
        if (navigator.clipboard) { navigator.clipboard.writeText(text).then(() => this.showMessage('✅ Copied!', 'success')); }
        else { const i = document.createElement('input'); i.value = text; document.body.appendChild(i); i.select(); document.execCommand('copy'); document.body.removeChild(i); this.showMessage('✅ Copied!', 'success'); }
    }

    showMessage(msg, type = 'info') {
        const colors = { success: '#10b981', error: '#ef4444', warning: '#f59e0b', info: '#3b82f6' };
        const el = document.getElementById('message') || document.createElement('div');
        if (!el.id) { el.id = 'message'; document.querySelector('.join-card')?.appendChild(el); }
        el.style.cssText = `color:${colors[type]};background:${colors[type]}15;padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px`;
        el.textContent = msg;
    }
}

document.addEventListener('DOMContentLoaded', () => { window.inviteSystem = new InviteSystem(); });
console.log('📨 Invite System v2.0 loaded');
