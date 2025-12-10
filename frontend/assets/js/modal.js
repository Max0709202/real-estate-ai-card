/**
 * Modal Notification System
 * Replaces alert() with modern, UI/UX-friendly modals
 */

// SVG Icons
const MODAL_ICONS = {
    success: `<svg viewBox="0 0 24 24"><path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>`,
    error: `<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>`,
    warning: `<svg viewBox="0 0 24 24"><path d="M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z"/></svg>`,
    info: `<svg viewBox="0 0 24 24"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>`
};

// Default titles by type
const MODAL_TITLES = {
    success: '成功',
    error: 'エラー',
    warning: '警告',
    info: 'お知らせ'
};

/**
 * Show a modal notification
 * @param {string|Array} message - The message to display (string or array of strings)
 * @param {string} type - Type: 'success', 'error', 'warning', 'info' (default: 'info')
 * @param {Object} options - Additional options
 * @param {string} options.title - Custom title (optional)
 * @param {number} options.autoClose - Auto-close after milliseconds (optional, 0 = no auto-close)
 * @param {Function} options.onClose - Callback when modal closes
 * @param {boolean} options.showCancel - Show cancel button (default: false)
 * @param {Function} options.onConfirm - Callback when confirm button is clicked
 */
function showModal(message, type = 'info', options = {}) {
    // Remove existing modal if any
    const existingModal = document.querySelector('.modal-overlay');
    if (existingModal) {
        existingModal.remove();
    }

    // Parse options
    const {
        title = MODAL_TITLES[type] || MODAL_TITLES.info,
        autoClose = 0,
        onClose = null,
        showCancel = false,
        onConfirm = null
    } = options;

    // Format message
    let messageText = '';
    if (Array.isArray(message)) {
        messageText = message.join('\n');
    } else {
        messageText = String(message);
    }

    // Create modal HTML
    const modalHTML = `
        <div class="modal-overlay" id="modal-overlay">
            <div class="modal-container">
                <div class="modal-header ${type}">
                    <div class="modal-icon">
                        ${MODAL_ICONS[type] || MODAL_ICONS.info}
                    </div>
                    <h3 class="modal-title">${escapeHtml(title)}</h3>
                </div>
                <div class="modal-body">
                    <p class="modal-message">${formatMessage(messageText)}</p>
                </div>
                <div class="modal-footer">
                    ${showCancel ? `<button class="modal-btn modal-btn-secondary" id="modal-cancel">キャンセル</button>` : ''}
                    <button class="modal-btn modal-btn-primary" id="modal-confirm">${showCancel ? '確認' : 'OK'}</button>
                </div>
            </div>
        </div>
    `;

    // Insert modal into DOM
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    const modalOverlay = document.getElementById('modal-overlay');
    const modalContainer = modalOverlay.querySelector('.modal-container');

    // Show modal with animation
    requestAnimationFrame(() => {
        modalOverlay.classList.add('show');
    });

    // Close handlers
    const closeModal = (confirmed = false) => {
        modalContainer.classList.add('dismissing');
        setTimeout(() => {
            modalOverlay.remove();
            if (onClose) onClose(confirmed);
        }, 300);
    };

    // Button handlers
    const confirmBtn = document.getElementById('modal-confirm');
    const cancelBtn = document.getElementById('modal-cancel');

    confirmBtn.addEventListener('click', () => {
        if (onConfirm) {
            onConfirm();
        }
        closeModal(true);
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            closeModal(false);
        });
    }

    // Click outside to close (only for non-critical messages)
    if (type !== 'error') {
        modalOverlay.addEventListener('click', (e) => {
            if (e.target === modalOverlay) {
                closeModal(false);
            }
        });
    }

    // ESC key to close
    const escHandler = (e) => {
        if (e.key === 'Escape') {
            closeModal(false);
            document.removeEventListener('keydown', escHandler);
        }
    };
    document.addEventListener('keydown', escHandler);

    // Auto-close timer
    if (autoClose > 0) {
        setTimeout(() => {
            if (modalOverlay.parentNode) {
                closeModal(false);
            }
        }, autoClose);
    }
}

/**
 * Helper: Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Helper: Format message (preserve line breaks, handle lists)
 */
function formatMessage(text) {
    // Escape HTML first
    let formatted = escapeHtml(text);
    
    // Convert \n to <br> for line breaks
    formatted = formatted.replace(/\n/g, '<br>');
    
    // If message contains multiple lines, format as list
    const lines = text.split('\n').filter(line => line.trim());
    if (lines.length > 1 && lines.length <= 10) {
        // Check if it looks like a list
        const isList = lines.every(line => 
            line.trim().startsWith('-') || 
            line.trim().startsWith('•') || 
            line.trim().match(/^\d+[\.\)]/)
        );
        
        if (isList) {
            formatted = '<ul>' + lines.map(line => {
                const cleanLine = line.replace(/^[-•\d\.\)\s]+/, '').trim();
                return `<li>${escapeHtml(cleanLine)}</li>`;
            }).join('') + '</ul>';
        }
    }
    
    return formatted;
}

/**
 * Convenience functions for each type
 */
function showSuccess(message, options = {}) {
    return showModal(message, 'success', { autoClose: 3000, ...options });
}

function showError(message, options = {}) {
    return showModal(message, 'error', { autoClose: 0, ...options });
}

function showWarning(message, options = {}) {
    return showModal(message, 'warning', { autoClose: 4000, ...options });
}

function showInfo(message, options = {}) {
    return showModal(message, 'info', { autoClose: 3000, ...options });
}

/**
 * Show a confirmation modal (replaces confirm())
 * @param {string} message - The message to display
 * @param {Function} onConfirm - Callback when user confirms
 * @param {Function} onCancel - Optional callback when user cancels
 * @param {string} title - Optional custom title (default: '確認')
 */
function showConfirm(message, onConfirm, onCancel = null, title = '確認') {
    return showModal(message, 'info', {
        title: title,
        showCancel: true,
        onConfirm: onConfirm,
        onClose: (confirmed) => {
            if (!confirmed && onCancel) {
                onCancel();
            }
        }
    });
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { showModal, showSuccess, showError, showWarning, showInfo, showConfirm };
}

