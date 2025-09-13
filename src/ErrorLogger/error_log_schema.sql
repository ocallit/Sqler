-- Error logging table with intelligent deduplication
-- Primary key is the error hash for automatic deduplication
CREATE TABLE error_log (
    -- Primary key: hash of the error template for deduplication
    error_hash VARCHAR(16) NOT NULL PRIMARY KEY COMMENT 'xxh3 hash of the normalized error template',
    
    -- Core error classification
    error_type ENUM('SQL', 'PHP', 'JS', 'JSON', 'PCRE', 'EXCEPTION') NOT NULL COMMENT 'Type of error for categorization',
    error_code VARCHAR(32) DEFAULT '' COMMENT 'Error code (errno, SQL error code, HTTP status, etc.)',
    error_message MEDIUMTEXT DEFAULT '' COMMENT 'Original error message',
    
    -- Normalized template and original content
    template MEDIUMTEXT NOT NULL COMMENT 'Normalized error template with dynamic values replaced by ?',
    original MEDIUMTEXT NOT NULL COMMENT 'Original error content (query, message, stack trace, etc.)',
    
    -- Location information
    file_path VARCHAR(500) DEFAULT '' COMMENT 'File where error occurred (relative path preferred)',
    function_name VARCHAR(255) DEFAULT '' COMMENT 'Function/method name where error occurred',
    line_number INT DEFAULT 0 COMMENT 'Line number (stored but not used in hash)',
    column_number INT DEFAULT 0 COMMENT 'Column number (stored but not used in hash)',
    
    -- Context and environment
    user_agent MEDIUMTEXT DEFAULT '' COMMENT 'Browser user agent for JS errors',
    request_uri VARCHAR(1000) DEFAULT '' COMMENT 'URL/URI where error occurred',
    user_nick VARCHAR(100) DEFAULT '' COMMENT 'User nickname if available when error occurred',
    
    -- Additional data (JSON for flexibility)
    context_data JSON DEFAULT NULL COMMENT 'Additional context data (parameters, stack trace, etc.)',
    
    -- Tracking fields
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this error template was first encountered',
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this error template was last encountered',
    seen_count INT NOT NULL DEFAULT 1 COMMENT 'Number of times this error template has occurred',
    
    -- Management fields
    status ENUM('Bug', 'Fixed', 'Won''t Fix', 'Info') NOT NULL DEFAULT 'Bug' COMMENT 'Error resolution status',
    developer_comments MEDIUMTEXT NOT NULL DEFAULT '' COMMENT 'Developer notes and comments about this error',
    
    -- Metadata
    commented_at DATETIME DEFAULT NULL COMMENT 'When developer last commented on this error'
) ENGINE=InnoDB 
  DEFAULT CHARSET=utf8mb4 
  COLLATE=utf8mb4_unicode_ci 
  COMMENT='Deduplicated error logging with intelligent template grouping';

-- Example INSERT with ON DUPLICATE KEY UPDATE for deduplication
-- This matches your ErrorTemplateNormalizer implementation:

/*
-- Example using the ErrorTemplateNormalizer::normalizeSqlError result:
INSERT INTO error_log (
    error_hash,
    error_type, 
    error_code,
    error_message,
    template,
    original,
    file_path,
    function_name,
    line_number,
    request_uri,
    user_nick,
    context_data
) VALUES (
    'a1b2c3d4e5f67890',  -- xxh3 hash from ErrorTemplateNormalizer 
    'SQL',
    '1146',
    'Table ''users'' doesn''t exist',
    'SQL_ERROR_1146|Table \'?\' doesn\'t exist|SELECT * FROM users WHERE id = ?|getUserById()',
    'SELECT * FROM users WHERE id = 12345',
    '/app/models/User.php',
    'getUserById',
    45,
    '/api/users/12345',
    'john_doe',
    JSON_OBJECT(
        'original_query', 'SELECT * FROM users WHERE id = 12345',
        'parameters', JSON_ARRAY(12345),
        'query_template', 'SELECT * FROM users WHERE id = ?',
        'context', 'getUserById()'
    )
) AS new_error
ON DUPLICATE KEY UPDATE
    last_seen = CURRENT_TIMESTAMP,
    seen_count = seen_count + 1,
    original = new_error.original,  -- Keep latest example
    error_message = new_error.error_message,  -- Keep latest error message
    line_number = new_error.line_number,  -- Update to latest occurrence 
    column_number = new_error.column_number,  -- Update to latest occurrence
    request_uri = new_error.request_uri,  -- Update to latest URI
    user_nick = new_error.user_nick,  -- Update to latest user
    context_data = new_error.context_data,  -- Keep latest context
    status = CASE 
        WHEN status IN ('Won''t Fix', 'Fixed') THEN status  -- Don't change resolved statuses
        ELSE 'Bug'  -- Reset other statuses to Bug on new occurrence
    END;

-- When developer adds a comment, update separately:
-- UPDATE error_log SET 
--   developer_comments = 'This is caused by missing table migration', 
--   commented_at = CURRENT_TIMESTAMP,
--   status = 'Info'
-- WHERE error_hash = 'a1b2c3d4e5f67890';

-- You would typically use the ErrorTemplateNormalizer like this:
-- $errorData = ErrorTemplateNormalizer::normalizeSqlError($errno, $errstr, $query, $params, $context);
-- Then insert using $errorData['template_hash'], $errorData['template'], etc.
*/