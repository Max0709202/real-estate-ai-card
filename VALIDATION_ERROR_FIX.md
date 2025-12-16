# Validation Error Display Fix

## Problem
When users submitted the registration form with multiple validation errors, only the general error message was displayed (e.g., "入力内容に誤りがあります"). Individual field errors (email, password, phone number, etc.) were not shown, making it difficult for users to understand what needed to be corrected.

### Backend Behavior (Correct)
The backend was already correctly validating all fields and sending all errors:

```json
{
  "success": false,
  "message": "入力内容に誤りがあります",
  "errors": {
    "email": "有効なメールアドレスを入力してください",
    "password": "パスワードは8文字以上で入力してください",
    "phone_number": "有効な電話番号を入力してください"
  }
}
```

### Frontend Behavior (Fixed)
The frontend was only displaying `result.message`, ignoring the `result.errors` object that contained the specific field errors.

**Before:**
```javascript
showError(result.message || '登録に失敗しました');
```

**After:**
```javascript
showApiError(result, '登録に失敗しました');
```

## Solution

### 1. Created Error Handler Utility (`frontend/assets/js/error-handler.js`)

A reusable utility module with the following functions:

#### `formatApiError(result, defaultMessage)`
Formats API error responses to include all field-specific validation errors.

```javascript
// Returns formatted error message with all field errors
const errorMessage = formatApiError(result, '登録に失敗しました');
// Output:
// "入力内容に誤りがあります
//
// 有効なメールアドレスを入力してください
// パスワードは8文字以上で入力してください
// 有効な電話番号を入力してください"
```

#### `showApiError(result, defaultMessage, options)`
Displays API errors using the modal system with all field errors included.

```javascript
showApiError(result, '登録に失敗しました');
```

#### `getFieldErrors(result)`
Extracts field errors as an object for programmatic access.

```javascript
const errors = getFieldErrors(result);
// Returns: { email: "...", password: "...", phone_number: "..." }
```

#### `getFieldError(result, fieldName)`
Gets the error message for a specific field.

```javascript
const emailError = getFieldError(result, 'email');
// Returns: "有効なメールアドレスを入力してください" or null
```

### 2. Updated Registration Form Error Handling

Updated `frontend/new_register.php`:
- Added `error-handler.js` script inclusion
- Replaced inline error formatting with `showApiError()` call
- Now displays all validation errors to users

### 3. Verified Existing Implementations

Checked that `frontend/admin/login.php` already had correct error handling:
```javascript
const errorMsg = result.message || '登録に失敗しました';
const errors = result.errors || {};
let errorText = errorMsg;
if (Object.keys(errors).length > 0) {
    errorText += '\n' + Object.values(errors).join('\n');
}
```

## Files Modified

1. **Created:**
   - `frontend/assets/js/error-handler.js` - Error handling utility
   - `frontend/test-validation-errors.html` - Test page for validation errors
   - `VALIDATION_ERROR_FIX.md` - This documentation

2. **Updated:**
   - `frontend/new_register.php` - Added error-handler.js and updated error display

## Testing

### Manual Testing
1. Open `http://localhost/php/frontend/test-validation-errors.html`
2. Click each test button to see how different error scenarios are displayed
3. Verify that all field errors are shown in the modal

### Registration Form Testing
1. Open `http://localhost/php/frontend/new_register.php`
2. Submit the form with invalid data:
   - Invalid email (e.g., "notanemail")
   - Short password (less than 8 characters)
   - Invalid phone number (e.g., "123")
3. Verify that ALL validation errors are displayed in the error modal

**Expected Result:**
```
入力内容に誤りがあります

有効なメールアドレスを入力してください
パスワードは8文字以上で入力してください
有効な電話番号を入力してください
```

## Usage in Other Forms

To use this error handler in other forms:

1. **Include the script:**
```html
<script src="assets/js/modal.js"></script>
<script src="assets/js/error-handler.js"></script>
```

2. **Use in error handling:**
```javascript
try {
    const response = await fetch('/api/endpoint', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    
    const result = await response.json();
    
    if (result.success) {
        // Handle success
        showSuccess('成功しました');
    } else {
        // Display all errors
        showApiError(result, 'エラーが発生しました');
    }
} catch (error) {
    showError('ネットワークエラーが発生しました');
}
```

## Backend API Response Format

All API endpoints should follow this standard format for validation errors:

```json
{
  "success": false,
  "message": "General error message",
  "errors": {
    "field_name_1": "Field-specific error message 1",
    "field_name_2": "Field-specific error message 2"
  }
}
```

### Example:
```php
// backend/api/example/endpoint.php
$errors = [];

if (empty($input['email']) || !validateEmail($input['email'])) {
    $errors['email'] = '有効なメールアドレスを入力してください';
}

if (empty($input['password']) || strlen($input['password']) < 8) {
    $errors['password'] = 'パスワードは8文字以上で入力してください';
}

if (!empty($errors)) {
    sendErrorResponse('入力内容に誤りがあります', 400, $errors);
}
```

## Benefits

1. **Better UX**: Users can see all validation errors at once instead of fixing one error at a time
2. **Consistency**: Standardized error handling across the application
3. **Reusability**: Error handler can be used in all forms
4. **Maintainability**: Centralized error formatting logic
5. **Accessibility**: Clear error messages help all users understand what needs to be fixed

## Browser Compatibility

The error handler uses standard JavaScript features and is compatible with:
- Chrome/Edge 90+
- Firefox 88+
- Safari 14+
- Mobile browsers (iOS Safari 14+, Chrome Android)

## Related Files

- **Backend Validation:** `backend/api/auth/register.php` (lines 24-45)
- **Error Response Function:** `backend/includes/functions.php` (sendErrorResponse)
- **Modal System:** `frontend/assets/js/modal.js`
- **Registration Form:** `frontend/new_register.php`

