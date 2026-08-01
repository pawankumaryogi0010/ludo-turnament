/**
 * Auth helper - Full implementation
 * Path: assets/js/auth-helper.js
 * Exposes: AuthHelper.register/login/check/logout/getCsrfToken
 */

const AuthHelper = (() => {
    // Determine API_BASE dynamically so it works from different pages
    const getApiBase = () => {
        const path = window.location.pathname || '/';
        const dir = path.substring(0, path.lastIndexOf('/')) || '';
        return window.location.origin + dir + '/api/auth.php';
    };
    const API_BASE = getApiBase();

    let _csrfToken = '';
    let _isInitialized = false;

    // Local storage helpers
    const storeUserData = (user) => {
        try {
            localStorage.setItem('user', JSON.stringify(user));
            localStorage.setItem('isLoggedIn', 'true');
            localStorage.setItem('userId', String(user.id || user.user_id || ''));
        } catch (e) {
            console.warn('[Auth] storeUserData error', e);
        }
    };

    const clearUserData = () => {
        try {
            localStorage.removeItem('user');
            localStorage.removeItem('isLoggedIn');
            localStorage.removeItem('userId');
            localStorage.removeItem('csrf_token');
            _csrfToken = '';
            _isInitialized = false;
        } catch (e) {
            console.warn('[Auth] clearUserData error', e);
        }
    };

    // Get CSRF token from server or local fallback
    const getCsrfToken = async () => {
        if (_csrfToken && _csrfToken.length > 10) return _csrfToken;
        const cached = localStorage.getItem('csrf_token');
        if (cached && cached.length > 10) { _csrfToken = cached; return _csrfToken; }

        try {
            const resp = await fetch(`${API_BASE}?action=get_csrf`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            const text = await resp.text();
            if (!text) {
                _csrfToken = generateFallbackToken();
                localStorage.setItem('csrf_token', _csrfToken);
                return _csrfToken;
            }
            // parse defensively
            if (text.trim().startsWith('{')) {
                const data = JSON.parse(text);
                if (data.success && data.data && data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                    _isInitialized = true;
                    return _csrfToken;
                }
            }
            // fallback
            _csrfToken = generateFallbackToken();
            localStorage.setItem('csrf_token', _csrfToken);
            return _csrfToken;
        } catch (err) {
            console.warn('[Auth] getCsrfToken error', err);
            _csrfToken = generateFallbackToken();
            localStorage.setItem('csrf_token', _csrfToken);
            return _csrfToken;
        }
    };

    const generateFallbackToken = () => {
        return 'csrf_' + Date.now() + '_' + Math.random().toString(36).substring(2, 15);
    };

    // Register
    const register = async ({ username, mobile, password, email = '', referral_code = '' }) => {
        try {
            // client-side validation
            if (!username || username.length < 3) return { success: false, message: 'Username must be at least 3 characters' };
            if (!mobile || !/^[0-9]{10}$/.test(mobile)) return { success: false, message: 'Please enter a valid 10-digit mobile number' };
            if (!password || password.length < 6) return { success: false, message: 'Password must be at least 6 characters' };

            const csrf = await getCsrfToken();
            const resp = await fetch(`${API_BASE}?action=register`, {
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
                    referral_code: referral_code?.trim() || '',
                    csrf_token: csrf
                })
            });

            const text = await resp.text();
            if (!text || !text.trim().startsWith('{')) {
                console.error('[Auth] Register non-JSON response', text && text.substring ? text.substring(0,200) : text);
                return { success: false, message: 'Server error. Please try again later.' };
            }

            const data = JSON.parse(text);
            if (data.success && data.data && data.data.user) {
                storeUserData(data.data.user);
                if (data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                }
            }
            return data;
        } catch (err) {
            console.error('[Auth] register error', err);
            return { success: false, message: 'Network error. Please try again.' };
        }
    };

    // Login
    const login = async ({ username, password }) => {
        try {
            if (!username || !password) return { success: false, message: 'Username and password are required' };

            const csrf = await getCsrfToken();
            const resp = await fetch(`${API_BASE}?action=login`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ username: username.trim(), password, csrf_token: csrf })
            });

            const text = await resp.text();
            if (!text || !text.trim().startsWith('{')) {
                console.error('[Auth] Login non-JSON response', text && text.substring ? text.substring(0,200) : text);
                return { success: false, message: 'Server error. Please try again later.' };
            }

            const data = JSON.parse(text);
            if (data.success && data.data && data.data.user) {
                storeUserData(data.data.user);
                if (data.data.csrf_token) {
                    _csrfToken = data.data.csrf_token;
                    localStorage.setItem('csrf_token', _csrfToken);
                }
            }
            return data;
        } catch (err) {
            console.error('[Auth] login error', err);
            return { success: false, message: 'Network error. Please try again.' };
        }
    };

    // Logout - informs server and clears local state
    const logout = async () => {
        try {
            const csrf = await getCsrfToken();
            await fetch(`${API_BASE}?action=logout`, {
                method: 'POST',
                credentials: 'include',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrf
                },
                body: JSON.stringify({ csrf_token: csrf })
            }).catch(() => {});
        } catch (e) {
            console.warn('[Auth] logout fetch failed', e);
        } finally {
            clearUserData();
            return { success: true };
        }
    };

    // Check auth status (local-first, then server verify)
    const checkAuth = async () => {
        try {
            const storedUser = localStorage.getItem('user');
            const isLoggedIn = localStorage.getItem('isLoggedIn');
            if (isLoggedIn === 'true' && storedUser) {
                try {
                    const u = JSON.parse(storedUser);
                    return { success: true, isLoggedIn: true, user: u };
                } catch (e) { clearUserData(); }
            }

            const resp = await fetch(`${API_BASE}?action=check`, {
                method: 'GET',
                credentials: 'include',
                headers: { 'Accept': 'application/json' }
            });
            const text = await resp.text();
            if (!text || !text.trim().startsWith('{')) return { success: false, isLoggedIn: false };

            const data = JSON.parse(text);
            // Accept either data.isLoggedIn or data.success + data.data.user
            if (data.success && (data.isLoggedIn || data.data?.user)) {
                const userObj = data.data?.user || data.user || {};
                storeUserData(userObj);
                return { success: true, isLoggedIn: true, user: userObj };
            }
            clearUserData();
            return { success: true, isLoggedIn: false };
        } catch (err) {
            console.warn('[Auth] checkAuth error', err);
            return { success: false, isLoggedIn: false, message: 'Network error' };
        }
    };

    return {
        register,
        login,
        logout,
        checkAuth,
        getCsrfToken
    };
})();
window.AuthHelper = AuthHelper;
