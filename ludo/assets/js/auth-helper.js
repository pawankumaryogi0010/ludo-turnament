/**
 * ======================================================
 * AUTH-HELPER.JS - Complete Authentication Helper (FIXED)
 * Ludo Tournament Platform - Auth Module
 * Version: 3.0.0 - API PATHS FIXED + ERROR HANDLING
 * ======================================================
 */

const AuthHelper = (() => {
    // FIXED: Dynamic API base path - detect from current location
    const getApiBase = () => {
        const path = window.location.pathname;
        // Remove filename, get directory
        const dir = path.substring(0, path.lastIndexOf('/'));
        return window.location.origin + dir + '/api/auth.php';
    };

    const API_BASE = getApiBase();
    
    let _csrfToken = '';
    let _isInitialized = false;

    /**
     * FIXED: Get CSRF token with retry and fallback
     */
    const getCsrfToken = async () => {
        // Return cached token if available
        if (_csrfToken && _csrfToken.length > 10) {
            return _csrfToken;
        }

        // Check localStorage for cached token
        const cachedToken = localStorage.getItem('csrf_token');
        if (cachedToken && cachedToken.length > 10) {
            _csrfToken = cachedToken;
            return _csrfToken;
        }

        try {
            const response = await fetch(`${API_BASE}?action=get_csrf`, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });

            const text = await response.text();
            
            // FIXED: Better JSON detection
            if (text.trim().startsWith('{')) {
                const data = JSON.parse(text);
                if (data.success && data.data && data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    _isInitialized = true;
                    localStorage.setItem('csrf_token', _csrfToken);
                    return _csrfToken;
                }
            }
            
            // Fallback: generate local token
            console.warn('[Auth] CSRF endpoint unavailable, using fallback token');
            _csrfToken = generateFallbackToken();
            return _csrfToken;
            
        } catch (error) {
            console.warn('[Auth] CSRF fetch error:', error.message);
            _csrfToken = generateFallbackToken();
            return _csrfToken;
        }
    };

    /**
     * Generate fallback CSRF token
     */
    const generateFallbackToken = () => {
        const token = 'csrf_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15) + 
                      '_' + Math.random().toString(36).substring(2, 10);
        return token;
    };

    /**
     * FIXED: Register with proper validation
     */
    const register = async ({ username, mobile, password, email = '', referralCode = '' }) => {
        try {
            // Validate inputs
            if (!username || username.length < 3) {
                return { success: false, message: 'Username must be at least 3 characters' };
            }
            if (!mobile || !/^[0-9]{10}$/.test(mobile)) {
                return { success: false, message: 'Please enter a valid 10-digit mobile number' };
            }
            if (!password || password.length < 6) {
                return { success: false, message: 'Password must be at least 6 characters' };
            }

            const csrf = await getCsrfToken();

            const response = await fetch(`${API_BASE}?action=register`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    username: username.trim(),
                    mobile: mobile.trim(),
                    email: email.trim() || '',
                    password: password,
                    referral_code: referralCode?.trim() || '',
                    csrf_token: csrf
                })
            });

            const text = await response.text();
            
            // FIXED: Handle non-JSON responses gracefully
            if (!text.trim().startsWith('{')) {
                console.error('[Auth] Non-JSON response:', text.substring(0, 200));
                return {
                    success: false,
                    message: 'Server error. Please try again later.'
                };
            }

            const data = JSON.parse(text);
            
            // FIXED: Store user data on success
            if (data.success && data.data && data.data.user) {
                storeUserData(data.data.user);
                if (data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                }
            }

            return data;
            
        } catch (error) {
            console.error('[Auth] Register error:', error);
            return {
                success: false,
                message: 'Network error. Please check your connection and try again.'
            };
        }
    };

    /**
     * FIXED: Login with username/mobile/email support
     */
    const login = async ({ username, password }) => {
        try {
            if (!username || !password) {
                return { success: false, message: 'Username and password are required' };
            }

            const csrf = await getCsrfToken();

            const response = await fetch(`${API_BASE}?action=login`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({
                    username: username.trim(),
                    password: password,
                    csrf_token: csrf
                })
            });

            const text = await response.text();
            
            if (!text.trim().startsWith('{')) {
                console.error('[Auth] Non-JSON response:', text.substring(0, 200));
                return {
                    success: false,
                    message: 'Server error. Please try again later.'
                };
            }

            const data = JSON.parse(text);
            
            // FIXED: Store user data on success
            if (data.success && data.data && data.data.user) {
                storeUserData(data.data.user);
                if (data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                }
            }

            return data;
            
        } catch (error) {
            console.error('[Auth] Login error:', error);
            return {
                success: false,
                message: 'Network error. Please check your connection and try again.'
            };
        }
    };

    /**
     * FIXED: Check authentication status
     */
    const checkAuth = async () => {
        try {
            // Check localStorage first
            const storedUser = localStorage.getItem('user');
            const isLoggedIn = localStorage.getItem('isLoggedIn');
            
            if (isLoggedIn === 'true' && storedUser) {
                try {
                    const user = JSON.parse(storedUser);
                    return {
                        success: true,
                        isLoggedIn: true,
                        user: user
                    };
                } catch (e) {
                    // Invalid stored data, clear it
                    clearUserData();
                }
            }

            // Verify with server
            const response = await fetch(`${API_BASE}?action=check`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });

            const text = await response.text();
            
            if (!text.trim().startsWith('{')) {
                return { success: false, isLoggedIn: false };
            }

            const data = JSON.parse(text);
            
            if (data.success && data.data && data.data.logged_in) {
                if (data.data.user) {
                    storeUserData(data.data.user);
                }
                if (data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                }
                return { success: true, isLoggedIn: true, user: data.data.user };
            }

            return { success: false, isLoggedIn: false };
            
        } catch (error) {
            console.error('[Auth] Check auth error:', error);
            return { success: false, isLoggedIn: false };
        }
    };

    /**
     * FIXED: Logout
     */
    const logout = async () => {
        try {
            const csrf = _csrfToken || await getCsrfToken();

            // Try server logout
            try {
                await fetch(`${API_BASE}?action=logout`, {
                    method: 'POST',
                    credentials: 'include',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': csrf
                    },
                    body: JSON.stringify({ csrf_token: csrf })
                });
            } catch (e) {
                // Ignore server errors on logout
            }

            // Always clear local data
            clearUserData();
            _csrfToken = '';

            return { success: true, message: 'Logged out successfully' };
            
        } catch (error) {
            console.error('[Auth] Logout error:', error);
            clearUserData();
            return { success: true, message: 'Logged out successfully' };
        }
    };

    /**
     * Store user data in localStorage
     */
    const storeUserData = (user) => {
        try {
            localStorage.setItem('user', JSON.stringify(user));
            localStorage.setItem('isLoggedIn', 'true');
            localStorage.setItem('userId', user.id?.toString() || '');
            localStorage.setItem('walletBalance', (user.wallet_balance || 0).toString());
        } catch (e) {
            console.warn('[Auth] Could not save to localStorage:', e);
        }
    };

    /**
     * Clear user data from localStorage
     */
    const clearUserData = () => {
        localStorage.removeItem('user');
        localStorage.removeItem('isLoggedIn');
        localStorage.removeItem('userId');
        localStorage.removeItem('walletBalance');
        localStorage.removeItem('csrf_token');
    };

    /**
     * Get current user from localStorage
     */
    const getCurrentUser = () => {
        try {
            const user = localStorage.getItem('user');
            return user ? JSON.parse(user) : null;
        } catch {
            return null;
        }
    };

    /**
     * Check if user is logged in
     */
    const isLoggedIn = () => {
        return localStorage.getItem('isLoggedIn') === 'true';
    };

    /**
     * Get user ID
     */
    const getUserId = () => {
        return localStorage.getItem('userId') || null;
    };

    /**
     * Update wallet balance in localStorage
     */
    const updateWalletBalance = (balance) => {
        localStorage.setItem('walletBalance', balance.toString());
        // Also update in user object
        const user = getCurrentUser();
        if (user) {
            user.wallet_balance = parseFloat(balance);
            localStorage.setItem('user', JSON.stringify(user));
        }
    };

    // Public API
    return {
        register,
        login,
        logout,
        checkAuth,
        getCsrfToken,
        getCurrentUser,
        isLoggedIn,
        getUserId,
        updateWalletBalance,
        API_BASE
    };
})();

// Export for ES modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AuthHelper;
}

// Make globally available
if (typeof window !== 'undefined') {
    window.AuthHelper = AuthHelper;
}

console.log('✅ [Auth] AuthHelper loaded - API:', AuthHelper.API_BASE);
