// Global cropper instance for edit page
let editCropper = null;
let editCropFieldName = null;
let editCropFile = null;
let editCropOriginalEvent = null;
let editImageObjectURL = null; // Track object URL for cleanup
let editCropperImageLoadHandler = null; // Track onload handler

// Move communication item up or down
function moveCommunicationItem(index, direction, type) {
    const gridId = type === 'message' ? 'message-apps-grid' : 'sns-grid';
    const container = document.getElementById(gridId);
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateCommunicationButtons(type);
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateCommunicationButtons(type);
    }
}

// Update communication item move buttons
function updateCommunicationButtons(type) {
    const gridId = type === 'message' ? 'message-apps-grid' : 'sns-grid';
    const container = document.getElementById(gridId);
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveCommunicationItem(${index}, 'up', '${type}')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveCommunicationItem(${index}, 'down', '${type}')`);
        }
    });
}

// Initialize drag and drop for communication items
function initializeCommunicationDragAndDrop(type) {
    const gridId = type === 'message' ? 'message-apps-grid' : 'sns-grid';
    const container = document.getElementById(gridId);
    if (!container) return;
    
    let draggedElement = null;
    let isInitializing = false;
    
    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;
        
        const items = container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`);
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
        const items = container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`);
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
                container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`).forEach(item => {
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
                    
                    const items = Array.from(container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`));
                    const targetIndex = items.indexOf(this);
                    const draggedIndexCurrent = items.indexOf(draggedElement);
                    
                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }
                    
                    draggedElement.dataset.dragInitialized = 'false';
                    this.dataset.dragInitialized = 'false';
                    
                    updateCommunicationButtons(type);
                    attachDragListeners();
                    
                    if (observer) {
                        observer.observe(container, {
                            childList: true,
                            subtree: false
                        });
                    }
                }
            });
        });
    }
    
    // MutationObserver to handle dynamic changes
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
                makeItemsDraggable();
            }
        });
    });
    
    makeItemsDraggable();
    
    observer.observe(container, {
        childList: true,
        subtree: false
    });
}

// Global variable to store business card data
let businessCardData = null;

// Load business card data from server (without reloading)
async function loadBusinessCardData() {
    try {
        const response = await fetch('backend/api/business-card/get.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        if (!response.ok) {
            console.log('No existing data found or not logged in');
            return;
        }
        
        const result = await response.json();
        console.log('Loaded business card data:', result);
        
        if (result.success && result.data) {
            // Update the global businessCardData variable
            businessCardData = result.data;
            // Also set window.businessCardData for easier access from other scripts
            window.businessCardData = result.data;
            // Note: We don't reload the page to avoid browser warnings
            // The form data is already saved, so we just need to navigate to the next step
        }
    } catch (error) {
        console.error('Error loading business card data:', error);
    }
}

// Load existing business card data on page load and populate forms
async function loadExistingBusinessCardData() {
    try {
        const response = await fetch('backend/api/business-card/get.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        if (!response.ok) {
            console.log('No existing data found or not logged in');
            return;
        }
        
        const result = await response.json();
        console.log('Loaded business card data:', result);
        
        if (result.success && result.data) {
            businessCardData = result.data;
            // Also set window.businessCardData for easier access from other scripts
            window.businessCardData = result.data;
            populateEditForms(businessCardData);
        }
    } catch (error) {
        console.error('Error loading business card data:', error);
    }
}

// Populate edit forms with existing data
function populateEditForms(data) {
    console.log('Populating edit forms with:', data);
    
    // Step 1: Header & Greeting
    if (data.company_name) {
        const companyNameInput = document.querySelector('#header-greeting-form input[name="company_name"]');
        if (companyNameInput) {
            // Trim to prevent unwanted periods/whitespace
            companyNameInput.value = String(data.company_name).trim();
        }
    }
    
    // Logo preview
    if (data.company_logo) {
        // Try multiple selectors to find the upload area
        let logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
        if (!logoUploadArea) {
            // Fallback: try to find by ID
            const logoInput = document.getElementById('company_logo');
            if (logoInput) {
                logoUploadArea = logoInput.closest('.upload-area');
            }
        }
        
        if (logoUploadArea) {
            const logoPreview = logoUploadArea.querySelector('.upload-preview');
            if (logoPreview) {
                // Construct correct image path using BASE_URL
                let logoPath = data.company_logo;
                // Remove BASE_URL if already included to avoid duplication
                if (typeof window !== 'undefined' && window.BASE_URL && logoPath.startsWith(window.BASE_URL)) {
                    logoPath = logoPath.replace(window.BASE_URL + '/', '').replace(window.BASE_URL, '');
                }
                // Construct full URL
                if (!logoPath.startsWith('http')) {
                    // Use BASE_URL if available, otherwise construct relative path
                    if (typeof window !== 'undefined' && window.BASE_URL) {
                        logoPath = window.BASE_URL + '/' + logoPath.replace(/^\/+/, '');
                    } else {
                        // Fallback: If path starts with backend/, add ../ prefix
                        if (logoPath.startsWith('backend/')) {
                            logoPath = '../' + logoPath;
                        } else if (!logoPath.startsWith('../')) {
                            logoPath = '../' + logoPath;
                        }
                    }
                }
                logoPreview.innerHTML = `<img src="${logoPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;" onerror="this.style.display='none';">`;
                // Store the original relative path from database
                logoUploadArea.dataset.existingImage = data.company_logo;
            } else {
                console.warn('Logo preview element not found');
            }
        } else {
            console.warn('Logo upload area not found');
        }
    }
        
    // Profile photo preview
    if (data.profile_photo) {
        // Try multiple selectors to find the upload area
        let photoUploadArea = document.querySelector('[data-upload-id="profile_photo"]');
        if (!photoUploadArea) {
            // Fallback: try to find by ID
            const photoInput = document.getElementById('profile_photo');
            if (photoInput) {
                photoUploadArea = photoInput.closest('.upload-area');
            }
        }
        
        if (photoUploadArea) {
            const photoPreview = photoUploadArea.querySelector('.upload-preview');
            if (photoPreview) {
                // Construct correct image path using BASE_URL
                let photoPath = data.profile_photo;
                // Remove BASE_URL if already included to avoid duplication
                if (typeof window !== 'undefined' && window.BASE_URL && photoPath.startsWith(window.BASE_URL)) {
                    photoPath = photoPath.replace(window.BASE_URL + '/', '').replace(window.BASE_URL, '');
                }
                // Construct full URL
                if (!photoPath.startsWith('http')) {
                    // Use BASE_URL if available, otherwise construct relative path
                    if (typeof window !== 'undefined' && window.BASE_URL) {
                        photoPath = window.BASE_URL + '/' + photoPath.replace(/^\/+/, '');
                    } else {
                        // Fallback: If path starts with backend/, add ../ prefix
                        if (photoPath.startsWith('backend/')) {
                            photoPath = '../' + photoPath;
                        } else if (!photoPath.startsWith('../')) {
                            photoPath = '../' + photoPath;
                        }
                    }
                }
                photoPreview.innerHTML = `<img src="${photoPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;" onerror="this.style.display='none';">`;
                // Store the original relative path from database
                photoUploadArea.dataset.existingImage = data.profile_photo;
            } else {
                console.warn('Profile photo preview element not found');
            }
        } else {
            console.warn('Profile photo upload area not found');
        }
    }
        
        // Greetings - ALWAYS clear first, then populate based on data
    const greetingsContainer = document.getElementById('greetings-list');
    if (greetingsContainer) {
        greetingsContainer.innerHTML = '';
            
            if (data.greetings && Array.isArray(data.greetings) && data.greetings.length > 0) {
            console.log('Displaying greetings from database:', data.greetings);
            displayGreetingsForEdit(data.greetings);
        }
        // If no greetings, keep the default ones from PHP
    }
    
    // Step 2: Company Profile
        if (data.real_estate_license_prefecture) {
        const prefSelect = document.getElementById('license_prefecture');
        if (prefSelect) prefSelect.value = data.real_estate_license_prefecture;
        }
        if (data.real_estate_license_renewal_number) {
        const renewalSelect = document.getElementById('license_renewal');
            if (renewalSelect) renewalSelect.value = data.real_estate_license_renewal_number;
        }
        if (data.real_estate_license_registration_number) {
        const regInput = document.getElementById('license_registration');
        if (regInput) regInput.value = data.real_estate_license_registration_number;
        }
        if (data.company_name) {
        const companyProfileInput = document.querySelector('input[name="company_name_profile"]');
        if (companyProfileInput) companyProfileInput.value = data.company_name;
        }
        if (data.company_postal_code) {
        const postalInput = document.getElementById('company_postal_code');
        if (postalInput) postalInput.value = data.company_postal_code;
        }
        if (data.company_address) {
        const addressInput = document.getElementById('company_address');
            if (addressInput) addressInput.value = data.company_address;
        }
        if (data.company_phone) {
        const phoneInput = document.querySelector('input[name="company_phone"]');
            if (phoneInput) phoneInput.value = data.company_phone;
        }
        if (data.company_website) {
        const websiteInput = document.querySelector('input[name="company_website"]');
            if (websiteInput) websiteInput.value = data.company_website;
    }
    
    // Step 3: Personal Information
        if (data.branch_department) {
        const branchInput = document.querySelector('input[name="branch_department"]');
        if (branchInput) branchInput.value = data.branch_department;
        }
        if (data.position) {
        const positionInput = document.querySelector('input[name="position"]');
            if (positionInput) positionInput.value = data.position;
        }
        
        // Name (split into last_name and first_name)
        if (data.name) {
        const nameParts = data.name.trim().split(/\s+/);
            const lastNameInput = document.getElementById('edit_last_name');
            const firstNameInput = document.getElementById('edit_first_name');
            if (lastNameInput && firstNameInput) {
                if (nameParts.length >= 2) {
                    lastNameInput.value = nameParts[0];
                    firstNameInput.value = nameParts.slice(1).join(' ');
                } else {
                    lastNameInput.value = data.name;
                }
            }
        }
        
    // Name Romaji
        if (data.name_romaji) {
        const romajiParts = data.name_romaji.trim().split(/\s+/);
            const lastNameRomajiInput = document.getElementById('edit_last_name_romaji');
            const firstNameRomajiInput = document.getElementById('edit_first_name_romaji');
            if (lastNameRomajiInput && firstNameRomajiInput) {
                if (romajiParts.length >= 2) {
                    lastNameRomajiInput.value = romajiParts[0];
                    firstNameRomajiInput.value = romajiParts.slice(1).join(' ');
                } else {
                    lastNameRomajiInput.value = data.name_romaji;
                }
            }
        }
        
        if (data.mobile_phone) {
        const mobileInput = document.querySelector('input[name="mobile_phone"]');
        if (mobileInput) mobileInput.value = data.mobile_phone;
        }
        if (data.birth_date) {
        const birthInput = document.querySelector('input[name="birth_date"]');
        if (birthInput) birthInput.value = data.birth_date;
        }
        if (data.current_residence) {
        const residenceInput = document.querySelector('input[name="current_residence"]');
            if (residenceInput) residenceInput.value = data.current_residence;
        }
        if (data.hometown) {
        const hometownInput = document.querySelector('input[name="hometown"]');
            if (hometownInput) hometownInput.value = data.hometown;
        }
        if (data.alma_mater) {
        const almaMaterInput = document.querySelector('input[name="alma_mater"]');
            if (almaMaterInput) almaMaterInput.value = data.alma_mater;
        }
        
        // Qualifications
        if (data.qualifications) {
            const qualifications = data.qualifications.split('、');
            if (qualifications.includes('宅地建物取引士')) {
                const takkenCheckbox = document.querySelector('input[name="qualification_takken"]');
                if (takkenCheckbox) takkenCheckbox.checked = true;
            }
            // Only check one architect qualification (mutual exclusivity)
            // Priority: 一級建築士 > 二級建築士 > 木造建築士
            if (qualifications.includes('一級建築士')) {
                const kenchikushi1Checkbox = document.querySelector('input[name="qualification_kenchikushi_1"]');
                if (kenchikushi1Checkbox) kenchikushi1Checkbox.checked = true;
            } else if (qualifications.includes('二級建築士')) {
                const kenchikushi2Checkbox = document.querySelector('input[name="qualification_kenchikushi_2"]');
                if (kenchikushi2Checkbox) kenchikushi2Checkbox.checked = true;
            } else if (qualifications.includes('木造建築士')) {
                const kenchikushi3Checkbox = document.querySelector('input[name="qualification_kenchikushi_3"]');
                if (kenchikushi3Checkbox) kenchikushi3Checkbox.checked = true;
            }
            // Filter out the 4 main qualifications to get "other" qualifications
            const mainQuals = ['宅地建物取引士', '一級建築士', '二級建築士', '木造建築士'];
            const otherQuals = qualifications.filter(q => !mainQuals.includes(q)).join('、');
            if (otherQuals) {
                const otherInput = document.querySelector('textarea[name="qualifications_other"]');
                if (otherInput) otherInput.value = otherQuals;
            }
        }
        
        if (data.hobbies) {
        const hobbiesInput = document.querySelector('textarea[name="hobbies"]');
        if (hobbiesInput) hobbiesInput.value = data.hobbies;
        }
        
    // Free input - Populate paired items (text + image)
        if (data.free_input) {
            try {
                const freeInputData = JSON.parse(data.free_input);
            const container = document.getElementById('free-input-pairs-container');
                
            if (container) {
                // Handle both old format and new format
                let texts = [];
                let images = [];

                if (freeInputData.texts && Array.isArray(freeInputData.texts)) {
                    texts = freeInputData.texts;
                } else if (freeInputData.text) {
                    texts = [freeInputData.text];
                }
                
                    if (freeInputData.images && Array.isArray(freeInputData.images)) {
                        images = freeInputData.images;
                    } else if (freeInputData.image || freeInputData.image_link) {
                        images = [{
                            image: freeInputData.image || '',
                            link: freeInputData.image_link || ''
                        }];
                    }
                    
                // Ensure we have at least one pair
                const pairCount = Math.max(texts.length, images.length, 1);

                // Clear existing items
                container.innerHTML = '';

                // Create paired items
                for (let i = 0; i < pairCount; i++) {
                    const text = texts[i] || '';
                    const imgData = images[i] || { image: '', link: '' };

                    const pairItem = document.createElement('div');
                    pairItem.className = 'free-input-pair-item';
                    if (i > 0) {
                        pairItem.style.marginTop = '2rem';
                        pairItem.style.paddingTop = '2rem';
                        pairItem.style.borderTop = '1px solid #e0e0e0';
                    }

                    pairItem.innerHTML = `
                        <div class="free-input-pair-header">
                            <span class="free-input-pair-number">${i + 1}</span>
                            <div class="free-input-pair-actions">
                                <button type="button" class="btn-move-up" onclick="moveFreeInputPair(${i}, 'up')" ${i === 0 ? 'disabled' : ''}>↑</button>
                                <button type="button" class="btn-move-down" onclick="moveFreeInputPair(${i}, 'down')" ${i === pairCount - 1 ? 'disabled' : ''}>↓</button>
                            </div>
                            <button type="button" class="btn-delete" onclick="removeFreeInputPair(this)" ${pairCount <= 1 ? 'style="display: none;"' : ''}>削除</button>
                        </div>
                        <!-- Text Input -->
                        <div class="form-group">
                            <label>テキスト</label>
                            <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx">${escapeHtml(text)}</textarea>
                        </div>
                        <!-- Image/Banner Input -->
                        <div class="form-group">
                            <label>画像・バナー（リンク付き画像）</label>
                            <div class="upload-area" data-upload-id="free_image_${i}">
                                <input type="file" name="free_image[]" accept="image/*" style="display: none;">
                                <div class="upload-preview">${(() => {
                                    if (!imgData.image) return '';
                                    let imgPath = imgData.image;
                                    if (!imgPath.startsWith('http')) {
                                        // Use BASE_URL if available
                                        if (typeof window !== 'undefined' && window.BASE_URL) {
                                            imgPath = window.BASE_URL + '/' + imgPath.replace(/^\/+/, '');
                                        } else {
                                            // Fallback: If path starts with backend/, add ../ prefix
                                            if (imgPath.startsWith('backend/')) {
                                                imgPath = '../' + imgPath;
                                            } else if (!imgPath.startsWith('../')) {
                                                imgPath = '../' + imgPath;
                                            }
                                        }
                                    }
                                    return `<img src="${imgPath}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                                })()}</div>
                                <button type="button" class="btn-outline" onclick="this.closest('.upload-area').querySelector('input[type=\\'file\\']').click()">
                                    画像をアップロード
                                </button>
                                <small>ファイルを選択するか、ここにドラッグ&ドロップしてください<br>対応形式：JPEG、PNG、GIF、WebP</small>
                            </div>
                            <div class="form-group" style="margin-top: 0.5rem;">
                                <label>画像のリンク先URL（任意）</label>
                                <input type="url" name="free_image_link[]" class="form-control" placeholder="https://example.com" value="${escapeHtml(imgData.link || '')}">
                            </div>
                        </div>
                        `;

                    container.appendChild(pairItem);
                        
                        // Store existing image path in data attribute for later use
                        if (imgData.image) {
                        pairItem.querySelector('.upload-area').dataset.existingImage = imgData.image;
                        }
                        
                        // Initialize file upload handler
                        initializeFreeImageUpload(pairItem);
                        
                        // Initialize drag and drop for upload area
                        const uploadArea = pairItem.querySelector('.upload-area');
                        if (uploadArea) {
                            initializeDragAndDropForUploadArea(uploadArea);
                        }
                }
                
                // Initialize drag and drop for reordering after all items are loaded
                setTimeout(() => {
                    initializeFreeInputPairDragAndDrop();
                    updateFreeInputPairButtons();
                    updateFreeInputPairNumbers();
                }, 100);
                }
            } catch (e) {
                console.error('Error parsing free_input:', e);
        }
    }
    
    // Step 4: Tech Tools - Load tech tools (always load, even if no saved tools)
    loadTechTools(data.tech_tools || []);
    
    // Step 5: Communication Methods
    if (data.communication_methods && Array.isArray(data.communication_methods)) {
        data.communication_methods.forEach(method => {
            // Check the checkbox
            const checkbox = document.querySelector(`input[name="comm_${method.method_type}"]`);
            if (checkbox) {
                checkbox.checked = method.is_active;
                
                // Show details div
                const item = checkbox.closest('.communication-item');
                if (item) {
                    const details = item.querySelector('.comm-details');
                    if (details && method.is_active) {
                        details.style.display = 'block';
                        
                        // Fill in the value
                        const input = details.querySelector('input');
                        if (input) {
                            input.value = method.method_url || method.method_id || '';
                        }
                    }
                }
            }
        });
        // Re-initialize communication checkbox handlers
        setupCommunicationCheckboxes();
    }
    
    console.log('Edit forms populated');
    
    // Set up mutual exclusivity for architect checkboxes after populating data
    setTimeout(() => {
        setupArchitectCheckboxMutualExclusivity();
    }, 100);
}

// Display greetings from database for edit page
function displayGreetingsForEdit(greetings) {
    const greetingsContainer = document.getElementById('greetings-list');
    if (!greetingsContainer) return;
    
    greetingsContainer.innerHTML = '';
    
    greetings.forEach((greeting, index) => {
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
        greetingItem.dataset.id = greeting.id || '';
        greetingItem.dataset.order = index;
        greetingItem.innerHTML = `
            <div class="greeting-header">
                <span class="greeting-number">${index + 1}</span>
                <div class="greeting-actions">
                    <button type="button" class="btn-move-up" onclick="moveGreeting(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" class="btn-move-down" onclick="moveGreeting(${index}, 'down')" ${index === greetings.length - 1 ? 'disabled' : ''}>↓</button>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
                </div>
            </div>
            <div class="form-group">
                <label>タイトル</label>
                <input type="text" name="greeting_title[]" class="form-control" value="${escapeHtml(greeting.title || '')}" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
                <textarea name="greeting_content[]" class="form-control" rows="4" placeholder="本文">${escapeHtml(greeting.content || '')}</textarea>
            </div>
        `;
        greetingsContainer.appendChild(greetingItem);
    });

    // Re-initialize drag and drop after displaying
    setTimeout(function() {
        initializeGreetingDragAndDrop();
        updateGreetingButtons();
    }, 100);
}

// Escape HTML to prevent XSS
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Load tech tools and display them
function loadTechTools(savedTechTools) {
    const techToolsList = document.getElementById('tech-tools-list');
    if (!techToolsList) return;
    
    // Tech tool definitions (same as register.js)
    const techToolNames = {
        'mdb': '全国マンションデータベース',
        'rlp': '物件提案ロボ',
        'llp': '土地情報ロボ',
        'ai': 'AIマンション査定',
        'slp': 'セルフィン',
        'olp': 'オーナーコネクト',
        'alp': '統合LP'
    };
    
    const techToolDescriptions = {
        'mdb': '全国のマンション情報を検索・比較できます',
        'rlp': 'お客様の条件に合った物件を自動で提案します',
        'llp': '土地情報を簡単に検索・比較できます',
        'ai': 'AIがマンションの適正価格を査定します',
        'slp': '不動産売買をサポートする総合ツール',
        'olp': 'オーナー向けの不動産管理ツール',
        'alp': '複数のテックツールを統合したLP'
    };
    
    const techToolBanners = {
        'mdb': 'assets/images/tech_banner/mdb.jpg',
        'rlp': 'assets/images/tech_banner/rlp.jpg',
        'llp': 'assets/images/tech_banner/llp.jpg',
        'ai': 'assets/images/tech_banner/ai.jpg',
        'slp': 'assets/images/tech_banner/slp.jpg',
        'olp': 'assets/images/tech_banner/olp.jpg',
        'alp': 'assets/images/tech_banner/alp.jpg'
    };
    
    // Define the default order of tech tools (used for unselected tools)
    const toolOrder = ['mdb', 'rlp', 'llp', 'ai', 'slp', 'olp', 'alp'];
    
    // Sort saved tech tools by display_order
    const sortedSavedTools = (savedTechTools && Array.isArray(savedTechTools)) 
        ? [...savedTechTools].sort((a, b) => {
            const orderA = a.display_order !== undefined ? parseInt(a.display_order) : 999;
            const orderB = b.display_order !== undefined ? parseInt(b.display_order) : 999;
            return orderA - orderB;
        })
        : [];
    
    // Create a map of saved tech tools by tool_type for quick lookup
    const savedToolsMap = {};
    sortedSavedTools.forEach(tool => {
        savedToolsMap[tool.tool_type] = tool;
    });
    
    // Build final order: selected tools (in display_order) first, then unselected tools (in default order)
    const selectedToolTypes = sortedSavedTools.map(tool => tool.tool_type);
    const unselectedToolTypes = toolOrder.filter(toolType => !selectedToolTypes.includes(toolType));
    const finalOrder = [...selectedToolTypes, ...unselectedToolTypes];
    
    techToolsList.innerHTML = '';
    
    // Display tech tools in the final order
    finalOrder.forEach((toolType, index) => {
        const savedTool = savedToolsMap[toolType];
        const isActive = savedTool?.is_active || false;
        const toolName = techToolNames[toolType] || toolType;
        const toolDescription = techToolDescriptions[toolType] || '';
        const toolBanner = techToolBanners[toolType] || 'assets/images/tech_banner/default.jpg';
        
        const toolCard = document.createElement('div');
        toolCard.className = 'tech-tool-banner-card';
        toolCard.dataset.toolType = toolType;
        if (isActive) {
            toolCard.classList.add('selected');
        }
        toolCard.innerHTML = `
            <div class="tech-tool-header">
                <span class="tool-number">${index + 1}</span>
                <div class="tool-actions">
                    <button type="button" class="btn-move-up" onclick="moveTechTool(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                    <button type="button" class="btn-move-down" onclick="moveTechTool(${index}, 'down')" ${index === finalOrder.length - 1 ? 'disabled' : ''}>↓</button>
            </div>
            </div>
            <label class="tech-tool-checkbox">
                <input type="checkbox" name="tech_tools[]" value="${toolType}" ${isActive ? 'checked' : ''}>
                <div class="tech-tool-content">
                    <img src="${toolBanner}" alt="${toolName}" class="tech-tool-banner">
                    <div class="tech-tool-info">
                        <h4>${escapeHtml(toolName)}</h4>
                        <p>${escapeHtml(toolDescription)}</p>
                </div>
        </div>
            </label>
        `;
        
        // Add click handler to toggle checkbox and update card state
        const checkbox = toolCard.querySelector('input[type="checkbox"]');
        const cardElement = toolCard;
        toolCard.addEventListener('click', function(e) {
            // Don't toggle if clicking on move buttons
            if (e.target.closest('.tool-actions')) {
                return;
            }
            checkbox.checked = !checkbox.checked;
            if (checkbox.checked) {
                cardElement.classList.add('selected');
            } else {
                cardElement.classList.remove('selected');
            }
        });
        
        techToolsList.appendChild(toolCard);
    });
    
    // Initialize tech tool drag and drop
    setTimeout(() => {
        initializeTechToolDragAndDrop();
        updateTechToolButtons();
    }, 100);
}

// Save tech tools
// Prevent double submission
let isSavingTechTools = false;

async function saveTechTools() {
    // Prevent double submission (especially important for mobile)
    if (isSavingTechTools) {
        console.log('Already saving tech tools, ignoring duplicate submission');
        return;
    }
    isSavingTechTools = true;
    
    // Disable button and show loading state
    const saveButton = document.querySelector('button[onclick="saveTechTools()"]');
    const originalButtonText = saveButton ? saveButton.textContent : '';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = '保存中...';
    }
    
    try {
        const selectedCheckboxes = document.querySelectorAll('#tech-tools-list input[name="tech_tools[]"]:checked');
        
        if (selectedCheckboxes.length < 2) {
            showError('最低2つ以上のテックツールを選択してください');
            isSavingTechTools = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
            return;
        }
        
        // Get selected tools in DOM order
        const techToolsList = document.getElementById('tech-tools-list');
        const selectedTools = [];
        techToolsList.querySelectorAll('.tech-tool-banner-card').forEach(card => {
            const checkbox = card.querySelector('input[name="tech_tools[]"]');
            if (checkbox && checkbox.checked) {
                selectedTools.push(checkbox.value);
            }
        });
        
        // Get tool URLs from API with timeout
        const urlController = new AbortController();
        const urlTimeoutId = setTimeout(() => urlController.abort(), 30000);
        
        const urlResponse = await fetch('backend/api/tech-tools/generate-urls.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ selected_tools: selectedTools }),
            credentials: 'include',
            signal: urlController.signal
        });
        clearTimeout(urlTimeoutId);
        
        if (!urlResponse.ok) {
            throw new Error(`HTTP error! status: ${urlResponse.status}`);
        }
        
        const urlResult = await urlResponse.json();
        if (!urlResult.success) {
            showError('テックツールURLの取得に失敗しました: ' + (urlResult.message || '不明なエラー'));
            isSavingTechTools = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
            return;
        }
        
        // Format tech tools for database - preserve DOM order
        const toolUrlMap = {};
        urlResult.data.tech_tools.forEach(tool => {
            toolUrlMap[tool.tool_type] = tool.tool_url;
        });
        
        const techToolsForDB = selectedTools.map((toolType, index) => ({
            tool_type: toolType,
            tool_url: toolUrlMap[toolType],
            display_order: index,
            is_active: 1
        }));
        
        // Save to database with timeout
        const saveController = new AbortController();
        const saveTimeoutId = setTimeout(() => saveController.abort(), 30000);
        
        const saveResponse = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ tech_tools: techToolsForDB }),
            credentials: 'include',
            signal: saveController.signal
        });
        clearTimeout(saveTimeoutId);
        
        if (!saveResponse.ok) {
            throw new Error(`HTTP error! status: ${saveResponse.status}`);
        }
        
        const saveResult = await saveResponse.json();
        if (saveResult.success) {
            // Clear dirty flag
            // Show success message
            if (typeof showSuccess === 'function') {
                showSuccess('保存しました');
            }
            // Restore button text before navigating
            isSavingTechTools = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
            // Update business card data without reloading
            await loadBusinessCardData();
            // Move to next step (Step 5)
            setTimeout(() => {
                if (window.goToNextStep) {
                    window.goToNextStep(4);
                }
            }, 300);
        } else {
            showError('保存に失敗しました: ' + (saveResult.message || '不明なエラー'));
            isSavingTechTools = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error saving tech tools:', error);
        let errorMessage = 'エラーが発生しました';
        if (error.name === 'AbortError') {
            errorMessage = 'タイムアウト: 接続がタイムアウトしました。もう一度お試しください。';
        } else if (error.message) {
            errorMessage = 'エラーが発生しました: ' + error.message;
        }
        showError(errorMessage);
        isSavingTechTools = false;
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalButtonText;
        }
    }
}

// Prevent double submission
let isSavingCommunicationMethods = false;

// Save communication methods
async function saveCommunicationMethods() {
    // Prevent double submission (especially important for mobile)
    if (isSavingCommunicationMethods) {
        console.log('Already saving communication methods, ignoring duplicate submission');
        return;
    }
    isSavingCommunicationMethods = true;
    
    // Disable button and show loading state
    const saveButton = document.querySelector('button[onclick="saveCommunicationMethods()"]');
    const originalButtonText = saveButton ? saveButton.textContent : '';
    if (saveButton) {
        saveButton.disabled = true;
        saveButton.textContent = '保存中...';
    }
    
    try {
        const communicationMethods = [];
    let displayOrder = 0;
    
    // Mapping from method_type to form field names
    const methodTypeMap = {
        'line': { key: 'comm_line', idField: 'comm_line_id', isUrl: false },
        'messenger': { key: 'comm_messenger', idField: 'comm_messenger_id', isUrl: false },
        'chatwork': { key: 'comm_chatwork', idField: 'comm_chatwork_id', isUrl: false },
        'instagram': { key: 'comm_instagram', urlField: 'comm_instagram_url', isUrl: true },
        'facebook': { key: 'comm_facebook', urlField: 'comm_facebook_url', isUrl: true },
        'twitter': { key: 'comm_twitter', urlField: 'comm_twitter_url', isUrl: true },
        'youtube': { key: 'comm_youtube', urlField: 'comm_youtube_url', isUrl: true },
        'tiktok': { key: 'comm_tiktok', urlField: 'comm_tiktok_url', isUrl: true },
        'note': { key: 'comm_note', urlField: 'comm_note_url', isUrl: true },
        'pinterest': { key: 'comm_pinterest', urlField: 'comm_pinterest_url', isUrl: true },
        'threads': { key: 'comm_threads', urlField: 'comm_threads_url', isUrl: true }
    };
    
    // Collect Message Apps first (in DOM order)
    const messageGrid = document.getElementById('message-apps-grid');
    if (messageGrid) {
        const messageItems = Array.from(messageGrid.querySelectorAll('.communication-item[data-comm-type="message"]'));
        messageItems.forEach(item => {
            const checkbox = item.querySelector('input[type="checkbox"]');
            if (checkbox && checkbox.checked) {
                const methodType = checkbox.name.replace('comm_', '');
                const methodInfo = methodTypeMap[methodType];
                if (methodInfo) {
                    if (methodInfo.isUrl) {
                        const urlInput = item.querySelector(`input[name="${methodInfo.urlField}"]`);
                        const url = urlInput ? urlInput.value.trim() : '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: url,
                            method_id: '',
                            display_order: displayOrder++
                        });
                    } else {
                        const idInput = item.querySelector(`input[name="${methodInfo.idField}"]`);
                        const id = idInput ? idInput.value.trim() : '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: id.startsWith('http') ? id : '',
                            method_id: id.startsWith('http') ? '' : id,
                            display_order: displayOrder++
                        });
                    }
                }
            }
        });
    }
    
    // Collect SNS Apps second (in DOM order)
    const snsGrid = document.getElementById('sns-grid');
    if (snsGrid) {
        const snsItems = Array.from(snsGrid.querySelectorAll('.communication-item[data-comm-type="sns"]'));
        snsItems.forEach(item => {
            const checkbox = item.querySelector('input[type="checkbox"]');
            if (checkbox && checkbox.checked) {
                const methodType = checkbox.name.replace('comm_', '');
                const methodInfo = methodTypeMap[methodType];
                if (methodInfo) {
                    if (methodInfo.isUrl) {
                        const urlInput = item.querySelector(`input[name="${methodInfo.urlField}"]`);
                        const url = urlInput ? urlInput.value.trim() : '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: url,
                            method_id: '',
                            display_order: displayOrder++
                        });
                    } else {
                        const idInput = item.querySelector(`input[name="${methodInfo.idField}"]`);
                        const id = idInput ? idInput.value.trim() : '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: id.startsWith('http') ? id : '',
                            method_id: id.startsWith('http') ? '' : id,
                            display_order: displayOrder++
                        });
                    }
                }
            }
        });
    }
    
        const data = {
            communication_methods: communicationMethods
        };
        
        // Add timeout for mobile networks (30 seconds)
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);
        
        const response = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include',
            signal: controller.signal
        });
        clearTimeout(timeoutId);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        if (result.success) {
            // Update business card data without reloading
            await loadBusinessCardData();
            if (typeof showSuccess === 'function') {
                showSuccess('保存しました');
            }
            // Restore button text before navigating
            isSavingCommunicationMethods = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
            // Navigate to payment section
            goToEditSection('payment-section');
        } else {
            showError('保存に失敗しました: ' + (result.message || '不明なエラー'));
            isSavingCommunicationMethods = false;
            if (saveButton) {
                saveButton.disabled = false;
                saveButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        let errorMessage = 'エラーが発生しました';
        if (error.name === 'AbortError') {
            errorMessage = 'タイムアウト: 接続がタイムアウトしました。もう一度お試しください。';
        } else if (error.message) {
            errorMessage = 'エラーが発生しました: ' + error.message;
        }
        showError(errorMessage);
        isSavingCommunicationMethods = false;
        if (saveButton) {
            saveButton.disabled = false;
            saveButton.textContent = originalButtonText;
        }
    }
}

// Setup communication checkboxes to show/hide details
function setupCommunicationCheckboxes() {
    document.querySelectorAll('.communication-checkbox input[type="checkbox"]').forEach(checkbox => {
        checkbox.addEventListener('change', function() {
            const item = this.closest('.communication-item');
            const details = item ? item.querySelector('.comm-details') : null;
            if (details) {
                details.style.display = this.checked ? 'block' : 'none';
            }
        });
    });
}

// Set up mutual exclusivity for architect qualification checkboxes
// Only one of the three architect checkboxes can be checked at a time
// 宅地建物取引士 checkbox remains independent
function setupArchitectCheckboxMutualExclusivity() {
    const architectCheckboxes = [
        document.querySelector('input[name="qualification_kenchikushi_1"]'), // 一級建築士
        document.querySelector('input[name="qualification_kenchikushi_2"]'), // 二級建築士
        document.querySelector('input[name="qualification_kenchikushi_3"]')  // 木造建築士
    ];
    
    // Filter out null checkboxes
    const validCheckboxes = architectCheckboxes.filter(cb => cb !== null);
    
    if (validCheckboxes.length === 0) {
        return; // Checkboxes don't exist yet
    }
    
    // Add change event listener to each architect checkbox
    // Use data attribute to prevent duplicate listeners
    validCheckboxes.forEach(checkbox => {
        if (checkbox.dataset.mutualExclusivitySetup === 'true') {
            return; // Already set up
        }
        checkbox.dataset.mutualExclusivitySetup = 'true';
        
        checkbox.addEventListener('change', function() {
            if (this.checked) {
                // If this checkbox is checked, uncheck all other architect checkboxes
                const allArchitectCheckboxes = [
                    document.querySelector('input[name="qualification_kenchikushi_1"]'),
                    document.querySelector('input[name="qualification_kenchikushi_2"]'),
                    document.querySelector('input[name="qualification_kenchikushi_3"]')
                ].filter(cb => cb !== null);
                
                allArchitectCheckboxes.forEach(otherCheckbox => {
                    if (otherCheckbox !== this) {
                        otherCheckbox.checked = false;
                    }
                });
            }
        });
    });
}

// ==============================================
// Communication help modals (LINE / Facebook / Chatwork)
// ==============================================
function showCommunicationHelpModal(helpType) {
    const titles = {
        line: 'LINE の設定方法',
        facebook: 'Facebook プロフィールリンクの取得方法',
        chatwork: 'Chatwork ID / プロフィールリンクの取得方法'
    };

    const bodies = {
        line: `
            <p>【LINE】</p>
            <p>●スマートフォンアプリの場合</p>
            <ul>
                <li>LINEアプリのホーム画面から「友達追加」をタップ</li>
                <li>「QRコード」→「マイQRコード」で自分のQRコードを表示</li>
                <li>画面下部の「リンクをコピー」をタップ</li>
                <li>これで、LINE QRコードのリンクのコピーが完了します。</li>
            </ul>
        `,
        facebook: `
            <p>【Facebook】</p>
            <p>●スマートフォンアプリの場合</p>
            <ol>
                <li>Facebookアプリを開く</li>
                <li>自分のプロフィールを開く</li>
                <li>画面上部またはプロフィール内の「…（三点）」をタップ</li>
                <li>プロフィール設定画面一番下の「プロフィールリンクをコピー」からURLを取得</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>Facebookにログイン</li>
                <li>画面左上の自分の名前をクリックしてプロフィールを開く</li>
                <li>ブラウザ上部のURL欄に表示されているアドレスがプロフィールリンクです<br>（例：https://www.facebook.com/ユーザー名）</li>
            </ol>
        `,
        chatwork: `
            <p>【Chatwork】</p>
            <p>Chatwork ID（チャットワークID）は初期設定では未設定の場合があります。</p>
            <p>●スマートフォンアプリの場合</p>
            <ol>
                <li>Chatworkアプリを開く</li>
                <li>「アカウント」→「プロフィール」を開く</li>
                <li>「Chatwork ID を伝える」をタップ</li>
                <li>「Chatwork ID を共有」を押す</li>
                <li>表示された情報からコピーします</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>Chatworkにログイン</li>
                <li>右上の自分のアイコンをクリック</li>
                <li>「プロフィール」を開く</li>
                <li>プロフィール画面のリンクをコピーして利用します</li>
            </ol>
        `
    };

    const title = titles[helpType] || '設定方法';
    const bodyHtml = bodies[helpType] || '';

    const existing = document.querySelector('.comm-help-modal-overlay');
    if (existing) {
        existing.parentNode.removeChild(existing);
    }

    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay comm-help-modal-overlay';
    overlay.innerHTML = `
        <div class="modal-container">
            <div class="modal-header info">
                <div class="modal-icon">
                    <span>?</span>
                </div>
                <h2 class="modal-title">${title}</h2>
            </div>
            <div class="modal-body">
                <div class="modal-message">
                    ${bodyHtml}
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="modal-btn modal-btn-primary comm-help-close-btn">閉じる</button>
            </div>
        </div>
    `;

    document.body.appendChild(overlay);

    requestAnimationFrame(() => {
        overlay.classList.add('show');
    });

    function close() {
        overlay.classList.remove('show');
        setTimeout(() => {
            if (overlay.parentNode) {
                overlay.parentNode.removeChild(overlay);
            }
        }, 300);
    }

    overlay.addEventListener('click', (e) => {
        if (e.target === overlay) {
            close();
        }
    });

    const closeBtn = overlay.querySelector('.comm-help-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', close);
    }
}

// Initialize communication drag and drop on page load
document.addEventListener('DOMContentLoaded', function() {
    // Ensure cropper modal is direct child of body - prevents it from being hidden by parent layout (e.g. after Save & Next)
    const cropperModal = document.getElementById('image-cropper-modal');
    if (cropperModal && cropperModal.parentElement !== document.body) {
        document.body.appendChild(cropperModal);
    }

    // Load existing business card data
    // Use setTimeout to ensure all DOM elements are ready
    setTimeout(() => {
        loadExistingBusinessCardData();
    }, 100);
    
    // Initialize for both message apps and SNS
        setTimeout(() => {
        initializeCommunicationDragAndDrop('message');
        initializeCommunicationDragAndDrop('sns');
    }, 100);
    
    // Initialize navigation functionality
    initializeEditNavigation();
    
    // Setup communication checkboxes
    setupCommunicationCheckboxes();

    // Communication help buttons (LINE / Facebook / Chatwork)
    document.querySelectorAll('.comm-help-button').forEach(button => {
        button.addEventListener('click', function() {
            const helpType = this.getAttribute('data-help-type');
            if (helpType) {
                showCommunicationHelpModal(helpType);
            }
        });
    });
    
    // Set up mutual exclusivity for architect qualification checkboxes
    setTimeout(() => {
        setupArchitectCheckboxMutualExclusivity();
    }, 300);
    
    // Initialize free input pair drag and drop
    setTimeout(() => {
        initializeFreeInputPairDragAndDrop();
    }, 200);
});

// Greeting functions for edit page
function moveGreeting(index, direction) {
    const container = document.getElementById('greetings-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.greeting-item'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateGreetingButtons();
        updateGreetingNumbers();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateGreetingButtons();
        updateGreetingNumbers();
    }
}

function updateGreetingButtons() {
    const container = document.getElementById('greetings-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.greeting-item'));
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

function updateGreetingNumbers() {
    const container = document.getElementById('greetings-list');
    if (!container) return;
    
    const items = container.querySelectorAll('.greeting-item');
    items.forEach((item, index) => {
        const numberSpan = item.querySelector('.greeting-number');
        if (numberSpan) {
            numberSpan.textContent = index + 1;
        }
    });
}

function clearGreeting(button) {
    const greetingItem = button.closest('.greeting-item');
    if (!greetingItem) return;
    
    // Remove the entire greeting-item div
    greetingItem.remove();
    
    // Update greeting numbers and buttons
    updateGreetingNumbers();
    updateGreetingButtons();
    initializeGreetingDragAndDrop();
}

function addGreeting() {
    const container = document.getElementById('greetings-list');
    if (!container) return;
    
    const items = container.querySelectorAll('.greeting-item');
    const newIndex = items.length;
    
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
    greetingItem.dataset.order = newIndex;
        greetingItem.innerHTML = `
            <div class="greeting-header">
            <span class="greeting-number">${newIndex + 1}</span>
                <div class="greeting-actions">
                <button type="button" class="btn-move-up" onclick="moveGreeting(${newIndex}, 'up')" ${newIndex === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="btn-move-down" onclick="moveGreeting(${newIndex}, 'down')">↓</button>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
            </div>
            </div>
            <div class="form-group">
                <label>タイトル</label>
            <input type="text" name="greeting_title[]" class="form-control" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
            <textarea name="greeting_content[]" class="form-control" rows="4" placeholder="本文"></textarea>
            </div>
        `;
    
    // Insert at the beginning
    container.insertBefore(greetingItem, container.firstChild);
    
    // Update greeting numbers and buttons
    updateGreetingNumbers();
        updateGreetingButtons();
    initializeGreetingDragAndDrop();
}

function restoreDefaultGreetings() {
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
    
    const container = document.getElementById('greetings-list');
    if (!container) return;
    
    const currentCount = container.querySelectorAll('.greeting-item').length;
    
    defaultGreetings.forEach((greeting, index) => {
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
        greetingItem.dataset.order = currentCount + index;
        greetingItem.innerHTML = `
            <div class="greeting-header">
                <span class="greeting-number">${currentCount + index + 1}</span>
                <div class="greeting-actions">
                    <button type="button" class="btn-move-up" onclick="moveGreeting(${currentCount + index}, 'up')">↑</button>
                    <button type="button" class="btn-move-down" onclick="moveGreeting(${currentCount + index}, 'down')">↓</button>
                    <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
                </div>
            </div>
            <div class="form-group">
                <label>タイトル</label>
                <input type="text" name="greeting_title[]" class="form-control" value="${escapeHtml(greeting.title)}" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
                <textarea name="greeting_content[]" class="form-control" rows="4" placeholder="本文">${escapeHtml(greeting.content)}</textarea>
            </div>
        `;
        container.appendChild(greetingItem);
    });
    
    // Re-initialize drag and drop and update numbering/buttons after displaying
    setTimeout(function() {
        initializeGreetingDragAndDrop();
        updateGreetingNumbers();
        updateGreetingButtons();
    }, 100);
}

// Initialize greeting drag and drop
function initializeGreetingDragAndDrop() {
    const container = document.getElementById('greetings-list');
    if (!container) return;

    let draggedElement = null;
    let isInitializing = false;

    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;

        const items = container.querySelectorAll('.greeting-item');
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
        const items = container.querySelectorAll('.greeting-item');
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
                container.querySelectorAll('.greeting-item').forEach(item => {
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

                    const items = Array.from(container.querySelectorAll('.greeting-item'));
                    const targetIndex = items.indexOf(this);
                    const draggedIndexCurrent = items.indexOf(draggedElement);

                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }

                    draggedElement.dataset.dragInitialized = 'false';
                    this.dataset.dragInitialized = 'false';

                    updateGreetingNumbers();
                    updateGreetingButtons();
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

    // MutationObserver to handle dynamic changes
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
                makeItemsDraggable();
            }
        });
    });
    
            makeItemsDraggable();

    observer.observe(container, {
        childList: true,
        subtree: false
    });
}

// Tech tool functions
function moveTechTool(index, direction) {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
    
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

function updateTechToolButtons() {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
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

function initializeTechToolDragAndDrop() {
    const container = document.getElementById('tech-tools-list');
    if (!container) return;
    
    let draggedElement = null;
    let isInitializing = false;
    
    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;
        
        const items = container.querySelectorAll('.tech-tool-banner-card');
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
        const items = container.querySelectorAll('.tech-tool-banner-card');
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
                container.querySelectorAll('.tech-tool-banner-card').forEach(item => {
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
                    
                    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
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
    
    // MutationObserver to handle dynamic changes
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.type === 'childList') {
                makeItemsDraggable();
            }
        });
    });
    
    makeItemsDraggable();
    
    observer.observe(container, {
        childList: true,
        subtree: false
    });
}

// Free input pair functions
function addFreeInputPair() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const pairCount = container.querySelectorAll('.free-input-pair-item').length;
    
    const pairItem = document.createElement('div');
    pairItem.className = 'free-input-pair-item';
    // No border/margin on new item (it's at the top)
    
    pairItem.innerHTML = `
        <!-- Text Input -->
        <div class="form-group">
            <label>テキスト</label>
            <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx"></textarea>
        </div>
        <!-- Image/Banner Input -->
        <div class="form-group">
            <label>画像・バナー（リンク付き画像）</label>
            <div class="upload-area" data-upload-id="free_image_${pairCount}">
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
        </div>
        <button type="button" class="btn-delete" onclick="removeFreeInputPair(this)">削除</button>
    `;
    
    // Insert at the top of the container (before the first child, or append if no children exist)
    if (container.firstChild) {
        container.insertBefore(pairItem, container.firstChild);
        // Add border to the second item (previously first) if it exists
        const nextItem = pairItem.nextElementSibling;
        if (nextItem && nextItem.classList.contains('free-input-pair-item')) {
            nextItem.style.marginTop = '2rem';
            nextItem.style.paddingTop = '2rem';
            nextItem.style.borderTop = '1px solid #e0e0e0';
        }
    } else {
        container.appendChild(pairItem);
    }
    
    // Initialize file upload handler for the new item
    initializeFreeImageUpload(pairItem);
    
    // Initialize drag and drop for the new upload area
    initializeDragAndDropForUploadArea(pairItem.querySelector('.upload-area'));
    
    // Initialize drag and drop for reordering
    initializeFreeInputPairDragAndDrop();
    
    // Update delete button visibility
    const allPairs = container.querySelectorAll('.free-input-pair-item');
    allPairs.forEach((pair, index) => {
        const deleteBtn = pair.querySelector('.btn-delete');
        if (deleteBtn) {
            deleteBtn.style.display = allPairs.length > 1 ? 'block' : 'none';
        }
    });
}

function removeFreeInputPair(button) {
    const pairItem = button.closest('.free-input-pair-item');
    if (!pairItem) return;
    
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const allPairs = container.querySelectorAll('.free-input-pair-item');
    if (allPairs.length <= 1) {
        showWarning('最低1つのペアが必要です');
        return;
    }
    
    pairItem.remove();
    
    // Update delete button visibility
    const remainingPairs = container.querySelectorAll('.free-input-pair-item');
    remainingPairs.forEach((pair) => {
        const deleteBtn = pair.querySelector('.btn-delete');
        if (deleteBtn) {
            deleteBtn.style.display = remainingPairs.length > 1 ? 'block' : 'none';
        }
    });
    
    // Reinitialize drag and drop after removal
    initializeFreeInputPairDragAndDrop();
    updateFreeInputPairButtons();
    updateFreeInputPairNumbers();
    updateFreeInputPairBorders();
}

// Initialize free input pair drag and drop for reordering
function initializeFreeInputPairDragAndDrop() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;

    let draggedElement = null;
    let isInitializing = false;

    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;

        const items = container.querySelectorAll('.free-input-pair-item');
        items.forEach((item, index) => {
            item.draggable = false; // whole item not draggable so textarea/inputs don't steal drag
            item.dataset.dragIndex = index;
            const header = item.querySelector('.free-input-pair-header');
            if (header) {
                header.draggable = true; // only header is the drag handle
            }
        });

        attachDragListeners();
        isInitializing = false;
    }

    function attachDragListeners() {
        const items = container.querySelectorAll('.free-input-pair-item');
        items.forEach((item) => {
            if (item.dataset.dragInitialized === 'true') return;
            item.dataset.dragInitialized = 'true';

            const header = item.querySelector('.free-input-pair-header');
            if (header) {
                header.addEventListener('dragstart', function(e) {
                    const pairItem = this.closest('.free-input-pair-item');
                    if (!pairItem) return;
                    draggedElement = pairItem;
                    pairItem.classList.add('dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/html', pairItem.innerHTML);
                });

                header.addEventListener('dragend', function(e) {
                    const pairItem = this.closest('.free-input-pair-item');
                    if (pairItem) pairItem.classList.remove('dragging');
                    container.querySelectorAll('.free-input-pair-item').forEach(el => {
                        el.classList.remove('drag-over');
                    });
                });
            }

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
                    const itemsList = Array.from(container.querySelectorAll('.free-input-pair-item'));
                    const targetIndex = itemsList.indexOf(this);
                    const draggedIndexCurrent = itemsList.indexOf(draggedElement);

                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }

                    // Update UI and borders; DOM order is used on submit so order is preserved
                    updateFreeInputPairNumbers();
                    updateFreeInputPairBorders();
                }
            });
        });
    }

    function updateFreeInputPairBorders() {
        const items = container.querySelectorAll('.free-input-pair-item');
        items.forEach((item, index) => {
            if (index === 0) {
                item.style.marginTop = '';
                item.style.paddingTop = '';
                item.style.borderTop = '';
            } else {
                item.style.marginTop = '2rem';
                item.style.paddingTop = '2rem';
                item.style.borderTop = '1px solid #e0e0e0';
            }
        });
    }

    makeItemsDraggable();
    updateFreeInputPairBorders();
}

// Move free input pair up/down using arrows
function moveFreeInputPair(index, direction) {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateFreeInputPairButtons();
        updateFreeInputPairNumbers();
        updateFreeInputPairBorders();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateFreeInputPairButtons();
        updateFreeInputPairNumbers();
        updateFreeInputPairBorders();
    }
}

// Update free input pair arrow buttons state
function updateFreeInputPairButtons() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveFreeInputPair(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveFreeInputPair(${index}, 'down')`);
        }
    });
}

// Update free input pair numbers
function updateFreeInputPairNumbers() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-pair-item');
    items.forEach((item, index) => {
        const numberSpan = item.querySelector('.free-input-pair-number');
        if (numberSpan) {
            numberSpan.textContent = index + 1;
        }
    });
}

// Initialize file upload handler for free image items in edit page (stores file, uploads on form save)
function initializeFreeImageUpload(item) {
    const fileInput = item.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    // Remove existing listener if any (by cloning the element)
    const newFileInput = fileInput.cloneNode(true);
    fileInput.parentNode.replaceChild(newFileInput, fileInput);
    
    newFileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const uploadArea = item.querySelector('.upload-area');
        if (!uploadArea) return;
        
        // Show preview and store file (will be uploaded on form save)
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = uploadArea.querySelector('.upload-preview');
            if (preview) {
                const img = new Image();
                img.onload = () => {
                    const resizeNote = (img.width > 1200 || img.height > 1200) 
                        ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大1200×1200px)</p>` 
                        : '';
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
                };
                img.src = event.target.result;
            }
            // Store file reference for later upload
            uploadArea.dataset.freeImageFile = JSON.stringify({
                name: file.name,
                type: file.type,
                size: file.size
            });
            uploadArea.dataset.freeImageData = event.target.result;
        };
        reader.readAsDataURL(file);
    });
}

// Initialize drag and drop for a specific upload area
function initializeDragAndDropForUploadArea(uploadArea) {
    if (!uploadArea) return;
    
    const fileInput = uploadArea.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    // Remove existing listeners by cloning the element (this removes all event listeners)
    // Actually, we should check if already initialized to avoid duplicate listeners
    if (uploadArea.dataset.dragInitialized === 'true') return;
    uploadArea.dataset.dragInitialized = 'true';
    
    // ドラッグエンター時の処理（ブラウザのデフォルト動作を防止）
    uploadArea.addEventListener('dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
    });

    // ドラッグオーバー時の処理
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('drag-over');
    });
    
    // ドラッグリーブ時の処理
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('drag-over');
    });
    
    // ドロップ時の処理
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('drag-over');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            // 画像ファイルかチェック
            if (file && file.type && file.type.startsWith('image/')) {
                // より確実な方法でファイルを設定
                try {
                    // DataTransferオブジェクトを使用してファイルを設定
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    fileInput.files = dataTransfer.files;

                    // ファイル選択イベントをトリガー
                    const event = new Event('change', { bubbles: true, cancelable: true });
                    fileInput.dispatchEvent(event);
                } catch (error) {
                    // フォールバック: 直接代入を試行
                    console.warn('DataTransfer not supported, using fallback:', error);
                    try {
                        fileInput.files = files;
                        const event = new Event('change', { bubbles: true, cancelable: true });
                        fileInput.dispatchEvent(event);
                    } catch (fallbackError) {
                        console.error('File assignment failed:', fallbackError);
                        if (typeof showError === 'function') {
                            showError('ファイルの読み込みに失敗しました。もう一度お試しください。');
                        } else {
                            alert('ファイルの読み込みに失敗しました。もう一度お試しください。');
                        }
                    }
                }
            } else {
                if (typeof showWarning === 'function') {
                    showWarning('画像ファイルを選択してください');
                } else {
                    alert('画像ファイルを選択してください');
                }
            }
        }
    });
    
    // クリックでファイル選択も可能
    uploadArea.addEventListener('click', function(e) {
        // ボタンやプレビュー画像をクリックした場合は除外
        if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'IMG') {
            fileInput.click();
        }
    });
}

// Navigate to specific edit section
window.goToEditSection = function(sectionId) {
    // Close cropper modal before navigation - ensures clean state when user returns to upload
    if (typeof closeEditImageCropper === 'function') {
        closeEditImageCropper();
    }

    // Hide all sections (explicitly exclude modal - it has modal-overlay class, not edit-section)
    document.querySelectorAll('.edit-section').forEach(section => {
        section.classList.remove('active');
        section.style.display = 'none';
    });
    
    // Show target section
    const targetSection = document.getElementById(sectionId);
    if (targetSection) {
        targetSection.classList.add('active');
        targetSection.style.display = 'block';
        
        // Sync company name from step 1 (header-greeting) to step 2 (company-profile) when navigating to company-profile
        if (sectionId === 'company-profile-section') {
            const companyNameInput = document.querySelector('#header-greeting-form input[name="company_name"]');
            const companyProfileInput = document.querySelector('input[name="company_name_profile"]');
            if (companyNameInput && companyProfileInput && companyNameInput.value.trim()) {
                companyProfileInput.value = companyNameInput.value.trim();
            }
        }
        
        // Update active nav item
        const navItems = document.querySelectorAll('.edit-nav .nav-item');
        navItems.forEach(item => item.classList.remove('active'));
        
        // Find and activate the corresponding nav item
        const targetNavItem = Array.from(navItems).find(item => {
            const section = item.getAttribute('data-section');
            return section === sectionId;
        });
        
        if (targetNavItem) {
            targetNavItem.classList.add('active');
        }
        
        // Scroll to top of the page
        setTimeout(() => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }, 100);
    }
};

// Navigate to next step (called after saving)
window.goToNextStep = function(currentStep) {
    const nextStep = currentStep + 1;
    const sectionMap = {
        1: 'header-greeting-section',
        2: 'company-profile-section',
        3: 'personal-info-section',
        4: 'tech-tools-section',
        5: 'communication-section',
        6: 'payment-section'
    };
    
    const targetSectionId = sectionMap[nextStep];
    if (!targetSectionId) {
        console.log('No next step available');
        return;
    }
    
    goToEditSection(targetSectionId);
};

// Navigation functionality for edit page
function initializeEditNavigation() {
    const navItems = document.querySelectorAll('.edit-nav .nav-item');
    
    navItems.forEach(navItem => {
        navItem.addEventListener('click', function(e) {
            e.preventDefault();
            
            // Get target section ID from data-section attribute or href
            const targetSectionId = this.getAttribute('data-section') || 
                                   this.getAttribute('href').replace('#', '') + '-section';
            
            if (targetSectionId) {
                // Hide all sections
                document.querySelectorAll('.edit-section').forEach(section => {
                    section.classList.remove('active');
                    section.style.display = 'none';
                });
                
                // Show target section
                const targetSection = document.getElementById(targetSectionId);
                if (targetSection) {
                    targetSection.classList.add('active');
                    targetSection.style.display = 'block';
                    
                    // Sync company name from step 1 to step 2 when navigating to company-profile
                    if (targetSectionId === 'company-profile-section') {
                        const companyNameInput = document.querySelector('#header-greeting-form input[name="company_name"]');
                        const companyProfileInput = document.querySelector('input[name="company_name_profile"]');
                        if (companyNameInput && companyProfileInput && companyNameInput.value.trim()) {
                            companyProfileInput.value = companyNameInput.value.trim();
                        }
                    }
                    
                    // Update active nav item
                    navItems.forEach(item => item.classList.remove('active'));
                    this.classList.add('active');
                    
                    // Scroll to top of the page for better UX
                    setTimeout(() => {
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    }, 100);
                }
            }
        });
    });
}

// Ensure image cropper modal exists in DOM - create if missing (handles dynamic pages, iframes, etc.)
function ensureEditCropperModalExists() {
    let modal = document.getElementById('image-cropper-modal');
    if (!modal) {
        modal = document.createElement('div');
        modal.id = 'image-cropper-modal';
        modal.className = 'modal-overlay';
        modal.style.cssText = 'display: none; z-index: 10000; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); justify-content: center; align-items: center;';
        modal.innerHTML = '<div class="modal-content" style="max-width: 90%; max-height: 90vh; overflow: auto; background: white; border-radius: 8px; padding: 0;"><div style="padding: 20px;"><h3 style="margin-bottom: 20px; color: #333;">画像をトリミング</h3><p style="margin-bottom: 15px; color: #666; font-size: 14px;">画像のサイズを調整し、必要な部分を選択してください。指でドラッグしてトリミングエリアを移動・拡大縮小できます。</p><div id="cropper-image-container" style="width: 100%; max-width: 800px; margin: 0 auto; background: #f5f5f5; border-radius: 4px; padding: 10px; display: flex; justify-content: center; align-items: center;"><img id="cropper-image" style="max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;"></div><div style="margin-top: 20px; display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;"><button type="button" id="crop-cancel-btn" class="btn-secondary" style="padding: 10px 20px; width: auto; cursor: pointer;">キャンセル</button><button type="button" id="crop-confirm-btn" class="btn-primary" style="padding: 10px 20px; width: auto;">トリミングを適用</button></div></div></div>';
        document.body.appendChild(modal);
    }
    let cropperContainer = document.getElementById('cropper-image-container');
    if (!cropperContainer) {
        cropperContainer = modal.querySelector('[id="cropper-image-container"]') || modal.querySelector('div img#cropper-image')?.parentElement;
        if (cropperContainer) {
            cropperContainer.id = 'cropper-image-container';
        } else {
            cropperContainer = document.createElement('div');
            cropperContainer.id = 'cropper-image-container';
            cropperContainer.style.cssText = 'width: 100%; max-width: 800px; margin: 0 auto; background: #f5f5f5; border-radius: 4px; padding: 10px; display: flex; justify-content: center; align-items: center;';
            const img = document.createElement('img');
            img.id = 'cropper-image';
            img.style.cssText = 'max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;';
            cropperContainer.appendChild(img);
            const inner = modal.querySelector('.modal-content > div');
            if (inner) {
                const btnDiv = inner.querySelector('div[style*="margin-top: 20px"]');
                inner.insertBefore(cropperContainer, btnDiv || inner.firstChild);
            } else {
                modal.querySelector('.modal-content').appendChild(cropperContainer);
            }
        }
    }
    return { modal, cropperContainer };
}

// Show image cropper modal for edit page
function showEditImageCropper(file, fieldName, originalEvent) {
    try {
        const { modal, cropperContainer } = ensureEditCropperModalExists();

        if (!modal || !cropperContainer) {
            console.warn('[showEditImageCropper] Modal or container not found after ensure, falling back to preview');
            showEditImagePreview(file, fieldName, originalEvent);
            return;
        }

        // Ensure modal is direct child of body - prevents it from being hidden by section layout
        try {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        } catch (e) {
            console.error('[showEditImageCropper] Failed to append modal to body:', e);
        }

        // Ensure modal is visible immediately when image is selected
        modal.style.display = 'flex';
        modal.style.visibility = 'visible';
        modal.style.opacity = '1';
        modal.classList.add('show');
        modal.style.zIndex = '10001'; // Above other overlays

        // Close when clicking overlay background (not modal content)
        const overlayClickHandler = function(e) {
            if (e.target === modal) {
                closeEditImageCropper();
                modal.removeEventListener('click', overlayClickHandler);
            }
        };
        modal.removeEventListener('click', overlayClickHandler); // Remove if previously added
        modal.addEventListener('click', overlayClickHandler);

        // Revoke previous object URL before creating new one
        if (editImageObjectURL) {
            try {
                URL.revokeObjectURL(editImageObjectURL);
            } catch (e) {
                console.warn('[showEditImageCropper] Error revoking previous object URL:', e);
            }
            editImageObjectURL = null;
        }

        try {
            editImageObjectURL = URL.createObjectURL(file);
        } catch (e) {
            console.error('[showEditImageCropper] Failed to create object URL:', e);
            if (typeof showError === 'function') {
                showError('画像の読み込みに失敗しました: ' + (e.message || ''));
            } else {
                alert('画像の読み込みに失敗しました: ' + (e.message || ''));
            }
            return;
        }

        editCropFile = file;
        editCropFieldName = fieldName;
        editCropOriginalEvent = originalEvent;

        // Helper: setup cancel/confirm button handlers
        function setupEditCropperButtons() {
            try {
                const newCancelBtn = document.getElementById('crop-cancel-btn');
                const newConfirmBtn = document.getElementById('crop-confirm-btn');
                if (newCancelBtn) {
                    newCancelBtn.onclick = function() {
                        try {
                            if (file && originalEvent && originalEvent.target) {
                                const uploadArea = originalEvent.target.closest('.upload-area');
                                if (uploadArea) {
                                    const reader = new FileReader();
                                    reader.onload = (event) => {
                                        try {
                                            uploadArea.dataset.originalFile = JSON.stringify({ name: file.name, type: file.type, size: file.size });
                                            uploadArea.dataset.originalFileData = event.target.result;
                                            uploadArea.dataset.originalFieldName = fieldName;
                                            const preview = uploadArea.querySelector('.upload-preview');
                                            if (preview) {
                                                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                                            }
                                        } catch (e) {
                                            console.error('[showEditImageCropper] Cancel handler - reader.onload error:', e);
                                        }
                                    };
                                    reader.onerror = () => console.error('[showEditImageCropper] FileReader error');
                                    reader.readAsDataURL(file);
                                }
                            }
                        } catch (e) {
                            console.error('[showEditImageCropper] Cancel handler error:', e);
                        }
                        closeEditImageCropper();
                    };
                }
                if (newConfirmBtn) {
                    newConfirmBtn.onclick = function() {
                        try {
                            cropAndStoreForEdit();
                        } catch (e) {
                            console.error('[showEditImageCropper] Confirm handler - cropAndStoreForEdit error:', e);
                            if (typeof showError === 'function') {
                                showError('トリミングの適用に失敗しました: ' + (e.message || ''));
                            } else {
                                alert('トリミングの適用に失敗しました: ' + (e.message || ''));
                            }
                        }
                    };
                }
            } catch (e) {
                console.error('[showEditImageCropper] setupEditCropperButtons error:', e);
            }
        }

        // Case A: Cropper already exists - use replace() for clean 2nd+ image (recommended by Cropper.js)
        if (editCropper) {
            try {
                editCropper.replace(editImageObjectURL);
                setupEditCropperButtons();
                return;
            } catch (e) {
                console.warn('[showEditImageCropper] Cropper replace failed, falling back to full reinit:', e);
                try {
                    editCropper.destroy();
                } catch (e2) {
                    console.warn('[showEditImageCropper] Cropper destroy on replace fail:', e2);
                }
                editCropper = null;
                // Fall through to Case B
            }
        }

        // Case B: No cropper - full init. Reset container to ensure clean state.
        editCropperImageLoadHandler = null;
        try {
            cropperContainer.innerHTML = '';
        } catch (e) {
            console.error('[showEditImageCropper] Failed to clear cropper container:', e);
        }

        const newImg = document.createElement('img');
        newImg.id = 'cropper-image';
        newImg.style.cssText = 'max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;';
        try {
            cropperContainer.appendChild(newImg);
        } catch (e) {
            console.error('[showEditImageCropper] Failed to append cropper image:', e);
            if (typeof showError === 'function') {
                showError('画像表示の準備に失敗しました');
            } else {
                alert('画像表示の準備に失敗しました');
            }
            return;
        }

        // Remove old event listeners from buttons (clone to clear)
        try {
            const cancelBtn = document.getElementById('crop-cancel-btn');
            const confirmBtn = document.getElementById('crop-confirm-btn');
            if (cancelBtn && cancelBtn.parentNode) {
                cancelBtn.parentNode.replaceChild(cancelBtn.cloneNode(true), cancelBtn);
            }
            if (confirmBtn && confirmBtn.parentNode) {
                confirmBtn.parentNode.replaceChild(confirmBtn.cloneNode(true), confirmBtn);
            }
        } catch (e) {
            console.warn('[showEditImageCropper] Button clone/replace error:', e);
        }

        // Set up image load handler - use newImg (not cropperImage which may be stale)
        setTimeout(() => {
            editCropperImageLoadHandler = function() {
                try {
                    if (editCropper) {
                        try {
                            editCropper.destroy();
                        } catch (e) {
                            console.warn('[showEditImageCropper] Error destroying cropper in onload:', e);
                        }
                    }

                    setTimeout(() => {
                        const aspectRatio = fieldName === 'company_logo' ? 1 : 1;
                        try {
                            if (typeof Cropper === 'undefined') {
                                throw new Error('Cropper.js is not loaded');
                            }
                            editCropper = new Cropper(newImg, {
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
                        } catch (e) {
                            console.error('[showEditImageCropper] Error initializing Cropper:', e);
                            if (typeof showError === 'function') {
                                showError('画像の読み込みに失敗しました: ' + (e.message || ''));
                            } else {
                                alert('画像の読み込みに失敗しました: ' + (e.message || ''));
                            }
                            closeEditImageCropper();
                        }
                    }, 50);
                } catch (e) {
                    console.error('[showEditImageCropper] editCropperImageLoadHandler error:', e);
                }
            };

            newImg.onerror = function() {
                console.error('[showEditImageCropper] Image load error (onerror)');
                if (typeof showError === 'function') {
                    showError('画像の読み込みに失敗しました');
                } else {
                    alert('画像の読み込みに失敗しました');
                }
                closeEditImageCropper();
            };

            newImg.src = editImageObjectURL;

            if (newImg.complete) {
                const currentSrc = newImg.src;
                newImg.src = '';
                setTimeout(() => {
                    newImg.src = currentSrc;
                    if (newImg.complete && editCropperImageLoadHandler) {
                        editCropperImageLoadHandler();
                    }
                }, 10);
            } else {
                newImg.onload = editCropperImageLoadHandler;
            }
        }, 150);

        setupEditCropperButtons();
    } catch (e) {
        console.error('[showEditImageCropper] Unhandled error:', e);
        if (typeof showError === 'function') {
            showError('画像トリミングの表示に失敗しました: ' + (e.message || ''));
        } else {
            alert('画像トリミングの表示に失敗しました: ' + (e.message || ''));
        }
        closeEditImageCropper();
    }
}

// Close image cropper for edit page
function closeEditImageCropper() {
    const modal = document.getElementById('image-cropper-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.visibility = 'hidden';
        modal.style.opacity = '0';
        modal.classList.remove('show');
    }

    // Reset file inputs so change event fires on next upload (same or different file)
    // Required: selecting the same file again won't trigger change unless value was cleared
    const logoInput = document.getElementById('company_logo');
    const photoInput = document.getElementById('profile_photo');
    if (logoInput) logoInput.value = '';
    if (photoInput) photoInput.value = '';

    // Destroy cropper on close - required for clean reinit after Save & Next navigation
    if (editCropper) {
        try {
            editCropper.destroy();
        } catch (e) {
            console.warn('Error destroying cropper on close:', e);
        }
        editCropper = null;
    }

    const cropperImage = document.getElementById('cropper-image');
    if (cropperImage) {
        if (editCropperImageLoadHandler) {
            cropperImage.removeEventListener('load', editCropperImageLoadHandler);
            cropperImage.onload = null;
            editCropperImageLoadHandler = null;
        }
        cropperImage.onerror = null;

        if (editImageObjectURL) {
            try {
                URL.revokeObjectURL(editImageObjectURL);
            } catch (e) {
                console.warn('Error revoking object URL:', e);
            }
            editImageObjectURL = null;
        }

        cropperImage.src = '';
    }

    editCropFile = null;
    editCropFieldName = null;
    editCropOriginalEvent = null;
}

// Crop and store image for edit page (stores blob, uploads on form save)
function cropAndStoreForEdit() {
    if (!editCropper || !editCropFile || !editCropFieldName) {
        return;
    }

    let uploadArea = null;
    if (editCropOriginalEvent && editCropOriginalEvent.target) {
        uploadArea = editCropOriginalEvent.target.closest('.upload-area');
    }

    if (!uploadArea && editCropFieldName) {
        const fieldId = editCropFieldName === 'company_logo' ? 'company_logo' : 'profile_photo';
        const fieldElement = document.getElementById(fieldId);
        if (fieldElement) {
            uploadArea = fieldElement.closest('.upload-area');
        }
    }

    if (!uploadArea) {
        showError('アップロードエリアが見つかりません');
        return;
    }
    
    const cropFileName = editCropFile ? editCropFile.name : 'cropped_image.png';
    const cropFileType = editCropFile ? editCropFile.type : 'image/png';

    try {
        const canvas = editCropper.getCroppedCanvas({
            width: 800,
            height: 800,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });

        canvas.toBlob(function(blob) {
            if (!blob) {
                showError('画像のトリミングに失敗しました');
                return;
            }
    
            // Show preview and store blob (will be uploaded on form save)
            const reader = new FileReader();
            reader.onload = (event) => {
                const preview = uploadArea.querySelector('.upload-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                }
                // Store the blob data URL and the actual blob for later upload
                uploadArea.dataset.croppedBlob = event.target.result;
                uploadArea.dataset.croppedBlobFile = JSON.stringify({
                    name: cropFileName,
                    type: cropFileType
                });
                // Store the actual blob object (we'll need to recreate it from the data URL on upload)
                uploadArea.dataset.croppedBlobData = event.target.result;
                uploadArea.dataset.croppedFileName = cropFileName;
                uploadArea.dataset.croppedFileType = cropFileType;
                uploadArea.dataset.croppedFieldName = editCropFieldName;
            };
            reader.readAsDataURL(blob);

            closeEditImageCropper(); // Also resets file inputs for next upload
        }, cropFileType, 0.95);
    } catch (error) {
        console.error('Crop error:', error);
        showError('画像のトリミング中にエラーが発生しました');
    }
}

// Show image preview (fallback - stores file, uploads on form save)
function showEditImagePreview(file, fieldName, originalEvent) {
    const uploadArea = originalEvent.target.closest('.upload-area');
    if (!uploadArea) return;
    
    // Show preview and store file (will be uploaded on form save)
    const reader = new FileReader();
    reader.onload = (event) => {
        const preview = uploadArea.querySelector('.upload-preview');
        if (preview) {
            const img = new Image();
            img.onload = () => {
                const resizeNote = (img.width > 800 || img.height > 800)
                    ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大800×800px)</p>`
                    : '';
                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
            };
            img.src = event.target.result;
        }
        // Store file reference for later upload
        uploadArea.dataset.originalFile = JSON.stringify({
            name: file.name,
            type: file.type,
            size: file.size
        });
        uploadArea.dataset.originalFileData = event.target.result;
        uploadArea.dataset.originalFieldName = fieldName;
    };
    reader.readAsDataURL(file);
}

// Photo upload previews with cropping for edit page
// Use event delegation to handle file changes (works for subsequent uploads)
document.addEventListener('change', function(e) {
    if (e.target.id === 'profile_photo' || e.target.id === 'company_logo') {
        const file = e.target.files?.[0];
        if (file) {
            // より厳密な画像ファイルチェック
            if (file.type && file.type.startsWith('image/')) {
                const fieldName = e.target.id === 'company_logo' ? 'company_logo' : 'profile_photo';
                showEditImageCropper(file, fieldName, e);
            } else {
                console.warn('Invalid file type:', file.type);
                if (typeof showWarning === 'function') {
                    showWarning('画像ファイルを選択してください');
                } else {
                    alert('画像ファイルを選択してください');
                }
                // ファイル入力をリセット
                e.target.value = '';
            }
        }
    } else if (e.target.matches('#free-input-pairs-container input[type="file"]') || e.target.name === 'free_image[]') {
        // Free input image - show preview (no cropping) - works for all pairs including initial
        const file = e.target.files?.[0];
        if (file && file.type.startsWith('image/')) {
            const uploadArea = e.target.closest('.upload-area');
            if (uploadArea) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    const img = new Image();
                    img.onload = () => {
                        const resizeNote = (img.width > 1200 || img.height > 1200)
                            ? '<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大1200×1200px)</p>'
                            : '';
                        const preview = uploadArea.querySelector('.upload-preview');
                        if (preview) {
                            preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
                        }
                    };
                    img.src = event.target.result;
                    uploadArea.dataset.freeImageFile = JSON.stringify({
                        name: file.name,
                        type: file.type,
                        size: file.size
                    });
                    uploadArea.dataset.freeImageData = event.target.result;
                };
                reader.readAsDataURL(file);
            }
        }
    }
});
