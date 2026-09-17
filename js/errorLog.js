/**
 * ErrorLog javascript client Quick Reference:
 *
 * Setup, once, as early as possible, before any other script:
 *   ErrorLog.start();                                  // errors and promise rejections
 *   ErrorLog.start({promises: false});                 // errors only
 *   ErrorLog.start({url: '/error_log/api/'});          // when the page is not at the site root
 *
 * Listens to window error and unhandledrejection, posts action=log to the error_log api,
 * which stores the error with Ocallit\Sqler\ErrorLog::javascriptErrors().
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

var url = 'error_log/api/';
/** Distinct errors posted per page load */
var maxErrors = 4;
/** Longest stack trace posted, in lines */
var stackLines = 9;
/** Whether unhandled promise rejections are logged */
var promises = true;

var isStarted = false;
/** {hash: {file: , line_number: , ...}} the errors posted so far, hash => error */
var errors = {};
/** distinct errors kept */
var kept = 0;
var previousOnError = null;

/**
 * Registers the listeners, chaining any previously registered window.onerror
 *
 * @param {Object} [options] url, maxErrors, stackLines, promises
 */
function start(options) {
    options = options || {};
    if(typeof options.url === 'string') url = options.url;
    if(typeof options.maxErrors === 'number') maxErrors = options.maxErrors;
    if(typeof options.stackLines === 'number') stackLines = options.stackLines;
    if(typeof options.promises === 'boolean') promises = options.promises;
    if(isStarted) return;
    isStarted = true;
    if(window.addEventListener) {
        window.addEventListener('error', onError, false);
        if(promises) window.addEventListener('unhandledrejection', onRejection, false);
        return;
    }
    previousOnError = window.onerror;
    window.onerror = function(message, file, lineNumber, columnNumber, error) {
        onError({message: message, filename: file, lineno: lineNumber, colno: columnNumber, error: error});
        return previousOnError ? previousOnError.apply(window, arguments) : false;
    };
}

/** @return {Object} the errors posted so far, hash => error */
function getErrors() {return errors;}

/** window error listener, an ErrorEvent, or the object window.onerror builds */
function onError(event) {
    try {
        var error = event.error || {};
        var frame = frameIt(error.stack);
        add({
          error_code: codeIt(error, 'Error'),
          error_message: String(event.message || error.message || 'Unknown error'),
          file: String(event.filename || frame.file),
          line_number: Number(event.lineno) || frame.line,
          column_number: Number(event.colno) || frame.column,
          function_name: functionIt(error.stack),
          content: stackIt(error.stack),
          request_uri: location.href
        });
    } catch(ignore) {}
}

/** window unhandledrejection listener, the rejection carries no file, the stack does */
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
          request_uri: location.href
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
    if(window.fetch) {
        // keepalive so an error on the last line of a page still gets posted,
        // catch so that a failed post does not become another error, or another rejection
        window.fetch(url, {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
          body: body,
          credentials: 'same-origin',
          keepalive: true
        })['catch'](function() {});
        return;
    }
    try {
        var request = new XMLHttpRequest();
        request.open('POST', url, true);
        request.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        request.send(body);
    } catch(ignore) {}
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

return {start: start, getErrors: getErrors};

})();
