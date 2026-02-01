/**
 * API Error Handler Utility
 * Formats error responses from the backend API consistently
 */

/**
 * Format API error response for display
 * @param {Object} result - The API response object
 * @param {string} defaultMessage - Default message if none provided
 * @returns {string} Formatted error message
 */
function formatApiError(result, defaultMessage = 'エラーが発生しました') {
    let errorMessage = result.message || defaultMessage;
    
    // Check if there are field-specific validation errors
    if (result.errors && typeof result.errors === 'object') {
        const errorList = Object.values(result.errors).filter(msg => msg);
        if (errorList.length > 0) {
            errorMessage += '\n\n' + errorList.join('\n');
        }
    }
    
    return errorMessage;
}

/**
 * Display API error using the modal system
 * @param {Object} result - The API response object
 * @param {string} defaultMessage - Default message if none provided
 * @param {Object} options - Additional options for showError
 */
function showApiError(result, defaultMessage = 'エラーが発生しました', options = {}) {
    const errorMessage = formatApiError(result, defaultMessage);
    if (typeof showError === 'function') {
        showError(errorMessage, options);
    } else {
        alert(errorMessage);
    }
}

/**
 * Extract field errors as an object
 * @param {Object} result - The API response object
 * @returns {Object} Field errors keyed by field name
 */
function getFieldErrors(result) {
    return (result.errors && typeof result.errors === 'object') ? result.errors : {};
}

/**
 * Check if a specific field has an error
 * @param {Object} result - The API response object
 * @param {string} fieldName - The field name to check
 * @returns {string|null} Error message for the field, or null if no error
 */
function getFieldError(result, fieldName) {
    const errors = getFieldErrors(result);
    return errors[fieldName] || null;
}

