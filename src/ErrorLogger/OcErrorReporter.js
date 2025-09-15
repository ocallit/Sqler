/**
 * Vanilla JS Error Logger
 * - Dedupe by hash(errorCode|file|line|col)
 * - Keeps at most MAX_HASHES unique hashes per page load (new uniques beyond that are ignored)
 * - Sends EXACT schema field names to your endpoint (JSON)
 * - Exports only: window.jsErrorReporter(err) for manual try/catch blocks
 * - Prefixes error_message with [kind] where kind ∈ {"error","unhandledrejection","manual"}
 */
(function() {
    // ====== CONFIG ======
    var ENDPOINT = "/api/error-log"; // ← change to your collector
    var MAX_HASHES = 64;               // cap of unique errors per page load

    // ====== FNV-1a 64-bit (hex, 16 chars) ======
    function _fnv1a64Hex(str) {
        var hLo = 0x84222325 | 0, hHi = 0xcbf29ce4 | 0; // offset basis split (low/high)
        for(var i = 0; i < str.length; i++) {
            var c = str.charCodeAt(i) & 0xff;
            hLo ^= c;
            var nLo = (hLo * 0x1b3) >>> 0; // * FNV prime (low)
            var nHi = (hHi * 0x1b3) >>> 0; // * FNV prime (high)
            nHi += ((hLo >>> 0) * 0x000001) >>> 0; // carry
            hLo = nLo;
            hHi = nHi >>> 0;
        }

        function to8Hex(n) {
            return ("00000000" + (n >>> 0).toString(16)).slice(-8);
        }

        return (to8Hex(hHi) + to8Hex(hLo)).slice(-16);
    }

    // ====== Helpers ======
    // Keep filenames stable across cache-buster/query params (optional but helps dedupe)
    function _normalizeFile(u) {
        try {
            var url = new URL(u || "", location.href);
            return url.origin + url.pathname; // drop ? and #
        } catch(_) {
            return (u || String(location.href)).split(/[?#]/)[0];
        }
    }

    function _pickCode(errLike) {
        return (errLike && (errLike.code || errLike.name || (errLike.constructor && errLike.constructor.name))) || "Error";
    }

    function _asString(x) {
        try {
            return String(x);
        } catch(_) {
            return "[unstringifiable]";
        }
    }

    // Parse the first stack frame that has url:line:col
    function _parseStack(err) {
        if(!err || !err.stack) return {file: "", line: 0, col: 0};
        var s = String(err.stack);
        var lines = s.split("\n");

        for(var i = 0; i < lines.length; i++) {
            // Common patterns (Chrome/Firefox/Safari)
            var m = lines[i].match(/\((https?:\/\/[^\s)]+):(\d+):(\d+)\)/) ||
                lines[i].match(/at (https?:\/\/[^\s)]+):(\d+):(\d+)/) ||
                lines[i].match(/(https?:\/\/[^\s)]+):(\d+):(\d+)/);
            if(m) {
                return {
                    file: m[1],
                    line: parseInt(m[2], 10) || 0,
                    col: parseInt(m[3], 10) || 0
                };
            }
        }
        return {file: "", line: 0, col: 0};
    }

    function _postJSON(payload) {
        try {
            fetch(ENDPOINT, {
                method: "POST",
                headers: {"Content-Type": "application/json"},
                body: JSON.stringify(payload),
                keepalive: true, // Allows the request to finish even if the page is unloading (navigation, close tab, reload).
                credentials: "same-origin"
            }).catch(function() {
            });
        } catch(_) {
            // Best-effort fallback via sendBeacon if available
            try {
                if(navigator.sendBeacon) {
                    var blob = new Blob([JSON.stringify(payload)], {type: "application/json"});
                    navigator.sendBeacon(ENDPOINT, blob);
                }
            } catch(__) {
            }
        }
    }

    // ====== Dedupe (max N unique per page load) ======
    var seen = []; // array of hashes to preserve order; hard-capped
    function _alreadySeen(hash) {
        return seen.indexOf(hash) !== -1;
    }

    function _remember(hash) {
        if(seen.length >= MAX_HASHES) return false; // ignore new uniques
        seen.push(hash);
        return true;
    }

    // ====== Core builder (schema-aligned keys ONLY) ======
    function reportError(kind, errLike, explicitFile, explicitLine, explicitCol) {
        var code = _pickCode(errLike);
        var loc = _parseStack(errLike);

        var file = _normalizeFile(explicitFile || loc.file || location.href);
        var line = (explicitLine != null ? explicitLine : loc.line) || 0;
        var col = (explicitCol != null ? explicitCol : loc.col) || 0;

        var hashBasis = code + "|" + file + "|" + line + "|" + col;
        var hash = _fnv1a64Hex(hashBasis);

        if(_alreadySeen(hash)) return;
        if(!_remember(hash)) return;

        var message = (errLike && errLike.message) || _asString(errLike);
        var prefixedMessage = "[" + kind + "] " + (message || "");

        var payload = {
            error_hash: hash,
            error_type: "JS",
            error_code: code,
            error_message: prefixedMessage,                 // <-- kind prefixed here
            original: (errLike && errLike.stack) || prefixedMessage,
            template: hashBasis,
            file_path: file,
            function_name: "",                              // browsers rarely provide reliably
            line_number: line,
            column_number: col,
            user_agent: navigator.userAgent,
            request_uri: location.href,
            user_nick: "",                              // fill if you have it
            context_data: ""                               // or JSON string if you want extra context
        };

        _postJSON(payload);
    }

    // ====== Global export for manual try/catch ======
    window.OcErrorReporter = function(err) {
        reportError("try/catch", err);
    };

    // ====== Automatic listeners ======
    window.addEventListener("error", function(e) {
        // e is an ErrorEvent; prefer e.error (an Error) if present
        let errObj = e && e.error ? e.error : {name: "ErrorEvent", message: e && e.message};
        reportError("error", errObj, e && e.filename, e && e.lineno, e && e.colno);
    }, true);

    window.addEventListener("unhandledrejection", function(event) {
        let reason = event && event.reason;
        let errObj = (reason instanceof Error) ? reason : {
            name: "UnhandledRejection",
            message: _asString(reason),
            stack: reason && reason.stack
        };
        reportError("unhandledrejection", errObj);
    }, true);
})();

