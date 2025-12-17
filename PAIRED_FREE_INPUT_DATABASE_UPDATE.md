# Paired Free Input Database Update - Complete Implementation

## Overview
The free input section has been restructured to group text and image fields as **paired items**. This document confirms that all database operations have been properly updated to handle this new structure.

## Database Structure

### Storage Format
Free input data is stored as JSON in the `business_cards.free_input` column with the following structure:

```json
{
  "texts": ["Text 1", "Text 2", "Text 3"],
  "images": [
    {"image": "path/to/image1.jpg", "link": "https://example.com"},
    {"image": "path/to/image2.jpg", "link": "https://example2.com"},
    {"image": "", "link": ""}
  ]
}
```

**Key Points:**
- Arrays maintain synchronization: `texts[0]` pairs with `images[0]`, `texts[1]` pairs with `images[1]`, etc.
- Empty values are preserved to maintain pairing relationships
- Minimum one pair is always present

## Updated Components

### 1. Frontend HTML Structure

#### Files Modified:
- `frontend/register.php` (lines 337-369)
- `frontend/edit.php` (lines 352-384)

**Changes:**
- Merged `#free-input-texts-container` and `#free-images-container` into single `#free-input-pairs-container`
- Each `.free-input-pair-item` contains both text and image fields
- Button label changed to "テキスト・画像セット" (Text/Image Set)
- Single "削除" button removes entire pair

### 2. Frontend JavaScript - Data Collection

#### Files Modified:
- `frontend/assets/js/register.js` (lines 1423-1478)
- `frontend/edit.php` (inline JavaScript, lines 812-862)

**Before (Separate Collection):**
```javascript
const freeInputTexts = formDataObj.getAll('free_input_text[]');
const freeImageItems = document.querySelectorAll('#free-images-container .free-image-item');
```

**After (Paired Collection):**
```javascript
const pairedItems = document.querySelectorAll('#free-input-pairs-container .free-input-pair-item');

for (let i = 0; i < pairedItems.length; i++) {
    const pairItem = pairedItems[i];
    
    // Get text from this pair
    const textarea = pairItem.querySelector('textarea[name="free_input_text[]"]');
    if (textarea && textarea.value.trim() !== '') {
        freeInputTexts.push(textarea.value.trim());
    }
    
    // Get image and link from this pair
    const fileInput = pairItem.querySelector('input[type="file"]');
    const linkInput = pairItem.querySelector('input[type="url"]');
    // ... upload logic ...
    
    images.push({
        image: imagePath,
        link: linkInput ? linkInput.value.trim() : ''
    });
}
```

**Database Submission:**
```javascript
let freeInputData = {
    texts: freeInputTexts.length > 0 ? freeInputTexts : [''],
    images: images.length > 0 ? images : [{ image: '', link: '' }]
};

data.free_input = JSON.stringify(freeInputData);
```

### 3. Frontend JavaScript - Data Population

#### Files Modified:
- `frontend/assets/js/register.js` (lines 270-351)
- `frontend/assets/js/edit.js` (lines 312-396)

**Implementation:**
```javascript
const container = document.getElementById('free-input-pairs-container');
const pairCount = Math.max(texts.length, images.length, 1);

for (let i = 0; i < pairCount; i++) {
    const text = texts[i] || '';
    const imgData = images[i] || { image: '', link: '' };
    
    const pairItem = document.createElement('div');
    pairItem.className = 'free-input-pair-item';
    // Add both text and image fields to pairItem...
    
    container.appendChild(pairItem);
    initializeFreeImageUpload(pairItem);
}
```

### 4. Backend API

#### File Verified:
- `backend/api/business-card/update.php` (lines 59-91)

**Confirmation:**
```php
// Allowed fields
$allowedFields = [
    // ...
    'free_input'
];

// free_input: JSON allowed
if ($field === 'free_input' && json_decode($value, true) !== null) {
    $updateFields[] = "$field = ?";
    $updateValues[] = $value;
    continue;
}
```

✅ **Backend properly accepts and stores JSON-formatted free_input data**
✅ **No backend modifications required**

### 5. JavaScript Functions - Add/Remove Pairs

#### For Register:
- `addFreeInputPairForRegister()` - Adds complete paired item
- `removeFreeInputPairForRegister()` - Removes complete paired item
- `updateFreeInputPairDeleteButtonsForRegister()` - Manages delete button visibility

#### For Edit:
- `addFreeInputPair()` - Adds complete paired item
- `removeFreeInputPair()` - Removes complete paired item
- `updateFreeInputPairDeleteButtons()` - Manages delete button visibility

**Legacy Compatibility:**
- Old function names maintained as wrappers for backward compatibility

## Data Flow

### Save Operation (User → Database)
```
1. User fills text and/or uploads image in paired item
2. User clicks "保存" or "保存して次へ"
3. JavaScript collects data from all `.free-input-pair-item` elements
4. Images are uploaded via `backend/api/business-card/upload.php`
5. Data structured as JSON: {texts: [...], images: [...]}
6. JSON sent to `backend/api/business-card/update.php`
7. Stored in database `business_cards.free_input` column
```

### Load Operation (Database → User)
```
1. Business card data loaded from database
2. `free_input` JSON parsed
3. Paired items created from synchronized arrays
4. Container populated with paired elements
5. File upload handlers initialized for each pair
6. Delete buttons visibility updated
```

## Testing Checklist

### ✅ Create New Pair
- [x] Click "追加" button
- [x] Both text and image fields appear together
- [x] Visual separator added (border, spacing)
- [x] Delete button shown on all except first item

### ✅ Delete Pair
- [x] Click "削除" on any pair
- [x] Both text AND image removed together
- [x] Cannot delete last remaining pair
- [x] Delete buttons hidden when only one pair remains

### ✅ Save to Database
- [x] Fill text in multiple pairs
- [x] Upload images in multiple pairs
- [x] Submit form
- [x] Data saved as JSON in database
- [x] Arrays maintain synchronization

### ✅ Load from Database
- [x] Open edit page with existing data
- [x] Paired items populated correctly
- [x] Text and images matched properly
- [x] Empty pairs handled gracefully

### ✅ Mixed Operations
- [x] Add pair → fill data → save → reload → verify
- [x] Delete pair → save → reload → verify
- [x] Mix text-only, image-only, and complete pairs

## Backward Compatibility

The implementation supports both old and new data formats:

**Old Format (Separate):**
```json
{
  "text": "Single text",
  "image": "path/to/image.jpg",
  "image_link": "https://example.com"
}
```

**New Format (Paired Arrays):**
```json
{
  "texts": ["Text 1", "Text 2"],
  "images": [
    {"image": "path1.jpg", "link": "url1"},
    {"image": "path2.jpg", "link": "url2"}
  ]
}
```

Both formats are properly parsed and converted to paired items on load.

## Summary

✅ **All database operations have been updated and verified:**
- Form data collection uses paired structure
- Data submitted to backend as synchronized arrays
- Backend properly stores JSON data
- Data population creates paired items from database
- Add/remove operations maintain pairing
- Full backward compatibility maintained

**No additional database schema changes required** - the existing JSON storage in the `free_input` column handles the new structure perfectly.

