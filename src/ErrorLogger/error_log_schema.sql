-- Error logging table with intelligent deduplication
-- Primary key is the error hash for automatic deduplication
CREATE TABLE error_log (
    -- Primary key: hash of the error template for deduplication
    error_hash VARCHAR(16) NOT NULL PRIMARY KEY COMMENT 'xxh3 hash of the normalized error template',

    -- Tracking fields
    first_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this error template was first encountered',
    last_seen DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'When this error template was last encountered',
    seen_count MEDIUMINT UNSIGNED NOT NULL DEFAULT 1 COMMENT 'Number of times this error template has occurred',

    -- Core error classification
    status ENUM('Bug', 'Fixed', 'Won''t Fix', 'Info') NOT NULL DEFAULT 'Bug' COMMENT 'Error resolution status',
    error_type ENUM('SQL', 'PHP', 'JS', 'INFO') NOT NULL COMMENT 'Type of error for categorization',
    error_code VARCHAR(32) DEFAULT '' COMMENT 'Error code (errno, SQL error code, HTTP status, etc.)',
    error_message MEDIUMTEXT DEFAULT '' COMMENT 'Original error message',
    content MEDIUMTEXT NOT NULL COMMENT 'Original error content (query, message, stack trace, etc.)',


    -- Location information
    file VARCHAR(500) DEFAULT '' COMMENT 'File where error occurred ',
    function_name VARCHAR(255) DEFAULT '' COMMENT 'Function/method name where error occurred',
    line_number INT DEFAULT 0 COMMENT 'Line number (stored but not used in hash)',
    column_number INT DEFAULT 0 COMMENT 'Column number (stored but not used in hash)',
    
    -- Context and environment
    request_uri VARCHAR(1000) DEFAULT '' COMMENT 'URL/URI where error occurred',
    user_nick VARCHAR(16) DEFAULT '' COMMENT 'User nickname if available when error occurred',
    user_agent MEDIUMTEXT DEFAULT '' COMMENT 'Browser user agent for JS errors',

    -- Additional data (JSON for flexibility)
    context_data JSON DEFAULT NULL COMMENT 'Additional context data (parameters, stack trace, etc.)',

    comment MEDIUMTEXT NOT NULL DEFAULT '' COMMENT 'Developer notes and comment about this error',
    commented_at DATETIME DEFAULT NULL COMMENT 'When developer last commented on this error'
) ENGINE=InnoDB
  COMMENT='Error logging';

