# Grok Efficiency Guide for puntWork

## Core Query Template
**Always start queries with:**
```
Context: puntWork WordPress job import plugin. Reference: [relevant .md file from notes/].
Task: [Specific action, e.g., "Write PHP function for batch processing with error handling"].
Requirements: Follow coding-standards.md, use Puntwork namespace, security validations.
Output: Code snippet + explanation + tests.
```

## Optimized Collaboration Patterns

### 1. Context Preservation
- **Session Context**: Always reference `session-context.md` for current state
- **Project Knowledge**: Use `project-knowledge-base.md` for technical overview
- **Learning Log**: Check `learning-log.md` for previous fixes and patterns
- **Technical Reference**: Use `technical-reference.md` for code patterns

### 2. Efficient Query Structure
**Good Query:**
```
"puntWork plugin: Add error handling to batch processing in import-batch.php.
Reference: session-context.md (modular architecture), technical-reference.md (error patterns).
Output: Modified function with try/catch, logging, and user feedback."
```

**Avoid:**
- Vague requests: "Fix the import" → "Fix timeout in batch processing for feeds > 1000 items"
- Missing context: Always specify file/function
- Over-engineering: Add "minimal viable" to prevent bloat

### 3. File Fetching Protocol
**For Code Reviews/Modifications:**
1. **Primary**: Use GitHub API + base64 decode
2. **Fallback**: Request manual paste if fetch fails
3. **Validation**: Always verify complete file content before modifications

**API Fetch Pattern:**
```
browse_page on https://api.github.com/repos/DGC-GH/puntWork/contents/PATH/TO/FILE
Instructions: Extract 'content' field, base64 decode to UTF-8, return full file content.
```

### 4. Code Generation Standards
**PHP Requirements:**
- Namespace: `Puntwork`
- Security: `wp_verify_nonce()`, `sanitize_text_field()`, `esc_html()`
- Documentation: PHPDoc blocks
- Error Handling: Try/catch with logging
- Performance: Transients for caching

**JavaScript Requirements:**
- Modular pattern: IIFE with global exports
- WordPress integration: `wp_enqueue_script()` with dependencies
- Async handling: `async/await` for AJAX
- Error handling: User feedback + logging

### 5. Testing & Validation
**Always Include:**
- Unit test stubs (PHPUnit for PHP)
- Integration test scenarios
- Error case handling
- Performance considerations

**Validation Checklist:**
- [ ] Security validations present
- [ ] Input sanitization
- [ ] Output escaping
- [ ] Error logging
- [ ] Performance optimization
- [ ] WordPress compatibility

## Common Patterns & Solutions

### Feed Processing
```php
// Dynamic feed URL retrieval
$feed_url = get_post_meta($post_id, 'feed_url', true);
if (!empty($feed_url)) {
    $feeds[$post->post_name] = $feed_url;
}

// Robust feed processing
function process_feed($url, &$logs) {
    try {
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            $logs[] = "Feed error: " . $response->get_error_message();
            return false;
        }
        return wp_remote_retrieve_body($response);
    } catch (Exception $e) {
        $logs[] = "Exception: " . $e->getMessage();
        return false;
    }
}
```

### AJAX Handlers
```php
// Secure AJAX handler pattern
add_action('wp_ajax_process_import', 'Puntwork\\handle_import_ajax');
function handle_import_ajax() {
    // Security checks
    if (!wp_verify_nonce($_POST['nonce'], 'job_import_nonce')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Insufficient permissions');
    }

    // Sanitize input
    $feed_key = sanitize_text_field($_POST['feed_key']);

    // Process with error handling
    try {
        $result = process_import_batch($feed_key);
        wp_send_json_success($result);
    } catch (Exception $e) {
        error_log("Import error: " . $e->getMessage());
        wp_send_json_error(['message' => 'Import failed']);
    }
}
```

### JavaScript Modules
```javascript
// IIFE Module Pattern
const PuntWorkJobImportUI = (function() {
    function updateProgress(percent, message) {
        jQuery('#progress-bar').css('width', percent + '%');
        jQuery('#progress-text').text(message);
    }

    return {
        updateProgress: updateProgress
    };
})();

// Export to global
window.PuntWorkJobImportAdmin = window.PuntWorkJobImportAdmin || {};
window.PuntWorkJobImportAdmin.UI = PuntWorkJobImportUI;
```

## Error Prevention

### Common Pitfalls to Avoid
1. **Missing Security**: Always include nonce verification
2. **No Sanitization**: Sanitize all user inputs
3. **Poor Error Handling**: Log errors, provide user feedback
4. **Performance Issues**: Use transients, avoid N+1 queries
5. **WordPress Conflicts**: Test with common plugins/themes

### Debugging Protocol
1. **Reproduce**: Clear steps to reproduce issue
2. **Logs**: Check WordPress debug logs
3. **Isolate**: Test in minimal environment
4. **Validate**: Ensure fix doesn't break existing functionality

## Learning Acceleration

### Build Upon Previous Work
- **Reference Learning Log**: Check `learning-log.md` for previous fixes
- **Pattern Recognition**: Reuse successful patterns
- **Incremental Improvement**: Build upon working solutions
- **Knowledge Transfer**: Document learnings for future sessions

### Efficiency Techniques
- **Modular Thinking**: Break complex tasks into focused modules
- **Test-Driven**: Write tests before implementation
- **Documentation First**: Document before coding
- **Review Patterns**: Use established code patterns

## Session Optimization

### Before Starting
1. **Read Session Context**: Understand current project state
2. **Check Learning Log**: Review previous improvements
3. **Identify Patterns**: Note reusable solutions
4. **Plan Modularly**: Break work into focused tasks

### During Development
1. **Reference Standards**: Use coding standards consistently
2. **Document Decisions**: Note why certain approaches chosen
3. **Test Incrementally**: Validate each change
4. **Update Context**: Keep session context current

### After Completion
1. **Log Learnings**: Add to learning log
2. **Update Context**: Refresh session context
3. **Validate Integration**: Ensure all parts work together
4. **Prepare for Next**: Set up efficient starting point

## Quick Reference

### Essential Files
- `session-context.md`: Current project state
- `project-knowledge-base.md`: Technical overview
- `technical-reference.md`: Code patterns
- `learning-log.md`: Session improvements

### Key Commands
```bash
# Test PHP
composer test

# Lint code
composer lint

# Build assets
npm run build
```

### Common WordPress Functions
```php
// Safe database queries
WP_Query($args)

// Transient caching
set_transient($key, $value, $expiration)

// AJAX responses
wp_send_json_success($data)
wp_send_json_error($message)
```

This guide evolves with each session. Update patterns that work well and note improvements for exponential development velocity.