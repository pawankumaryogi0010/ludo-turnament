/**
 * ======================================================
 * PWA-INSTALLER.JS - PWA Install Prompt Handler
 * Ludo Tournament Platform - Complete PWA Installer
 * Version: 2.0.0 - COMPLETE
 * ======================================================
 */

class PWAInstaller {
    constructor() {
        this.deferredPrompt = null;
        this.isInstalled = false;
        this.installButton = null;
        this.installContainer = null;
        this.init();
    }

    init() {
        this.checkInstalled();
        
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            this.showInstallPromotion();
            console.log('📱 Install prompt available');
        });

        window.addEventListener('appinstalled', (e) => {
            this.isInstalled = true;
            this.hideInstallPromotion();
            console.log('✅ App installed successfully');
            this.showToast('🎉 App installed! Thank you for installing!', 'success');
        });

        this.detectIOS();
        
        // Service Worker registration
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/service-worker.js')
                .then(reg => console.log('✅ Service Worker registered:', reg))
                .catch(err => console.error('❌ Service Worker registration failed:', err));
        }
    }

    checkInstalled() {
        if (window.matchMedia('(display-mode: standalone)').matches) {
            this.isInstalled = true;
            console.log('📱 App is running in standalone mode');
        }
        if (window.navigator.standalone) {
            this.isInstalled = true;
            console.log('📱 App is running in iOS standalone mode');
        }
    }

    detectIOS() {
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        if (isIOS) {
            console.log('📱 iOS device detected');
            setTimeout(() => {
                if (!this.isInstalled && !localStorage.getItem('ios_install_dismissed')) {
                    this.showIOSInstallInstructions();
                }
            }, 3000);
        }
    }

    showInstallPromotion() {
        if (this.isInstalled || this.installContainer) return;

        this.installContainer = document.createElement('div');
        this.installContainer.id = 'install-promotion';
        this.installContainer.style.cssText = `
            position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);
            background: #1a1a2e; padding: 16px 20px; border-radius: 12px;
            border: 1px solid rgba(251,191,36,0.15);
            box-shadow: 0 8px 40px rgba(0,0,0,0.5); z-index: 999;
            max-width: 90%; width: 400px;
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            animation: slideUp 0.4s ease;
        `;

        this.installContainer.innerHTML = `
            <div style="display: flex; align-items: center; gap: 12px;">
                <div style="font-size: 28px;">📱</div>
                <div>
                    <div style="font-weight: 700; color: #f1f5f9; font-size: 14px;">Install Ludo Pro</div>
                    <div style="font-size: 12px; color: #94a3b8;">Play anytime, anywhere!</div>
                </div>
            </div>
            <div style="display: flex; gap: 8px;">
                <button id="install-app-btn" style="
                    padding: 8px 16px; border: none; border-radius: 8px;
                    background: linear-gradient(135deg, #fbbf24, #f59e0b);
                    color: #1a1a2e; font-weight: 700; font-size: 13px;
                    cursor: pointer; font-family: inherit;
                ">Install</button>
                <button id="dismiss-install-btn" style="
                    padding: 8px 12px; border: none; border-radius: 8px;
                    background: transparent; color: #94a3b8; font-size: 18px;
                    cursor: pointer; font-family: inherit;
                ">✕</button>
            </div>
        `;

        document.body.appendChild(this.installContainer);

        document.getElementById('install-app-btn').addEventListener('click', () => {
            this.installApp();
        });

        document.getElementById('dismiss-install-btn').addEventListener('click', () => {
            this.hideInstallPromotion();
        });

        this.installButton = document.getElementById('install-app-btn');
    }

    installApp() {
        if (!this.deferredPrompt) {
            this.showToast('Install prompt not available. Try again later.', 'warning');
            return;
        }

        this.deferredPrompt.prompt();

        this.deferredPrompt.userChoice
            .then((choiceResult) => {
                if (choiceResult.outcome === 'accepted') {
                    console.log('✅ User accepted the install prompt');
                    this.isInstalled = true;
                    this.hideInstallPromotion();
                    this.showToast('🎉 App installed successfully!', 'success');
                } else {
                    console.log('❌ User dismissed the install prompt');
                    this.showToast('Installation cancelled. You can install later.', 'info');
                }
                this.deferredPrompt = null;
            })
            .catch((error) => {
                console.error('❌ Install prompt error:', error);
            });
    }

    hideInstallPromotion() {
        if (this.installContainer) {
            this.installContainer.style.opacity = '0';
            this.installContainer.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(() => {
                if (this.installContainer && this.installContainer.parentNode) {
                    this.installContainer.parentNode.removeChild(this.installContainer);
                }
                this.installContainer = null;
            }, 300);
        }
    }

    showIOSInstallInstructions() {
        if (this.isInstalled || localStorage.getItem('ios_install_dismissed')) return;

        const container = document.createElement('div');
        container.id = 'ios-install-instructions';
        container.style.cssText = `
            position: fixed; bottom: 80px; left: 50%; transform: translateX(-50%);
            background: #1a1a2e; padding: 20px; border-radius: 12px;
            border: 1px solid rgba(59,130,246,0.15);
            box-shadow: 0 8px 40px rgba(0,0,0,0.5); z-index: 999;
            max-width: 90%; width: 350px; animation: slideUp 0.4s ease;
        `;

        container.innerHTML = `
            <div style="text-align: center; margin-bottom: 12px;">
                <div style="font-size: 40px;">📱</div>
                <div style="font-weight: 700; color: #f1f5f9; font-size: 16px; margin-top: 8px;">Install Ludo Pro</div>
                <div style="font-size: 13px; color: #94a3b8; margin-top: 4px;">Add to home screen for the best experience</div>
            </div>
            <div style="display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: #94a3b8; text-align: left; padding: 8px 0;">
                <div>1. Tap the <strong style="color: #f1f5f9;">Share</strong> button</div>
                <div>2. Scroll down and tap <strong style="color: #f1f5f9;">Add to Home Screen</strong></div>
                <div>3. Tap <strong style="color: #f1f5f9;">Add</strong> to install</div>
            </div>
            <button id="dismiss-ios-install" style="
                width: 100%; padding: 10px; border: none; border-radius: 8px;
                background: rgba(255,255,255,0.04); color: #94a3b8;
                font-weight: 600; font-size: 14px; cursor: pointer; font-family: inherit;
                margin-top: 8px;
            ">Dismiss</button>
        `;

        document.body.appendChild(container);

        document.getElementById('dismiss-ios-install').addEventListener('click', () => {
            container.style.opacity = '0';
            container.style.transform = 'translateX(-50%) translateY(20px)';
            setTimeout(() => {
                if (container.parentNode) {
                    container.parentNode.removeChild(container);
                }
            }, 300);
            localStorage.setItem('ios_install_dismissed', 'true');
        });
    }

    showToast(message, type = 'info') {
        if (window.app && window.app.showToast) {
            window.app.showToast(message, type);
            return;
        }
        const toast = document.createElement('div');
        const colors = { success: 'rgba(16,185,129,0.2)', error: 'rgba(239,68,68,0.2)', warning: 'rgba(245,158,11,0.2)', info: 'rgba(59,130,246,0.2)' };
        toast.style.cssText = `
            position: fixed; bottom: 100px; left: 50%; transform: translateX(-50%);
            padding: 12px 24px; border-radius: 12px; font-weight: 600; font-size: 14px;
            z-index: 9999; background: ${colors[type] || colors.info};
            border: 1px solid rgba(255,255,255,0.08); color: #f1f5f9;
            max-width: 90%; text-align: center; animation: slideUp 0.3s ease;
            transition: opacity 0.3s ease;
        `;
        toast.textContent = message;
        document.body.appendChild(toast);
        setTimeout(() => {
            toast.style.opacity = '0';
            setTimeout(() => { if (toast.parentNode) { toast.parentNode.removeChild(toast); } }, 300);
        }, 4000);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    window.pwaInstaller = new PWAInstaller();
});

const pwaStyles = document.createElement('style');
pwaStyles.textContent = `
    @keyframes slideUp {
        from { opacity: 0; transform: translateX(-50%) translateY(20px); }
        to { opacity: 1; transform: translateX(-50%) translateY(0); }
    }
`;
document.head.appendChild(pwaStyles);

console.log('📱 PWA Installer ready');
