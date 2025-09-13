# Error Logging System Template Creation Prompt

## Overview
I'm building an advanced error logging system for a PHP application that needs to intelligently deduplicate errors by creating "templates" that group similar errors together while ignoring dynamic values. The goal is to have as few unique error entries as possible while maintaining meaningful error tracking.

## Context & Requirements

### 1. SQL Error Templates (already working well)
- Use `SqlUtils::createQueryTemplate` to replace literals with `?` 
- `SELECT * FROM users WHERE id = 123` becomes `SELECT * FROM users WHERE id = ?`
- This groups all similar queries regardless of parameter values

### 2. PHP Error Templates (needs improvement)
- **Current**: `PHP_ERROR_{$errno}_{basename($filename)}`
- **Problem**: Line numbers create too many duplicates
- **Goal**: Same error type in same file = same template, regardless of line number

### 3. JavaScript Error Templates (needs improvement)
- **Current**: `JS_ERROR_{basename($filename)}_{normalizedMessage}`
- **Problem**: Column/line numbers in messages create duplicates
- **Goal**: Same error type in same file = same template

### 4. JSON/PCRE Error Templates (needs complete redesign)
- **Current**: `JSON_ERROR_{$jsonError}` - too generic
- **Current**: `PREG_ERROR_{$regexError}_{$patternTemplate}` - line/col specific
- **Goal**: Group by error type and context, not specific location

## Key Requirements

- **Minimize duplicates**: Same logical error should have same template regardless of line numbers, column numbers, or dynamic values
- **Preserve meaning**: Template should still be meaningful for debugging
- **Handle dynamic content**: Remove or replace changing values (numbers, strings, URLs, IDs, etc.)
- **Maintain context**: Keep enough information to understand the error type and general location

## Current SqlUtils::createQueryTemplate Logic to Apply Elsewhere

```php
// Replaces:
// - Single/double quoted strings with ?
// - Decimal numbers with ?  
// - Integer numbers with ?
// - Normalizes whitespace
// Result: Consistent template regardless of actual values
```

## Specific Questions

### 1. PHP Error Templates
How should I create templates for PHP errors that group by error type and file, but ignore line numbers? Should I use function names, class names, or method signatures instead?

### 2. JavaScript Error Templates
How can I normalize JavaScript error messages to remove line/column references, dynamic IDs, changing URLs, and variable values while preserving the core error type?

### 3. JSON Error Templates
How should I create meaningful templates for JSON parse errors that include context (like which data structure was being parsed) without being location-specific?

### 4. PCRE Error Templates
How can I create templates for regex errors that group by pattern type and error type, not specific line/column?

### 5. Message Normalization
What's the best strategy to replace dynamic content (numbers, quoted strings, URLs, file paths, etc.) in error messages with placeholders like `?`?

### 6. Hash Strategy
Should I use the error type + file/context combination for hashing instead of including line/column numbers?

## Expected Output

- Concrete PHP functions for creating templates for each error type
- Regex patterns or string replacement strategies for normalizing messages
- Examples showing how different specific errors would map to the same template
- Strategies for preventing error log spam while maintaining useful debugging information

## Example of Desired Behavior

```php
// These should all map to the same template:
"Undefined variable $user at line 45"  
"Undefined variable $product at line 67"
"Undefined variable $order at line 123"
// Template: "Undefined variable ? at line ?"

// These should all map to the same template:  
"JSON parse error: Syntax error at line 5 column 12"
"JSON parse error: Syntax error at line 8 column 23" 
// Template: "JSON parse error: Syntax error at line ? column ?"
```

## Additional Considerations

### Error Data Structure Requirements
1. **SQL Errors**: Save both template AND actual query with values
2. **All Errors**: Hash should NOT include column numbers to minimize duplicates
3. **Error Categorization**: JSON/PCRE should be subcategories of PHP errors
4. **Error Object Handling**: Need direct support for Error objects in catch blocks
5. **Spam Prevention**: System should defend against logging many errors with just different values

## Additional Considerations

### Current Issues to Address
- Line numbers creating unnecessary duplicates within same file/error type
- Column numbers creating unnecessary duplicates within same file/error type  
- Dynamic values (IDs, timestamps, etc.) creating unique errors for same logical issue
- Generic templates that don't provide enough debugging context
- Over-specific templates that create too many unique entries
- Need to distinguish between different logical errors vs. same error at different locations

### Error Categorization Rules
1. **SQL Errors**: Save both template AND actual query with values
2. **All Errors**: Hash should NOT include line/column numbers to minimize duplicates
3. **Error Categorization**: JSON/PCRE should be subcategories of PHP errors
4. **Error Object Handling**: Need direct support for Error objects in catch blocks  
5. **Spam Prevention**: System should defend against logging many errors with just different values

### Template Grouping Philosophy
- **Same logical error + same file = same template** (regardless of line/column)
- **Different variables/properties = different templates** (different logical errors)
- **Different files = different templates** (even if same error type)
- **Different error types = different templates** (obviously)

## Request
Can you provide a comprehensive solution for creating intelligent error templates that minimize duplicates while preserving debugging value, following the same principles as the existing SQL template system but adapted for different error sources?