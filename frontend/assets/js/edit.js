/**
 * Edit Business Card JavaScript
 */

// Make businessCardData globally accessible
let businessCardData = null;
window.businessCardData = businessCardData;

// Load business card data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadBusinessCardData();
    
    // Navigation handling
    setupNavigation();
    
    // File upload preview handlers
    setupFileUploads();

    // Initialize drag and drop for greeting items
    setTimeout(function() {
        initializeGreetingDragAndDrop();
    }, 500); // Wait for greetings to be loaded
    
    // Auto-capitalize first letter of romaji input fields
    setupRomajiAutoCapitalize();
    
    // Initialize free image upload handlers for existing items
    const freeImageItems = document.querySelectorAll('#free-images-container .free-image-item');
    freeImageItems.forEach(item => {
        initializeFreeImageUpload(item);
    });
    
    // Initialize communication checkbox handlers
    setupCommunicationCheckboxes();
});

// Setup communication checkbox handlers
function setupCommunicationCheckboxes() {
    document.querySelectorAll('.communication-checkbox input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const item = this.closest('.communication-item');
            const details = item.querySelector('.comm-details');
            if (details) {
                details.style.display = this.checked ? 'block' : 'none';
            }
        });
    });
}

// Auto-capitalize first letter of romaji input fields
function setupRomajiAutoCapitalize() {
    const romajiFields = [
        document.getElementById('edit_last_name_romaji'),
        document.getElementById('edit_first_name_romaji')
    ];
    
    romajiFields.forEach(field => {
        if (field) {
            // Use input event to capitalize first letter
            field.addEventListener('input', function(e) {
                const input = e.target;
                let value = input.value;
                
                if (value.length > 0) {
                    // 最初の文字が小文字（a-z）の場合は大文字に変換
                    const firstChar = value.charAt(0);
                    if (firstChar >= 'a' && firstChar <= 'z') {
                        const cursorPosition = input.selectionStart;
                        value = firstChar.toUpperCase() + value.slice(1);
                        input.value = value;
                        // カーソル位置を復元
                        input.setSelectionRange(cursorPosition, cursorPosition);
                    }
                }
            });
        }
    });
}

// Load business card data from API
async function loadBusinessCardData() {
    const previewContent = document.getElementById('preview-content');
    if (previewContent) {
        previewContent.innerHTML = '<p>データを読み込み中...</p>';
    }
    
    try {
        const response = await fetch('../backend/api/business-card/get.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('API Response:', result);
        
        if (result.success && result.data) {
            businessCardData = result.data;
            window.businessCardData = businessCardData; // Make it globally accessible
            console.log('Business Card Data:', businessCardData);
            populateForms(businessCardData);
            updatePreview(businessCardData);
        } else {
            console.error('Failed to load business card data:', result);
            // Still display tech tools even if data load fails
            displayTechTools([]);
            const errorMsg = result.message || 'データの読み込みに失敗しました';
            if (previewContent) {
                previewContent.innerHTML = `<p style="color: red;">${errorMsg}</p>`;
            }
            showError('データの読み込みに失敗しました: ' + errorMsg);
        }
    } catch (error) {
        console.error('Error loading business card data:', error);
        // Still display tech tools even if there's an error
        displayTechTools([]);
        const errorMsg = error.message || 'エラーが発生しました';
        if (previewContent) {
            previewContent.innerHTML = `<p style="color: red;">${errorMsg}</p>`;
        }
        showError('エラーが発生しました: ' + errorMsg);
    }
}

// Populate forms with loaded data
function populateForms(data) {
    console.log('Populating forms with data:', data);
    
    // Step 1: Header & Greeting Form
    const headerGreetingForm = document.getElementById('header-greeting-form');
    if (headerGreetingForm) {
        // Company name
        const companyNameInput = headerGreetingForm.querySelector('input[name="company_name"]');
        if (companyNameInput && data.company_name) {
            companyNameInput.value = data.company_name;
            console.log('Set company_name:', data.company_name);
        }
        
        // Logo
        if (data.company_logo) {
            const logoPreview = document.querySelector('[data-upload-id="company_logo"] .upload-preview');
            if (logoPreview) {
                const logoPath = data.company_logo.startsWith('http') ? data.company_logo : '../' + data.company_logo;
                logoPreview.innerHTML = `<img src="${logoPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                console.log('Set company_logo:', logoPath);
            }
        }
        
        // Profile Photo
        if (data.profile_photo) {
            const photoPreview = document.querySelector('[data-upload-id="profile_photo"] .upload-preview');
            if (photoPreview) {
                const photoPath = data.profile_photo.startsWith('http') ? data.profile_photo : '../' + data.profile_photo;
                photoPreview.innerHTML = `<img src="${photoPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                console.log('Set profile_photo:', photoPath);
            }
        }
        
        // Greetings - ALWAYS clear first, then populate based on data
        const greetingsList = document.getElementById('greetings-list');
        if (greetingsList) {
            greetingsList.innerHTML = '';
            
            if (data.greetings && Array.isArray(data.greetings) && data.greetings.length > 0) {
                console.log('Displaying greetings:', data.greetings);
                displayGreetings(data.greetings);
            } else if (data.greetings && Array.isArray(data.greetings) && data.greetings.length === 0) {
                // Empty array - user has deleted all greetings, keep it empty
                console.log('Greetings array is empty - keeping it empty');
                // Already cleared above
            } else {
                // No greetings data or greetings is null/undefined - first time, display defaults
                console.log('No greetings data - displaying defaults');
                displayDefaultGreetings();
            }
        }
    }
    
    // Step 2: Company Profile Form
    const companyProfileForm = document.getElementById('company-profile-form');
    if (companyProfileForm) {
        if (data.real_estate_license_prefecture) {
            const prefectureSelect = companyProfileForm.querySelector('select[name="real_estate_license_prefecture"]');
            if (prefectureSelect) prefectureSelect.value = data.real_estate_license_prefecture;
        }
        if (data.real_estate_license_renewal_number) {
            const renewalSelect = companyProfileForm.querySelector('select[name="real_estate_license_renewal_number"]');
            if (renewalSelect) renewalSelect.value = data.real_estate_license_renewal_number;
        }
        if (data.real_estate_license_registration_number) {
            const registrationInput = companyProfileForm.querySelector('input[name="real_estate_license_registration_number"]');
            if (registrationInput) registrationInput.value = data.real_estate_license_registration_number;
        }
        if (data.company_name) {
            const companyNameInput = companyProfileForm.querySelector('input[name="company_name_profile"]');
            if (companyNameInput) companyNameInput.value = data.company_name;
        }
        if (data.company_postal_code) {
            const postalCodeInput = companyProfileForm.querySelector('input[name="company_postal_code"]');
            if (postalCodeInput) postalCodeInput.value = data.company_postal_code;
        }
        if (data.company_address) {
            const addressInput = companyProfileForm.querySelector('input[name="company_address"]');
            if (addressInput) addressInput.value = data.company_address;
        }
        if (data.company_phone) {
            const phoneInput = companyProfileForm.querySelector('input[name="company_phone"]');
            if (phoneInput) phoneInput.value = data.company_phone;
        }
        if (data.company_website) {
            const websiteInput = companyProfileForm.querySelector('input[name="company_website"]');
            if (websiteInput) websiteInput.value = data.company_website;
        }
    }
    
    // Step 3: Personal Information Form
    const personalInfoForm = document.getElementById('personal-info-form');
    if (personalInfoForm) {
        if (data.branch_department) {
            const branchDeptInput = personalInfoForm.querySelector('input[name="branch_department"]');
            if (branchDeptInput) branchDeptInput.value = data.branch_department;
        }
        if (data.position) {
            const positionInput = personalInfoForm.querySelector('input[name="position"]');
            if (positionInput) positionInput.value = data.position;
        }
        
        // Name (split into last_name and first_name)
        if (data.name) {
            const lastNameInput = document.getElementById('edit_last_name');
            const firstNameInput = document.getElementById('edit_first_name');
            
            if (lastNameInput && firstNameInput) {
                const nameParts = data.name.trim().split(/\s+/);
                if (nameParts.length >= 2) {
                    lastNameInput.value = nameParts[0];
                    firstNameInput.value = nameParts.slice(1).join(' ');
                } else {
                    lastNameInput.value = data.name;
                    firstNameInput.value = '';
                }
                console.log('Set name:', data.name, '->', lastNameInput.value, firstNameInput.value);
            }
        }
        
        // Name Romaji (split into last_name_romaji and first_name_romaji)
        if (data.name_romaji) {
            const lastNameRomajiInput = document.getElementById('edit_last_name_romaji');
            const firstNameRomajiInput = document.getElementById('edit_first_name_romaji');
            
            if (lastNameRomajiInput && firstNameRomajiInput) {
                const romajiParts = data.name_romaji.trim().split(/\s+/);
                if (romajiParts.length >= 2) {
                    lastNameRomajiInput.value = romajiParts[0];
                    firstNameRomajiInput.value = romajiParts.slice(1).join(' ');
                } else {
                    lastNameRomajiInput.value = data.name_romaji;
                    firstNameRomajiInput.value = '';
                }
                console.log('Set name_romaji:', data.name_romaji);
            }
        }
        
        if (data.mobile_phone) {
            const mobilePhoneInput = personalInfoForm.querySelector('input[name="mobile_phone"]');
            if (mobilePhoneInput) mobilePhoneInput.value = data.mobile_phone;
        }
        if (data.birth_date) {
            const birthDateInput = personalInfoForm.querySelector('input[name="birth_date"]');
            if (birthDateInput) birthDateInput.value = data.birth_date;
        }
        if (data.current_residence) {
            const residenceInput = personalInfoForm.querySelector('input[name="current_residence"]');
            if (residenceInput) residenceInput.value = data.current_residence;
        }
        if (data.hometown) {
            const hometownInput = personalInfoForm.querySelector('input[name="hometown"]');
            if (hometownInput) hometownInput.value = data.hometown;
        }
        if (data.alma_mater) {
            const almaMaterInput = personalInfoForm.querySelector('input[name="alma_mater"]');
            if (almaMaterInput) almaMaterInput.value = data.alma_mater;
        }
        
        // Qualifications
        if (data.qualifications) {
            const qualifications = data.qualifications.split('、');
            if (qualifications.includes('宅地建物取引士')) {
                const takkenCheckbox = personalInfoForm.querySelector('input[name="qualification_takken"]');
                if (takkenCheckbox) takkenCheckbox.checked = true;
            }
            if (qualifications.includes('建築士')) {
                const kenchikushiCheckbox = personalInfoForm.querySelector('input[name="qualification_kenchikushi"]');
                if (kenchikushiCheckbox) kenchikushiCheckbox.checked = true;
            }
            // Other qualifications
            const otherQuals = qualifications.filter(q => q !== '宅地建物取引士' && q !== '建築士').join('、');
            if (otherQuals) {
                const otherQualsTextarea = personalInfoForm.querySelector('textarea[name="qualifications_other"]');
                if (otherQualsTextarea) otherQualsTextarea.value = otherQuals;
            }
        }
        
        if (data.hobbies) {
            const hobbiesTextarea = personalInfoForm.querySelector('textarea[name="hobbies"]');
            if (hobbiesTextarea) hobbiesTextarea.value = data.hobbies;
        }
        
        // Free input
        if (data.free_input) {
            try {
                const freeInputData = JSON.parse(data.free_input);
                const container = document.getElementById('free-input-texts-container');
                
                // Handle both old format (text) and new format (texts)
                let texts = [];
                if (freeInputData.texts && Array.isArray(freeInputData.texts)) {
                    texts = freeInputData.texts;
                } else if (freeInputData.text) {
                    texts = [freeInputData.text];
                }
                
                // Clear existing textareas
                if (container) {
                    container.innerHTML = '';
                    
                    // Create textareas for each text
                    if (texts.length === 0) {
                        // If no texts, create one empty textarea
                        texts = [''];
                    }
                    
                    texts.forEach((text, index) => {
                        const item = document.createElement('div');
                        item.className = 'free-input-text-item';
                        item.innerHTML = `
                            <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx">${escapeHtml(text)}</textarea>
                            <button type="button" class="btn-delete-small" onclick="removeFreeInputText(this)" ${texts.length <= 1 ? 'style="display: none;"' : ''}>削除</button>
                        `;
                        container.appendChild(item);
                    });
                }
                
                // Handle images - support both old format (single image) and new format (array)
                const imagesContainer = document.getElementById('free-images-container');
                if (imagesContainer) {
                    imagesContainer.innerHTML = '';
                    
                    let images = [];
                    if (freeInputData.images && Array.isArray(freeInputData.images)) {
                        images = freeInputData.images;
                    } else if (freeInputData.image || freeInputData.image_link) {
                        // Old format: single image
                        images = [{
                            image: freeInputData.image || '',
                            link: freeInputData.image_link || ''
                        }];
                    }
                    
                    // If no images, create one empty item
                    if (images.length === 0) {
                        images = [{ image: '', link: '' }];
                    }
                    
                    images.forEach((imgData, index) => {
                        const item = document.createElement('div');
                        item.className = 'free-image-item';
                        item.innerHTML = `
                            <div class="upload-area" data-upload-id="free_image_${index}">
                                <input type="file" name="free_image[]" accept="image/*" style="display: none;">
                                <div class="upload-preview">${imgData.image ? `<img src="${imgData.image.startsWith('http') ? imgData.image : '../' + imgData.image}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">` : ''}</div>
                                <button type="button" class="btn-outline" onclick="this.closest('.upload-area').querySelector('input[type=\\'file\\']').click()">
                                    画像をアップロード
                                </button>
                                <small>ファイルを選択するか、ここにドラッグ&ドロップしてください<br>対応形式：JPEG、PNG、GIF、WebP</small>
                            </div>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>画像のリンク先URL（任意）</label>
                                <input type="url" name="free_image_link[]" class="form-control" placeholder="https://example.com" value="${escapeHtml(imgData.link || '')}">
                            </div>
                            <button type="button" class="btn-delete-small" onclick="removeFreeImageItem(this)" ${images.length <= 1 ? 'style="display: none;"' : ''}>削除</button>
                        `;
                        imagesContainer.appendChild(item);
                        
                        // Store existing image path in data attribute for later use
                        if (imgData.image) {
                            item.querySelector('.upload-area').dataset.existingImage = imgData.image;
                        }
                        
                        // Initialize file upload handler
                        initializeFreeImageUpload(item);
                    });
                }
            } catch (e) {
                console.error('Error parsing free_input:', e);
            }
        }
    }
    
    // Step 4: Tech Tools - Always display all tools (even if none selected)
    const techTools = (data.tech_tools && Array.isArray(data.tech_tools)) ? data.tech_tools : [];
    console.log('Displaying tech tools:', techTools);
    displayTechTools(techTools);
    
    // Step 5: Communication Methods
    if (data.communication_methods && Array.isArray(data.communication_methods) && data.communication_methods.length > 0) {
        console.log('Displaying communication methods:', data.communication_methods);
        displayCommunicationMethods(data.communication_methods);
    } else {
        console.log('No communication methods to display');
    }
    
    console.log('Form population complete');
}

// Default greetings (same as register.php)
const defaultGreetings = [
    {
        title: '笑顔が増える「住み替え」を叶えます',
        content: '初めての売買で感じる不安や疑問。「あなたに頼んでよかった」と言っていただけるよう、理想の住まい探しと売却を全力で伴走いたします。私は、お客様が描く「10年後の幸せな日常」を第一に考えます。'
    },
    {
        title: '自宅は大きな貯金箱',
        content: '「不動産売買は人生最大の投資」という視点に立ち、物件のメリットだけでなく、将来のリスクやデメリットも隠さずお伝えするのが信条です。感情に流されない、確実な資産形成と納得のいく取引をサポートします。'
    },
    {
        title: 'お客様に「情報武装」をご提案',
        content: '「この価格は妥当なのだろうか？」「もっとよい物件情報は無いのだろうか？」私は全ての情報をお客様に開示いたしますが、お客様に「情報武装」していただく事で、それをさらに担保いたします。他のエージェントにはない、私独自のサービスをご活用ください。'
    },
    {
        title: 'お客様を「3つの疲労」から解放いたします',
        content: '一つ目は、ポータルサイト巡りの「情報収集疲労」。二つ目は、不動産会社への「問い合わせ疲労」、専門知識不足による「判断疲労」です。私がご提供するテックツールで、情報収集は自動化、私が全ての情報を公開しますので多くの不動産会社に問い合わせることも不要、物件情報にAI評価がついているので客観的判断も自動化されます。'
    },
    {
        title: '忙しい子育て世代へ。手間を省くスマート売買',
        content: '「売り」と「買い」を同時に進める住み替えは手続きが煩雑になりがちです。忙しいご夫婦に代わり、書類作成から金融機関との折衝、内覧の調整まで私が窓口となってスムーズに進めます。お子様連れでの内覧や打ち合わせも大歓迎です。ご家族の貴重な時間を奪わないよう、迅速かつ丁寧な段取りをお約束します。'
    }
];

// Display default greetings when no saved greetings exist
function displayDefaultGreetings() {
    const greetingsList = document.getElementById('greetings-list');
    if (!greetingsList) return;
    
    greetingsList.innerHTML = '';
    
    defaultGreetings.forEach((greeting, index) => {
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
        greetingItem.dataset.order = index;
        greetingItem.innerHTML = `
            <div class="greeting-header">
                <span class="greeting-number">${index + 1}</span>
                <div class="greeting-actions">
                    <button type="button" class="btn-move-up" onclick="moveGreeting(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" class="btn-move-down" onclick="moveGreeting(${index}, 'down')" ${index === defaultGreetings.length - 1 ? 'disabled' : ''}>↓</button>
                </div>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
            </div>
            <div class="form-group">
                <label>タイトル</label>
                <input type="text" name="greeting_title[]" class="form-control greeting-title" value="${escapeHtml(greeting.title)}" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
                <textarea name="greeting_content[]" class="form-control greeting-content" rows="4" placeholder="本文">${escapeHtml(greeting.content)}</textarea>
            </div>
        `;
        greetingsList.appendChild(greetingItem);
    });

    // Re-initialize drag and drop after displaying
    setTimeout(function() {
        initializeGreetingDragAndDrop();
        updateGreetingButtons();
    }, 100);
}

// Restore default greetings (button click handler)
function restoreDefaultGreetings() {
    // Remove any existing modal first
    const existingModal = document.querySelector('.modal-overlay');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal
    const modal = document.createElement('div');
    modal.className = 'modal-overlay';
    modal.style.display = 'block';
    modal.innerHTML = `
        <div class="modal-content restore-greetings-modal" style="display:flex !important; flex-direction: column !important; margin :auto !important; margin-top:30% !important;">
            <div class="modal-header-restore">
                <h3>デフォルトの挨拶文を再表示</h3>
            </div>
            <div class="modal-body-restore">
                <p class="modal-message-main">デフォルトの挨拶文を再表示しますか？</p>
                <p class="modal-message-sub">現在の挨拶文は上書きされます。</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" id="confirm-restore-yes">はい</button>
                <button class="modal-btn modal-btn-no" id="confirm-restore-no">いいえ</button>
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
        const yesBtn = document.getElementById('confirm-restore-yes');
        const noBtn = document.getElementById('confirm-restore-no');
        
        if (yesBtn) {
            yesBtn.addEventListener('click', function() {
                closeRestoreModal();
                displayDefaultGreetings();
            });
        }
        
        if (noBtn) {
            noBtn.addEventListener('click', function() {
                closeRestoreModal();
            });
        }
    }, 50);
}

// Close restore modal
function closeRestoreModal() {
    const modal = document.querySelector('.modal-overlay');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => {
            modal.remove();
        }, 300);
    }
}

// Display greetings
function displayGreetings(greetings) {
    const greetingsList = document.getElementById('greetings-list');
    if (!greetingsList) return;
    
    greetingsList.innerHTML = '';
    
    greetings.forEach((greeting, index) => {
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
        greetingItem.dataset.id = greeting.id;
        greetingItem.dataset.order = index;
        greetingItem.innerHTML = `
            <div class="greeting-header">
                <span class="greeting-number">${index + 1}</span>
                <div class="greeting-actions">
                    <button type="button" class="btn-move-up" onclick="moveGreeting(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" class="btn-move-down" onclick="moveGreeting(${index}, 'down')" ${index === greetings.length - 1 ? 'disabled' : ''}>↓</button>
                </div>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
            </div>
            <div class="form-group">
                <label>タイトル</label>
                <input type="text" class="form-control greeting-title" value="${escapeHtml(greeting.title || '')}" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
                <textarea class="form-control greeting-content" rows="4" placeholder="本文">${escapeHtml(greeting.content || '')}</textarea>
            </div>
        `;
        greetingsList.appendChild(greetingItem);
    });

    // Re-initialize drag and drop after displaying
    setTimeout(function() {
        initializeGreetingDragAndDrop();
        updateGreetingButtons();
    }, 100);
}

// Move greeting up/down
function moveGreeting(index, direction) {
    const container = document.getElementById('greetings-list');
    const items = Array.from(container.querySelectorAll('.greeting-item'));

    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateGreetingNumbers();
        updateGreetingButtons();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateGreetingNumbers();
        updateGreetingButtons();
    }
}

// Initialize drag and drop for greeting items
function initializeGreetingDragAndDrop() {
    const container = document.getElementById('greetings-list');
    if (!container) return;

    let draggedElement = null;
    let isInitializing = false; // Flag to prevent infinite loops

    // Make all greeting items draggable
    function makeItemsDraggable() {
        if (isInitializing) return; // Prevent recursive calls
        isInitializing = true;

        const items = container.querySelectorAll('.greeting-item');
        items.forEach((item, index) => {
            // Only set draggable if not already set
            if (!item.hasAttribute('draggable')) {
                item.draggable = true;
            }
            item.dataset.dragIndex = index;
        });

        // Attach event listeners (only if not already attached)
        attachDragListeners();

        isInitializing = false;
    }

    function attachDragListeners() {
        const items = container.querySelectorAll('.greeting-item');
        items.forEach((item) => {
            // Skip if already has drag listeners (check for data attribute)
            if (item.dataset.dragInitialized === 'true') return;

            // Mark as initialized
            item.dataset.dragInitialized = 'true';

            // Drag start
            item.addEventListener('dragstart', function(e) {
                draggedElement = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', this.innerHTML);
            });

            // Drag end
            item.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
                container.querySelectorAll('.greeting-item').forEach(item => {
                    item.classList.remove('drag-over');
                });
            });

            // Drag over
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('drag-over');
                return false;
            });

            // Drag leave
            item.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });

            // Drop
            item.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();

                if (draggedElement !== this && draggedElement !== null) {
                    // Temporarily disconnect observer to prevent loop
                    if (observer) observer.disconnect();

                    const items = Array.from(container.querySelectorAll('.greeting-item'));
                    const targetIndex = items.indexOf(this);
                    const draggedIndexCurrent = items.indexOf(draggedElement);

                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }

                    // Mark dragged element as not initialized so it gets re-initialized
                    draggedElement.dataset.dragInitialized = 'false';
                    this.dataset.dragInitialized = 'false';

                    updateGreetingNumbers();
                    updateGreetingButtons();

                    // Re-attach listeners to moved items
                    attachDragListeners();

                    // Reconnect observer
                    if (observer) {
                        observer.observe(container, {
                            childList: true,
                            subtree: false
                        });
                    }
                }

                this.classList.remove('drag-over');
                draggedElement = null;
                return false;
            });
        });
    }

    // Initial setup
    makeItemsDraggable();

    // Re-initialize when items are added/modified (but not during our own operations)
    const observer = new MutationObserver(function(mutations) {
        if (isInitializing) return; // Skip if we're already initializing

        let shouldReinit = false;
        mutations.forEach(function(mutation) {
            // Only reinit if nodes were actually added/removed (not just moved)
            if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                // Check if any added nodes are new greeting items (not just reordered)
                for (let node of mutation.addedNodes) {
                    if (node.nodeType === 1 && node.classList && node.classList.contains('greeting-item')) {
                        if (!node.dataset.dragInitialized) {
                            shouldReinit = true;
                            break;
                        }
                    }
                }
            }
        });

        if (shouldReinit) {
            makeItemsDraggable();
        }
    });

    observer.observe(container, {
        childList: true,
        subtree: false
    });
}

function updateGreetingNumbers() {
    const items = document.querySelectorAll('#greetings-list .greeting-item');
    items.forEach((item, index) => {
        item.querySelector('.greeting-number').textContent = index + 1;
        item.setAttribute('data-order', index);
    });
}

function updateGreetingButtons() {
    const items = document.querySelectorAll('#greetings-list .greeting-item');
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveGreeting(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveGreeting(${index}, 'down')`);
        }
    });
}

// Display tech tools (Banner format - matching register.php)
function displayTechTools(techTools) {
    const techToolsList = document.getElementById('tech-tools-list');
    if (!techToolsList) {
        console.error('Tech tools list element not found');
        return;
    }
    
    const toolData = {
        'mdb': {
            name: '全国マンションデータベース',
            id: 'tool-mdb-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span></p></div>',
            banner_image: 'assets/images/tech_banner/mdb.jpg'
        },
        'rlp': {
            name: '物件提案ロボ',
            id: 'tool-rlp-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>AI評価付き『物件提案ロボ』は貴社顧客の希望条件に合致する不動産情報を「</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><strong><span style="color: #000000;">」で自動配信します。WEB上に登録になった</span></strong><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span></p></div>',
            banner_image: 'assets/images/tech_banner/rlp.jpg'
        },
        'llp': {
            name: '土地情報ロボ',
            id: 'tool-llp-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>『土地情報ロボ』は貴社顧客の希望条件に合致する不動産情報を「</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><span style="color: #000000;"><strong>」で自動配信します。WEB上に登録になった</strong></span><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span></p></div>',
            banner_image: 'assets/images/tech_banner/llp.jpg'
        },
        'ai': {
            name: 'AIマンション査定',
            id: 'tool-ai-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span></p></div>',
            banner_image: 'assets/images/tech_banner/ai.jpg'
        },
        'slp': {
            name: 'セルフィン',
            id: 'tool-slp-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><strong><span style="color: #000000;">AI評価付き『SelFin（セルフィン）』は消費者自ら</span></strong><span style="color: #ff0000;"><span style="font-weight: 700 !important;">「物件の資産性」を自動判定できる</span></span></span><span style="color: #000000;"><strong><span style="font-size: 14px;">ツールです。「価格の妥当性」「街力」「流動性」「耐震性」「管理費・修繕積立金の妥当性」を自動判定します。また物件提案ロボで配信される物件にはSelFin評価が付随します。</span></strong></span></p></div>',
            banner_image: 'assets/images/tech_banner/slp.jpg'
        },
        'olp': {
            name: 'オーナーコネクト',
            id: 'tool-olp-edit',
            description: '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>オーナーコネクトはマンション所有者様向けのサービスで、</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">誰でも簡単に自宅の資産状況を確認できます。</span></span></span><span style="color: #000000;"><strong>登録されたマンションで新たに売り出し情報が出たらメールでお知らせいたします。</strong></span><span style="color: #000000;"><strong>また、</strong></span><span style="font-weight: 700 !important;"><span style="color: #ff0000;">毎週自宅の資産状況をまとめたレポートメールも送信</span></span><strong><span style="color: #000000;">いたします。</span></strong></span></p></div>',
            banner_image: 'assets/images/tech_banner/olp.jpg'
        }
    };
    
    // All available tools
    const allTools = ['mdb', 'rlp', 'llp', 'ai', 'slp', 'olp'];
    
    techToolsList.innerHTML = '';
    
    // Display all tools in banner card format (same as register.php)
    allTools.forEach((toolType) => {
        const tool = toolData[toolType];
        const existingTool = techTools ? techTools.find(t => t.tool_type === toolType) : null;
        const isActive = existingTool ? (existingTool.is_active === 1 || existingTool.is_active === true) : false;
        
        const toolCard = document.createElement('div');
        toolCard.className = 'tech-tool-banner-card register-tech-card';
        if (isActive) {
            toolCard.classList.add('selected');
        }
        if (existingTool) {
            toolCard.dataset.id = existingTool.id;
        }
        toolCard.dataset.toolType = toolType;

        toolCard.innerHTML = `
            <div class="tech-tool-actions">
                <button type="button" class="btn-move-up" onclick="moveTechTool(${allTools.indexOf(toolType)}, 'up')" ${allTools.indexOf(toolType) === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="btn-move-down" onclick="moveTechTool(${allTools.indexOf(toolType)}, 'down')" ${allTools.indexOf(toolType) === allTools.length - 1 ? 'disabled' : ''}>↓</button>
            </div>
            <input type="checkbox" id="${tool.id}" class="tech-tool-checkbox" ${isActive ? 'checked' : ''}>
            <label for="${tool.id}" class="tech-tool-label">
                <div class="tool-banner-header" style="background-image: url('${tool.banner_image}'); background-size: contain; background-position: center; background-repeat: no-repeat;"></div>
                <div class="tool-banner-content">
                    <div class="tool-description">${tool.description}</div>
                </div>
            </label>
        `;

        techToolsList.appendChild(toolCard);
        
        // Add click event to toggle selection styling
        const checkbox = toolCard.querySelector('.tech-tool-checkbox');
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                toolCard.classList.add('selected');
            } else {
                toolCard.classList.remove('selected');
            }
        });
    });

    // Initialize drag and drop for tech tools
    setTimeout(() => {
        initializeTechToolDragAndDrop();
        updateTechToolButtons();
    }, 100);

    console.log('Tech tools displayed:', techTools);
}

// Display communication methods
function displayCommunicationMethods(methods) {
    if (!methods || !Array.isArray(methods)) return;
    
    // Create a map of method_type to method data for quick lookup
    const methodMap = {};
    methods.forEach(method => {
        methodMap[method.method_type] = method;
    });
    
    // Message apps
    const messageApps = [
        { type: 'line', checkboxName: 'comm_line', inputName: 'comm_line_id' },
        { type: 'messenger', checkboxName: 'comm_messenger', inputName: 'comm_messenger_id' },
        { type: 'whatsapp', checkboxName: 'comm_whatsapp', inputName: 'comm_whatsapp_id' },
        { type: 'plus_message', checkboxName: 'comm_plus_message', inputName: 'comm_plus_message_id' },
        { type: 'chatwork', checkboxName: 'comm_chatwork', inputName: 'comm_chatwork_id' },
        { type: 'andpad', checkboxName: 'comm_andpad', inputName: 'comm_andpad_id' }
    ];
    
    messageApps.forEach(app => {
        const method = methodMap[app.type];
        if (method) {
            const checkbox = document.querySelector(`input[name="${app.checkboxName}"]`);
            const input = document.querySelector(`input[name="${app.inputName}"]`);
            const details = checkbox?.closest('.communication-item')?.querySelector('.comm-details');
            
            if (checkbox) {
                checkbox.checked = method.is_active === 1 || method.is_active === true;
            }
            if (input && (method.method_id || method.method_url)) {
                input.value = method.method_id || method.method_url || '';
            }
            if (details && checkbox && checkbox.checked) {
                details.style.display = 'block';
            }
        }
    });
    
    // SNS apps
    const snsApps = [
        { type: 'instagram', checkboxName: 'comm_instagram', inputName: 'comm_instagram_url' },
        { type: 'facebook', checkboxName: 'comm_facebook', inputName: 'comm_facebook_url' },
        { type: 'twitter', checkboxName: 'comm_twitter', inputName: 'comm_twitter_url' },
        { type: 'youtube', checkboxName: 'comm_youtube', inputName: 'comm_youtube_url' },
        { type: 'tiktok', checkboxName: 'comm_tiktok', inputName: 'comm_tiktok_url' },
        { type: 'note', checkboxName: 'comm_note', inputName: 'comm_note_url' },
        { type: 'pinterest', checkboxName: 'comm_pinterest', inputName: 'comm_pinterest_url' },
        { type: 'threads', checkboxName: 'comm_threads', inputName: 'comm_threads_url' }
    ];
    
    snsApps.forEach(app => {
        const method = methodMap[app.type];
        if (method) {
            const checkbox = document.querySelector(`input[name="${app.checkboxName}"]`);
            const input = document.querySelector(`input[name="${app.inputName}"]`);
            const details = checkbox?.closest('.communication-item')?.querySelector('.comm-details');
            
            if (checkbox) {
                checkbox.checked = method.is_active === 1 || method.is_active === true;
            }
            if (input && method.method_url) {
                input.value = method.method_url || '';
            }
            if (details && checkbox && checkbox.checked) {
                details.style.display = 'block';
            }
        }
    });
    
    // Re-initialize communication checkbox handlers after data is loaded
    setupCommunicationCheckboxes();
}

// Setup navigation
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    const sections = document.querySelectorAll('.edit-section');
    const editNav = document.querySelector('.edit-nav');
    
    // Function to scroll active nav item to center
    function scrollActiveNavToCenter(activeItem) {
        if (!editNav || !activeItem) return;
        
        const navRect = editNav.getBoundingClientRect();
        const itemRect = activeItem.getBoundingClientRect();
        const itemTop = itemRect.top - navRect.top;
        const itemHeight = itemRect.height;
        const navHeight = navRect.height;
        
        // Calculate scroll position to center the item
        const scrollPosition = itemTop - (navHeight / 2) + (itemHeight / 2);
        
        editNav.scrollTo({
            top: scrollPosition,
            behavior: 'smooth'
        });
    }
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href').substring(1);
            
            // Update active nav item
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            
            // Scroll active item to center
            scrollActiveNavToCenter(this);
            
            // Show target section
            sections.forEach(section => {
                section.classList.remove('active');
                if (section.id === targetId + '-section') {
                    section.classList.add('active');
                }
            });
        });
    });
    
    // Scroll active nav item to center on page load
    const activeNavItem = document.querySelector('.nav-item.active');
    if (activeNavItem) {
        setTimeout(() => {
            scrollActiveNavToCenter(activeNavItem);
        }, 100);
    }
    
    // Map navigation IDs to section IDs
    const navMap = {
        'header-greeting': 'header-greeting-section',
        'company-profile': 'company-profile-section',
        'personal-info': 'personal-info-section',
        'tech-tools': 'tech-tools-section',
        'communication': 'communication-section'
    };
}

// Setup file uploads
function setupFileUploads() {
    // Logo upload
    const logoInput = document.getElementById('company_logo');
    if (logoInput) {
        logoInput.addEventListener('change', function(e) {
            handleFileUpload(e, 'company_logo');
        });
    }
    
    // Profile photo upload
    const photoInput = document.getElementById('profile_photo');
    if (photoInput) {
        photoInput.addEventListener('change', function(e) {
            handleFileUpload(e, 'profile_photo');
        });
    }
    
    // Free image upload
    const freeImageInput = document.getElementById('free_image');
    if (freeImageInput) {
        freeImageInput.addEventListener('change', function(e) {
            handleFileUpload(e, 'free_image');
        });
    }
}

// Global cropper instance
let currentCropper = null;
let currentCropFieldName = null;
let currentCropFile = null;

// Handle file upload
async function handleFileUpload(event, fieldName) {
    const file = event.target.files[0];
    if (!file) return;
    
    if (!file.type.startsWith('image/')) {
        showWarning('画像ファイルを選択してください');
        return;
    }
    
    // For logo and profile photo, show cropper modal
    if (fieldName === 'company_logo' || fieldName === 'profile_photo') {
        showImageCropper(file, fieldName, event);
        return;
    }
    
    // For other images, upload directly
    await uploadFileDirectly(file, fieldName, event);
}

// Show image cropper modal
function showImageCropper(file, fieldName, originalEvent) {
    const modal = document.getElementById('image-cropper-modal');
    const cropperImage = document.getElementById('cropper-image');
    
    if (!modal || !cropperImage) {
        // Fallback to direct upload if modal doesn't exist
        uploadFileDirectly(file, fieldName, originalEvent);
        return;
    }
    
    // Store file and field name for later use
    currentCropFile = file;
    currentCropFieldName = fieldName;
    
    // Create object URL for the image
    const imageUrl = URL.createObjectURL(file);
    cropperImage.src = imageUrl;
    
    // Show modal with proper styling
    modal.style.display = 'flex';
    modal.style.alignItems = 'center';
    modal.style.justifyContent = 'center';
    
    // Initialize cropper after image loads
    cropperImage.onload = function() {
        // Destroy existing cropper if any
        if (currentCropper) {
            currentCropper.destroy();
        }
        
        // Initialize cropper with aspect ratio
        const aspectRatio = fieldName === 'company_logo' ? 1 : 1; // Square for both
        currentCropper = new Cropper(cropperImage, {
            aspectRatio: aspectRatio,
            viewMode: 1,
            dragMode: 'move',
            autoCropArea: 0.8,
            restore: false,
            guides: true,
            center: true,
            highlight: false,
            cropBoxMovable: true,
            cropBoxResizable: true,
            toggleDragModeOnDblclick: false,
            responsive: true,
            minContainerWidth: 300,
            minContainerHeight: 300
        });
    };
    
    // Setup cancel button
    const cancelBtn = document.getElementById('crop-cancel-btn');
    if (cancelBtn) {
        cancelBtn.onclick = function() {
            closeImageCropper();
            // Reset file input
            if (originalEvent && originalEvent.target) {
                originalEvent.target.value = '';
            }
        };
    }
    
    // Setup confirm button
    const confirmBtn = document.getElementById('crop-confirm-btn');
    if (confirmBtn) {
        confirmBtn.onclick = function() {
            cropAndUpload(originalEvent);
        };
    }
}

// Close image cropper
function closeImageCropper() {
    const modal = document.getElementById('image-cropper-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.alignItems = '';
        modal.style.justifyContent = '';
    }
    
    if (currentCropper) {
        currentCropper.destroy();
        currentCropper = null;
    }
    
    // Clean up object URL
    const cropperImage = document.getElementById('cropper-image');
    if (cropperImage && cropperImage.src) {
        URL.revokeObjectURL(cropperImage.src);
        cropperImage.src = '';
    }
    
    currentCropFile = null;
    currentCropFieldName = null;
}

// Crop and upload image
async function cropAndUpload(originalEvent) {
    if (!currentCropper || !currentCropFile || !currentCropFieldName) {
        return;
    }
    
    try {
        // Get cropped canvas
        const canvas = currentCropper.getCroppedCanvas({
            width: 800,
            height: 800,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });
        
        // Convert canvas to blob
        canvas.toBlob(async function(blob) {
            if (!blob) {
                showError('画像のトリミングに失敗しました');
                return;
            }
            
            // Create FormData with cropped image
            const formData = new FormData();
            formData.append('file', blob, currentCropFile.name);
            
            // Determine file type
            let fileType = 'photo';
            if (currentCropFieldName === 'company_logo') {
                fileType = 'logo';
            } else if (currentCropFieldName === 'free_image') {
                fileType = 'free';
            }
            formData.append('file_type', fileType);
            
            // Upload cropped image
            await uploadFileDirectly(blob, currentCropFieldName, originalEvent, formData);
            
            // Close cropper
            closeImageCropper();
        }, currentCropFile.type, 0.95);
        
    } catch (error) {
        console.error('Crop error:', error);
        showError('画像のトリミング中にエラーが発生しました');
    }
}

// Upload file directly (without cropping or after cropping)
async function uploadFileDirectly(file, fieldName, originalEvent, existingFormData = null) {
    const formData = existingFormData || new FormData();
    
    if (!existingFormData) {
        formData.append('file', file);
        
        // Determine file type
        let fileType = 'photo';
        if (fieldName === 'company_logo') {
            fileType = 'logo';
        } else if (fieldName === 'free_image') {
            fileType = 'free';
        }
        formData.append('file_type', fileType);
    }
    
    try {
        const response = await fetch('../backend/api/business-card/upload.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show preview with resize info
            const preview = originalEvent.target.closest('.upload-area').querySelector('.upload-preview');
            if (preview) {
                const imagePath = result.data.file_path.startsWith('http') ? result.data.file_path : '../' + result.data.file_path;

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

                preview.innerHTML = `
                    <img src="${imagePath}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">
                    ${resizeInfo}
                `;
            }
            
                // Update business card data
                if (businessCardData) {
                    if (fieldName === 'free_image') {
                        // For free image, update the free_input JSON
                        let freeInputData = {};
                        try {
                            if (businessCardData.free_input) {
                                freeInputData = JSON.parse(businessCardData.free_input);
                            }
                        } catch (e) {
                            console.error('Error parsing free_input:', e);
                        }
                        const fullPath = result.data.file_path;
                        const relativePath = fullPath.split('/php/')[1] || fullPath;
                        freeInputData.image = relativePath;
                        businessCardData.free_input = JSON.stringify(freeInputData);
                    } else {
                        // Extract relative path from absolute URL for database storage
                        let relativePath = result.data.file_path;
                        if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                            // Remove BASE_URL prefix to get relative path
                            // URL format: http://domain/backend/uploads/logo/filename.jpg
                            // We need: backend/uploads/logo/filename.jpg
                            const urlParts = relativePath.split('/');
                            const backendIndex = urlParts.indexOf('backend');
                            if (backendIndex !== -1) {
                                relativePath = urlParts.slice(backendIndex).join('/');
                            } else {
                                // Fallback: try to find 'uploads' directory
                                const uploadsIndex = urlParts.indexOf('uploads');
                                if (uploadsIndex !== -1) {
                                    relativePath = 'backend/' + urlParts.slice(uploadsIndex).join('/');
                                }
                            }
                        }
                        businessCardData[fieldName] = relativePath;
                        console.log('Updated businessCardData[' + fieldName + '] =', relativePath);
                    }
                    window.businessCardData = businessCardData; // Sync with global
                }
                
                // Update preview
                if (businessCardData) {
                updatePreview(businessCardData);
            }

            // Log resize info to console
            if (result.data.was_resized) {
                console.log('Image auto-resized:', result.data);
            }
        } else {
            showError('アップロードに失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Upload error:', error);
        showError('エラーが発生しました');
    }
}

// Add greeting
function addGreeting() {
    const greetingsList = document.getElementById('greetings-list');
    if (!greetingsList) return;
    
    const index = greetingsList.children.length;
    const greetingItem = document.createElement('div');
    greetingItem.className = 'greeting-item';
    greetingItem.dataset.order = index;
    greetingItem.innerHTML = `
        <div class="greeting-header">
            <span class="greeting-number">${index + 1}</span>
            <div class="greeting-actions">
                <button type="button" class="btn-move-up" onclick="moveGreeting(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="btn-move-down" onclick="moveGreeting(${index}, 'down')">↓</button>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
            </div>
        </div>
        <div class="form-group">
            <label>タイトル</label>
            <input type="text" name="greeting_title[]" class="form-control greeting-title" placeholder="タイトル">
        </div>
        <div class="form-group">
            <label>本文</label>
            <textarea name="greeting_content[]" class="form-control greeting-content" rows="4" placeholder="本文"></textarea>
        </div>
    `;
    greetingsList.appendChild(greetingItem);
    updateGreetingNumbers();
    updateGreetingButtons();
    initializeGreetingDragAndDrop();
}

// Save greetings
async function saveGreetings() {
    const greetingItems = document.querySelectorAll('#greetings-list .greeting-item');
    const greetings = [];
    
    greetingItems.forEach((item, index) => {
        // Try multiple selectors for title and content
        const titleInput = item.querySelector('input[name="greeting_title[]"]') || 
                           item.querySelector('.greeting-title');
        const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]') || 
                                item.querySelector('.greeting-content');
        
        const title = titleInput ? titleInput.value.trim() : '';
        const content = contentTextarea ? contentTextarea.value.trim() : '';
        
        // Only add if both title and content have values
        if (title && content) {
            greetings.push({
                title: title,
                content: content,
                display_order: index
            });
        }
    });
    
    // ALWAYS send greetings array, even if empty - this ensures database is updated
    console.log('Saving greetings:', greetings.length === 0 ? 'EMPTY ARRAY' : greetings);
    
    try {
        const response = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ greetings: greetings }), // Send empty array if all deleted
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('保存しました');
            // Reload data to reflect changes
            await loadBusinessCardData();
        } else {
            showError('保存に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
}

// Delete greeting (for existing greetings with ID)
function deleteGreeting(id) {
    showConfirm('この挨拶文を削除しますか？', () => {
        // Remove from DOM
        const item = document.querySelector(`.greeting-item[data-id="${id}"]`);
        if (item) {
            item.remove();
        }
        // Save changes
        saveGreetings();
    });
    return;
    
    // Remove from DOM
    const item = document.querySelector(`.greeting-item[data-id="${id}"]`);
    if (item) {
        item.remove();
    }
    
    // Save changes
    saveGreetings();
}

// Delete greeting (removes the entire greeting-item div and updates database)
function clearGreeting(button) {
    const greetingItem = button.closest('.greeting-item');
    if (!greetingItem) return;
    
    // Check if this is an existing greeting with ID (from database)
    const greetingId = greetingItem.dataset.id;
    
    if (greetingId) {
        // Existing greeting - use deleteGreeting function
        deleteGreeting(greetingId);
    } else {
        // Template greeting - remove from DOM
        greetingItem.remove();
        
        // Update greeting numbers and buttons
        updateGreetingNumbers();
        updateGreetingButtons();
        initializeGreetingDragAndDrop();
        
        // Save changes to database
        saveGreetings();
    }
}

// Toggle tech tool (deprecated - no longer auto-saves)
// Tech tools are now only saved when the "保存" button is clicked
function toggleTechTool(toolTypeOrId, isActive) {
    // Just update visual state - no auto-save
    // The checkbox state is already handled by the browser
    console.log('Tech tool toggled:', toolTypeOrId, isActive);
}

// Save tech tools
async function saveTechTools() {
    const techToolsList = document.getElementById('tech-tools-list');
    if (!techToolsList) {
        console.error('Tech tools list not found');
        return;
    }
    
    // Use .tech-tool-banner-card or .tech-tool-card selector (matches the banner format)
    // Get tools in DOM order (respecting user's reordering)
    const toolCards = Array.from(techToolsList.querySelectorAll('.tech-tool-banner-card, .tech-tool-card'));
    const selectedToolTypes = [];
    
    toolCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            selectedToolTypes.push(card.dataset.toolType);
        }
    });
    
    if (selectedToolTypes.length < 2) {
        showWarning('最低2つ以上のテックツールを選択してください');
        return;
    }
    
    try {
        // Step 1: Generate URLs for selected tools
        const urlResponse = await fetch('../backend/api/tech-tools/generate-urls.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ selected_tools: selectedToolTypes }),
            credentials: 'include'
        });
        
        const urlResult = await urlResponse.json();
        if (!urlResult.success) {
            showError('URL生成に失敗しました: ' + urlResult.message);
            return;
        }
        
        // Step 2: Format tech tools for database - preserve DOM order
        // Create a map of tool_type to tool_url for quick lookup
        const toolUrlMap = {};
        urlResult.data.tech_tools.forEach(tool => {
            toolUrlMap[tool.tool_type] = tool.tool_url;
        });
        
        // Build tech tools array in DOM order
        const techTools = selectedToolTypes.map((toolType, index) => ({
            tool_type: toolType,
            tool_url: toolUrlMap[toolType],
            display_order: index,
            is_active: 1
        }));
        
        console.log('Saving tech tools:', techTools);
        
        // Step 3: Save to database
        const saveResponse = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ tech_tools: techTools }),
            credentials: 'include'
        });
        
        const saveResult = await saveResponse.json();
        
        if (saveResult.success) {
            showSuccess('保存しました');
            loadBusinessCardData(); // Reload data
        } else {
            showError('保存に失敗しました: ' + saveResult.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
}

// Toggle communication method (kept for backward compatibility, but now handled by setupCommunicationCheckboxes)
function toggleCommunicationMethod(id, isActive) {
    // This function is now handled by setupCommunicationCheckboxes
    // But we keep it for any existing calls
    if (id === null) {
        // Handle inline checkbox changes - already handled by setupCommunicationCheckboxes
        return;
    } else {
        const item = document.querySelector(`.communication-item[data-id="${id}"]`);
        if (item) {
            const details = item.querySelector('.comm-details');
            details.style.display = isActive ? 'block' : 'none';
        }
    }
}

// Delete communication method (no longer needed since all methods are always visible)
async function deleteCommunicationMethod(id) {
    // This function is kept for backward compatibility but is no longer used
    // All communication methods are now always visible
    return;
}

// Add communication method (no longer needed since all methods are always visible)
function addCommunicationMethod() {
    // This function is kept for backward compatibility but is no longer used
    // All communication methods are now always visible
    showInfo('すべてのコミュニケーション方法が表示されています。チェックボックスで選択してください。');
}

// Validate URL
function isValidUrl(url) {
    if (!url) return false;
    try {
        const urlObj = new URL(url);
        return urlObj.protocol === 'http:' || urlObj.protocol === 'https:';
    } catch (e) {
        return false;
    }
}

// Save communication methods
async function saveCommunicationMethods() {
    const methods = [];
    const errors = [];
    let displayOrder = 0;
    
    // Message apps
    const messageApps = [
        { key: 'comm_line', type: 'line', idField: 'comm_line_id' },
        { key: 'comm_messenger', type: 'messenger', idField: 'comm_messenger_id' },
        { key: 'comm_whatsapp', type: 'whatsapp', idField: 'comm_whatsapp_id' },
        { key: 'comm_plus_message', type: 'plus_message', idField: 'comm_plus_message_id' },
        { key: 'comm_chatwork', type: 'chatwork', idField: 'comm_chatwork_id' },
        { key: 'comm_andpad', type: 'andpad', idField: 'comm_andpad_id' }
    ];
    
    messageApps.forEach(app => {
        const checkbox = document.querySelector(`input[name="${app.key}"]`);
        if (checkbox && checkbox.checked) {
            const input = document.querySelector(`input[name="${app.idField}"]`);
            const value = input ? input.value.trim() : '';
            
            if (!value) {
                const methodNames = {
                    'line': 'LINE',
                    'messenger': 'Messenger',
                    'whatsapp': 'WhatsApp',
                    'plus_message': '+メッセージ',
                    'chatwork': 'Chatwork',
                    'andpad': 'Andpad'
                };
                errors.push(`${methodNames[app.type] || app.type}のIDまたはURLを入力してください。`);
                if (input) {
                    input.style.borderColor = '#dc3545';
                    input.addEventListener('input', function() {
                        if (this.value.trim()) {
                            this.style.borderColor = '';
                        }
                    });
                }
                return;
            }
            
            if (input) {
                input.style.borderColor = '';
            }
            
            methods.push({
                method_type: app.type,
                method_name: app.type,
                method_url: value.startsWith('http') ? value : '',
                method_id: value.startsWith('http') ? '' : value,
                is_active: 1,
                display_order: displayOrder++
            });
        }
    });
    
    // SNS apps
    const snsApps = [
        { key: 'comm_instagram', type: 'instagram', urlField: 'comm_instagram_url' },
        { key: 'comm_facebook', type: 'facebook', urlField: 'comm_facebook_url' },
        { key: 'comm_twitter', type: 'twitter', urlField: 'comm_twitter_url' },
        { key: 'comm_youtube', type: 'youtube', urlField: 'comm_youtube_url' },
        { key: 'comm_tiktok', type: 'tiktok', urlField: 'comm_tiktok_url' },
        { key: 'comm_note', type: 'note', urlField: 'comm_note_url' },
        { key: 'comm_pinterest', type: 'pinterest', urlField: 'comm_pinterest_url' },
        { key: 'comm_threads', type: 'threads', urlField: 'comm_threads_url' }
    ];
    
    snsApps.forEach(app => {
        const checkbox = document.querySelector(`input[name="${app.key}"]`);
        if (checkbox && checkbox.checked) {
            const input = document.querySelector(`input[name="${app.urlField}"]`);
            const value = input ? input.value.trim() : '';
            
            // Validation for URL-based methods
            if (value && !isValidUrl(value)) {
                const methodNames = {
                    'instagram': 'Instagram',
                    'facebook': 'Facebook',
                    'twitter': 'X (Twitter)',
                    'youtube': 'YouTube',
                    'tiktok': 'TikTok',
                    'note': 'note',
                    'pinterest': 'Pinterest',
                    'threads': 'Threads'
                };
                errors.push(`${methodNames[app.type] || app.type}のURLが無効です。https://で始まる有効なURLを入力してください。`);
                if (input) {
                    input.style.borderColor = '#dc3545';
                    input.addEventListener('input', function() {
                        if (isValidUrl(this.value.trim())) {
                            this.style.borderColor = '';
                        }
                    });
                }
                return;
            }
            
            if (input) {
                input.style.borderColor = '';
            }
            
            methods.push({
                method_type: app.type,
                method_name: app.type,
                method_url: value,
                method_id: '',
                is_active: 1,
                display_order: displayOrder++
            });
        }
    });
    
    // Show validation errors if any
    if (errors.length > 0) {
        showError('入力内容に誤りがあります:\n' + errors.join('\n'));
        return;
    }
    
    // If no methods selected, show warning
    if (methods.length === 0) {
        showWarning('少なくとも1つのコミュニケーション方法を選択してください');
        return;
    }
    
    try {
        const response = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ communication_methods: methods }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showSuccess('保存しました');
            loadBusinessCardData(); // Reload data
        } else {
            showError('保存に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
}

// Update preview
function updatePreview(data) {
    const previewContent = document.getElementById('preview-content');
    if (!previewContent) {
        console.error('Preview content element not found');
        return;
    }
    
    console.log('Updating preview with data:', data);
    
    // Simple preview HTML generation
    let html = '<div class="preview-card" style="background: #fff;">';
    
    // Header
    html += '<div style="text-align: center; margin-bottom: 20px;">';
    if (data.company_logo) {
        const logoPath = data.company_logo.startsWith('http') ? data.company_logo : '../' + data.company_logo;
        html += `<div style="margin-bottom: 10px;"><img src="${logoPath}" alt="ロゴ" style="max-width: 150px; max-height: 150px;"></div>`;
    }
    if (data.company_name) {
        html += `<h1 style="font-size: 1.5rem; margin: 10px 0;">${escapeHtml(data.company_name)}</h1>`;
    }
    html += '</div>';
    
    // Profile
    html += '<div style="display: flex; gap: 20px; margin-bottom: 20px;">';
    if (data.profile_photo) {
        const photoPath = data.profile_photo.startsWith('http') ? data.profile_photo : '../' + data.profile_photo;
        html += `<div><img src="${photoPath}" alt="プロフィール写真" style="max-width: 100px; max-height: 100px; border-radius: 50%;"></div>`;
    }
    html += '<div>';
    if (data.name) {
        html += `<h2 style="font-size: 1.2rem; margin: 0 0 10px 0;">${escapeHtml(data.name)}</h2>`;
    }
    if (data.position) {
        html += `<p style="margin: 5px 0; color: #666;">${escapeHtml(data.position)}</p>`;
    }
    if (data.branch_department) {
        html += `<p style="margin: 5px 0; color: #666;">${escapeHtml(data.branch_department)}</p>`;
    }
    html += '</div>';
    html += '</div>';
    
    // Additional info
    if (data.company_address || data.company_phone || data.mobile_phone) {
        html += '<div style="border-top: 1px solid #eee; padding-top: 15px; margin-top: 15px;">';
        if (data.company_address) {
            html += `<p style="margin: 5px 0;"><strong>住所:</strong> ${escapeHtml(data.company_address)}</p>`;
        }
        if (data.company_phone) {
            html += `<p style="margin: 5px 0;"><strong>電話:</strong> ${escapeHtml(data.company_phone)}</p>`;
        }
        if (data.mobile_phone) {
            html += `<p style="margin: 5px 0;"><strong>携帯:</strong> ${escapeHtml(data.mobile_phone)}</p>`;
        }
        html += '</div>';
    }
    
    html += '</div>';
    
    previewContent.innerHTML = html;
    console.log('Preview updated');
}

// Escape HTML
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Add free input text textarea
function addFreeInputText() {
    const container = document.getElementById('free-input-texts-container');
    if (!container) return;
    
    const newItem = document.createElement('div');
    newItem.className = 'free-input-text-item';
    newItem.innerHTML = `
        <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx"></textarea>
        <button type="button" class="btn-delete-small" onclick="removeFreeInputText(this)">削除</button>
    `;
    
    container.appendChild(newItem);
    
    // Show delete buttons if there are multiple items
    updateFreeInputDeleteButtons();
}

// Remove free input text textarea
function removeFreeInputText(button) {
    const container = document.getElementById('free-input-texts-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-text-item');
    if (items.length <= 1) {
        showWarning('最低1つのテキストエリアが必要です。');
        return;
    }
    
    const item = button.closest('.free-input-text-item');
    if (item) {
        item.remove();
        updateFreeInputDeleteButtons();
    }
}

// Update delete button visibility
function updateFreeInputDeleteButtons() {
    const container = document.getElementById('free-input-texts-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-text-item');
    const deleteButtons = container.querySelectorAll('.btn-delete-small');
    
    if (items.length > 1) {
        deleteButtons.forEach(btn => btn.style.display = 'inline-block');
    } else {
        deleteButtons.forEach(btn => btn.style.display = 'none');
    }
}

// Add free image item
function addFreeImageItem() {
    const container = document.getElementById('free-images-container');
    if (!container) return;
    
    const itemCount = container.querySelectorAll('.free-image-item').length;
    const newItem = document.createElement('div');
    newItem.className = 'free-image-item';
    newItem.innerHTML = `
        <div class="upload-area" data-upload-id="free_image_${itemCount}">
            <input type="file" name="free_image[]" accept="image/*" style="display: none;">
            <div class="upload-preview"></div>
            <button type="button" class="btn-outline" onclick="this.closest('.upload-area').querySelector('input[type=\\'file\\']').click()">
                画像をアップロード
            </button>
            <small>ファイルを選択するか、ここにドラッグ&ドロップしてください<br>対応形式：JPEG、PNG、GIF、WebP</small>
        </div>
        <div class="form-group" style="margin-top: 0.5rem;">
            <label>画像のリンク先URL（任意）</label>
            <input type="url" name="free_image_link[]" class="form-control" placeholder="https://example.com">
        </div>
        <button type="button" class="btn-delete-small" onclick="removeFreeImageItem(this)">削除</button>
    `;
    
    container.appendChild(newItem);
    
    // Initialize file input handler for the new item
    initializeFreeImageUpload(newItem);
    
    // Show delete buttons if there are multiple items
    updateFreeImageDeleteButtons();
}

// Remove free image item
function removeFreeImageItem(button) {
    const container = document.getElementById('free-images-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-image-item');
    if (items.length <= 1) {
        showWarning('最低1つの画像項目が必要です。');
        return;
    }
    
    const item = button.closest('.free-image-item');
    if (item) {
        item.remove();
        updateFreeImageDeleteButtons();
    }
}

// Update delete button visibility for free images
function updateFreeImageDeleteButtons() {
    const container = document.getElementById('free-images-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-image-item');
    const deleteButtons = container.querySelectorAll('#free-images-container .btn-delete-small');
    
    if (items.length > 1) {
        deleteButtons.forEach(btn => btn.style.display = 'inline-block');
    } else {
        deleteButtons.forEach(btn => btn.style.display = 'none');
    }
}

// Initialize file upload handler for free image items
function initializeFreeImageUpload(item) {
    const fileInput = item.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file) {
            const reader = new FileReader();
            reader.onload = (event) => {
                const preview = item.querySelector('.upload-preview');
                if (preview) {
                    const img = new Image();
                    img.onload = () => {
                        const resizeNote = (img.width > 1200 || img.height > 1200) 
                            ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大1200×1200px)</p>` 
                            : '';
                        preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">${resizeNote}`;
                    };
                    img.src = event.target.result;
                }
            };
            reader.readAsDataURL(file);
        }
    });
}

// Move tech tool up or down
function moveTechTool(index, direction) {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateTechToolButtons();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateTechToolButtons();
    }
}

// Update tech tool move buttons
function updateTechToolButtons() {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveTechTool(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveTechTool(${index}, 'down')`);
        }
    });
}

// Initialize drag and drop for tech tools
function initializeTechToolDragAndDrop() {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    let draggedElement = null;
    let isInitializing = false;
    
    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;
        
        const items = container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card');
        items.forEach((item, index) => {
            if (!item.hasAttribute('draggable')) {
                item.draggable = true;
            }
            item.dataset.dragIndex = index;
        });
        
        attachDragListeners();
        isInitializing = false;
    }
    
    function attachDragListeners() {
        const items = container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card');
        items.forEach((item) => {
            if (item.dataset.dragInitialized === 'true') return;
            item.dataset.dragInitialized = 'true';
            
            item.addEventListener('dragstart', function(e) {
                draggedElement = this;
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/html', this.innerHTML);
            });
            
            item.addEventListener('dragend', function(e) {
                this.classList.remove('dragging');
                container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card').forEach(item => {
                    item.classList.remove('drag-over');
                });
            });
            
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('drag-over');
                return false;
            });
            
            item.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });
            
            item.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                if (draggedElement !== this && draggedElement !== null) {
                    if (observer) observer.disconnect();
                    
                    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card, .tech-tool-card'));
                    const targetIndex = items.indexOf(this);
                    const draggedIndexCurrent = items.indexOf(draggedElement);
                    
                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }
                    
                    draggedElement.dataset.dragInitialized = 'false';
                    this.dataset.dragInitialized = 'false';
                    
                    updateTechToolButtons();
                    attachDragListeners();
                    
                    if (observer) {
                        observer.observe(container, {
                            childList: true,
                            subtree: false
                        });
                    }
                }
                
                this.classList.remove('drag-over');
                draggedElement = null;
                return false;
            });
        });
    }
    
    makeItemsDraggable();
    
    const observer = new MutationObserver(function(mutations) {
        if (isInitializing) return;
        
        let shouldReinit = false;
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0 || mutation.removedNodes.length > 0) {
                for (let node of mutation.addedNodes) {
                    if (node.nodeType === 1 && node.classList && (node.classList.contains('tech-tool-banner-card') || node.classList.contains('tech-tool-card'))) {
                        if (!node.dataset.dragInitialized) {
                            shouldReinit = true;
                            break;
                        }
                    }
                }
            }
        });
        
        if (shouldReinit) {
            makeItemsDraggable();
        }
    });
    
    observer.observe(container, {
        childList: true,
        subtree: false
    });
}

