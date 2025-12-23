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
        const response = await fetch('../backend/api/business-card/get.php', {
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
        const response = await fetch('../backend/api/business-card/get.php', {
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
        const logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
        if (logoUploadArea) {
            const logoPreview = logoUploadArea.querySelector('.upload-preview');
            if (logoPreview) {
                const logoPath = data.company_logo.startsWith('http') ? data.company_logo : '../' + data.company_logo;
                logoPreview.innerHTML = `<img src="${logoPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                logoUploadArea.dataset.existingImage = data.company_logo;
            }
        }
    }
    
    // Profile photo preview
    if (data.profile_photo) {
        const photoUploadArea = document.querySelector('[data-upload-id="profile_photo"]');
        if (photoUploadArea) {
            const photoPreview = photoUploadArea.querySelector('.upload-preview');
            if (photoPreview) {
                const photoPath = data.profile_photo.startsWith('http') ? data.profile_photo : '../' + data.profile_photo;
                photoPreview.innerHTML = `<img src="${photoPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                photoUploadArea.dataset.existingImage = data.profile_photo;
            }
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
        if (qualifications.includes('建築士')) {
            const kenchikushiCheckbox = document.querySelector('input[name="qualification_kenchikushi"]');
            if (kenchikushiCheckbox) kenchikushiCheckbox.checked = true;
        }
        const otherQuals = qualifications.filter(q => q !== '宅地建物取引士' && q !== '建築士').join('、');
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
                        </div>
                        <button type="button" class="btn-delete-small" onclick="removeFreeInputPair(this)" ${pairCount <= 1 ? 'style="display: none;"' : ''}>削除</button>
                    `;

                    container.appendChild(pairItem);
                    
                    // Store existing image path in data attribute for later use
                    if (imgData.image) {
                        pairItem.querySelector('.upload-area').dataset.existingImage = imgData.image;
                    }
                }
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
    
    // Create a map of saved tech tools by tool_type for quick lookup
    const savedToolsMap = {};
    if (savedTechTools && Array.isArray(savedTechTools)) {
        savedTechTools.forEach(tool => {
            savedToolsMap[tool.tool_type] = tool;
        });
    }
    
    // Define the order of tech tools
    const toolOrder = ['mdb', 'rlp', 'llp', 'ai', 'slp', 'olp', 'alp'];
    
    techToolsList.innerHTML = '';
    
    // Display tech tools in the defined order
    toolOrder.forEach((toolType, index) => {
        const isActive = savedToolsMap[toolType]?.is_active || false;
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
                    <button type="button" class="btn-move-down" onclick="moveTechTool(${index}, 'down')" ${index === toolOrder.length - 1 ? 'disabled' : ''}>↓</button>
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
async function saveTechTools() {
    const selectedCheckboxes = document.querySelectorAll('#tech-tools-list input[name="tech_tools[]"]:checked');
    
    if (selectedCheckboxes.length < 2) {
        showError('最低2つ以上のテックツールを選択してください');
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
    
    try {
        // Get tool URLs from API
        const urlResponse = await fetch('../backend/api/tech-tools/generate-urls.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ selected_tools: selectedTools }),
            credentials: 'include'
        });
        
        const urlResult = await urlResponse.json();
        if (!urlResult.success) {
            showError('テックツールURLの取得に失敗しました');
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
        
        // Save to database
        const saveResponse = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ tech_tools: techToolsForDB }),
            credentials: 'include'
        });
        
        const saveResult = await saveResponse.json();
        if (saveResult.success) {
            // Update business card data without reloading
            await loadBusinessCardData();
            // Move to next step (Step 5)
            setTimeout(() => {
                if (window.goToNextStep) {
                    window.goToNextStep(4);
                }
            }, 300);
        } else {
            showError('保存に失敗しました: ' + saveResult.message);
        }
    } catch (error) {
        console.error('Error saving tech tools:', error);
        showError('エラーが発生しました');
    }
}

// Save communication methods
async function saveCommunicationMethods() {
    const communicationMethods = [];
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
            const idInput = document.querySelector(`input[name="${app.idField}"]`);
            const id = idInput ? idInput.value.trim() : '';
            communicationMethods.push({
                method_type: app.type,
                method_name: app.type,
                method_url: id.startsWith('http') ? id : '',
                method_id: id.startsWith('http') ? '' : id,
                display_order: displayOrder++
            });
        }
    });
    
    // SNS
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
            const urlInput = document.querySelector(`input[name="${app.urlField}"]`);
            const url = urlInput ? urlInput.value.trim() : '';
            communicationMethods.push({
                method_type: app.type,
                method_name: app.type,
                method_url: url,
                method_id: '',
                display_order: displayOrder++
            });
        }
    });
    
    const data = {
        communication_methods: communicationMethods
    };
    
    try {
        const response = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include'
        });
        
        const result = await response.json();
        if (result.success) {
            // Update business card data without reloading
            await loadBusinessCardData();
            showSuccess('保存しました');
        } else {
            showError('保存に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
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

// Initialize communication drag and drop on page load
document.addEventListener('DOMContentLoaded', function() {
    // Load existing business card data
    loadExistingBusinessCardData();
    
    // Initialize for both message apps and SNS
    setTimeout(() => {
        initializeCommunicationDragAndDrop('message');
        initializeCommunicationDragAndDrop('sns');
    }, 100);
    
    // Initialize navigation functionality
    initializeEditNavigation();
    
    // Setup communication checkboxes
    setupCommunicationCheckboxes();
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
    if (pairCount > 0) {
        pairItem.style.marginTop = '2rem';
        pairItem.style.paddingTop = '2rem';
        pairItem.style.borderTop = '1px solid #e0e0e0';
    }
    
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
        <button type="button" class="btn-delete-small" onclick="removeFreeInputPair(this)">削除</button>
    `;
    
    container.appendChild(pairItem);
    
    // Update delete button visibility
    const allPairs = container.querySelectorAll('.free-input-pair-item');
    allPairs.forEach((pair, index) => {
        const deleteBtn = pair.querySelector('.btn-delete-small');
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
        const deleteBtn = pair.querySelector('.btn-delete-small');
        if (deleteBtn) {
            deleteBtn.style.display = remainingPairs.length > 1 ? 'block' : 'none';
        }
    });
}

// Navigate to next step (called after saving)
window.goToNextStep = function(currentStep) {
    const nextStep = currentStep + 1;
    const sectionMap = {
        1: 'header-greeting-section',
        2: 'company-profile-section',
        3: 'personal-info-section',
        4: 'tech-tools-section',
        5: 'communication-section'
    };
    
    const targetSectionId = sectionMap[nextStep];
    if (!targetSectionId) {
        console.log('No next step available');
        return;
    }
    
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
        
        // Update active nav item
        const navItems = document.querySelectorAll('.edit-nav .nav-item');
        navItems.forEach(item => item.classList.remove('active'));
        
        // Find and activate the corresponding nav item
        const targetNavItem = Array.from(navItems).find(item => {
            const section = item.getAttribute('data-section');
            return section === targetSectionId;
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

// Show image cropper modal for edit page
function showEditImageCropper(file, fieldName, originalEvent) {
    const modal = document.getElementById('image-cropper-modal');
    const cropperImage = document.getElementById('cropper-image');

    if (!modal || !cropperImage) {
        showEditImagePreview(file, fieldName, originalEvent);
        return;
    }

    // Step 1: Clean up previous state completely
    if (editCropper) {
        try {
            editCropper.destroy();
        } catch (e) {
            console.warn('Error destroying previous cropper:', e);
        }
        editCropper = null;
    }

    // Reset image element completely
    cropperImage.onload = null;
    cropperImage.onerror = null;
    cropperImage.removeAttribute('src');
    cropperImage.style.display = 'none';

    // Remove any cropper wrapper elements
    const cropperContainer = cropperImage.parentElement;
    if (cropperContainer) {
        cropperContainer.querySelectorAll('.cropper-container, .cropper-wrap-box, .cropper-canvas, .cropper-drag-box, .cropper-crop-box, .cropper-modal').forEach(el => el.remove());
    }

    // Revoke previous object URL
    if (editImageObjectURL) {
        try {
            URL.revokeObjectURL(editImageObjectURL);
        } catch (e) {
            console.warn('Error revoking previous object URL:', e);
        }
        editImageObjectURL = null;
    }

    // Remove previous onload handler
    if (editCropperImageLoadHandler) {
        cropperImage.removeEventListener('load', editCropperImageLoadHandler);
        editCropperImageLoadHandler = null;
    }

    // Remove old event listeners from buttons
    const cancelBtn = document.getElementById('crop-cancel-btn');
    const confirmBtn = document.getElementById('crop-confirm-btn');

    if (cancelBtn) {
        const newCancelBtn = cancelBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
    }

    if (confirmBtn) {
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
    }

    // Store file and field name
    editCropFile = file;
    editCropFieldName = fieldName;
    editCropOriginalEvent = originalEvent;

    // Create new object URL
    editImageObjectURL = URL.createObjectURL(file);

    // Show modal
    modal.style.display = 'block';

    // Set up image load handler
    setTimeout(() => {
        cropperImage.style.display = 'block';

        editCropperImageLoadHandler = function() {
            if (editCropper) {
                try {
                    editCropper.destroy();
                } catch (e) {
                    console.warn('Error destroying cropper in onload:', e);
                }
            }

            setTimeout(() => {
                const aspectRatio = fieldName === 'company_logo' ? 1 : 1;
                try {
                    editCropper = new Cropper(cropperImage, {
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
                    console.error('Error initializing cropper:', e);
                    showError('画像の読み込みに失敗しました');
                    closeEditImageCropper();
                }
            }, 50);
        };

        cropperImage.onerror = function() {
            console.error('Error loading image');
            showError('画像の読み込みに失敗しました');
            closeEditImageCropper();
        };

        cropperImage.src = editImageObjectURL;

        if (cropperImage.complete) {
            const currentSrc = cropperImage.src;
            cropperImage.src = '';
            setTimeout(() => {
                cropperImage.src = currentSrc;
                if (cropperImage.complete && editCropperImageLoadHandler) {
                    editCropperImageLoadHandler();
                }
            }, 10);
        } else {
            cropperImage.onload = editCropperImageLoadHandler;
        }
    }, 100);

    // Setup cancel button
    const newCancelBtn = document.getElementById('crop-cancel-btn');
    if (newCancelBtn) {
        newCancelBtn.onclick = function() {
            closeEditImageCropper();
            if (originalEvent && originalEvent.target) {
                originalEvent.target.value = '';
            }
        };
    }

    // Setup confirm button
    const newConfirmBtn = document.getElementById('crop-confirm-btn');
    if (newConfirmBtn) {
        newConfirmBtn.onclick = function() {
            cropAndStoreForEdit();
        };
    }
}

// Close image cropper for edit page
function closeEditImageCropper() {
    const modal = document.getElementById('image-cropper-modal');
    if (modal) {
        modal.style.display = 'none';
    }

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

// Crop and store image for edit page
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

            const reader = new FileReader();
            reader.onload = (event) => {
                const preview = uploadArea.querySelector('.upload-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                }

                uploadArea.dataset.croppedBlob = event.target.result;
                uploadArea.dataset.croppedFileName = cropFileName;
            };
            reader.readAsDataURL(blob);

            closeEditImageCropper();
        }, cropFileType, 0.95);
    } catch (error) {
        console.error('Crop error:', error);
        showError('画像のトリミング中にエラーが発生しました');
    }
}

// Show image preview (fallback)
function showEditImagePreview(file, fieldName, originalEvent) {
    const reader = new FileReader();
    reader.onload = (event) => {
        const preview = originalEvent.target.closest('.upload-area').querySelector('.upload-preview');
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
    };
    reader.readAsDataURL(file);
}

// Photo upload previews with cropping for edit page
document.getElementById('profile_photo')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
        showEditImageCropper(file, 'profile_photo', e);
    }
});

document.getElementById('company_logo')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
        showEditImageCropper(file, 'company_logo', e);
    }
});
