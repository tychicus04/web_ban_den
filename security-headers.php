<?php
/**
 * Security Headers Helper
 * Adds important security headers to HTTP responses
 */

/**
 * Set all security headers
 * Call this at the beginning of your PHP scripts
 */
function setSecurityHeaders()
{
    // Prevent clickjacking attacks
    header("X-Frame-Options: SAMEORIGIN");

    // Enable XSS protection in older browsers
    header("X-XSS-Protection: 1; mode=block");

    // Prevent MIME type sniffing
    header("X-Content-Type-Options: nosniff");

    // Referrer Policy - control referrer information
    header("Referrer-Policy: strict-origin-when-cross-origin");

    // Content Security Policy (CSP)
    // Adjust this based on your needs
    $csp = "default-src 'self'; " .
           "script-src 'self' 'unsafe-inline' 'unsafe-eval'; " .
           "style-src 'self' 'unsafe-inline'; " .
           "img-src 'self' data: https:; " .
           "font-src 'self' data:; " .
           "connect-src 'self'; " .
           "frame-ancestors 'self';";
    header("Content-Security-Policy: " . $csp);

    // Permissions Policy (formerly Feature Policy)
    $permissions = "geolocation=(), " .
                   "microphone=(), " .
                   "camera=(), " .
                   "payment=(), " .
                   "usb=(), " .
                   "magnetometer=()";
    header("Permissions-Policy: " . $permissions);

    // Strict Transport Security (HSTS) - only enable if you have HTTPS
    // Uncomment the following line when you have SSL certificate installed
    // header("Strict-Transport-Security: max-age=31536000; includeSubDomains; preload");
}

/**
 * Force HTTPS redirect
 * Redirects HTTP requests to HTTPS
 */
function forceHTTPS()
{
    if (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] !== 'on') {
        if (php_sapi_name() !== 'cli') {
            $redirect_url = "https://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
            header("Location: " . $redirect_url, true, 301);
            exit;
        }
    }
}

/**
 * Prevent page caching for sensitive pages
 */
function preventCaching()
{
    header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
    header("Cache-Control: post-check=0, pre-check=0", false);
    header("Pragma: no-cache");
    header("Expires: 0");
}

/**
 * Set secure session cookie parameters
 */
function setSecureSessionConfig()
{
    // Session cookie settings
    $session_name = 'TKMALL_SESSION';
    $secure = false; // Set to true when you have HTTPS
    $httponly = true; // Prevent JavaScript access to session cookie
    $samesite = 'Strict'; // CSRF protection

    ini_set('session.cookie_httponly', $httponly);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', $secure);
    ini_set('session.cookie_samesite', $samesite);
    ini_set('session.name', $session_name);

    // Session settings
    ini_set('session.gc_maxlifetime', 3600); // 1 hour
    ini_set('session.cookie_lifetime', 0); // Until browser closes
}

/**
 * Initialize all security measures
 * Call this at the beginning of your application
 *
 * @param bool $forceHttps Whether to force HTTPS redirect
 * @param bool $preventCache Whether to prevent page caching
 */
function initSecurity($forceHttps = false, $preventCache = false)
{
    // Set secure session configuration before starting session
    setSecureSessionConfig();

    // Set security headers
    setSecurityHeaders();

    // Force HTTPS if requested
    if ($forceHttps) {
        forceHTTPS();
    }

    // Prevent caching if requested (for sensitive pages)
    if ($preventCache) {
        preventCaching();
    }
}

/**
 * Sanitize user input to prevent XSS
 * @param string $data The data to sanitize
 * @return string Sanitized data
 */
function sanitizeInput($data)
{
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Generate a secure random token
 * @param int $length Length of the token (default 32)
 * @return string Random token
 */
function generateSecureToken($length = 32)
{
    return bin2hex(random_bytes($length));
}

/**
 * Check if request is from same origin
 * @return bool True if same origin, false otherwise
 */
function isSameOrigin()
{
    if (!isset($_SERVER['HTTP_ORIGIN'])) {
        return true; // No origin header, likely direct request
    }

    $origin = parse_url($_SERVER['HTTP_ORIGIN']);
    $host = $_SERVER['HTTP_HOST'];

    return $origin['host'] === $host;
}
?>
