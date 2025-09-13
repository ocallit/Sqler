/**
 * ErrorTemplateNormalizer - JavaScript equivalent of PHP ErrorTemplateNormalizer
 * 
 * Creates normalized templates and hashes for client-side error deduplication.
 * This prevents sending the same error multiple times per page load.
 */
class ErrorTemplateNormalizer {
    
    /**
     * Cache to track which errors have already been sent this session
     * @type {Map<string, boolean>}
     */
    static sentErrors = new Map();
    
    /**
     * Normalize JavaScript errors and create template hash
     * 
     * @param {string} message - Error message
     * @param {string} filename - File where error occurred
     * @param {number} lineno - Line number
     * @param {number} colno - Column number  
     * @param {string} stack - Stack trace
     * @param {string} source - Error source (window.error, unhandledrejection, etc.)
     * @returns {Object|null} Normalized error data or null if already sent
     */
    static normalizeJavaScriptError(message, filename = '', lineno = 0, colno = 0, stack = '', source = 'unknown') {
        // Normalize JavaScript error message
        const normalizedMessage = this.normalizeJavaScriptMessage(message);
        
        // Clean filename (remove query parameters, hash, etc.)
        const cleanFilename = this.cleanJavaScriptFilename(filename);
        
        // Extract function context from stack trace
        const functionContext = this.extractJavaScriptContext(stack);
        
        // Create template: JS_ERROR + normalized_message + filename + function_context
        let template = `JS_ERROR|${normalizedMessage}|${cleanFilename}`;
        if (functionContext) {
            template += `|${functionContext}`;
        }
        
        // Create hash using simple string hashing (xxh3 equivalent)
        const templateHash = this.createHash(template);
        
        // Check if we've already sent this error in this session
        if (this.sentErrors.has(templateHash)) {
            return null; // Skip duplicate
        }
        
        // Mark as sent
        this.sentErrors.set(templateHash, true);
        
        return {
            template_hash: templateHash,
            template: template,
            source: 'javascript',
            error_number: null,
            message: message.substring(0, 1000),
            filename: filename.substring(0, 500),
            lineno: lineno,
            colno: colno,
            stack: stack.substring(0, 2000),
            url: window.location.href,
            timestamp: new Date().toISOString(),
            user_agent: navigator.userAgent,
            context_data: {
                original_message: message,
                normalized_message: normalizedMessage,
                stack_trace: stack.substring(0, 2000),
                function_context: functionContext,
                source: source,
                clean_filename: cleanFilename,
                page_url: window.location.href
            }
        };
    }
    
    /**
     * Normalize AJAX/Fetch errors
     * 
     * @param {string} message - Error message
     * @param {string} url - Request URL that failed
     * @param {number|string} status - HTTP status code
     * @param {string} method - HTTP method (GET, POST, etc.)
     * @returns {Object|null} Normalized error data or null if already sent
     */
    static normalizeAjaxError(message, url = '', status = 0, method = 'GET') {
        // Normalize URL (remove query parameters and dynamic parts)
        const normalizedUrl = this.normalizeUrl(url);
        
        // Normalize status to string
        const statusStr = String(status);
        
        // Create template
        const template = `AJAX_ERROR|${method}|${statusStr}|${normalizedUrl}`;
        const templateHash = this.createHash(template);
        
        // Check for duplicates
        if (this.sentErrors.has(templateHash)) {
            return null;
        }
        
        this.sentErrors.set(templateHash, true);
        
        return {
            template_hash: templateHash,
            template: template,
            source: 'javascript',
            error_number: status,
            message: message.substring(0, 1000),
            filename: window.location.href,
            lineno: 0,
            colno: 0,
            stack: new Error().stack || '',
            url: window.location.href,
            timestamp: new Date().toISOString(),
            user_agent: navigator.userAgent,
            context_data: {
                original_message: message,
                request_url: url,
                normalized_url: normalizedUrl,
                status: status,
                method: method,
                source: 'ajax'
            }
        };
    }
    
    /**
     * Normalize Promise rejection errors
     * 
     * @param {*} reason - Promise rejection reason
     * @param {string} source - Where the rejection occurred
     * @returns {Object|null} Normalized error data or null if already sent
     */
    static normalizePromiseRejection(reason, source = 'unhandledrejection') {
        let message = 'Unhandled promise rejection';
        let stack = '';
        
        if (reason instanceof Error) {
            message = reason.message || message;
            stack = reason.stack || '';
        } else if (typeof reason === 'string') {
            message = reason;
        } else if (reason && typeof reason === 'object') {
            message = reason.message || reason.toString() || message;
            stack = reason.stack || '';
        }
        
        // Normalize the message
        const normalizedMessage = this.normalizeJavaScriptMessage(`Promise rejection: ${message}`);
        
        // Extract context
        const functionContext = this.extractJavaScriptContext(stack);
        
        // Create template
        let template = `PROMISE_ERROR|${normalizedMessage}`;
        if (functionContext) {
            template += `|${functionContext}`;
        }
        
        const templateHash = this.createHash(template);
        
        // Check for duplicates
        if (this.sentErrors.has(templateHash)) {
            return null;
        }
        
        this.sentErrors.set(templateHash, true);
        
        return {
            template_hash: templateHash,
            template: template,
            source: 'javascript',
            error_number: null,
            message: `Promise rejection: ${message}`.substring(0, 1000),
            filename: window.location.href,
            lineno: 0,
            colno: 0,
            stack: stack.substring(0, 2000),
            url: window.location.href,
            timestamp: new Date().toISOString(),
            user_agent: navigator.userAgent,
            context_data: {
                original_reason: typeof reason === 'object' ? JSON.stringify(reason) : String(reason),
                normalized_message: normalizedMessage,
                stack_trace: stack.substring(0, 2000),
                function_context: functionContext,
                source: source
            }
        };
    }
    
    /**
     * Normalize JavaScript error messages
     * 
     * @param {string} message - Original error message
     * @returns {string} Normalized message
     */
    static normalizeJavaScriptMessage(message) {
        if (!message) return '';
        
        // First apply general normalization
        let normalized = this.normalizeMessage(message);
        
        // JavaScript-specific patterns
        const jsPatterns = [
            [/at line \d+/g, 'at line ?'],
            [/at column \d+/g, 'at column ?'],
            [/line \d+/g, 'line ?'],
            [/:\d+:\d+/g, ':?:?'], // Line:column references
            [/Cannot read propert(?:y|ies) '[^']+'/g, "Cannot read property '?'"],
            [/Cannot set propert(?:y|ies) '[^']+'/g, "Cannot set property '?'"],
            [/[a-zA-Z_$][a-zA-Z0-9_$]* is not defined/g, '? is not defined'],
            [/[a-zA-Z_$][a-zA-Z0-9_$]* is not a function/g, '? is not a function'],
            [/Uncaught \w+Error:/g, 'Uncaught ?Error:'],
        ];
        
        jsPatterns.forEach(([pattern, replacement]) => {
            normalized = normalized.replace(pattern, replacement);
        });
        
        return normalized.substring(0, 200).trim();
    }
    
    /**
     * Normalize general error messages by replacing dynamic values
     * 
     * @param {string} message - Original error message
     * @returns {string} Normalized message with placeholders
     */
    static normalizeMessage(message) {
        if (!message) return '';
        
        // Replace common dynamic patterns with placeholders
        const patterns = [
            [/\b\d+\b/g, '?'], // Numbers
            [/'[^']*'/g, "'?'"], // Single-quoted strings
            [/"[^"]*"/g, '"?"'], // Double-quoted strings
            [/\b[a-f0-9]{8,}\b/gi, '?'], // Hex values (IDs, hashes)
            [/\b\d{4}-\d{2}-\d{2}/g, '?'], // Dates
            [/\b\d{2}:\d{2}:\d{2}/g, '?'], // Times
            [/https?:\/\/[^\s]+/g, '?'], // URLs
            [/\/[^\s]*\.js/g, '/?.js'], // JS file paths
            [/\$[a-zA-Z_][a-zA-Z0-9_]*/g, '$?'], // Variable names
        ];
        
        let normalized = message;
        patterns.forEach(([pattern, replacement]) => {
            normalized = normalized.replace(pattern, replacement);
        });
        
        // Clean up multiple consecutive placeholders
        normalized = normalized.replace(/\?+/g, '?');
        
        return normalized.trim();
    }
    
    /**
     * Clean JavaScript filename (remove query params, hash, etc.)
     * 
     * @param {string} filename - Original filename/URL
     * @returns {string} Cleaned filename
     */
    static cleanJavaScriptFilename(filename) {
        if (!filename) return '';
        
        // Remove query parameters and hash
        let clean = filename.replace(/[?#].*$/, '');
        
        // Keep only the path part, not the full URL
        clean = clean.replace(/^https?:\/\/[^\/]+/, '');
        
        return clean;
    }
    
    /**
     * Normalize URLs by removing dynamic parameters
     * 
     * @param {string} url - Original URL
     * @returns {string} Normalized URL
     */
    static normalizeUrl(url) {
        if (!url) return '';
        
        try {
            const urlObj = new URL(url);
            
            // Keep protocol, host, and pathname, but normalize query params
            let normalized = `${urlObj.protocol}//${urlObj.host}${urlObj.pathname}`;
            
            // Replace dynamic path segments
            normalized = normalized.replace(/\/\d+/g, '/?'); // Numeric IDs
            normalized = normalized.replace(/\/[a-f0-9-]{8,}/gi, '/?'); // UUIDs/hashes
            
            return normalized;
        } catch (e) {
            // If URL parsing fails, just clean it up as string
            return url.replace(/[?#].*$/, '').replace(/\/\d+/g, '/?');
        }
    }
    
    /**
     * Extract JavaScript function context from stack trace
     * 
     * @param {string} stack - Stack trace
     * @returns {string} Function context
     */
    static extractJavaScriptContext(stack) {
        if (!stack) return '';
        
        // Try alternative stack trace format
        match = stack.match(/([a-zA-Z_$][a-zA-Z0-9_$]*)\@/);
        if (match) {
            return `${match[1]}()`;
        }
        
        return '';
    }
    
    /**
     * Create a simple hash from string (xxh3 equivalent for JavaScript)
     * Uses a simple but effective string hashing algorithm
     * 
     * @param {string} str - String to hash
     * @returns {string} Hash string
     */
    static createHash(str) {
        let hash = 0;
        if (str.length === 0) return hash.toString(16);
        
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32-bit integer
        }
        
        // Convert to positive hex string
        return Math.abs(hash).toString(16).padStart(8, '0');
    }
    
    /**
     * Check if an error should be ignored (common non-errors)
     * 
     * @param {string} message - Error message
     * @param {string} filename - Filename where error occurred
     * @returns {boolean} True if error should be ignored
     */
    static shouldIgnoreError(message, filename = '') {
        const ignoredMessages = [
            'Script error.',
            'ResizeObserver loop limit exceeded',
            'ResizeObserver loop completed with undelivered notifications',
            'Non-Error promise rejection captured',
            'Loading chunk',
            'Loading CSS chunk',
            'ChunkLoadError',
            'NetworkError when attempting to fetch resource',
            'The request is not allowed by the user agent',
            'Permission denied to access property',
            'SecurityError',
            'Script error',
            'Network request failed'
        ];
        
        const messageToCheck = message.toLowerCase();
        
        // Check against ignored messages
        for (const ignoredMessage of ignoredMessages) {
            if (messageToCheck.includes(ignoredMessage.toLowerCase())) {
                return true;
            }
        }
        
        // Ignore errors from browser extensions
        if (filename && (
            filename.includes('extension://') ||
            filename.includes('chrome-extension://') ||
            filename.includes('moz-extension://') ||
            filename.includes('safari-extension://')
        )) {
            return true;
        }
        
        // Ignore very short or empty messages
        if (!message || message.trim().length < 3) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Clear the sent errors cache (call this when navigating to new page)
     */
    static clearSentErrors() {
        this.sentErrors.clear();
    }
    
    /**
     * Get count of unique errors sent this session
     * 
     * @returns {number} Count of unique errors sent
     */
    static getSentErrorCount() {
        return this.sentErrors.size;
    }
    
    /**
     * Check if a specific error hash has been sent
     * 
     * @param {string} templateHash - Template hash to check
     * @returns {boolean} True if already sent
     */
    static hasBeenSent(templateHash) {
        return this.sentErrors.has(templateHash);
    }
    
    /**
     * Manually mark an error as sent (useful for testing)
     * 
     * @param {string} templateHash - Template hash to mark as sent
     */
    static markAsSent(templateHash) {
        this.sentErrors.set(templateHash, true);
    }
    
    /**
     * Get just the filename from a path (for easy filtering)
     * 
     * @param {string} filepath - Full file path
     * @returns {string} Just the filename
     */
    static getFilenameOnly(filepath) {
        if (!filepath) return '';
        return filepath.split('/').pop() || '';
    }
    
    /**
     * Parse user agent for structured data
     * 
     * @param {string} userAgent - User agent string
     * @returns {Object} Parsed user agent data
     */
    static parseUserAgent(userAgent) {
        if (!userAgent) return {};
        
        const result = {
            browser: 'unknown',
            version: 'unknown',
            os: 'unknown',
            mobile: false
        };
        
        // Simple browser detection
        if (userAgent.includes('Chrome')) {
            result.browser = 'Chrome';
            const match = userAgent.match(/Chrome\/(\d+)/);
            if (match) result.version = match[1];
        } else if (userAgent.includes('Firefox')) {
            result.browser = 'Firefox';
            const match = userAgent.match(/Firefox\/(\d+)/);
            if (match) result.version = match[1];
        } else if (userAgent.includes('Safari') && !userAgent.includes('Chrome')) {
            result.browser = 'Safari';
        } else if (userAgent.includes('Edge')) {
            result.browser = 'Edge';
        }
        
        // Simple OS detection
        if (userAgent.includes('Windows')) result.os = 'Windows';
        else if (userAgent.includes('Mac')) result.os = 'macOS';
        else if (userAgent.includes('Linux')) result.os = 'Linux';
        else if (userAgent.includes('Android')) result.os = 'Android';
        else if (userAgent.includes('iOS')) result.os = 'iOS';
        
        // Mobile detection
        result.mobile = /Mobile|Android|iPhone|iPad/.test(userAgent);
        
        return result;
    }
    
    /**
     * Get debug information about the normalizer
     * 
     * @returns {Object} Debug information
     */
    static getDebugInfo() {
        return {
            sentErrorCount: this.sentErrors.size,
            sentErrorHashes: Array.from(this.sentErrors.keys()),
            timestamp: new Date().toISOString()
        };
    }
}

// Auto-clear cache when page unloads (for single-page applications)
if (typeof window !== 'undefined') {
    window.addEventListener('beforeunload', () => {
        ErrorTemplateNormalizer.clearSentErrors();
    });
    
    // For SPA navigation, you might want to clear on route changes
    // This depends on your SPA framework
    if (typeof history !== 'undefined' && history.pushState) {
        const originalPushState = history.pushState;
        const originalReplaceState = history.replaceState;
        
        history.pushState = function() {
            ErrorTemplateNormalizer.clearSentErrors();
            return originalPushState.apply(this, arguments);
        };
        
        history.replaceState = function() {
            ErrorTemplateNormalizer.clearSentErrors();
            return originalReplaceState.apply(this, arguments);
        };
    }
}

// Export for both CommonJS and ES6 modules
if (typeof module !== 'undefined' && module.exports) {
    module.exports = ErrorTemplateNormalizer;
}

/**
 * Usage Examples:
 * 
 * // Basic error normalization
 * const errorData = ErrorTemplateNormalizer.normalizeJavaScriptError(
 *     'TypeError: Cannot read property "name" of null',
 *     '/assets/js/app.js',
 *     42,
 *     10,
 *     'Error: TypeError...\n    at getUserName (/assets/js/app.js:42:10)'
 * );
 * 
 * if (errorData) {
 *     // Send to server - errorData will be null if already sent
 *     sendErrorToServer(errorData);
 * }
 * 
 * // AJAX error normalization
 * const ajaxError = ErrorTemplateNormalizer.normalizeAjaxError(
 *     'Failed to fetch',
 *     '/api/users/123',
 *     500,
 *     'GET'
 * );
 * 
 * // Promise rejection normalization
 * const promiseError = ErrorTemplateNormalizer.normalizePromiseRejection(
 *     new Error('Database connection failed'),
 *     'async-operation'
 * );
 * 
 * // Check if error should be ignored
 * if (!ErrorTemplateNormalizer.shouldIgnoreError(errorMessage, filename)) {
 *     // Process the error
 * }
 * 
 * // Debug information
 * console.log(ErrorTemplateNormalizer.getDebugInfo());
 */ to extract the first meaningful function name from stack
        let match = stack.match(/at\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\(/);
        if (match) {
            return `${match[1]}()`;
        }
        
        // Try