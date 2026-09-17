/**
 * ErrorLog javascript client Quick Reference:
 *
 * Setup, include it as the first script of the page, it starts itself:
 *   <script src="/js/errorLog.js"></script>
 *   <script src="/js/errorLog.js" data-url="/error_log/api/" data-max-errors="4" data-stack-lines="9"></script>
 *
 * The same script logs the errors of a worker, as the first line of the worker:
 *   importScripts('/js/errorLog.js');
 * A worker has no script tag to read the options from, ErrorLog.start({url: '/error_log/api/'})
 * sets them, and its own errors, hashes and count, the page's four are not spent by it.
 *
 * Anything that throws before the script runs is not logged, the browser has no listener yet.
 * ErrorLog.start({url: '/error_log/api/'}) is only needed to set the options from javascript,
 * on a page that already started it a later start() call updates the options, the listeners
 * are registered once.
 *
 * Logging what the code catches, the window listeners never see it:
 *   try { risky(); } catch(err) { ErrorLog.log(err); }
 *   fetch(url)['catch'](function(err) { ErrorLog.log(err); });
 *   ErrorLog.log(new Error('total does not match the cart'));   // new Error carries the stack
 *
 * Listens to window error and unhandledrejection, posts action=log to the error_log api,
 * which stores the error with Ocallit\Sqler\ErrorLog::javascriptErrors().
 * An exception thrown inside a then(), an async function or an awaited call never fires the
 * error event, the language turns it into a rejection of that promise, so
 * unhandledrejection is what logs the errors of every asynchronous call. A rejection that
 * something catches is invisible to the browser and to this client, log those with
 * ErrorLog.log() from inside the catch.
 *
 * Only the first ErrorLog.maxErrors distinct hashes of a page load are posted, a repeat of a
 * kept hash and anything past the last one return without a request. The hash is djb2 of
 * file|line|JS|error_code, a guard for the browser only, the stored hash is the one
 * ErrorLog::javascriptErrors() makes again on the server.
 * The stack trace is cut to ErrorLog.stackLines lines, a failed post is swallowed so that
 * the logger never becomes the error it is logging.
 */
var ErrorLog = (function() {
'use strict';

/** window in a page, self in a worker, the only global this script touches */
var root = self;

var url = '/error_log/api/';
/** Distinct errors posted per page load */
var maxErrors = 4;
/** Longest stack trace posted, in lines */
var stackLines = 9;

var isStarted = false;
/** {hash: {file: , line_number: , ...}} the errors posted so far, hash => error */
var errors = {};
/** distinct errors kept */
var kept = 0;
var previousOnError = null;

/**
 * Registers the listeners, chaining any previously registered onerror
 *
 * @param {Object} [options] url, maxErrors, stackLines
 */
function start(options) {
    options = options || {};
    url = textOption(options.url, url);
    maxErrors = numberOption(options.maxErrors, maxErrors);
    stackLines = numberOption(options.stackLines, stackLines);
    if(isStarted) return;
    isStarted = true;
    if(root.addEventListener) {
        root.addEventListener('error', onError, false);
        root.addEventListener('unhandledrejection', onRejection, false);
        return;
    }
    previousOnError = root.onerror;
    root.onerror = function(message, file, lineNumber, columnNumber, error) {
        onError({message: message, filename: file, lineno: lineNumber, colno: columnNumber, error: error});
        return previousOnError ? previousOnError.apply(root, arguments) : false;
    };
}

/**
 * Logs an error the code caught, it counts against the same maxErrors of the page load
 *
 * @param {*} error the caught Error, or anything a throw or a rejection carried
 * @return {void}
 */
function log(error) {
    try {
        var stack = error && error.stack ? error.stack : '';
        var frame = frameIt(stack);
        add({
          error_code: codeIt(error, 'Caught'),
          error_message: messageIt(error),
          file: String((error && error.fileName) || frame.file),
          line_number: Number(error && error.lineNumber) || frame.line,
          column_number: Number(error && error.columnNumber) || frame.column,
          function_name: functionIt(stack),
          content: stackIt(stack),
          request_uri: root.location.href
        });
    } catch(ignore) {}
}

/** @return {Object} the errors posted so far, hash => error */
function getErrors() {return errors;}

/**
 * Starts from the script tag, data-url, data-max-errors and data-stack-lines set the options,
 * an attribute that is missing or is not a value the option accepts keeps the default.
 * A worker has no document, it starts with the defaults until start() sets the options
 */
function autoStart() {
    var script = root.document ? root.document.currentScript : null;
    if(!script) {
        start();
        return;
    }
    start({
      url: script.getAttribute('data-url'),
      maxErrors: script.getAttribute('data-max-errors'),
      stackLines: script.getAttribute('data-stack-lines')
    });
}

/** @return {string} the option when it is text, the default when it is anything else */
function textOption(value, byDefault) {
    if(typeof value !== 'string') return byDefault;
    value = value.replace(/^\s+|\s+$/g, '');
    return value === '' ? byDefault : value;
}

/** @return {number} the option when it is a whole number over zero, the default otherwise */
function numberOption(value, byDefault) {
    value = Math.floor(Number(value));
    return isFinite(value) && value > 0 ? value : byDefault;
}

/** error listener, an ErrorEvent, or the object the onerror fallback builds */
function onError(event) {
    try {
        var error = event.error || {};
        var frame = frameIt(error.stack);
        // Cross-origin scripts report "Script error." with no file, line or stack unless the
        // tag has crossorigin="anonymous" and the CDN sends Access-Control-Allow-Origin
        add({
          error_code: codeIt(error, 'Error'),
          error_message: String(event.message || error.message || 'Unknown error'),
          file: String(event.filename || frame.file),
          line_number: Number(event.lineno) || frame.line,
          column_number: Number(event.colno) || frame.column,
          function_name: functionIt(error.stack),
          content: stackIt(error.stack),
          request_uri: root.location.href
        });
    } catch(ignore) {}
}

/** unhandledrejection listener, the rejection carries no file, the stack does */
function onRejection(event) {
    try {
        var reason = event.reason;
        var stack = reason && reason.stack ? reason.stack : '';
        var frame = frameIt(stack);
        add({
          error_code: codeIt(reason, 'UnhandledRejection'),
          error_message: 'Unhandled rejection: ' + messageIt(reason),
          file: frame.file,
          line_number: frame.line,
          column_number: frame.column,
          function_name: functionIt(stack),
          content: stackIt(stack),
          request_uri: root.location.href
        });
    } catch(ignore) {}
}

/** Posts the error when it is new and there is room, returns on a repeat or when full */
function add(error) {
    var hash = hashIt(error.file, error.line_number, error.error_code);
    if(Object.prototype.hasOwnProperty.call(errors, hash)) return;
    if(kept >= maxErrors) return;
    kept++;
    errors[hash] = error;
    send(error);
}

function send(error) {
    var body = 'action=log', name;
    for(name in error)
        if(Object.prototype.hasOwnProperty.call(error, name))
            body += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(error[name]);
    if(root.fetch) {
        // keepalive so an error on the last line of a page still gets posted,
        // catch so that a failed post does not become another error, or another rejection
        root.fetch(url, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body,
          credentials: 'same-origin',
          keepalive: true
        })['catch'](function() {});
        return;
    }
    try {
        var request = new root.XMLHttpRequest();
        request.open('POST', url, true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        request.send(body);
        return;
    } catch(ignore) {}
    // neither fetch nor XMLHttpRequest, the console is the last place left to leave it
    if(root.console && root.console.error)
        root.console.error('ErrorLog could not post the error', error);
}

/** djb2 of file|line|JS|error_code, the browser's guard, the server hashes again */
function hashIt(file, lineNumber, errorCode) {
    var text = file + '|' + lineNumber + '|JS|' + errorCode, hash = 5381, index = text.length;
    while(index) hash = (hash * 33 ^ text.charCodeAt(--index)) >>> 0;
    return hash.toString(16);
}

/** @return {string} the error name, error_code is stored as 32 characters */
function codeIt(error, fallback) {
    var name = error && error.name ? String(error.name) : fallback;
    return name.substring(0, 32);
}

/** @return {string} the message of whatever a promise was rejected with */
function messageIt(reason) {
    if(reason === null || reason === undefined) return 'no reason';
    if(reason.message) return String(reason.message);
    try {return String(reason);} catch(ignore) {return 'unreadable reason';}
}

/** @return {string} the stack trace cut to stackLines lines */
function stackIt(stack) {
    if(!stack) return '';
    return String(stack).split('\n').slice(0, stackLines).join('\n');
}

/** @return {string} the function the error occurred in, chrome "at name (file)", firefox "name@file" */
function functionIt(stack) {
    if(!stack) return '';
    var match = /\bat\s+([^\s(]+)\s*\(/.exec(stack) || /^\s*([^@\s]+)@/m.exec(String(stack));
    return match ? match[1].substring(0, 255) : '';
}

/** @return {Object} file, line and column of the first frame of the stack, for the rejections
 *   and the errors that carry none */
function frameIt(stack) {
    var match = stack ? /(\w+:\/\/[^\s)]+?|\/[^\s):]+):(\d+):(\d+)/.exec(String(stack)) : null;
    if(!match) return {file: '', line: 0, column: 0};
    return {file: match[1], line: Number(match[2]) || 0, column: Number(match[3]) || 0};
}

autoStart();

return {start: start, log: log, getErrors: getErrors};

})();
