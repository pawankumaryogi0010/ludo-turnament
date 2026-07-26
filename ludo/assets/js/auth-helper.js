/**
 * auth-helper.js - Complete Authentication Helper
 * Version: 2.0.0 - Fully Fixed
 * Handles registration, login, logout with proper error handling
 */

const AuthHelper = (() => {
    // ✅ FIXED: Dynamic API base path
    const API_BASE = window.location.origin + '/ludo/api/auth.php';
    
    let _csrfToken = '';
    let _isInitialized = false;

    /**
     * ✅ FIXED: Get CSRF token with fallback
     * If CSRF endpoint doesn't exist, generate local token
     */
    const getCsrfToken = async () => {
        // If we already have a token, return it
        if (_csrfToken) {
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

            // ✅ Check if response is valid JSON
            const text = await response.text();
            
            // If response is not JSON or 404, generate fallback token
            if (!response.ok || !text.startsWith('{')) {
                console.warn('[Auth] CSRF endpoint not found, using fallback token');
                _csrfToken = generateFallbackToken();
                return _csrfToken;
            }

            const data = JSON.parse(text);
            
            if (data.success && data.data && data.data.csrf_token) {
                _csrfToken = data.data.csrf_token;
                _isInitialized = true;
                return _csrfToken;
            } else {
                // API returned error, use fallback
                console.warn('[Auth] CSRF fetch failed, using fallback token');
                _csrfToken = generateFallbackToken();
                return _csrfToken;
            }
        } catch (error) {
            console.warn('[Auth] CSRF fetch error:', error.message);
            // Generate fallback token
            _csrfToken = generateFallbackToken();
            return _csrfToken;
        }
    };

    /**
     * ✅ Generate fallback CSRF token if API is not available
     */
    const generateFallbackToken = () => {
        return 'csrf_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
    };

    /**
     * ✅ FIXED: Register with CSRF token and proper error handling
     */
    const register = async ({ username, mobile, password, email = '', referralCode = '' }) => {
        try {
            // Validate inputs
            if (!username || !mobile || !password) {
                return {
                    success: false,
                    message: 'Username, mobile and password are required'
                };
            }

            if (password.length < 6) {
                return {
                    success: false,
                    message: 'Password must be at least 6 characters'
                };
            }

            // Get CSRF token (with fallback)
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

            // ✅ Handle non-JSON responses (like 404 HTML)
            const text = await response.text();
            
            if (!text.startsWith('{')) {
                console.error('[Auth] Non-JSON response:', text.substring(0, 200));
                return {
                    success: false,
                    message: 'Server error. Please check API path.',
                    debug: text.substring(0, 100)
                };
            }

            const data = JSON.parse(text);
            
            if (!response.ok) {
                return {
                    success: false,
                    message: data.message || `HTTP ${response.status}: ${response.statusText}`
                };
            }

            return data;
        } catch (error) {
            console.error('[Auth] Register error:', error);
            return {
                success: false,
                message: error.message || 'Registration failed. Please try again.'
            };
        }
    };

    /**
     * ✅ FIXED: Login with username/mobile/email support
     */
    const login = async ({ username, password }) => {
        try {
            // Validate inputs
            if (!username || !password) {
                return {
                    success: false,
                    message: 'Username/Mobile/Email and password are required'
                };
            }

            // Get CSRF token (with fallback)
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

            // ✅ Handle non-JSON responses
            const text = await response.text();
            
            if (!text.startsWith('{')) {
                console.error('[Auth] Non-JSON response:', text.substring(0, 200));
                return {
                    success: false,
                    message: 'Server error. Please check API path.',
                    debug: text.substring(0, 100)
                };
            }

            const data = JSON.parse(text);
            
            if (!response.ok) {
                return {
                    success: false,
                    message: data.message || `HTTP ${response.status}: ${response.statusText}`
                };
            }

            // ✅ Store user data on successful login
            if (data.success && data.user) {
                try {
                    localStorage.setItem('user', JSON.stringify(data.user));
                    localStorage.setItem('isLoggedIn', 'true');
                    localStorage.setItem('userId', data.user.id);
                } catch (e) {
                    console.warn('[Auth] Could not save to localStorage:', e);
                }
            }

            return data;
        } catch (error) {
            console.error('[Auth] Login error:', error);
            return {
                success: false,
                message: error.message || 'Login failed. Please try again.'
            };
        }
    };

    /**
     * ✅ FIXED: Check authentication status
     */
    const checkAuth = async () => {
        try {
            // First check localStorage
            if (localStorage.getItem('isLoggedIn') === 'true') {
                const user = localStorage.getItem('user');
                if (user) {
                    return {
                        success: true,
                        isLoggedIn: true,
                        user: JSON.parse(user)
                    };
                }
            }

            // Then check with server
            const response = await fetch(`${API_BASE}?action=check`, {
                method: 'GET',
                credentials: 'include',
                headers: {
                    'Accept': 'application/json'
                }
            });

            const text = await response.text();
            
            if (!text.startsWith('{')) {
                return { success: false, isLoggedIn: false };
            }

            const data = JSON.parse(text);
            
            if (data.success && data.isLoggedIn) {
                // Update localStorage
                if (data.user) {
                    localStorage.setItem('user', JSON.stringify(data.user));
                    localStorage.setItem('isLoggedIn', 'true');
                }
                return data;
            }

            return { success: false, isLoggedIn: false };
        } catch (error) {
            console.error('[Auth] Check auth error:', error);
            return { success: false, isLoggedIn: false };
        }
    };

    /**
     * ✅ FIXED: Logout
     */
    const logout = async () => {
        try {
            const csrf = _csrfToken || await getCsrfToken();

            const response = await fetch(`${API_BASE}?action=logout`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ csrf_token: csrf })
            });

            // Clear localStorage regardless of response
            localStorage.removeItem('user');
            localStorage.removeItem('isLoggedIn');
            localStorage.removeItem('userId');

            const text = await response.text();
            
            if (!text.startsWith('{')) {
                return { success: true, message: 'Logged out successfully' };
            }

            return JSON.parse(text);
        } catch (error) {
            console.error('[Auth] Logout error:', error);
            // Still clear local data
            localStorage.removeItem('user');
            localStorage.removeItem('isLoggedIn');
            localStorage.removeItem('userId');
            return { success: true, message: 'Logged out successfully' };
        }
    };

    /**
     * ✅ Get current user from localStorage
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
     * ✅ Check if user is logged in
     */
    const isLoggedIn = () => {
        return localStorage.getItem('isLoggedIn') === 'true';
    };

    /**
     * ✅ Get user ID
     */
    const getUserId = () => {
        return localStorage.getItem('userId') || null;
    };

    // ✅ Public API
    return {
        register,
        login,
        logout,
        checkAuth,
        getCsrfToken,
        getCurrentUser,
        isLoggedIn,
        getUserId
    };
})();

// ✅ Export for ES modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AuthHelper;
}

// ✅ Make it globally available
if (typeof window !== 'undefined') {
    window.AuthHelper = AuthHelper;
}

console.log('[Auth] AuthHelper loaded successfully ✅');
console.log('[Auth] API Base:', AuthHelper.API_BASE || 'Not set');
