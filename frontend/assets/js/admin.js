/**
 * Admin Dashboard JavaScript
 */

// QR Code generation confirmation modal
function showQRCodeConfirmation(businessCardId, checkboxElement) {
    // Check if user has admin role
    if (window.isAdmin === false) {
        checkboxElement.checked = false;
        showError('クライアントロールはこの操作を実行できません');
        return;
    }
    
    // Store checkbox element for reverting if cancelled
    window.currentCheckboxElement = checkboxElement;
    window.currentBusinessCardId = businessCardId;
    
    // Remove any existing modal first
    const existingModal = document.querySelector('.modal-overlay');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>QRコード発行確認</h3>
            <p>QRコードを発行しますか？</p>
            <p style="font-size: 14px; color: #666; margin-top: 10px;">入金確認と同時にQRコードが発行され、ユーザーにメールが送信されます。</p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" id="confirm-qr-yes">はい</button>
                <button class="modal-btn modal-btn-no" id="confirm-qr-no">いいえ</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Force reflow to ensure DOM is updated
    void modal.offsetHeight;
    
    // Add active class to show modal
    setTimeout(() => {
        modal.classList.add('show');
    }, 10);

    // Add event listeners after DOM is updated
    setTimeout(() => {
        const yesBtn = document.getElementById('confirm-qr-yes');
        const noBtn = document.getElementById('confirm-qr-no');
        
        if (yesBtn) {
            yesBtn.addEventListener('click', function() {
                processQRCodeGeneration(businessCardId);
            });
        }
        
        if (noBtn) {
            noBtn.addEventListener('click', function() {
                cancelQRCodeGeneration();
            });
        }
    }, 50);
}

// Cancel QR code generation
function cancelQRCodeGeneration() {
    closeModal();
    // Uncheck the checkbox
    if (window.currentCheckboxElement) {
        window.currentCheckboxElement.checked = false;
        window.currentCheckboxElement = null;
    }
    window.currentBusinessCardId = null;
}

// Process QR code generation
async function processQRCodeGeneration(businessCardId) {
    closeModal();
    
    try {
        const response = await fetch('../../backend/api/admin/users.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                business_card_id: businessCardId,
                action: 'confirm_payment'
            }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('入金を確認し、QRコードを発行しました。ユーザーにメールを送信しました。', {
                autoClose: 3000,
                onClose: () => location.reload()
            });
        } else {
            // Revert checkbox on error
            if (window.currentCheckboxElement) {
                window.currentCheckboxElement.checked = false;
                window.currentCheckboxElement = null;
            }
            showError(result.message || '処理に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        // Revert checkbox on error
        if (window.currentCheckboxElement) {
            window.currentCheckboxElement.checked = false;
            window.currentCheckboxElement = null;
        }
        showError('エラーが発生しました');
    }
}

// Show confirmation modal for stopping business card usage
function showStopBusinessCardConfirmation(businessCardId, checkboxElement) {
    // Check if user has admin role
    if (window.isAdmin === false) {
        checkboxElement.checked = true; // Revert to checked
        showError('クライアントロールはこの操作を実行できません');
        return;
    }
    
    // Store checkbox element for reverting if cancelled
    window.currentCheckboxElement = checkboxElement;
    window.currentBusinessCardId = businessCardId;
    
    // Remove any existing modal first
    const existingModal = document.querySelector('.modal-overlay');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>名刺使用停止確認</h3>
            <p>このユーザーの名刺の使用を停止しますか？</p>
            <p style="font-size: 14px; color: #666; margin-top: 10px;">入金ステータスが「未入金」に変更され、名刺が非公開になります。</p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" id="confirm-stop-yes">はい</button>
                <button class="modal-btn modal-btn-no" id="confirm-stop-no">いいえ</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Force reflow to ensure DOM is updated
    void modal.offsetHeight;
    
    // Add active class to show modal
    setTimeout(() => {
        modal.classList.add('show');
    }, 10);

    // Add event listeners after DOM is updated
    setTimeout(() => {
        const yesBtn = document.getElementById('confirm-stop-yes');
        const noBtn = document.getElementById('confirm-stop-no');
        
        if (yesBtn) {
            yesBtn.addEventListener('click', function() {
                processStopBusinessCard(businessCardId);
            });
        }
        
        if (noBtn) {
            noBtn.addEventListener('click', function() {
                cancelStopBusinessCard();
            });
        }
    }, 50);
}

// Cancel stop business card
function cancelStopBusinessCard() {
    closeModal();
    // Revert checkbox to checked
    if (window.currentCheckboxElement) {
        window.currentCheckboxElement.checked = true;
        window.currentCheckboxElement = null;
    }
    window.currentBusinessCardId = null;
}

// Process stop business card
async function processStopBusinessCard(businessCardId) {
    closeModal();
    
    try {
        const response = await fetch('../../backend/api/admin/users.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                business_card_id: businessCardId,
                action: 'cancel_payment'
            }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('名刺の使用を停止しました。入金ステータスが「未入金」に変更され、名刺が非公開になりました。', {
                autoClose: 3000,
                onClose: () => location.reload()
            });
        } else {
            // Revert checkbox on error
            if (window.currentCheckboxElement) {
                window.currentCheckboxElement.checked = true;
                window.currentCheckboxElement = null;
            }
            showError(result.message || '処理に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        // Revert checkbox on error
        if (window.currentCheckboxElement) {
            window.currentCheckboxElement.checked = true;
            window.currentCheckboxElement = null;
        }
        showError('エラーが発生しました');
    }
}

// Payment confirmation with modal (kept for backward compatibility)
async function confirmPayment(businessCardId) {
    // Check if user has admin role
    if (window.isAdmin === false) {
        showError('クライアントロールはこの操作を実行できません');
        return;
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay active';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>入金確認</h3>
            <p>入金を確認し、QRコードを発行しますか？</p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" onclick="processPayment(${businessCardId})">はい</button>
                <button class="modal-btn modal-btn-no" onclick="closeModal()">いいえ</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Store business card ID for processing
    window.currentBusinessCardId = businessCardId;
}

function closeModal() {
    const modal = document.querySelector('.modal-overlay');
    if (modal) {
        modal.classList.remove('active');
        setTimeout(() => {
            modal.remove();
        }, 300);
    }
}

async function processPayment(businessCardId) {
    closeModal();
    
    try {
        const response = await fetch('../../backend/api/admin/users.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                business_card_id: businessCardId,
                action: 'confirm_payment'
            }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('入金を確認し、QRコードを発行しました', { autoClose: 2000, onClose: () => location.reload() });
        } else {
            showError(result.message || '処理に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
}

// Payment checkbox change
document.querySelectorAll('.payment-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        // Check if checkbox is disabled
        if (this.disabled) {
            this.checked = !this.checked; // Revert change
            
            // Check if it's a free user
            const row = this.closest('tr');
            const userTypeCell = row.querySelector('td[data-label="ユーザータイプ"]');
            if (userTypeCell) {
                const userTypeBadge = userTypeCell.querySelector('.user-type-free');
                if (userTypeBadge) {
                    showError('無料ユーザーは入金確認できません');
                    return;
                }
            }
            
            showError('この操作を実行できません');
            return;
        }
        
        if (this.checked) {
            const businessCardId = this.dataset.bcId;
            // Show confirmation popup for QR code generation
            showQRCodeConfirmation(businessCardId, this);
        } else {
            // If unchecked, show confirmation to stop using business card
            const businessCardId = this.dataset.bcId;
            showStopBusinessCardConfirmation(businessCardId, this);
        }
    });
});

// Open checkbox change
document.querySelectorAll('.open-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        // Check if checkbox is disabled (client role or payment not allowed)
        if (this.disabled) {
            this.checked = !this.checked; // Revert change
            const paymentStatus = this.dataset.paymentStatus;
            if (paymentStatus && !['CR', 'BANK_PAID'].includes(paymentStatus)) {
                showError('入金完了（CR / 振込済）後にOPEN可能です');
            } else {
            showError('クライアントロールはこの操作を実行できません');
            }
            return;
        }
        
        const businessCardId = this.dataset.bcId;
        const paymentStatus = this.dataset.paymentStatus;
        const isOpen = this.checked ? 1 : 0;
        const originalState = !this.checked; // Store original state for revert
        
        // Frontend validation: double-check payment status before sending
        if (isOpen === 1 && paymentStatus && !['CR', 'BANK_PAID'].includes(paymentStatus)) {
            this.checked = false; // Revert
            showError('入金完了（CR / 振込済）後にOPEN可能です');
            return;
        }
        
        // Update published status
        updatePublishedStatus(businessCardId, isOpen, this, originalState);
    });
});

// Update published status
async function updatePublishedStatus(businessCardId, isPublished, checkboxElement, originalState) {
    try {
        const response = await fetch('../../backend/api/admin/users.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                business_card_id: businessCardId,
                action: 'update_published',
                is_published: isPublished
            }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            const statusText = isPublished ? '公開' : '非公開';
            showSuccess(`公開状態を${statusText}に変更しました`, { autoClose: 2000 });
        } else {
            // Revert checkbox on error
            checkboxElement.checked = originalState;
            showError(result.message || '公開状態の変更に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        // Revert checkbox on error
        checkboxElement.checked = originalState;
        showError('エラーが発生しました');
    }
}

// Get selected user IDs
function getSelectedUserIds() {
    const checkboxes = document.querySelectorAll('.user-select-checkbox:checked');
    return Array.from(checkboxes).map(cb => parseInt(cb.dataset.userId));
}

// Update delete button state
function updateDeleteButtonState() {
    const selectedIds = getSelectedUserIds();
    const deleteBtn = document.getElementById('btn-delete-selected');
    if (deleteBtn) {
        deleteBtn.disabled = selectedIds.length === 0;
    }
}

// Show confirmation modal
function showConfirm(message, onConfirm) {
    // Remove any existing modal first
    const existingModal = document.querySelector('.modal-overlay');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.innerHTML = `
        <div class="modal-content">
            <h3>確認</h3>
            <p>${message}</p>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" id="confirm-yes">はい</button>
                <button class="modal-btn modal-btn-no" id="confirm-no">いいえ</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);
    
    // Force reflow to ensure DOM is updated
    void modal.offsetHeight;
    
    // Add active class to show modal
    setTimeout(() => {
        modal.classList.add('show');
    }, 10);

    // Add event listeners after DOM is updated
    setTimeout(() => {
        const yesBtn = document.getElementById('confirm-yes');
        const noBtn = document.getElementById('confirm-no');
        
        if (yesBtn) {
            yesBtn.addEventListener('click', function() {
                closeModal();
                if (onConfirm) {
                    onConfirm();
                }
            });
        }
        
        if (noBtn) {
            noBtn.addEventListener('click', function() {
                closeModal();
            });
        }
    }, 50);
}

// Check overdue payments
document.addEventListener('DOMContentLoaded', function() {
    const checkOverdueBtn = document.getElementById('btn-check-overdue');
    if (checkOverdueBtn) {
        checkOverdueBtn.addEventListener('click', async function() {
            if (window.isAdmin === false) {
                showError('クライアントロールはこの操作を実行できません');
                return;
            }
            
            const message = '未払い月額料金をチェックして、該当するユーザーの支払いステータスを「未入金」に更新しますか？';
            showConfirm(message, async () => {
                checkOverdueBtn.disabled = true;
                checkOverdueBtn.textContent = 'チェック中...';
                
                try {
                    const response = await fetch('../../backend/api/admin/check-overdue-payments.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'include'
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        const count = result.updated_count || 0;
                        let message = result.message || `${count}件のレコードを更新しました`;
                        if (count > 0 && result.updated_business_cards) {
                            message += `\n\n更新されたビジネスカードID: `;
                            message += result.updated_business_cards.map(bc => bc.business_card_id).join(', ');
                        }
                        showSuccess(message, {
                            autoClose: 5000,
                            onClose: () => location.reload()
                        });
                    } else {
                        showError(result.message || 'チェックに失敗しました');
                    }
                } catch (error) {
                    console.error('Error:', error);
                    showError('エラーが発生しました');
                } finally {
                    checkOverdueBtn.disabled = false;
                    checkOverdueBtn.textContent = '未払いチェック';
                }
            });
        });
    }
});

// Delete selected users
async function deleteSelectedUsers() {
    // Check if user has admin role
    if (window.isAdmin === false) {
        showError('クライアントロールはこの操作を実行できません');
        return;
    }
    
    const selectedIds = getSelectedUserIds();
    
    if (selectedIds.length === 0) {
        showError('削除するユーザーを選択してください');
        return;
    }
    
    const count = selectedIds.length;
    const message = `${count}件のユーザーを削除しますか？\nこの操作は取り消せません。`;
    
    showConfirm(message, async () => {
        try {
            const response = await fetch('../../backend/api/admin/users.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    user_ids: selectedIds
                }),
                credentials: 'include'
            });
            
            const result = await response.json();
            
            if (result.success) {
                showSuccess(`${count}件のユーザーを削除しました`, { 
                    autoClose: 2000, 
                    onClose: () => location.reload() 
                });
            } else {
                showError(result.message || '削除に失敗しました');
            }
        } catch (error) {
            console.error('Error:', error);
            showError('エラーが発生しました');
        }
    }, null, 'ユーザー削除の確認');
}

// Confirm bank transfer paid (make it globally accessible)
function confirmBankTransferPaid(businessCardId, badgeElement) {
    console.log('confirmBankTransferPaid called', businessCardId, badgeElement);

    try {
        // Check if user has admin role
        if (window.isAdmin === false) {
            if (typeof showError === 'function') {
                showError('クライアントロールはこの操作を実行できません');
            } else {
                alert('クライアントロールはこの操作を実行できません');
            }
            return;
        }

        // Store badge element for reverting if cancelled
        window.currentBadgeElement = badgeElement;
        window.currentBusinessCardId = businessCardId;

        // Remove any existing modal first
        const existingModal = document.querySelector('.modal-overlay');
        if (existingModal) {
            existingModal.remove();
        }

        // Create modal (using admin.css modal styles - same as showQRCodeConfirmation)
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
            <div class="modal-content">
                <h3>振込確認</h3>
                <p>振込済みに変更しますか？</p>
                <p style="font-size: 14px; color: #666; margin-top: 10px;">QRコードが発行され、ユーザーにメールが送信されます。</p>
                <div class="modal-buttons">
                    <button class="modal-btn modal-btn-yes" id="confirm-bank-paid-yes">はい</button>
                    <button class="modal-btn modal-btn-no" id="confirm-bank-paid-no">いいえ</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        // Force reflow to ensure DOM is updated
        void modal.offsetHeight;

        // Add active class to display modal (matching admin.css)
        setTimeout(() => {
            modal.classList.add('active');
            console.log('Modal class added:', modal.className);
            console.log('Modal element:', modal);
            console.log('Modal display style:', window.getComputedStyle(modal).display);
        }, 10);

        // Add event listeners after DOM is updated
        setTimeout(() => {
            const yesBtn = document.getElementById('confirm-bank-paid-yes');
            const noBtn = document.getElementById('confirm-bank-paid-no');

            if (yesBtn) {
                yesBtn.addEventListener('click', function() {
                    processBankTransferPaid(businessCardId, badgeElement);
                    modal.remove();
                });
            } else {
                console.error('Yes button not found');
            }

            if (noBtn) {
                noBtn.addEventListener('click', function() {
                    modal.remove();
                });
            } else {
                console.error('No button not found');
            }
        }, 10);
    } catch (error) {
        console.error('Error in confirmBankTransferPaid:', error);
        alert('エラーが発生しました: ' + error.message);
    }
}

// Also assign to window for inline onclick handlers
window.confirmBankTransferPaid = confirmBankTransferPaid;

async function processBankTransferPaid(businessCardId, badgeElement) {
    try {
        const response = await fetch('../../backend/api/admin/update-payment-status.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                business_card_id: businessCardId,
                payment_status: 'BANK_PAID'
            })
        });

        const result = await response.json();

        if (result.success) {
            // Update badge
            if (badgeElement) {
                badgeElement.className = 'payment-badge payment-badge-transfer-completed';
                badgeElement.textContent = '振込済';
                badgeElement.removeAttribute('onclick');
                badgeElement.removeAttribute('title');
                badgeElement.style.cursor = 'default';
                badgeElement.setAttribute('data-current-status', 'BANK_PAID');
            }

            showSuccess(result.message || '入金状況を「振込済」に更新しました。QRコードが発行され、ユーザーにメールが送信されました。', {
                autoClose: 3000,
                onClose: () => {
                    // Reload page to ensure all data is synced
                    window.location.reload();
                }
            });
        } else {
            showError(result.message || '更新に失敗しました');
        }
    } catch (error) {
        console.error('Error updating payment status:', error);
        showError('エラーが発生しました');
    }
}

// Table sorting
function sortTable(column) {
    const url = new URL(window.location);
    const currentSort = url.searchParams.get('sort');
    const currentOrder = url.searchParams.get('order');
    
    let newOrder = 'ASC';
    if (currentSort === column && currentOrder === 'ASC') {
        newOrder = 'DESC';
    }
    
    url.searchParams.set('sort', column);
    url.searchParams.set('order', newOrder);
    window.location = url.toString();
}

// Initialize
document.addEventListener('DOMContentLoaded', () => {
    // Add click handlers to sortable headers
    document.querySelectorAll('.sortable').forEach(header => {
        header.addEventListener('click', function() {
            const sortField = this.dataset.sort;
            sortTable(sortField);
        });
    });
    
    // Update sort indicators based on current sort
    const urlParams = new URLSearchParams(window.location.search);
    const currentSort = urlParams.get('sort');
    const currentOrder = urlParams.get('order');
    
    if (currentSort) {
        document.querySelectorAll('.sortable').forEach(header => {
            if (header.dataset.sort === currentSort) {
                header.classList.remove('sort-asc', 'sort-desc');
                header.classList.add(currentOrder === 'ASC' ? 'sort-asc' : 'sort-desc');
            } else {
                header.classList.remove('sort-asc', 'sort-desc');
            }
        });
    }
    
    // Close modal on overlay click
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('modal-overlay')) {
            closeModal();
        }
    });
    
    // Select all checkbox
    const selectAllCheckbox = document.getElementById('select-all');
    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.user-select-checkbox');
            checkboxes.forEach(cb => {
                cb.checked = this.checked;
            });
            updateDeleteButtonState();
        });
    }
    
    // User selection checkboxes
    document.querySelectorAll('.user-select-checkbox').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            updateDeleteButtonState();
            
            // Update select-all checkbox state
            const allCheckboxes = document.querySelectorAll('.user-select-checkbox');
            const checkedCount = document.querySelectorAll('.user-select-checkbox:checked').length;
            if (selectAllCheckbox) {
                selectAllCheckbox.checked = checkedCount === allCheckboxes.length && allCheckboxes.length > 0;
                selectAllCheckbox.indeterminate = checkedCount > 0 && checkedCount < allCheckboxes.length;
            }
        });
    });
    
    // Delete button
    const deleteBtn = document.getElementById('btn-delete-selected');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', deleteSelectedUsers);
    }
    
    // Initialize delete button state
    updateDeleteButtonState();
    
    console.log('Admin dashboard loaded');
});

