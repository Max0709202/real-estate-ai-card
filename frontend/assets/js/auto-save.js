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
     * Save form data to localStorage
     */
    function saveFormData() {
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
            localStorage.setItem(CONFIG.STORAGE_KEY, JSON.stringify(formData));
            // Clear any pending status timeout
            if (statusTimeout) {
                clearTimeout(statusTimeout);
            }
            // Show "Saving..." briefly, then change to "Saved" which auto-hides
            showStatus('保存中...', 'saving');
            // Change to "Saved" message after a brief moment (since localStorage is synchronous, this happens almost immediately)
            statusTimeout = setTimeout(() => {
                showStatus('保存しました', 'success');
                statusTimeout = null;
            }, 300);
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
     * Restore form data from localStorage
     */
    function restoreFormData() {
        try {
            const saved = localStorage.getItem(CONFIG.STORAGE_KEY);
            if (!saved) return false;

            const formData = JSON.parse(saved);
            let restored = false;

            Object.keys(formData).forEach(formId => {
                const form = document.getElementById(formId) || document.querySelector('form');
                if (!form) return;

                Object.keys(formData[formId]).forEach(fieldName => {
                    const field = form.querySelector(`[name="${fieldName}"], #${fieldName}`);
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
                });
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
            const fileInput = document.querySelector(`input[type="file"][name="${fieldName}"], input[type="file"]#${fieldName}`);
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
        preview.appendChild(removeBtn);

        previewContainer.appendChild(preview);
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

