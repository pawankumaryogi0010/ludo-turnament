/**
 * auth-helper.js
 * FIX BUG 1: CSRF Token flow for register/login
 * Include this BEFORE any auth calls in your main HTML
 */

const AuthHelper = (() => {
    const API_BASE = '/api/auth.php';
    let _csrfToken = '';

    // Step 1: Always fetch CSRF token first before register/login
    const getCsrfToken = async () => {
        try {
            const res = await fetch(`${API_BASE}?action=get_csrf`, {
                method: 'GET',
                credentials: 'include', // sends session cookie
                headers: { 'Content-Type': 'application/json' }
            });
            const data = await res.json();
            if (data.success) {
                _csrfToken = data.data.csrf_token;
                return _csrfToken;
            }
        } catch (e) {
            console.error('[Auth] CSRF fetch failed:', e);
        }
        return '';
    };

    // Step 2: Register with CSRF token
    const register = async ({ username, mobile, password, email = '', referralCode = '' }) => {
        const csrf = await getCsrfToken(); // always fresh token
        const res = await fetch(`${API_BASE}?action=register`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf, // header method
            },
            body: JSON.stringify({
                username, mobile, password, email,
                referral_code: referralCode,
                csrf_token: csrf // body method (auth.php checks both)
            })
        });
        return res.json();
    };

    // Step 3: Login with CSRF token
    const login = async ({ mobile, password }) => {
        const csrf = await getCsrfToken();
        const res = await fetch(`${API_BASE}?action=login`, {
            method: 'POST',
            credentials: 'include',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrf,
            },
            body: JSON.stringify({ mobile, password, csrf_token: csrf })
        });
        return res.json();
    };

    // Check if logged in
    const checkAuth = async () => {
        const res = await fetch(`${API_BASE}?action=check`, {
            credentials: 'include'
        });
        return res.json();
    };

    // Logout
    const logout = async () => {
        const csrf = _csrfToken || await getCsrfToken();
        const res = await fetch(`${API_BASE}?action=logout`, {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrf },
            body: JSON.stringify({ csrf_token: csrf })
        });
        return res.json();
    };

    return { register, login, checkAuth, logout, getCsrfToken };
})();

// Usage in your HTML:
// const result = await AuthHelper.register({ username, mobile, password });
// const result = await AuthHelper.login({ mobile, password });
