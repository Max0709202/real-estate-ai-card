/**
 * Auto-Save and Restore Module for Forms
 * 
 * Features:
 * - Auto-save text fields to localStorage (debounced)
 * - Auto-save file inputs to IndexedDB
 * - Restore on page load
 * - beforeunload warning for unsaved changes
 * - Clear drafts after successful submission
 * - UI indicators for saving/restored state
 * - File previews for restored files
 * 
 * Security:
 * - Does NOT store password fields
 * - File size limit: 5MB per file
 */

(function() {
    'use strict';

    // Configuration
    const CONFIG = {
        STORAGE_KEY: 'regFormDraft_v1',
        DB_NAME: 'regDraftDB',
        DB_VERSION: 1,
        STORE_NAME: 'files',
        MAX_FILE_SIZE: 5 * 1024 * 1024, // 5MB
        DEBOUNCE_MS: 200,
        STATUS_DURATION: 3000 // Show status message for 3 seconds
    };

    // State
    let isDirty = false;
    let saveTimeout = null;
    let statusTimeout = null; // Track status message timeout
    let db = null;
    let restoredFiles = new Map(); // Map of fieldName -> {blob, name, type, lastModified, size}
    let isSubmitting = false;

    /**
     * Initialize IndexedDB
     */
    async function initDB() {
        return new Promise((resolve, reject) => {
            const request = indexedDB.open(CONFIG.DB_NAME, CONFIG.DB_VERSION);

            request.onerror = () => {
                console.warn('IndexedDB not available, file drafts will not be saved');
                resolve(null);
            };

            request.onsuccess = () => {
                db = request.result;
                resolve(db);
            };

            request.onupgradeneeded = (event) => {
                const db = event.target.result;
                if (!db.objectStoreNames.contains(CONFIG.STORE_NAME)) {
                    db.createObjectStore(CONFIG.STORE_NAME, { keyPath: 'key' });
                }
            };
        });
    }

    /**
     * Save file to IndexedDB
     */
    async function saveFileToDB(fieldName, file) {
        if (!db || file.size > CONFIG.MAX_FILE_SIZE) {
            if (file.size > CONFIG.MAX_FILE_SIZE) {
                showStatus('ファイルが大きすぎます（5MB以下）。再読み込み後は再選択が必要です。', 'warning');
            }
            return false;
        }

        try {
            const blob = file instanceof Blob ? file : await file.arrayBuffer().then(ab => new Blob([ab], { type: file.type }));
            
            const fileData = {
                key: fieldName,
                blob: blob,
                name: file.name,
                type: file.type,
                lastModified: file.lastModified || Date.now(),
                size: file.size
            };

            const transaction = db.transaction([CONFIG.STORE_NAME], 'readwrite');
            const store = transaction.objectStore(CONFIG.STORE_NAME);
            await store.put(fileData);

            return true;
        } catch (error) {
            console.error('Error saving file to IndexedDB:', error);
            return false;
        }
    }

    /**
     * Get file from IndexedDB
     */
    async function getFileFromDB(fieldName) {
        if (!db) return null;

        try {
            const transaction = db.transaction([CONFIG.STORE_NAME], 'readonly');
            const store = transaction.objectStore(CONFIG.STORE_NAME);
            const request = store.get(fieldName);

            return new Promise((resolve) => {
                request.onsuccess = () => {
                    resolve(request.result || null);
                };
                request.onerror = () => {
                    resolve(null);
                };
            });
        } catch (error) {
            console.error('Error getting file from IndexedDB:', error);
            return null;
        }
    }

    /**
     * Delete file from IndexedDB
     */
    async function deleteFileFromDB(fieldName) {
        if (!db) return;

        try {
            const transaction = db.transaction([CONFIG.STORE_NAME], 'readwrite');
            const store = transaction.objectStore(CONFIG.STORE_NAME);
            await store.delete(fieldName);
        } catch (error) {
            console.error('Error deleting file from IndexedDB:', error);
        }
    }

    /**
     * Clear all files from IndexedDB
     */
    async function clearAllFilesFromDB() {
        if (!db) return;

        try {
            const transaction = db.transaction([CONFIG.STORE_NAME], 'readwrite');
            const store = transaction.objectStore(CONFIG.STORE_NAME);
            await store.clear();
        } catch (error) {
            console.error('Error clearing files from IndexedDB:', error);
        }
    }

    /**
     * Check if field is a password field
     */
    function isPasswordField(field) {
        return field.type === 'password' || 
               field.name.toLowerCase().includes('password') ||
               field.id.toLowerCase().includes('password');
    }

    /**
     * Save form data to localStorage and optionally to server (draft autosave)
     */
    async function saveFormData() {
        const forms = document.querySelectorAll('form');
        const formData = {};

        forms.forEach(form => {
            const formId = form.id || 'default';
            formData[formId] = {};

            // Get all input, textarea, select fields (except passwords and files)
            const fields = form.querySelectorAll('input:not([type="file"]):not([type="password"]), textarea, select');
            
            fields.forEach(field => {
                if (isPasswordField(field)) return;

                const fieldName = field.name || field.id;
                if (!fieldName) return;

                if (field.type === 'checkbox' || field.type === 'radio') {
                    formData[formId][fieldName] = field.checked ? field.value : '';
                } else {
                    formData[formId][fieldName] = field.value;
                }
            });
        });

        try {
            // Save to localStorage first (immediate)
            localStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(formData));
            
            // Clear any pending status timeout
            if (statusTimeout) {
                clearTimeout(statusTimeout);
            }
            
            // Show "Saving..." status
            showStatus('保存中...', 'saving');
            
            // Try to save draft to server (autosave endpoint)
            // Only if we're on edit.php (My Page)
            if (window.location.pathname.includes('edit.php')) {
                try {
                    const draftData = collectFormDataForDraft();
                    if (draftData && Object.keys(draftData).length > 0) {
                        const response = await fetch('../backend/api/mypage/autosave.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                            },
                            credentials: 'include',
                            body: JSON.stringify(draftData)
                        });
                        
                        const result = await response.json();
                        if (result.success) {
                            showStatus('ドラフトを保存しました', 'success');
                        } else {
                            // If server save fails, still show local save success
                            showStatus('ローカルに保存しました', 'success');
                        }
                    } else {
                        showStatus('保存しました', 'success');
                    }
                } catch (error) {
                    console.error('Error saving draft to server:', error);
                    // If server save fails, still show local save success
                    showStatus('ローカルに保存しました', 'success');
                }
            } else {
                // Not on edit page, just show local save success
                showStatus('保存しました', 'success');
            }
            
            statusTimeout = null;
        } catch (error) {
            console.error('Error saving to localStorage:', error);
            if (statusTimeout) {
                clearTimeout(statusTimeout);
                statusTimeout = null;
            }
            showStatus('保存に失敗しました', 'warning');
        }
    }

    /**
     * Collect form data for draft autosave
     */
    function collectFormDataForDraft() {
        const data = {};
        
        // Collect all form fields
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            // Basic fields
            const fields = form.querySelectorAll('input:not([type="file"]):not([type="password"]):not([type="checkbox"]):not([type="radio"]), textarea, select');
            fields.forEach(field => {
                if (isPasswordField(field)) return;
                const name = field.name || field.id;
                if (name && field.value) {
                    data[name] = field.value;
                }
            });
            
            // Checkboxes and radios
            const checkboxes = form.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked');
            checkboxes.forEach(checkbox => {
                const name = checkbox.name;
                if (name) {
                    if (!data[name]) {
                        data[name] = checkbox.value;
                    } else if (Array.isArray(data[name])) {
                        data[name].push(checkbox.value);
                    } else {
                        data[name] = [data[name], checkbox.value];
                    }
                }
            });
        });
        
        // Collect greetings
        const greetingItems = document.querySelectorAll('#greetings-list .greeting-item');
        if (greetingItems.length > 0) {
            const greetings = [];
            greetingItems.forEach((item, index) => {
                const titleInput = item.querySelector('input[name="greeting_title[]"]') || item.querySelector('.greeting-title');
                const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]') || item.querySelector('.greeting-content');
                const title = titleInput ? titleInput.value.trim() : '';
                const content = contentTextarea ? contentTextarea.value.trim() : '';
                if (title || content) {
                    greetings.push({
                        title: title,
                        content: content,
                        display_order: index
                    });
                }
            });
            if (greetings.length > 0) {
                data.greetings = greetings;
            }
        }
        
        // Collect tech tools
        const techToolCheckboxes = document.querySelectorAll('.tech-tool-checkbox:checked');
        if (techToolCheckboxes.length > 0) {
            const techTools = [];
            techToolCheckboxes.forEach((checkbox, index) => {
                techTools.push({
                    tool_type: checkbox.value,
                    tool_url: '', // Will be generated on server
                    display_order: index,
                    is_active: true
                });
            });
            if (techTools.length > 0) {
                data.tech_tools = techTools;
            }
        }
        
        // Collect communication methods
        const commItems = document.querySelectorAll('.communication-item');
        if (commItems.length > 0) {
            const communicationMethods = [];
            commItems.forEach((item, index) => {
                const checkbox = item.querySelector('input[type="checkbox"]');
                if (checkbox && checkbox.checked) {
                    const methodType = checkbox.name.replace('comm_', '');
                    const details = item.querySelector('.comm-details');
                    const urlInput = details ? details.querySelector('input[type="url"]') : null;
                    const textInput = details ? details.querySelector('input[type="text"]') : null;
                    
                    communicationMethods.push({
                        method_type: methodType,
                        method_name: item.querySelector('span')?.textContent || methodType,
                        method_url: urlInput ? urlInput.value : '',
                        method_id: textInput ? textInput.value : '',
                        is_active: true,
                        display_order: index
                    });
                }
            });
            if (communicationMethods.length > 0) {
                data.communication_methods = communicationMethods;
            }
        }
        
        return data;
    }

    /**
     * Restore form data from localStorage
     */
    function restoreFormData() {
        try {
            const saved = localStorage.getItem(CONFIG.STORAGE_KEY);
            if (!saved) return false;

            const formData = JSON.parse(saved);
            let restored = false;

            // Handle flat data structure (from collectFormDataForDraft)
            if (formData.greetings && Array.isArray(formData.greetings)) {
                // Restore greetings separately
                const greetingItems = document.querySelectorAll('#greetings-list .greeting-item');
                formData.greetings.forEach((greeting, index) => {
                    if (index < greetingItems.length) {
                        const item = greetingItems[index];
                        const titleInput = item.querySelector('input[name="greeting_title[]"]') || item.querySelector('.greeting-title');
                        const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]') || item.querySelector('.greeting-content');
                        if (titleInput && greeting.title) {
                            titleInput.value = greeting.title;
                            restored = true;
                        }
                        if (contentTextarea && greeting.content) {
                            contentTextarea.value = greeting.content;
                            restored = true;
                        }
                    }
                });
                // Remove greetings from formData to avoid duplicate processing
                delete formData.greetings;
            }

            // Handle nested form structure (formId -> fields)
            Object.keys(formData).forEach(formId => {
                // Skip if it's not a form ID (e.g., greetings, techTools, etc.)
                if (formId === 'techTools' || formId === 'files') {
                    return;
                }

                const form = document.getElementById(formId) || document.querySelector('form');
                if (!form) return;

                // Check if formData[formId] is an object (nested structure)
                if (typeof formData[formId] === 'object' && formData[formId] !== null && !Array.isArray(formData[formId])) {
                Object.keys(formData[formId]).forEach(fieldName => {
                    // Skip array fields (they are handled separately)
                    if (fieldName.includes('[]')) {
                        return;
                    }
                    
                    // Build selector - only use ID selector if fieldName doesn't contain brackets or special characters
                    let selector = `[name="${fieldName}"]`;
                    // Only add ID selector if fieldName is a valid CSS identifier (no brackets, spaces, etc.)
                    if (fieldName && /^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(fieldName)) {
                        selector += `, #${fieldName}`;
                    }
                    
                    try {
                        const field = form.querySelector(selector);
                        if (!field || isPasswordField(field)) return;

                        if (field.type === 'checkbox' || field.type === 'radio') {
                            if (formData[formId][fieldName]) {
                                field.checked = true;
                                restored = true;
                            }
                        } else {
                            field.value = formData[formId][fieldName];
                            if (formData[formId][fieldName]) restored = true;
                        }
                    } catch (selectorError) {
                        console.warn(`Invalid selector for field "${fieldName}":`, selectorError);
                    }
                });
                } else {
                    // Handle flat structure (direct field names)
                    const fieldName = formId;
                    let selector = `[name="${fieldName}"]`;
                    if (fieldName && /^[a-zA-Z_][a-zA-Z0-9_-]*$/.test(fieldName)) {
                        selector += `, #${fieldName}`;
                    }
                    try {
                        const form = document.querySelector('form');
                        if (form) {
                            const field = form.querySelector(selector);
                            if (field && !isPasswordField(field)) {
                                if (field.type === 'checkbox' || field.type === 'radio') {
                                    if (formData[fieldName]) {
                                        field.checked = true;
                                        restored = true;
                                    }
                                } else {
                                    field.value = formData[fieldName];
                                    if (formData[fieldName]) restored = true;
                                }
                            }
                        }
                    } catch (selectorError) {
                        console.warn(`Invalid selector for field "${fieldName}":`, selectorError);
                    }
                }
            });

            if (restored) {
                showStatus('下書きを復元しました', 'restored');
                isDirty = true;
            }

            return restored;
        } catch (error) {
            console.error('Error restoring from localStorage:', error);
            return false;
        }
    }

    /**
     * Create file preview element
     */
    function createFilePreview(fieldName, fileData) {
        const previewContainer = document.querySelector(`[data-file-preview="${fieldName}"]`);
        if (!previewContainer) {
            // Create preview container if it doesn't exist
            // Escape special characters in fieldName for CSS selector (especially brackets)
            const escapedFieldName = fieldName.replace(/[\[\]\\]/g, '\\$&');
            let fileInput = null;
            
            // Try with escaped name attribute
            try {
                fileInput = document.querySelector(`input[type="file"][name="${escapedFieldName}"]`);
            } catch (e) {
                // If querySelector fails, use alternative method
            }
            
            // If not found, try with ID selector (no escaping needed for ID)
            if (!fileInput) {
                try {
                    fileInput = document.querySelector(`input[type="file"]#${fieldName}`);
                } catch (e) {
                    // If querySelector fails, use alternative method
                }
            }
            
            // If still not found, find by iterating through all file inputs
            if (!fileInput) {
                const allFileInputs = document.querySelectorAll('input[type="file"]');
                fileInput = Array.from(allFileInputs).find(input => {
                    return (input.name === fieldName) || (input.id === fieldName);
                });
            }
            
            if (fileInput) {
                const container = document.createElement('div');
                container.setAttribute('data-file-preview', fieldName);
                container.className = 'file-preview-container';
                fileInput.parentNode.insertBefore(container, fileInput.nextSibling);
                return createFilePreview(fieldName, fileData);
            }
            return;
        }

        previewContainer.innerHTML = '';

        const preview = document.createElement('div');
        preview.className = 'file-preview';

        // Image preview
        if (fileData.type && fileData.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = URL.createObjectURL(fileData.blob);
            img.className = 'file-preview-image';
            img.style.maxWidth = '200px';
            img.style.maxHeight = '200px';
            img.style.marginBottom = '10px';
            preview.appendChild(img);
        }

        // File info
        const info = document.createElement('div');
        info.className = 'file-preview-info';
        info.innerHTML = `
            <div><strong>ファイル名:</strong> ${fileData.name}</div>
            <div><strong>サイズ:</strong> ${formatFileSize(fileData.size)}</div>
            <div class="file-preview-note">復元されたファイル（再読み込み後）</div>
        `;
        preview.appendChild(info);

        // Button container
        const buttonContainer = document.createElement('div');
        buttonContainer.style.display = 'flex';
        buttonContainer.style.gap = '10px';
        buttonContainer.style.marginTop = '10px';

        // Restore button (upload to database)
        const restoreBtn = document.createElement('button');
        restoreBtn.type = 'button';
        restoreBtn.className = 'btn-restore-file';
        restoreBtn.textContent = 'ファイルを復元';
        restoreBtn.style.padding = '8px 16px';
        restoreBtn.style.backgroundColor = '#4CAF50';
        restoreBtn.style.color = 'white';
        restoreBtn.style.border = 'none';
        restoreBtn.style.borderRadius = '4px';
        restoreBtn.style.cursor = 'pointer';
        restoreBtn.style.fontSize = '14px';
        restoreBtn.onclick = async () => {
            await restoreFileToDatabase(fieldName, fileData);
        };
        buttonContainer.appendChild(restoreBtn);

        // Remove button
        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'btn-remove-file';
        removeBtn.textContent = '復元ファイルを削除';
        removeBtn.onclick = async () => {
            await deleteFileFromDB(fieldName);
            restoredFiles.delete(fieldName);
            previewContainer.innerHTML = '';
            isDirty = true;
        };
        buttonContainer.appendChild(removeBtn);

        preview.appendChild(buttonContainer);

        previewContainer.appendChild(preview);
    }

    /**
     * Restore file to database (upload and display)
     */
    async function restoreFileToDatabase(fieldName, fileData) {
        if (!fileData || !fileData.blob) {
            showStatus('ファイルデータが見つかりません', 'error');
            return;
        }

        try {
            // Determine file type based on field name
            let fileType = 'photo';
            if (fieldName === 'company_logo' || fieldName === 'logo') {
                fileType = 'logo';
            } else if (fieldName === 'free_image' || fieldName === 'free') {
                fileType = 'free';
            } else if (fieldName === 'profile_photo' || fieldName === 'profile_photo_header') {
                fileType = 'photo';
            }

            // Create FormData
            const formData = new FormData();
            const file = new File([fileData.blob], fileData.name, {
                type: fileData.type,
                lastModified: fileData.lastModified || Date.now()
            });
            formData.append('file', file);
            formData.append('file_type', fileType);

            // Show loading status
            showStatus('ファイルをアップロード中...', 'saving');

            // Upload to backend
            const response = await fetch('../backend/api/business-card/upload.php', {
                method: 'POST',
                body: formData,
                credentials: 'include'
            });

            const result = await response.json();

            if (result.success) {
                // Get the preview container
                const previewContainer = document.querySelector(`[data-file-preview="${fieldName}"]`);

                // Find the upload area (for edit.js and register.js compatibility)
                const fileInput = document.querySelector(`input[type="file"][name="${fieldName}"], input[type="file"]#${fieldName}`);
                let uploadArea = null;
                let previewElement = null;

                if (fileInput) {
                    uploadArea = fileInput.closest('.upload-area');
                    if (uploadArea) {
                        previewElement = uploadArea.querySelector('.upload-preview');
                    }
                }

                // Update preview with uploaded image
                const imagePath = result.data.file_path.startsWith('http')
                    ? result.data.file_path
                    : '../' + result.data.file_path;

                // Build resize info message
                let resizeInfo = '';
                if (result.data.was_resized) {
                    const orig = result.data.original_dimensions;
                    const final = result.data.final_dimensions;
                    const origSize = result.data.original_size_kb;
                    const finalSize = result.data.final_size_kb;
                    resizeInfo = `<p style="font-size: 0.8rem; color: #666; margin-top: 0.5rem;">
                        自動リサイズ: ${orig.width}×${orig.height} → ${final.width}×${final.height}px
                        <br>(${origSize}KB → ${finalSize}KB)
                    </p>`;
                }

                // Update preview in upload area (if exists)
                if (previewElement) {
                    previewElement.innerHTML = `
                        <img src="${imagePath}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
                        ${resizeInfo}
                    `;
                }

                // Update preview container (auto-save preview)
                if (previewContainer) {
                    previewContainer.innerHTML = `
                        <div class="file-preview" style="padding: 10px; background: #d4edda; border-radius: 4px; border: 1px solid #c3e6cb;">
                            <img src="${imagePath}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; margin-bottom: 10px;">
                            <div style="color: #155724; font-size: 14px;">
                                <div><strong>ファイル名:</strong> ${fileData.name}</div>
                                <div><strong>ステータス:</strong> データベースに保存済み</div>
                                ${resizeInfo}
                            </div>
                        </div>
                    `;
                }

                // Clear restored file from IndexedDB since it's now in database
                await deleteFileFromDB(fieldName);
                restoredFiles.delete(fieldName);

                // Update business card data if available (for edit.js)
                if (typeof window.businessCardData !== 'undefined' && window.businessCardData) {
                    let relativePath = result.data.file_path;
                    if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                        const urlParts = relativePath.split('/');
                        const backendIndex = urlParts.indexOf('backend');
                        if (backendIndex !== -1) {
                            relativePath = urlParts.slice(backendIndex).join('/');
                        } else {
                            const uploadsIndex = urlParts.indexOf('uploads');
                            if (uploadsIndex !== -1) {
                                relativePath = 'backend/' + urlParts.slice(uploadsIndex).join('/');
                            }
                        }
                    }
                    window.businessCardData[fieldName] = relativePath;
                }

                // Update preview if updatePreview function exists (for edit.js)
                if (typeof window.updatePreview === 'function' && window.businessCardData) {
                    window.updatePreview(window.businessCardData);
                }

                showStatus('ファイルをデータベースに保存しました', 'success');
                isDirty = true;
            } else {
                showStatus('ファイルのアップロードに失敗しました: ' + (result.message || '不明なエラー'), 'error');
            }
        } catch (error) {
            console.error('Error restoring file to database:', error);
            showStatus('ファイルの復元中にエラーが発生しました', 'error');
        }
    }

    /**
     * Format file size
     */
    function formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i];
    }

    /**
     * Restore files from IndexedDB
     */
    async function restoreFiles() {
        if (!db) {
            showStatus('ファイルの復元は利用できません（IndexedDB非対応）', 'warning');
            return;
        }

        const forms = document.querySelectorAll('form');
        let restoredCount = 0;

        for (const form of forms) {
            const fileInputs = form.querySelectorAll('input[type="file"]');
            
            for (const fileInput of fileInputs) {
                const fieldName = fileInput.name || fileInput.id;
                if (!fieldName) continue;

                const fileData = await getFileFromDB(fieldName);
                if (fileData && fileData.blob) {
                    // Create a File object from the blob
                    const file = new File([fileData.blob], fileData.name, {
                        type: fileData.type,
                        lastModified: fileData.lastModified
                    });

                    restoredFiles.set(fieldName, {
                        blob: fileData.blob,
                        name: fileData.name,
                        type: fileData.type,
                        lastModified: fileData.lastModified,
                        size: fileData.size
                    });

                    createFilePreview(fieldName, fileData);
                    restoredCount++;
                }
            }
        }

        if (restoredCount > 0) {
            showStatus(`${restoredCount}個のファイルを復元しました`, 'restored');
            isDirty = true;
        }
    }

    /**
     * Show status message
     */
    function showStatus(message, type = 'info') {
        let statusEl = document.getElementById('auto-save-status');
        
        if (!statusEl) {
            statusEl = document.createElement('div');
            statusEl.id = 'auto-save-status';
            statusEl.className = 'auto-save-status';
            
            // Try to find a good place to insert it (form header or first form)
            const form = document.querySelector('form');
            if (form) {
                const header = form.querySelector('h1, h2, .form-header, .step-header');
                if (header) {
                    header.parentNode.insertBefore(statusEl, header.nextSibling);
                } else {
                    form.insertBefore(statusEl, form.firstChild);
                }
            } else {
                document.body.insertBefore(statusEl, document.body.firstChild);
            }
        }

        statusEl.textContent = message;
        statusEl.className = `auto-save-status auto-save-status-${type}`;
        statusEl.style.display = 'block';

        // Auto-hide after duration
        if (type !== 'saving') {
            setTimeout(() => {
                statusEl.style.display = 'none';
            }, CONFIG.STATUS_DURATION);
        }
    }

    /**
     * Debounced save function
     */
    function debouncedSave() {
        if (saveTimeout) {
            clearTimeout(saveTimeout);
        }

        saveTimeout = setTimeout(() => {
            saveFormData();
            isDirty = true;
        }, CONFIG.DEBOUNCE_MS);
    }

    /**
     * Handle file input change
     */
    async function handleFileChange(event) {
        const fileInput = event.target;
        const fieldName = fileInput.name || fileInput.id;
        if (!fieldName) return;

        const file = fileInput.files[0];
        if (!file) return;

        // If user selected a new file, remove the restored file
        if (restoredFiles.has(fieldName)) {
            await deleteFileFromDB(fieldName);
            restoredFiles.delete(fieldName);
            const previewContainer = document.querySelector(`[data-file-preview="${fieldName}"]`);
            if (previewContainer) {
                previewContainer.innerHTML = '';
            }
        }

        // Save new file
        const saved = await saveFileToDB(fieldName, file);
        if (saved) {
            isDirty = true;
        }
    }

    /**
     * Add restored files to FormData
     * Call this from your existing form submission handler
     */
    function addRestoredFilesToFormData(form, formData) {
        for (const [fieldName, fileData] of restoredFiles.entries()) {
            const fileInput = form.querySelector(`input[type="file"][name="${fieldName}"], input[type="file"]#${fieldName}`);
            if (fileInput && (!fileInput.files || fileInput.files.length === 0)) {
                // User hasn't selected a new file, use restored one
                const file = new File([fileData.blob], fileData.name, {
                    type: fileData.type,
                    lastModified: fileData.lastModified
                });
                formData.set(fieldName, file);
            }
        }
    }

    /**
     * Clear drafts after successful submission
     * Call this from your existing form submission handler after success
     */
    async function clearDraftsOnSuccess() {
        localStorage.removeItem(CONFIG.STORAGE_KEY);
        await clearAllFilesFromDB();
        restoredFiles.clear();
        isDirty = false;
        showStatus('保存完了', 'success');
    }

    /**
     * Mark submission as failed (keep drafts)
     */
    function markSubmissionFailed() {
        isDirty = true;
    }

    /**
     * Initialize auto-save for all forms
     */
    async function init() {
        // Initialize IndexedDB
        await initDB();

        // Create status indicator CSS if not exists
        if (!document.getElementById('auto-save-styles')) {
            const style = document.createElement('style');
            style.id = 'auto-save-styles';
            style.textContent = `
                .auto-save-status {
                    padding: 8px 12px;
                    margin: 10px 0;
                    border-radius: 4px;
                    font-size: 14px;
                    display: none;
                }
                .auto-save-status-saving {
                    background: #fff3cd;
                    color: #856404;
                    border: 1px solid #ffeaa7;
                }
                .auto-save-status-restored {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }
                .auto-save-status-warning {
                    background: #fff3cd;
                    color: #856404;
                    border: 1px solid #ffeaa7;
                }
                .auto-save-status-error {
                    background: #f8d7da;
                    color: #721c24;
                    border: 1px solid #f5c6cb;
                }
                .auto-save-status-success {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }
                .auto-save-status-success {
                    background: #d4edda;
                    color: #155724;
                    border: 1px solid #c3e6cb;
                }
                .file-preview-container {
                    margin: 10px 0;
                    padding: 0px;
                    background: #f9f9f9;
                }
                .file-preview {
                    display: flex;
                    flex-direction: column;
                    gap: 10px;
                }
                .file-preview-image {
                    border-radius: 4px;
                }
                .file-preview-info {
                    font-size: 14px;
                }
                .file-preview-note {
                    font-size: 12px;
                    color: #666;
                    font-style: italic;
                }
                .btn-remove-file {
                    padding: 6px 12px;
                    background: #dc3545;
                    color: white;
                    border: none;
                    border-radius: 4px;
                    cursor: pointer;
                    font-size: 14px;
                }
                .btn-remove-file:hover {
                    background: #c82333;
                }
            `;
            document.head.appendChild(style);
        }

        // Restore form data
        restoreFormData();

        // Restore files
        await restoreFiles();

        // Set up event listeners for all forms
        const forms = document.querySelectorAll('form');
        forms.forEach(form => {
            // Text inputs
            const textFields = form.querySelectorAll('input:not([type="file"]):not([type="password"]), textarea, select');
            textFields.forEach(field => {
                if (isPasswordField(field)) return;
                
                field.addEventListener('input', debouncedSave);
                field.addEventListener('change', debouncedSave);
            });

            // File inputs
            const fileInputs = form.querySelectorAll('input[type="file"]');
            fileInputs.forEach(fileInput => {
                fileInput.addEventListener('change', handleFileChange);
            });
        });

        // beforeunload warning
        window.addEventListener('beforeunload', (e) => {
            if (isDirty && !isSubmitting) {
                e.preventDefault();
                e.returnValue = '入力内容が保存されていません。このページを離れますか？';
                return e.returnValue;
            }
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Export for manual control and integration with existing form handlers
    window.autoSave = {
        clearDrafts: async () => {
            localStorage.removeItem(CONFIG.STORAGE_KEY);
            await clearAllFilesFromDB();
            restoredFiles.clear();
            isDirty = false;
        },
        clearDraftsOnSuccess: clearDraftsOnSuccess,
        addRestoredFilesToFormData: addRestoredFilesToFormData,
        markClean: () => {
            isDirty = false;
        },
        markDirty: () => {
            isDirty = true;
        },
        markSubmissionFailed: markSubmissionFailed,
        getRestoredFiles: () => {
            return new Map(restoredFiles);
        }
    };
})();

