/**
 * Admin Dashboard JavaScript
 */

// Payment confirmation with modal
async function confirmPayment(businessCardId) {
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
        modal.remove();
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
        if (this.checked) {
            const businessCardId = this.dataset.bcId;
            confirmPayment(businessCardId);
        }
    });
});

// Open checkbox change
document.querySelectorAll('.open-checkbox').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const businessCardId = this.dataset.bcId;
        const isOpen = this.checked ? 1 : 0;
        
        // Update published status
        // API呼び出し実装が必要
        console.log('Update published status:', businessCardId, isOpen);
    });
});

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

// Delete selected users
async function deleteSelectedUsers() {
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

