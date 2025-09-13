<?php
/** @noinspection PhpUnused */

namespace Ocallit\Sqler;

use function hash;
use function preg_replace;
use function basename;
use function substr;
use function trim;
use function mb_substr;
use function str_replace;
use function json_encode;
use function debug_backtrace;

/**
 * ErrorTemplateNormalizer - Creates normalized templates and hashes for error deduplication
 * 
 * This class creates intelligent error templates that group similar errors together
 * while preserving enough context for debugging. Each method returns an associative
 * array ready for SQL insert/update operations.
 */
class ErrorTemplateNormalizer {
    
    /**
     * Normalize SQL errors using existing SqlUtils::createQueryTemplate
     * 
     * @param int $errorNumber MySQL error number
     * @param string $errorMessage MySQL error message
     * @param string $query Original SQL query with actual values
     * @param array $parameters Query parameters if using prepared statements
     * @param string $context Additional context (method name, etc.)
     * @return array Normalized error data for database storage
     */
    public static function normalizeSqlError(
        int $errorNumber,
        string $errorMessage, 
        string $query,
        array $parameters = [],
        string $context = ''
    ): array {
        // Use existing SqlUtils to create query template
        $queryTemplate = SqlUtils::createQueryTemplate($query);
        
        // Normalize error message by replacing dynamic values
        $normalizedMessage = self::normalizeMessage($errorMessage);
        
        // Create template combining error type and query pattern
        $template = "SQL_ERROR_{$errorNumber}|{$normalizedMessage}|{$queryTemplate}";
        
        // Add context if available
        if (!empty($context)) {
            $template .= "|{$context}";
        }
        
        $templateHash = hash('xxh3', $template);
        
        return [
            'template_hash' => $templateHash,
            'template' => $template,
            'source' => 'sql',
            'error_number' => $errorNumber,
            'error_message' => mb_substr($errorMessage, 0, 1000),
            'context_data' => json_encode([
                'original_query' => $query,
                'parameters' => $parameters,
                'query_template' => $queryTemplate,
                'context' => $context
            ], SqlUtils::JSON_MYSQL_OPTIONS)
        ];
    }
    
    /**
     * Normalize PHP errors (general PHP runtime errors)
     * 
     * @param int $errno PHP error number (E_ERROR, E_WARNING, etc.)
     * @param string $errstr Error message
     * @param string $errfile Full file path where error occurred
     * @param int $errline Line number where error occurred
     * @param string $context Additional context or calling function
     * @return array Normalized error data for database storage
     */
    public static function normalizePhpError(
        int $errno,
        string $errstr,
        string $errfile = '',
        int $errline = 0,
        string $context = ''
    ): array {
        // Normalize the error message
        $normalizedMessage = self::normalizePhpMessage($errstr);
        
        // Create file context (full path for uniqueness, but without line number)
        $fileContext = $errfile ? trim($errfile) : 'unknown';
        
        // Extract function/method context from backtrace if not provided
        if (empty($context)) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $context = self::extractPhpContext($backtrace);
        }
        
        // Create template: error_type + normalized_message + file_path + context
        $template = "PHP_ERROR_{$errno}|{$normalizedMessage}|{$fileContext}";
        if (!empty($context)) {
            $template .= "|{$context}";
        }
        
        $templateHash = hash('xxh3', $template);
        
        return [
            'template_hash' => $templateHash,
            'template' => $template,
            'source' => 'php',
            'error_number' => $errno,
            'error_message' => mb_substr($errstr, 0, 1000),
            'filename' => mb_substr($errfile, 0, 500),
            'line_number' => $errline,
            'context_data' => json_encode([
                'original_message' => $errstr,
                'normalized_message' => $normalizedMessage,
                'context' => $context,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5)
            ], SqlUtils::JSON_MYSQL_OPTIONS)
        ];
    }
    
    /**
     * Normalize PHP JSON errors
     * 
     * @param int $jsonError JSON error constant (JSON_ERROR_SYNTAX, etc.)
     * @param string $jsonErrorMsg JSON error message
     * @param string $context What was being parsed (e.g., 'user_preferences', 'api_response')
     * @param string $jsonData The actual JSON data that failed (optional, for debugging)
     * @return array Normalized error data for database storage
     */
    public static function normalizePhpJsonError(
        int $jsonError,
        string $jsonErrorMsg,
        string $context = '',
        string $jsonData = ''
    ): array {
        // Normalize JSON error message (remove line/column specifics)
        $normalizedMessage = self::normalizeJsonMessage($jsonErrorMsg);
        
        // Extract context from backtrace if not provided
        if (empty($context)) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $context = self::extractPhpContext($backtrace);
        }
        
        // Create template: JSON_ERROR + error_type + normalized_message + context
        $template = "JSON_ERROR_{$jsonError}|{$normalizedMessage}|{$context}";
        
        $templateHash = hash('xxh3', $template);
        
        return [
            'template_hash' => $templateHash,
            'template' => $template,
            'source' => 'php',
            'error_number' => $jsonError,
            'error_message' => mb_substr($jsonErrorMsg, 0, 1000),
            'context_data' => json_encode([
                'json_error_constant' => $jsonError,
                'original_message' => $jsonErrorMsg,
                'normalized_message' => $normalizedMessage,
                'context' => $context,
                'json_data_preview' => mb_substr($jsonData, 0, 500),
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
            ], SqlUtils::JSON_MYSQL_OPTIONS)
        ];
    }
    
    /**
     * Normalize PHP PCRE (regex) errors
     * 
     * @param int $pregError PREG error constant (PREG_INTERNAL_ERROR, etc.)
     * @param string $pregErrorMsg PREG error message  
     * @param string $pattern The regex pattern that caused the error
     * @param string $context What operation was being performed
     * @return array Normalized error data for database storage
     */
    public static function normalizePhpPcreError(
        int $pregError,
        string $pregErrorMsg,
        string $pattern = '',
        string $context = ''
    ): array {
        // Normalize the regex pattern (replace specific values with placeholders)
        $normalizedPattern = self::normalizeRegexPattern($pattern);
        
        // Extract context from backtrace if not provided
        if (empty($context)) {
            $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $context = self::extractPhpContext($backtrace);
        }
        
        // Create template: PREG_ERROR + error_type + normalized_pattern + context
        $template = "PREG_ERROR_{$pregError}|{$normalizedPattern}|{$context}";
        
        $templateHash = hash('xxh3', $template);
        
        return [
            'template_hash' => $templateHash,
            'template' => $template,
            'source' => 'php',
            'error_number' => $pregError,
            'error_message' => mb_substr($pregErrorMsg, 0, 1000),
            'context_data' => json_encode([
                'preg_error_constant' => $pregError,
                'original_message' => $pregErrorMsg,
                'original_pattern' => $pattern,
                'normalized_pattern' => $normalizedPattern,
                'context' => $context,
                'backtrace' => debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)
            ], SqlUtils::JSON_MYSQL_OPTIONS)
        ];
    }
    
    /**
     * Normalize JavaScript errors (received via AJAX)
     * 
     * @param string $message JavaScript error message
     * @param string $filename File where error occurred
     * @param int $lineno Line number
     * @param int $colno Column number
     * @param string $stack Stack trace
     * @param string $source Error source (window.error, unhandledrejection, etc.)
     * @return array Normalized error data for database storage
     */
    public static function normalizeJavaScriptError(
        string $message,
        string $filename = '',
        int $lineno = 0,
        int $colno = 0,
        string $stack = '',
        string $source = 'unknown'
    ): array {
        // Normalize JavaScript error message
        $normalizedMessage = self::normalizeJavaScriptMessage($message);
        
        // Clean filename (remove query parameters, hash, etc.)
        $cleanFilename = self::cleanJavaScriptFilename($filename);
        
        // Extract function context from stack trace
        $functionContext = self::extractJavaScriptContext($stack);
        
        // Create template: JS_ERROR + normalized_message + filename + function_context
        $template = "JS_ERROR|{$normalizedMessage}|{$cleanFilename}";
        if (!empty($functionContext)) {
            $template .= "|{$functionContext}";
        }
        
        $templateHash = hash('xxh3', $template);
        
        return [
            'template_hash' => $templateHash,
            'template' => $template,
            'source' => 'javascript',
            'error_number' => null,
            'error_message' => mb_substr($message, 0, 1000),
            'filename' => mb_substr($filename, 0, 500),
            'line_number' => $lineno,
            'column_number' => $colno,
            'context_data' => json_encode([
                'original_message' => $message,
                'normalized_message' => $normalizedMessage,
                'stack_trace' => mb_substr($stack, 0, 2000),
                'function_context' => $functionContext,
                'source' => $source,
                'clean_filename' => $cleanFilename
            ], SqlUtils::JSON_MYSQL_OPTIONS)
        ];
    }
    
    /**
     * Normalize general error messages by replacing dynamic values
     * 
     * @param string $message Original error message
     * @return string Normalized message with placeholders
     */
    protected static function normalizeMessage(string $message): string {
        if (empty($message)) return '';
        
        // Replace common dynamic patterns with placeholders
        $patterns = [
            '/\b\d+\b/' => '?',                    // Numbers
            "/'[^']*'/" => "'?'",                  // Single-quoted strings  
            '/"[^"]*"/' => '"?"',                  // Double-quoted strings
            '/\b[a-f0-9]{8,}\b/i' => '?',          // Hex values (IDs, hashes)
            '/\b\d{4}-\d{2}-\d{2}/' => '?',        // Dates
            '/\b\d{2}:\d{2}:\d{2}/' => '?',        // Times
            '/https?:\/\/[^\s]+/' => '?',          // URLs
            '/\/[^\s]*\.php/' => '/?.php',         // PHP file paths
            '/\$[a-zA-Z_][a-zA-Z0-9_]*/' => '$?',  // Variable names (keep structure)
        ];
        
        $normalized = $message;
        foreach ($patterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        
        // Clean up multiple consecutive placeholders
        $normalized = preg_replace('/\?+/', '?', $normalized);
        
        return mb_substr(trim($normalized), 0, 200);
    }
    
    /**
     * Normalize PHP-specific error messages
     */
    protected static function normalizePhpMessage(string $message): string {
        if (empty($message)) return '';
        
        // PHP-specific patterns
        $phpPatterns = [
            '/line \d+/' => 'line ?',                           // Line numbers
            '/in \/[^\s]+ on line \d+/' => 'in /? on line ?',   // File paths with line
            '/\$[a-zA-Z_][a-zA-Z0-9_]*/' => '$VAR',            // Keep variable structure but normalize name
            '/Undefined variable: \$[a-zA-Z_][a-zA-Z0-9_]*/' => 'Undefined variable: $VAR',
            '/Call to undefined function [a-zA-Z_][a-zA-Z0-9_]*\(\)/' => 'Call to undefined function FUNC()',
            '/Class \'[^\']+\' not found/' => 'Class \'?\' not found',
        ];
        
        $normalized = self::normalizeMessage($message);
        foreach ($phpPatterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        
        return mb_substr(trim($normalized), 0, 200);
    }
    
    /**
     * Normalize JSON error messages
     */
    protected static function normalizeJsonMessage(string $message): string {
        if (empty($message)) return '';
        
        // JSON-specific patterns
        $jsonPatterns = [
            '/line \d+/' => 'line ?',
            '/column \d+/' => 'column ?',
            '/position \d+/' => 'position ?',
            '/at line \d+ column \d+/' => 'at line ? column ?',
            '/character \d+/' => 'character ?',
        ];
        
        $normalized = self::normalizeMessage($message);
        foreach ($jsonPatterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        
        return mb_substr(trim($normalized), 0, 200);
    }
    
    /**
     * Normalize JavaScript error messages
     */
    protected static function normalizeJavaScriptMessage(string $message): string {
        if (empty($message)) return '';
        
        // JavaScript-specific patterns
        $jsPatterns = [
            '/at line \d+/' => 'at line ?',
            '/at column \d+/' => 'at column ?',
            '/line \d+/' => 'line ?',
            '/:\d+:\d+/' => ':?:?',                            // Line:column references
            '/Cannot read propert(y|ies) \'[^\']+\'/' => 'Cannot read property \'?\'',
            '/Cannot set propert(y|ies) \'[^\']+\'/' => 'Cannot set property \'?\'',
            '/[a-zA-Z_$][a-zA-Z0-9_$]* is not defined/' => '? is not defined',
            '/[a-zA-Z_$][a-zA-Z0-9_$]* is not a function/' => '? is not a function',
        ];
        
        $normalized = self::normalizeMessage($message);
        foreach ($jsPatterns as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized);
        }
        
        return mb_substr(trim($normalized), 0, 200);
    }
    
    /**
     * Normalize regex patterns for template creation
     */
    protected static function normalizeRegexPattern(string $pattern): string {
        if (empty($pattern)) return '';
        
        // Keep regex structure but replace specific values
        $normalized = preg_replace([
            '/[a-zA-Z0-9]+/' => '?',     // Replace alphanumeric sequences
            '/\{[\d,]+\}/' => '{?,?}',   // Quantifiers like {3,5}
        ], $pattern);
        
        return mb_substr($normalized, 0, 100);
    }
    
    /**
     * Clean JavaScript filename (remove query params, hash, etc.)
     */
    protected static function cleanJavaScriptFilename(string $filename): string {
        if (empty($filename)) return '';
        
        // Remove query parameters and hash
        $clean = preg_replace('/[?#].*$/', '', $filename);
        
        // Keep only the path part, not the full URL
        $clean = preg_replace('/^https?:\/\/[^\/]+/', '', $clean);
        
        return $clean;
    }
    
    /**
     * Extract PHP context (function/method name) from backtrace
     */
    protected static function extractPhpContext(array $backtrace): string {
        foreach ($backtrace as $trace) {
            if (isset($trace['function'])) {
                $context = '';
                if (isset($trace['class'])) {
                    $context .= $trace['class'] . '::';
                }
                $context .= $trace['function'] . '()';
                return $context;
            }
        }
        return '';
    }
    
    /**
     * Extract JavaScript function context from stack trace
     */
    protected static function extractJavaScriptContext(string $stack): string {
        if (empty($stack)) return '';
        
        // Try to extract the first meaningful function name from stack
        if (preg_match('/at\s+([a-zA-Z_$][a-zA-Z0-9_$]*)\s*\(/', $stack, $matches)) {
            return $matches[1] . '()';
        }
        
        // Try alternative stack trace format
        if (preg_match('/([a-zA-Z_$][a-zA-Z0-9_$]*)\@/', $stack, $matches)) {
            return $matches[1] . '()';
        }
        
        return '';
    }
}