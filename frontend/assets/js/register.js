/**
 * Registration Form JavaScript
 */

// Global cropper instance for register page
let registerCropper = null;
let registerCropFieldName = null;
let registerCropFile = null;
let registerCropOriginalEvent = null;
let registerImageObjectURL = null; // Track object URL for cleanup
let registerCropperImageLoadHandler = null; // Track onload handler

let currentStep = 1;
let formData = {};
let completedSteps = new Set(); // Track which steps have been submitted
let businessCardData = null; // Store loaded business card data

// Helper function to build URLs with token and type parameters (security: preserve token)
function buildUrlWithToken(baseUrl) {
    if (typeof window !== 'undefined' && window.invitationToken && (window.userType === 'existing' || window.userType === 'free')) {
        return baseUrl + (baseUrl.includes('?') ? '&' : '?') + 'type=' + encodeURIComponent(window.userType) + '&token=' + encodeURIComponent(window.invitationToken);
    }
    return baseUrl;
}

// Load existing business card data on page load
document.addEventListener('DOMContentLoaded', async function() {
    await loadExistingBusinessCardData();

    // Ensure default greetings are displayed if container is still empty (for new users)
    const greetingsContainer = document.getElementById('greetings-container');
    if (greetingsContainer && greetingsContainer.children.length === 0) {
        displayDefaultGreetingsForRegister();
    }
    
    // Auto-capitalize first letter of romaji input fields
    setupRomajiAutoCapitalize();
    
    // Make step indicators clickable
    const stepItems = document.querySelectorAll('.step-indicator .step');
    stepItems.forEach(stepItem => {
        stepItem.style.cursor = 'pointer';
            stepItem.addEventListener('click', async function() {
            const stepNumber = parseInt(this.dataset.step);
            if (stepNumber && stepNumber >= 1 && stepNumber <= 6) {
                // Save data for all steps before the target step (only if navigating forward)
                if (stepNumber > currentStep && stepNumber > 1) {
                    await saveStepsBeforeTarget(stepNumber);
                }
                goToStep(stepNumber, true);
            }
        });
    });
});

// Auto-capitalize first letter of romaji input fields
function setupRomajiAutoCapitalize() {
    const romajiFields = [
        document.getElementById('last_name_romaji'),
        document.getElementById('first_name_romaji')
    ];
    
    romajiFields.forEach(field => {
        if (field) {
            let isComposing = false; // Track IME composition state
            
            // Track composition start (IME input started)
            field.addEventListener('compositionstart', function() {
                isComposing = true;
            });
            
            // Track composition end (IME input finished)
            field.addEventListener('compositionend', function(e) {
                isComposing = false;
                // Apply capitalization after composition ends
                capitalizeFirstLetterForRegister(e.target);
            });
            
            // Handle input event - skip during IME composition
            field.addEventListener('input', function(e) {
                // Skip if IME is composing or if event has isComposing flag
                if (isComposing || e.isComposing) {
                    return;
                }
                capitalizeFirstLetterForRegister(e.target);
            });
            
            // Also apply on blur (when field loses focus)
            field.addEventListener('blur', function(e) {
                capitalizeFirstLetterForRegister(e.target);
                // Sanitize romaji characters on blur
                sanitizeRomajiInputForRegister(e.target);
            });
        }
    });
}

// Capitalize first letter helper function for register page
function capitalizeFirstLetterForRegister(input) {
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
}

// Sanitize romaji input - allow A-Z, a-z, space, hyphen, apostrophe, and dot
function sanitizeRomajiInputForRegister(input) {
    let value = input.value;
    // Remove any characters that are not: A-Z, a-z, space, hyphen (-), apostrophe ('), or dot (.)
    const sanitized = value.replace(/[^A-Za-z\s\-'.]/g, '');
    
    if (sanitized !== value) {
        const cursorPosition = input.selectionStart;
        input.value = sanitized;
        // Adjust cursor position if characters were removed
        const removedChars = value.length - sanitized.length;
        input.setSelectionRange(Math.max(0, cursorPosition - removedChars), Math.max(0, cursorPosition - removedChars));
    }
}

// Load existing business card data from API and populate forms
async function loadExistingBusinessCardData() {
    try {
        const response = await fetch('../backend/api/business-card/get.php', {
            method: 'GET',
            credentials: 'include'
        });
        
        if (!response.ok) {
            console.log('No existing data found or not logged in');
            // For new users, display default greetings
            displayDefaultGreetingsForRegister();
            return;
        }
        
        const result = await response.json();
        console.log('Loaded business card data:', result);
        
        if (result.success && result.data) {
            businessCardData = result.data;
            populateRegistrationForms(businessCardData);
        } else {
            // No data found - display default greetings for new users
            displayDefaultGreetingsForRegister();
        }
    } catch (error) {
        console.error('Error loading business card data:', error);
        // On error, display default greetings for new users
        displayDefaultGreetingsForRegister();
    }
}

// Populate all registration forms with existing data
function populateRegistrationForms(data) {
    console.log('Populating registration forms with:', data);
    
    // Step 1: Header & Greeting
    if (data.company_name) {
        const companyNameInput = document.querySelector('#header-greeting-form input[name="company_name"]');
        if (companyNameInput) companyNameInput.value = data.company_name;
    }
    
    // Logo preview
    if (data.company_logo) {
        const logoPreview = document.querySelector('#logo-upload .upload-preview');
        if (logoPreview) {
            const logoPath = data.company_logo.startsWith('http') ? data.company_logo : '../' + data.company_logo;
            logoPreview.innerHTML = `<img src="${logoPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
        }
    }
    
    // Profile photo preview
    if (data.profile_photo) {
        const photoPreview = document.querySelector('#photo-upload-header .upload-preview');
        if (photoPreview) {
            const photoPath = data.profile_photo.startsWith('http') ? data.profile_photo : '../' + data.profile_photo;
            photoPreview.innerHTML = `<img src="${photoPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
        }
    }
    
    // Greetings - ALWAYS clear first, then populate based on data
    const greetingsContainer = document.getElementById('greetings-container');
    if (greetingsContainer) {
        greetingsContainer.innerHTML = '';
        
        if (data.greetings && Array.isArray(data.greetings) && data.greetings.length > 0) {
            console.log('Displaying greetings from database:', data.greetings);
            displayGreetingsForRegister(data.greetings);
        } else if (data.greetings && Array.isArray(data.greetings) && data.greetings.length === 0) {
            // Empty array - user has deleted all greetings, keep it empty
            console.log('Greetings array is empty - keeping it empty');
            // Already cleared above
        } else {
            // No greetings data - first time, display defaults
            console.log('No greetings data - displaying defaults');
            displayDefaultGreetingsForRegister();
        }
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
    // Step 2: Company Profile - Company name will be loaded when step 2 becomes active (not on initial page load)
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
        const lastNameInput = document.getElementById('last_name');
        const firstNameInput = document.getElementById('first_name');
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
        const lastNameRomajiInput = document.getElementById('last_name_romaji');
        const firstNameRomajiInput = document.getElementById('first_name_romaji');
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
            
            if (!container) return;

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
                    <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)" ${pairCount <= 1 ? 'style="display: none;"' : ''}>削除</button>
                    `;

                container.appendChild(pairItem);
                    
                    // Store existing image path in data attribute for later use
                    if (imgData.image) {
                    pairItem.querySelector('.upload-area').dataset.existingImage = imgData.image;
                    }
                    
                    // Initialize file upload handler
                initializeFreeImageUploadForRegister(pairItem);
            }
        } catch (e) {
            console.error('Error parsing free_input:', e);
        }
    }
    
    // Step 4: Tech Tools
    if (data.tech_tools && Array.isArray(data.tech_tools)) {
        data.tech_tools.forEach(tool => {
            if (tool.is_active) {
                const checkbox = document.querySelector(`input[name="tech_tools[]"][value="${tool.tool_type}"]`);
                if (checkbox) checkbox.checked = true;
            }
        });
    }
    
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
    }
    
    console.log('Registration forms populated');
}

/**
 * Save data for a specific step to the database
 * Returns a promise that resolves when the save is complete
 */
async function saveStepData(stepNumber) {
    let form;
    let saveData = {};
    
    try {
        switch(stepNumber) {
            case 1:
                form = document.getElementById('header-greeting-form');
                if (!form) return true;
                
                // Collect form data (simplified version - just basic fields, files handled separately)
                const formData1 = new FormData(form);
                saveData = Object.fromEntries(formData1);
                
                // Handle greetings
                const greetingItems = document.querySelectorAll('#greetings-container .greeting-item');
                const greetings = [];
                greetingItems.forEach((item, index) => {
                    if (item.dataset.cleared === 'true') return;
                    const titleInput = item.querySelector('input[name="greeting_title[]"]');
                    const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]');
                    const title = titleInput ? (titleInput.value || '').trim() : '';
                    const content = contentTextarea ? (contentTextarea.value || '').trim() : '';
                    if (title && content) {
                        greetings.push({
                            title: title,
                            content: content,
                            display_order: index
                        });
                    }
                });
                saveData.greetings = greetings;
                
                // Note: File uploads for step 1 are handled separately and are complex
                // For now, we'll save the text data. Files should be uploaded via form submit.
                break;
                
            case 2:
                form = document.getElementById('company-profile-form');
                if (!form) return true;
                
                const formData2 = new FormData(form);
                saveData = Object.fromEntries(formData2);
                if (saveData.company_name_profile) {
                    saveData.company_name = saveData.company_name_profile;
                    delete saveData.company_name_profile;
                }
                break;
                
            case 3:
                form = document.getElementById('personal-info-form');
                if (!form) return true;
                
                const formData3 = new FormData(form);
                saveData = Object.fromEntries(formData3);
                
                // Combine names
                const lastName = saveData.last_name || '';
                const firstName = saveData.first_name || '';
                saveData.name = (lastName + ' ' + firstName).trim();
                
                const lastNameRomaji = saveData.last_name_romaji || '';
                const firstNameRomaji = saveData.first_name_romaji || '';
                saveData.name_romaji = (lastNameRomaji + ' ' + firstNameRomaji).trim();
                
                // Handle qualifications
                const qualifications = [];
                if (formData3.get('qualification_takken')) {
                    qualifications.push('宅地建物取引士');
                }
                if (formData3.get('qualification_kenchikushi')) {
                    qualifications.push('建築士');
                }
                if (saveData.qualifications_other) {
                    qualifications.push(saveData.qualifications_other);
                }
                saveData.qualifications = qualifications.join('、');
                delete saveData.qualification_takken;
                delete saveData.qualification_kenchikushi;
                delete saveData.qualifications_other;
                
                // Handle free input (simplified - just preserve existing structure)
                const freeInputTexts = formData3.getAll('free_input_text[]').filter(text => text.trim() !== '');
                const freeImageLinks = formData3.getAll('free_image_link[]');
                // Note: Free image file uploads are complex and should be handled via form submit
                // For navigation, we'll preserve text data only
                let freeInputData = {
                    texts: freeInputTexts.length > 0 ? freeInputTexts : [''],
                    images: [] // Will be preserved from existing data
                };
                saveData.free_input = JSON.stringify(freeInputData);
                break;
                
            case 4:
                // Step 4 (tech tools) doesn't need to save before navigation
                return true;
                
            case 5:
                // Step 5 (communication) doesn't need to save before navigation
                return true;
                
            default:
                return true;
        }
        
        // Save to database
        if (Object.keys(saveData).length > 0) {
            const response = await fetch('../backend/api/business-card/update.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(saveData),
                credentials: 'include'
            });
            
            const result = await response.json();
            if (result.success) {
                return true;
            } else {
                console.warn(`Failed to save step ${stepNumber}:`, result.message);
                return false;
            }
        }
        
        return true;
    } catch (error) {
        console.error(`Error saving step ${stepNumber}:`, error);
        return false;
    }
}

/**
 * Save all steps up to (but not including) the target step
 */
async function saveStepsBeforeTarget(targetStep) {
    // Save steps 1 through (targetStep - 1)
    for (let step = 1; step < targetStep; step++) {
        // Skip steps 4 and 5 as they don't need pre-save (they're selection-based)
        if (step === 4 || step === 5) continue;
        
        const saved = await saveStepData(step);
        if (!saved) {
            console.warn(`Failed to save step ${step} before navigating to step ${targetStep}`);
            // Continue anyway - don't block navigation
        }
    }
}

// Step navigation
async function goToStep(step, skipSave = false) {
    if (step < 1 || step > 6) return;
    
    // Update step indicator
    const stepItems = document.querySelectorAll('.step-indicator .step');
    stepItems.forEach(item => {
        item.classList.remove('active');
        if (parseInt(item.dataset.step) === step) {
            item.classList.add('active');
            // Scroll active step to center
            const stepIndicator = document.querySelector('.step-indicator');
            if (stepIndicator) {
                const itemRect = item.getBoundingClientRect();
                const indicatorRect = stepIndicator.getBoundingClientRect();
                const itemLeft = itemRect.left - indicatorRect.left;
                const itemWidth = itemRect.width;
                const indicatorWidth = indicatorRect.width;
                
                const scrollPosition = itemLeft - (indicatorWidth / 2) + (itemWidth / 2);
                stepIndicator.scrollTo({
                    left: scrollPosition,
                    behavior: 'smooth'
                });
            }
        }
    });
    
    // Hide all steps
    document.querySelectorAll('.register-step').forEach(el => {
        el.classList.remove('active');
        el.style.display = 'none';
    });
    
    // Show target step
    document.getElementById(`step-${step}`).classList.add('active');
    document.getElementById(`step-${step}`).style.display = 'block';
    
    // Update step indicator
    document.querySelectorAll('.step').forEach((el, index) => {
        if (index + 1 <= step) {
            el.classList.add('active'); 
            // el.style.display = 'block';
        } else {
            el.classList.remove('active'); 
            // el.style.display = 'none';
        }
    });
    
    currentStep = step;
    
    // Scroll to the top of the screen (not just the section)
    setTimeout(() => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }, 100);
    
    // Load company name from database when step 2 becomes active
    if (step === 2) {
        // Wait a bit for DOM to be ready, then load company name
        setTimeout(() => {
            if (businessCardData && businessCardData.company_name) {
                const companyProfileInput = document.querySelector('input[name="company_name_profile"]');
                if (companyProfileInput) {
                    companyProfileInput.value = businessCardData.company_name;
                }
            }
        }, 100);
    }
    
    // Generate and display QR code when reaching step 6 (payment step)
    // if (step === 6) {
    //     generateAndDisplayQRCode();
    // }
}

// Move greeting up/down
function moveGreeting(index, direction) {
    const container = document.getElementById('greetings-container');
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
    const container = document.getElementById('greetings-container');
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
    const items = document.querySelectorAll('#greetings-container .greeting-item');
    items.forEach((item, index) => {
        const numberElement = item.querySelector('.greeting-number');
        if (numberElement) {
            numberElement.textContent = index + 1;
        }
        item.setAttribute('data-order', index);
    });
}

function updateGreetingButtons() {
    const items = document.querySelectorAll('#greetings-container .greeting-item');
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

// Add new greeting item
function addGreeting() {
    const container = document.getElementById('greetings-container');
    if (!container) return;

    const index = 0; // New items are added at the top, so index is always 0
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
            <input type="text" name="greeting_title[]" class="form-control" placeholder="タイトル" required>
        </div>
        <div class="form-group">
            <label>本文</label>
            <textarea name="greeting_content[]" class="form-control" rows="4" placeholder="本文" required></textarea>
        </div>
    `;
    // Insert at the top of the container (before the first child, or append if no children exist)
    if (container.firstChild) {
        container.insertBefore(greetingItem, container.firstChild);
    } else {
    container.appendChild(greetingItem);
    }
    updateGreetingNumbers();
    updateGreetingButtons();
    initializeGreetingDragAndDrop();
}

// Delete greeting (removes the entire greeting-item div)
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

// Default greetings (same as edit.php)
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

// Escape HTML to prevent XSS
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Display default greetings when no saved greetings exist
// This function now appends default greetings to existing ones instead of replacing them
function displayDefaultGreetingsForRegister() {
    const greetingsContainer = document.getElementById('greetings-container');
    if (!greetingsContainer) return;
    
    // Get current greeting items count
    const existingItems = greetingsContainer.querySelectorAll('.greeting-item');
    const currentCount = existingItems.length;
    
    // Append default greetings to existing ones
    defaultGreetings.forEach((greeting, index) => {
        const greetingItem = document.createElement('div');
        greetingItem.className = 'greeting-item';
        // Temporary order, will be updated by updateGreetingNumbers()
        greetingItem.dataset.order = currentCount + index;
        greetingItem.innerHTML = `
            <div class="greeting-header">
                <span class="greeting-number">${currentCount + index + 1}</span>
                <div class="greeting-actions">
                    <button type="button" class="btn-move-up" onclick="moveGreeting(${currentCount + index}, 'up')">↑</button>
                    <button type="button" class="btn-move-down" onclick="moveGreeting(${currentCount + index}, 'down')">↓</button>
                </div>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
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
        greetingsContainer.appendChild(greetingItem);
    });
    
    // Re-initialize drag and drop and update numbering/buttons after displaying
    setTimeout(function() {
        initializeGreetingDragAndDrop();
        updateGreetingNumbers();
        updateGreetingButtons();
    }, 100);
}

// Restore default greetings (button click handler)
function restoreDefaultGreetingsForRegister() {
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
        <div class="modal-content restore-greetings-modal" style="display:flex !important; flex-direction: column !important; margin :auto !important; margin-top:20rem !important;">
            <div class="modal-header-restore">
                <h3>5つの挨拶文例を再表示</h3>
            </div>
            <div class="modal-body-restore">
                <p class="modal-message-main">デフォルトの挨拶文を再表示しますか？</p>
                <p class="modal-message-sub">現在の挨拶文に5つの挨拶文例が追加されます。</p>
            </div>
            <div class="modal-buttons">
                <button class="modal-btn modal-btn-yes" id="confirm-restore-register-yes">はい</button>
                <button class="modal-btn modal-btn-no" id="confirm-restore-register-no">いいえ</button>
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
        const yesBtn = document.getElementById('confirm-restore-register-yes');
        const noBtn = document.getElementById('confirm-restore-register-no');
        
        if (yesBtn) {
            yesBtn.addEventListener('click', function() {
                closeRestoreModalForRegister();
                displayDefaultGreetingsForRegister();
            });
        }
        
        if (noBtn) {
            noBtn.addEventListener('click', function() {
                closeRestoreModalForRegister();
            });
        }
    }, 50);
}

// Close restore modal for register
function closeRestoreModalForRegister() {
    const modal = document.querySelector('.modal-overlay');
    if (modal) {
        modal.classList.remove('show');
        setTimeout(() => {
            modal.remove();
        }, 300);
    }
}

// Display greetings from database
function displayGreetingsForRegister(greetings) {
    const greetingsContainer = document.getElementById('greetings-container');
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
                </div>
                <button type="button" class="btn-delete" onclick="clearGreeting(this)">削除</button>
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

// Postal code lookup
document.getElementById('lookup-address')?.addEventListener('click', async () => {
    const postalCode = document.getElementById('company_postal_code').value.replace(/-/g, '');
    
    if (!postalCode || postalCode.length !== 7) {
        showWarning('7桁の郵便番号を入力してください');
        return;
    }
    
    try {
        const response = await fetch(`../backend/api/utils/postal-code-lookup.php?postal_code=${postalCode}`);
        const result = await response.json();
        
        if (result.success) {
            document.getElementById('company_address').value = result.data.address;
        } else {
            showError(result.message || '住所の取得に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// License lookup
document.getElementById('lookup-license')?.addEventListener('click', async () => {
    const prefecture = document.getElementById('license_prefecture').value;
    const renewal = document.getElementById('license_renewal').value;
    const registration = document.getElementById('license_registration').value;
    
    if (!prefecture || !renewal || !registration) {
        showWarning('都道府県、更新番号、登録番号をすべて入力してください');
        return;
    }
    
    try {
        const response = await fetch('../backend/api/utils/license-lookup.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                prefecture: prefecture,
                renewal: renewal,
                registration: registration
            })
        });
        const result = await response.json();
        
        if (result.success) {
            if (result.data.company_name) {
                document.querySelector('input[name="company_name_profile"]').value = result.data.company_name;
            }
            if (result.data.address) {
                document.getElementById('company_address').value = result.data.address;
            }
        } else {
            showError(result.message || '会社情報の取得に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Communication checkbox handlers
document.querySelectorAll('.communication-checkbox input[type="checkbox"]').forEach(checkbox => {
    checkbox.addEventListener('change', function() {
        const item = this.closest('.communication-item');
        const details = item.querySelector('.comm-details');
        if (this.checked) {
            details.style.display = 'block';
        } else {
            details.style.display = 'none';
        }
    });
});

// Step 1: Header & Greeting (Note: Account registration is now in new_register.php)
document.getElementById('header-greeting-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // Handle logo upload (check for cropped image first, then restored file)
    const logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
    const logoFile = document.getElementById('company_logo').files[0];
    // Check for restored file from auto-save
    let restoredLogoFile = null;
    if (window.autoSave) {
        const restoredFiles = window.autoSave.getRestoredFiles();
        const restoredLogo = restoredFiles.get('company_logo');
        if (restoredLogo && !logoFile && !(logoUploadArea && logoUploadArea.dataset.croppedBlob)) {
            restoredLogoFile = new File([restoredLogo.blob], restoredLogo.name, {
                type: restoredLogo.type,
                lastModified: restoredLogo.lastModified
            });
        }
    }
    if (logoFile || (logoUploadArea && logoUploadArea.dataset.croppedBlob) || restoredLogoFile) {
        const uploadData = new FormData();
        
        // Use cropped image if available, otherwise use original file
        if (logoUploadArea && logoUploadArea.dataset.croppedBlob) {
            // Convert data URL to blob
            const response = await fetch(logoUploadArea.dataset.croppedBlob);
            const blob = await response.blob();
            uploadData.append('file', blob, logoUploadArea.dataset.croppedFileName || 'logo.png');
            // Clear cropped data
            delete logoUploadArea.dataset.croppedBlob;
            delete logoUploadArea.dataset.croppedFileName;
        } else if (restoredLogoFile) {
            uploadData.append('file', restoredLogoFile);
        } else {
            uploadData.append('file', logoFile);
        }
        uploadData.append('file_type', 'logo');
        
        try {
            const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                method: 'POST',
                body: uploadData,
                credentials: 'include'
            });
            
            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                // Extract relative path from absolute URL for database storage
                let relativePath = uploadResult.data.file_path;
                if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                    // Remove BASE_URL prefix to get relative path
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
                data.company_logo = relativePath;
                console.log('Logo uploaded and saved to database:', relativePath);
                // Log resize info
                if (uploadResult.data.was_resized) {
                    console.log('Logo auto-resized:', uploadResult.data);
                }
            }
        } catch (error) {
            console.error('Logo upload error:', error);
        }
    } else if (businessCardData && businessCardData.company_logo) {
        // Preserve existing logo
        data.company_logo = businessCardData.company_logo;
    }
    
    // Handle profile photo upload (check for cropped image first, then restored file)
    const photoUploadArea = document.querySelector('[data-upload-id="profile_photo_header"]');
    const photoFile = document.getElementById('profile_photo_header').files[0];
    // Check for restored file from auto-save
    let restoredPhotoFile = null;
    if (window.autoSave) {
        const restoredFiles = window.autoSave.getRestoredFiles();
        const restoredPhoto = restoredFiles.get('profile_photo');
        if (restoredPhoto && !photoFile && !(photoUploadArea && photoUploadArea.dataset.croppedBlob)) {
            restoredPhotoFile = new File([restoredPhoto.blob], restoredPhoto.name, {
                type: restoredPhoto.type,
                lastModified: restoredPhoto.lastModified
            });
        }
    }
    if (photoFile || (photoUploadArea && photoUploadArea.dataset.croppedBlob) || restoredPhotoFile) {
        const uploadData = new FormData();
        
        // Use cropped image if available, otherwise use original file
        if (photoUploadArea && photoUploadArea.dataset.croppedBlob) {
            // Convert data URL to blob
            const response = await fetch(photoUploadArea.dataset.croppedBlob);
            const blob = await response.blob();
            uploadData.append('file', blob, photoUploadArea.dataset.croppedFileName || 'photo.png');
            // Clear cropped data
            delete photoUploadArea.dataset.croppedBlob;
            delete photoUploadArea.dataset.croppedFileName;
        } else if (restoredPhotoFile) {
            uploadData.append('file', restoredPhotoFile);
        } else {
            uploadData.append('file', photoFile);
        }
        uploadData.append('file_type', 'photo');
        
        try {
            const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                method: 'POST',
                body: uploadData,
                credentials: 'include'
            });

            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                // Extract relative path from absolute URL for database storage
                let relativePath = uploadResult.data.file_path;
                if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                    // Remove BASE_URL prefix to get relative path
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
                data.profile_photo = relativePath;
                console.log('Profile photo uploaded and saved to database:', relativePath);
                // Log resize info
                if (uploadResult.data.was_resized) {
                    console.log('Profile photo auto-resized:', uploadResult.data);
                }
            }
        } catch (error) {
            console.error('Photo upload error:', error);
        }
    } else if (businessCardData && businessCardData.profile_photo) {
        // Preserve existing profile photo
        data.profile_photo = businessCardData.profile_photo;
    }
    
    // Handle greetings - get order from DOM
    const greetingItems = document.querySelectorAll('#greetings-container .greeting-item');
    const greetings = [];
    greetingItems.forEach((item, index) => {
        // Skip cleared items (items that were deleted/cleared)
        if (item.dataset.cleared === 'true') {
            return;
        }
        
        const titleInput = item.querySelector('input[name="greeting_title[]"]');
        const contentTextarea = item.querySelector('textarea[name="greeting_content[]"]');
        
        const title = titleInput ? (titleInput.value || '').trim() : '';
        const content = contentTextarea ? (contentTextarea.value || '').trim() : '';
        
        // Only add if both title and content have values
        if (title && content) {
            greetings.push({
                title: title,
                content: content,
                display_order: index
            });
        }
    });
    
    data.greetings = greetings;
    
    formData = { ...formData, ...data };
    completedSteps.add(1); // Mark step 1 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
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
            // Clear drafts on successful step completion
            if (window.autoSave && window.autoSave.clearDraftsOnSuccess) {
                await window.autoSave.clearDraftsOnSuccess();
            }
            // Reload business card data to get updated company name
            await loadExistingBusinessCardData();
            goToStep(2);
        } else {
            if (window.autoSave && window.autoSave.markSubmissionFailed) {
                window.autoSave.markSubmissionFailed();
            }
            showError('更新に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Step 2: Company Profile
document.getElementById('company-profile-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Validate required fields for real estate license
    const prefecture = document.getElementById('license_prefecture').value;
    const renewal = document.getElementById('license_renewal').value;
    const registration = document.getElementById('license_registration').value.trim();
    
    if (!prefecture || !renewal || !registration) {
        showError('宅建業者番号（都道府県、更新番号、登録番号）は必須項目です。');
        return;
    }
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // Merge company_name from profile step
    if (data.company_name_profile) {
        data.company_name = data.company_name_profile;
        delete data.company_name_profile;
    }
    
    formData = { ...formData, ...data };
    completedSteps.add(2); // Mark step 2 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
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
            goToStep(3);
        } else {
            showError('更新に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Step 3: Personal Information
document.getElementById('personal-info-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // Combine last_name and first_name into name
    const lastName = data.last_name || '';
    const firstName = data.first_name || '';
    data.name = (lastName + ' ' + firstName).trim();
    
    // Combine romaji names
    const lastNameRomaji = data.last_name_romaji || '';
    const firstNameRomaji = data.first_name_romaji || '';
    data.name_romaji = (lastNameRomaji + ' ' + firstNameRomaji).trim();
    
    // Handle qualifications checkboxes
    const qualifications = [];
    if (formDataObj.get('qualification_takken')) {
        qualifications.push('宅地建物取引士');
    }
    if (formDataObj.get('qualification_kenchikushi')) {
        qualifications.push('建築士');
    }
    if (data.qualifications_other) {
        qualifications.push(data.qualifications_other);
    }
    data.qualifications = qualifications.join('、');
    
    // Remove individual qualification fields
    delete data.qualification_takken;
    delete data.qualification_kenchikushi;
    delete data.qualifications_other;
    
    // Combine free input fields from paired items - collect all textarea values and images
    const freeInputTexts = [];
    const images = [];
    
    // Get all paired items
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
        const uploadArea = pairItem.querySelector('.upload-area');
        const existingImage = uploadArea ? uploadArea.dataset.existingImage : '';
        
        let imagePath = existingImage || '';
        
        // If new file is selected, upload it
        if (fileInput && fileInput.files && fileInput.files[0]) {
            const uploadData = new FormData();
            uploadData.append('file', fileInput.files[0]);
            uploadData.append('file_type', 'free');
            
            try {
                const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                    method: 'POST',
                    body: uploadData,
                    credentials: 'include'
                });
                
                const uploadResult = await uploadResponse.json();
                if (uploadResult.success) {
                    const fullPath = uploadResult.data.file_path;
                    imagePath = fullPath.split('/php/')[1] || fullPath;
                    // Log resize info
                    if (uploadResult.data.was_resized) {
                        console.log('Free image auto-resized:', uploadResult.data);
                    }
                }
            } catch (error) {
                console.error('Upload error:', error);
            }
        }
        
        // Add image data (even if empty, to maintain pairing)
        images.push({
            image: imagePath,
            link: linkInput ? linkInput.value.trim() : ''
        });
    }
    
    let freeInputData = {
        texts: freeInputTexts.length > 0 ? freeInputTexts : [''],
        images: images.length > 0 ? images : [{ image: '', link: '' }]
    };
    
    // Store free input as JSON
    data.free_input = JSON.stringify(freeInputData);
    // Remove all free_input_text entries from data
    Object.keys(data).forEach(key => {
        if (key.startsWith('free_input_text')) {
            delete data[key];
        }
    });
    delete data.free_image_link;
    
    formData = { ...formData, ...data };
    completedSteps.add(3); // Mark step 3 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
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
            goToStep(4);
        } else {
            showError('更新に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Step 4: Tech Tools Selection
document.getElementById('tech-tools-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    // Get selected tools in DOM order (respecting user's reordering)
    const grid = document.getElementById('tech-tools-grid');
    const toolCards = Array.from(grid.querySelectorAll('.tech-tool-banner-card'));
    const selectedTools = [];
    
    toolCards.forEach(card => {
        const checkbox = card.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            selectedTools.push(card.dataset.toolType);
        }
    });
    
    if (selectedTools.length < 2) {
        showWarning('最低2つ以上のテックツールを選択してください');
        return;
    }
    
    formData.tech_tools = selectedTools;
    completedSteps.add(4); // Mark step 4 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Generate tech tool URLs and save to database
    await generateTechToolUrls(selectedTools);
    
    goToStep(5);
});

// Generate Tech Tool URLs and save to database
async function generateTechToolUrls(selectedTools) {
    try {
        // Step 1: Generate URLs
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
            console.error('Failed to generate URLs:', urlResult.message);
            return;
        }
        
        // Step 2: Format tech tools for database - preserve DOM order
        // Create a map of tool_type to tool_url for quick lookup
        const toolUrlMap = {};
        urlResult.data.tech_tools.forEach(tool => {
            toolUrlMap[tool.tool_type] = tool.tool_url;
        });
        
        // Build tech tools array in DOM order
        const techToolsForDB = selectedTools.map((toolType, index) => ({
            tool_type: toolType,
            tool_url: toolUrlMap[toolType],
            display_order: index,
            is_active: 1
        }));
        
        formData.tech_tool_urls = urlResult.data.tech_tools;
        sessionStorage.setItem('registerData', JSON.stringify(formData));
        
        // Step 3: Save to database
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
            console.log('Tech tools saved to database successfully');
        } else {
            console.error('Failed to save tech tools:', saveResult.message);
        }
    } catch (error) {
        console.error('Error generating/saving tech tools:', error);
    }
}

// Step 6: Communication Functions
document.getElementById('communication-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formDataObj = new FormData(e.target);
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
        if (formDataObj.get(app.key)) {
            const id = formDataObj.get(app.idField) || '';
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
        if (formDataObj.get(app.key)) {
            const url = formDataObj.get(app.urlField) || '';
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
    
    formData = { ...formData, ...data };
    completedSteps.add(5); // Mark step 5 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
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
            goToStep(6);
        } else {
            showError('更新に失敗しました: ' + result.message);
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Step 6: Preview & Payment
document.getElementById('submit-payment')?.addEventListener('click', async () => {
    const paymentMethod = document.querySelector('input[name="payment_method"]:checked')?.value;
    
    if (!paymentMethod) {
        showWarning('支払方法を選択してください');
        return;
    }
    
    // Create payment intent
    try {
        const response = await fetch('../backend/api/payment/create-intent.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                payment_method: paymentMethod,
                payment_type: formData.user_type
            }),
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (paymentMethod === 'credit_card') {
                // Redirect to payment page with payment_id and client_secret
                const params = new URLSearchParams({
                    payment_id: result.data.payment_id,
                    client_secret: result.data.client_secret || ''
                });
                // Preserve token and type in URL for security
                const paymentUrl = 'payment.php?' + params.toString();
                window.location.href = buildUrlWithToken(paymentUrl);
            } else {
                // Bank transfer - redirect to bank transfer info page
                const params = new URLSearchParams({
                    payment_id: result.data.payment_id,
                    pi: result.data.stripe_payment_intent_id || ''
                });
                const bankTransferUrl = buildUrlWithToken('bank-transfer-info.php?' + params.toString());
                window.location.href = bankTransferUrl;
            }
        } else {
            showError(result.message || '決済処理に失敗しました');
        }
    } catch (error) {
        console.error('Error:', error);
        showError('エラーが発生しました');
    }
});

// Load preview
function loadPreview() {
    const previewArea = document.getElementById('preview-area');
    if (previewArea) {
        previewArea.innerHTML = '<p>プレビューを読み込み中...</p>';
        // TODO: Implement actual preview
    }
}

// Preview functionality
let isPreviewMode = false;
let storedFormData = {};

// Collect all form data
function collectFormData() {
    // Start with stored data from sessionStorage (only contains submitted data)
    const storedData = sessionStorage.getItem('registerData');
    const data = storedData ? JSON.parse(storedData) : {};
    
    // Only read from form fields for steps that have been completed
    // This prevents showing default values from forms that haven't been submitted yet
    
    // Step 1: Header & Greeting (Step 1 in the current flow)
    if (completedSteps.has(1)) {
        const step1Form = document.getElementById('header-greeting-form');
        if (step1Form) {
            const formData = new FormData(step1Form);
            if (formData.get('company_name')) {
                data.company_name = formData.get('company_name');
            }
            
            // Logo
            const logoInput = document.getElementById('company_logo');
            if (logoInput && logoInput.files[0]) {
                data.company_logo = URL.createObjectURL(logoInput.files[0]);
            } else {
                const logoPreview = document.querySelector('#logo-upload .upload-preview img');
                if (logoPreview) data.company_logo = logoPreview.src;
            }
            
            // Profile photo
            const photoInput = document.getElementById('profile_photo_header');
            if (photoInput && photoInput.files[0]) {
                data.profile_photo = URL.createObjectURL(photoInput.files[0]);
            } else {
                const photoPreview = document.querySelector('#photo-upload-header .upload-preview img');
                if (photoPreview) data.profile_photo = photoPreview.src;
            }
            
            // Greetings
            data.greetings = [];
            const greetingTitles = formData.getAll('greeting_title[]');
            const greetingContents = formData.getAll('greeting_content[]');
            greetingTitles.forEach((title, index) => {
                if (title && greetingContents[index]) {
                    data.greetings.push({
                        title: title,
                        content: greetingContents[index]
                    });
                }
            });
        }
    }
    
    // Step 2: Company Profile
    if (completedSteps.has(2)) {
        const step2Form = document.getElementById('company-profile-form');
        if (step2Form) {
            const formData = new FormData(step2Form);
            if (formData.get('company_name_profile')) {
                data.company_name_profile = formData.get('company_name_profile');
            }
            if (formData.get('company_postal_code')) {
                data.company_postal_code = formData.get('company_postal_code');
            }
            if (formData.get('company_address')) {
                data.company_address = formData.get('company_address');
            }
            if (formData.get('company_phone')) {
                data.company_phone = formData.get('company_phone');
            }
            if (formData.get('company_website')) {
                data.company_website = formData.get('company_website');
            }
            if (formData.get('real_estate_license_prefecture')) {
                data.real_estate_license_prefecture = formData.get('real_estate_license_prefecture');
            }
            if (formData.get('real_estate_license_renewal_number')) {
                data.real_estate_license_renewal_number = formData.get('real_estate_license_renewal_number');
            }
            if (formData.get('real_estate_license_registration_number')) {
                data.real_estate_license_registration_number = formData.get('real_estate_license_registration_number');
            }
        }
    }
    
    // Step 3: Personal Info
    if (completedSteps.has(3)) {
        const step3Form = document.getElementById('personal-info-form');
        if (step3Form) {
            const formData = new FormData(step3Form);
            // Combine last_name and first_name
            const lastName = formData.get('last_name') || '';
            const firstName = formData.get('first_name') || '';
            if (lastName || firstName) {
                data.name = (lastName + ' ' + firstName).trim();
            }
            // Combine romaji names
            const lastNameRomaji = formData.get('last_name_romaji') || '';
            const firstNameRomaji = formData.get('first_name_romaji') || '';
            if (lastNameRomaji || firstNameRomaji) {
                data.name_romaji = (lastNameRomaji + ' ' + firstNameRomaji).trim();
            }
            if (formData.get('branch_department')) {
                data.branch_department = formData.get('branch_department');
            }
            if (formData.get('position')) {
                data.position = formData.get('position');
            }
            if (formData.get('mobile_phone')) {
                data.mobile_phone = formData.get('mobile_phone');
            }
            if (formData.get('birth_date')) {
                data.birth_date = formData.get('birth_date');
            }
            if (formData.get('current_residence')) {
                data.current_residence = formData.get('current_residence');
            }
            if (formData.get('hometown')) {
                data.hometown = formData.get('hometown');
            }
            if (formData.get('alma_mater')) {
                data.alma_mater = formData.get('alma_mater');
            }
            if (formData.get('hobbies')) {
                data.hobbies = formData.get('hobbies');
            }
            if (formData.get('free_input_text')) {
                data.free_input_text = formData.get('free_input_text');
            }
            if (formData.get('free_image_link')) {
                data.free_image_link = formData.get('free_image_link');
            }
            
            // Qualifications
            const qualifications = [];
            if (formData.get('qualification_takken')) qualifications.push('宅地建物取引士');
            if (formData.get('qualification_kenchikushi')) qualifications.push('建築士');
            const otherQuals = formData.get('qualifications_other');
            if (otherQuals) qualifications.push(otherQuals);
            if (qualifications.length > 0) {
                data.qualifications = qualifications.join('、');
            }
            
            // Free image
            const freeImageInput = document.getElementById('free_image');
            if (freeImageInput && freeImageInput.files[0]) {
                data.free_image = URL.createObjectURL(freeImageInput.files[0]);
            } else {
                const freeImagePreview = document.querySelector('#free-image-upload .upload-preview img');
                if (freeImagePreview) data.free_image = freeImagePreview.src;
            }
        }
    }
    
    // Step 4: Tech Tools
    if (completedSteps.has(4)) {
        const step4Form = document.getElementById('tech-tools-form');
        if (step4Form) {
            const formData = new FormData(step4Form);
            const selectedTools = formData.getAll('tech_tools[]');
            if (selectedTools.length > 0) {
                data.tech_tools = selectedTools;
            }
        }
    }
    
    // Step 5: Communication
    if (completedSteps.has(5)) {
        const step5Form = document.getElementById('communication-form');
        if (step5Form) {
            const formData = new FormData(step5Form);
            data.communication = {};
            
            // Message apps
            if (formData.get('comm_line')) {
                data.communication.line = {
                    name: 'LINE',
                    id: formData.get('comm_line_id') || '',
                    icon: '<img src="assets/images/icons/line.png" alt="LINE">'
                };
            }
            if (formData.get('comm_plus_message')) {
                data.communication.plus_message = {
                    name: '+メッセージ',
                    id: formData.get('comm_plus_message_id') || '',
                    icon: '<img src="assets/images/icons/message.png" alt="+メッセージ">'
                };
            }
            if (formData.get('comm_andpad')) {
                data.communication.andpad = {
                    name: 'Andpad',
                    id: formData.get('comm_andpad_id') || '',
                    icon: '<img src="assets/images/icons/andpad.png" alt="Andpad">'
                };
            }
            if (formData.get('comm_messenger')) {
                data.communication.messenger = {
                    name: 'Messenger',
                    id: formData.get('comm_messenger_id') || '',
                    icon: '<img src="assets/images/icons/messenger.png" alt="Messenger">'
                };
            }
            if (formData.get('comm_whatsapp')) {
                data.communication.whatsapp = {
                    name: 'WhatsApp',
                    id: formData.get('comm_whatsapp_id') || '',
                    icon: '<img src="assets/images/icons/whatsapp.png" alt="WhatsApp">'
                };
            }
            if (formData.get('comm_chatwork')) {
                data.communication.chatwork = {
                    name: 'Chatwork',
                    id: formData.get('comm_chatwork_id') || '',
                    icon: '<img src="assets/images/icons/chatwork.png" alt="Chatwork">'
                };
            }
            
            // SNS
            if (formData.get('comm_instagram')) {
                data.communication.instagram = {
                    name: 'Instagram',
                    url: formData.get('comm_instagram_url') || '',
                    icon: '<img src="assets/images/icons/instagram.png" alt="Instagram">'
                };
            }
            if (formData.get('comm_facebook')) {
                data.communication.facebook = {
                    name: 'Facebook',
                    url: formData.get('comm_facebook_url') || '',
                    icon: '<img src="assets/images/icons/facebook.png" alt="Facebook">'
                };
            }
            if (formData.get('comm_twitter')) {
                data.communication.twitter = {
                    name: 'X (Twitter)',
                    url: formData.get('comm_twitter_url') || '',
                    icon: '<img src="assets/images/icons/twitter.png" alt="X (Twitter)">'
                };
            }
            if (formData.get('comm_youtube')) {
                data.communication.youtube = {
                    name: 'YouTube',
                    url: formData.get('comm_youtube_url') || '',
                    icon: '<img src="assets/images/icons/youtube.png" alt="YouTube">'
                };
            }
            if (formData.get('comm_tiktok')) {
                data.communication.tiktok = {
                    name: 'TikTok',
                    url: formData.get('comm_tiktok_url') || '',
                    icon: '<img src="assets/images/icons/tiktok.png" alt="TikTok">'
                };
            }
            if (formData.get('comm_note')) {
                data.communication.note = {
                    name: 'note',
                    url: formData.get('comm_note_url') || '',
                    icon: '<img src="assets/images/icons/note.png" alt="note">'
                };
            }
            if (formData.get('comm_pinterest')) {
                data.communication.pinterest = {
                    name: 'Pinterest',
                    url: formData.get('comm_pinterest_url') || '',
                    icon: '<img src="assets/images/icons/pinterest.png" alt="Pinterest">'
                };
            }
            if (formData.get('comm_threads')) {
                data.communication.threads = {
                    name: 'Threads',
                    url: formData.get('comm_threads_url') || '',
                    icon: '<img src="assets/images/icons/threads.png" alt="Threads">'
                };
            }
        }
    }
    
    return data;
}

// Generate preview HTML - matches card.php layout
function generatePreview(data) {
    const techToolNames = {
        'mdb': '全国マンションデータベース',
        'rlp': '物件提案ロボ',
        'llp': '土地情報ロボ',
        'ai': 'AIマンション査定',
        'slp': 'セルフィン',
        'olp': 'オーナーコネクト'
    };
    
    const techToolDescriptions = {
        'slp': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><strong><span style="color: #000000;">AI評価付き『SelFin（セルフィン）』は消費者自ら</span></strong><span style="color: #ff0000;"><span style="font-weight: 700 !important;">「物件の資産性」を自動判定できる</span></span></span><span style="color: #000000;"><strong><span style="font-size: 14px;">ツールです。「価格の妥当性」「街力」「流動性」「耐震性」「管理費・修繕積立金の妥当性」を自動判定します。また物件提案ロボで配信される物件にはSelFin評価が付随します。</span></strong></span></p></div>',
        'rlp': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>AI評価付き『物件提案ロボ』は貴社顧客の希望条件に合致する不動産情報を「</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><strong><span style="color: #000000;">」で自動配信します。WEB上に登録になった</span></strong><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span></p></div>',
        'llp': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>『土地情報ロボ』は貴社顧客の希望条件に合致する不動産情報を「</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">御社名</span></span><span style="color: #000000;"><strong>」で自動配信します。WEB上に登録になった</strong></span><span style="color: #000000; font-weight: 700 !important;"><span style="color: #ff0000;">新着不動産情報を２４時間以内に、毎日自動配信</span></span><span style="color: #000000;"><strong>するサービスです。</strong></span></span></p></div>',
        'mdb': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span></p></div>',
        'ai': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #ff0000;"><strong>全国マンションデータベース（MDB)を売却案件の獲得の為に見せ方を変えたツール</strong></span><span style="color: #000000;"><strong>となります。大手仲介事業者のAI〇〇査定サイトのようなページとは異なり、</strong></span><span style="color: #ff0000;"><strong>誰でもマンションの価格だけは登録せずにご覧いただけるようなシステム</strong></span><strong><span style="color: #000000;">となっています。</span></strong></span></p></div>',
        'olp': '<div class="j-module n j-text"><p><span style="font-size: 14px;"><span style="color: #000000;"><strong>オーナーコネクトはマンション所有者様向けのサービスで、</strong></span><span style="color: #ff0000;"><span style="font-weight: 700 !important;">誰でも簡単に自宅の資産状況を確認できます。</span></span></span><span style="color: #000000;"><strong>登録されたマンションで新たに売り出し情報が出たらメールでお知らせいたします。</strong></span><span style="color: #000000;"><strong>また、</strong></span><span style="font-weight: 700 !important;"><span style="color: #ff0000;">毎週自宅の資産状況をまとめたレポートメールも送信</span></span><strong><span style="color: #000000;">いたします。</span></strong></span></p></div>'
    };
    
    const techToolBanners = {
        'slp': 'assets/images/tech_banner/slp.jpg',
        'rlp': 'assets/images/tech_banner/rlp.jpg',
        'llp': 'assets/images/tech_banner/llp.jpg',
        'mdb': 'assets/images/tech_banner/mdb.jpg',
        'ai': 'assets/images/tech_banner/ai.jpg',
        'olp': 'assets/images/tech_banner/olp.jpg'
    };
    
    let html = '<div class="card-container">';
    html += '<div class="card-section">';
    
    // Header Section (matching card.php)
    html += '<div class="card-header">';
    if (data.company_logo) {
        html += `<img src="${escapeHtml(data.company_logo)}" alt="ロゴ" class="company-logo">`;
    }
    const companyName = data.company_name || data.company_name_profile || '';
    if (companyName) {
        html += `<h1 class="company-name">${escapeHtml(companyName)}</h1>`;
    }
    html += '</div>';
    html += '<hr>';
    
    // Card Body
    html += '<div class="card-body">';
    
    // Profile photo and greeting section (matching card.php)
    html += '<div class="profile-greeting-section">';
    if (data.profile_photo) {
        html += '<div class="profile-photo-container">';
        html += `<img src="${escapeHtml(data.profile_photo)}" alt="プロフィール写真" class="profile-photo">`;
        html += '</div>';
    }
    
    // First greeting only
    html += '<div class="greeting-content">';
    if (data.greetings && data.greetings.length > 0) {
        const firstGreeting = data.greetings[0];
        if (firstGreeting && (firstGreeting.title || firstGreeting.content)) {
            html += '<div class="greeting-item">';
            if (firstGreeting.title) {
                html += `<h3 class="greeting-title">${escapeHtml(firstGreeting.title)}</h3>`;
            }
            if (firstGreeting.content) {
                html += `<p class="greeting-text">${escapeHtml(firstGreeting.content).replace(/\n/g, '<br>')}</p>`;
            }
            html += '</div>';
        }
    }
    html += '</div>'; // greeting-content
    html += '</div>'; // profile-greeting-section
    html += '</div>'; // card-body
    html += '<hr>';
    
    // Responsive two-column info layout (matching card.php)
    html += '<div class="card-body">';
    html += '<div class="info-responsive-grid">';
    
    // Company name
    if (companyName) {
        html += '<div class="info-section company-info">';
        html += '<h3>会社名</h3>';
        html += `<p>${escapeHtml(companyName)}</p>`;
        html += '</div>';
    }
    
    // License
    if (data.real_estate_license_registration_number) {
        html += '<div class="info-section">';
        html += '<h3>宅建業番号</h3>';
        let licenseText = '';
        if (data.real_estate_license_prefecture) licenseText += escapeHtml(data.real_estate_license_prefecture);
        if (data.real_estate_license_renewal_number) licenseText += `(${escapeHtml(data.real_estate_license_renewal_number)})`;
        licenseText += `第${escapeHtml(data.real_estate_license_registration_number)}号`;
        html += `<p>${licenseText}</p>`;
        html += '</div>';
    }
    
    // Address
    if (data.company_postal_code || data.company_address) {
        html += '<div class="info-section">';
        html += '<h3>所在地</h3>';
        if (data.company_postal_code) {
            html += `<p>〒${escapeHtml(data.company_postal_code)}</p>`;
        }
        if (data.company_address) {
            html += `<p>${escapeHtml(data.company_address)}</p>`;
        }
        html += '</div>';
    }
    
    // Company phone
    if (data.company_phone) {
        html += '<div class="info-section">';
        html += '<h3>会社電話番号</h3>';
        html += `<p>${escapeHtml(data.company_phone)}</p>`;
        html += '</div>';
    }
    
    // Website
    if (data.company_website) {
        html += '<div class="info-section">';
        html += '<h3>HP</h3>';
        html += `<p><a href="${escapeHtml(data.company_website)}" target="_blank">${escapeHtml(data.company_website)}</a></p>`;
        html += '</div>';
    }
    
    // Department / Position
    if (data.branch_department || data.position) {
        html += '<div class="info-section">';
        html += '<h3>部署 / 役職</h3>';
        let deptPosition = [data.branch_department, data.position].filter(Boolean).join(' / ');
        html += `<p>${escapeHtml(deptPosition)}</p>`;
        html += '</div>';
    }
    
    // Name
    if (data.name) {
        html += '<div class="info-section person-name-section">';
        html += '<h3>名前</h3>';
        html += `<p class="person-name-large">${escapeHtml(data.name)}`;
        if (data.name_romaji) {
            html += ` <span class="name-romaji">(${escapeHtml(data.name_romaji)})</span>`;
        }
        html += '</p>';
        html += '</div>';
    }
    
    // Mobile phone
    if (data.mobile_phone) {
        html += '<div class="info-section">';
        html += '<h3>携帯番号</h3>';
        html += `<p>${escapeHtml(data.mobile_phone)}</p>`;
        html += '</div>';
    }
    
    // Birthday
    if (data.birth_date) {
        html += '<div class="info-section">';
        html += '<h3>誕生日</h3>';
        html += `<p>${escapeHtml(data.birth_date)}</p>`;
        html += '</div>';
    }
    
    // Residence / Hometown
    if (data.current_residence || data.hometown) {
        html += '<div class="info-section">';
        html += '<h3>現在の居住地 / 出身地</h3>';
        let residenceText = [data.current_residence, data.hometown].filter(Boolean).join(' / ');
        html += `<p>${escapeHtml(residenceText)}</p>`;
        html += '</div>';
    }
    
    // Alma mater
    if (data.alma_mater) {
        html += '<div class="info-section">';
        html += '<h3>出身大学</h3>';
        html += `<p>${escapeHtml(data.alma_mater).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
    }
    
    // Qualifications
    if (data.qualifications) {
        html += '<div class="info-section">';
        html += '<h3>資格</h3>';
        html += `<p>${escapeHtml(data.qualifications).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
    }
    
    // Hobbies
    if (data.hobbies) {
        html += '<div class="info-section">';
        html += '<h3>趣味</h3>';
        html += `<p>${escapeHtml(data.hobbies).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
    }
    
    // Free input (Other)
    if (data.free_input !== '{"text":"","image_link":""}' && (data.free_input_text || data.free_image)) {
        html += '<div class="info-section">';
        html += '<h3>その他</h3>';
        html += '<div class="free-input-content" style="overflow-wrap: anywhere;">';
        
        if (data.free_input_text) {
            html += `<p class="free-input-text">${escapeHtml(data.free_input_text).replace(/\n/g, '<br>')}</p>`;
        }
        
        if (data.free_image) {
            html += '<div class="free-input-image">';
            html += `<img src="${escapeHtml(data.free_image)}" alt="アップロード画像" style="max-width: 100%; height: auto; border-radius: 4px; margin: 0.5rem 0; display: block;">`;
            html += '</div>';
        }
        
        if (data.free_image_link) {
            html += '<p class="free-input-link">';
            html += `<a href="${escapeHtml(data.free_image_link)}" target="_blank" rel="noopener noreferrer">${escapeHtml(data.free_image_link)}</a>`;
            html += '</p>';
        }
        
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>'; // info-responsive-grid
    
    html += '<hr>';
    
    // Tech Tools Section (matching card.php banner style)
    if (data.tech_tools && data.tech_tools.length > 0) {
        html += '<section class="tech-tools-section">';
        html += '<h2>不動産テックツール</h2>';
        html += '<p class="section-description">物件の購入・売却に役立つツールをご利用いただけます</p>';
        html += '<div class="tech-tools-grid">';
        
        data.tech_tools.forEach(tool => {
            const bannerImage = techToolBanners[tool] || 'assets/images/tech_banner/default.jpg';
            const description = techToolDescriptions[tool] || '';
            
            html += '<div class="tech-tool-banner-card">';
            html += `<div class="tool-banner-header" style="background-image: url('${bannerImage}'); background-size: contain; background-position: center; background-repeat: no-repeat; height: 200px;"></div>`;
            html += '<div class="tool-banner-content">';
            html += `<div class="tool-description">${description}</div>`;
            html += '<a href="#" class="tool-details-button" target="_blank">詳細はこちら</a>';
            html += '</div>';
            html += '</div>';
        });
        
        html += '</div>';
        html += '</div>';
    }
    
    html += '<hr>';
    
    // Communication Section (matching card.php)
    if (data.communication && Object.keys(data.communication).length > 0) {
        html += '<div class="communication-section">';
        html += '<h3>コミュニケーション方法</h3>';
        html += '<div class="communication-grid">';
        
        Object.entries(data.communication).forEach(([key, comm]) => {
            if (comm.url || comm.id) {
                html += '<div class="comm-card">';
                html += '<div class="comm-logo">';
                html += `<img src="assets/images/sns/${key}.png" alt="${escapeHtml(comm.name)}" onerror="this.style.display='none'; this.parentElement.innerHTML='${escapeHtml(comm.name)}';">`;
                html += '</div>';
                html += `<a href="${escapeHtml(comm.url || '#')}" class="comm-details-button" target="_blank">詳細はこちら</a>`;
                html += '</div>';
            }
        });
        
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>'; // card-body
    html += '</section>';
    html += '</div>'; // card-container
    
    return html;
}

function escapeHtml(text) {
    // Guard against non-string values (e.g. File/Blob objects) to avoid "[object Object]" URLs
    if (text === null || text === undefined) return '';
    if (typeof text !== 'string') return '';

    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Show preview
function showPreview() {
    const data = collectFormData();
    storedFormData = data;
    
    const previewContent = document.getElementById('preview-content');
    const previewContainer = document.getElementById('preview-container');
    const registerSteps = document.querySelector('.register-steps').parentElement;
    
    previewContent.innerHTML = generatePreview(data);
    previewContainer.style.display = 'block';
    
    // Hide all register steps
    document.querySelectorAll('.register-step').forEach(step => {
        step.style.display = 'none';
    });
    
    isPreviewMode = true;
}

// Hide preview
function hidePreview() {
    const previewContainer = document.getElementById('preview-container');
    previewContainer.style.display = 'none';
    
    // Show active register step
    document.querySelectorAll('.register-step').forEach(step => {
        if (step.classList.contains('active')) {
            step.style.display = 'block';
        }
    });
    
    isPreviewMode = false;
}


// Show image cropper modal for register page
function showRegisterImageCropper(file, fieldName, originalEvent) {
    const modal = document.getElementById('image-cropper-modal');
    const cropperImage = document.getElementById('cropper-image');
    
    if (!modal || !cropperImage) {
        // Fallback to preview if modal doesn't exist
        showRegisterImagePreview(file, fieldName, originalEvent);
        return;
    }
    
    // Step 1: Clean up previous state completely
    if (registerCropper) {
        try {
            registerCropper.destroy();
        } catch (e) {
            console.warn('Error destroying previous cropper:', e);
        }
        registerCropper = null;
    }
    
    // Revoke previous object URL if exists
    if (registerImageObjectURL) {
        try {
            URL.revokeObjectURL(registerImageObjectURL);
        } catch (e) {
            console.warn('Error revoking previous object URL:', e);
        }
        registerImageObjectURL = null;
    }
    
    // Remove previous onload handler if exists
    if (registerCropperImageLoadHandler) {
        cropperImage.removeEventListener('load', registerCropperImageLoadHandler);
        registerCropperImageLoadHandler = null;
    }
    
    // Reset image element completely
    cropperImage.onload = null;
    cropperImage.onerror = null;
    cropperImage.src = ''; // Clear src first
    
    // Step 2: Remove old event listeners from buttons (create new handlers)
    const cancelBtn = document.getElementById('crop-cancel-btn');
    const confirmBtn = document.getElementById('crop-confirm-btn');
    
    // Clone buttons to remove all event listeners
    if (cancelBtn) {
        const newCancelBtn = cancelBtn.cloneNode(true);
        cancelBtn.parentNode.replaceChild(newCancelBtn, cancelBtn);
        document.getElementById('crop-cancel-btn').onclick = null; // Clear any existing handlers
    }
    
    if (confirmBtn) {
        const newConfirmBtn = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirmBtn, confirmBtn);
        document.getElementById('crop-confirm-btn').onclick = null; // Clear any existing handlers
    }
    
    // Step 3: Store file and field name for later use
    registerCropFile = file;
    registerCropFieldName = fieldName;
    registerCropOriginalEvent = originalEvent;
    
    // Step 4: Create new object URL for the image
    registerImageObjectURL = URL.createObjectURL(file);
    
    // Step 5: Show modal
    modal.style.display = 'block';
    
    // Step 6: Set up image load handler
    registerCropperImageLoadHandler = function() {
        // Destroy any existing cropper (defensive)
        if (registerCropper) {
            try {
                registerCropper.destroy();
            } catch (e) {
                console.warn('Error destroying cropper in onload:', e);
            }
        }
        
        // Initialize cropper with aspect ratio
        const aspectRatio = fieldName === 'company_logo' ? 1 : 1; // Square for both
        try {
            registerCropper = new Cropper(cropperImage, {
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
            closeRegisterImageCropper();
        }
    };
    
    cropperImage.onerror = function() {
        console.error('Error loading image');
        showError('画像の読み込みに失敗しました');
        closeRegisterImageCropper();
    };
    
    // Set image src (this will trigger onload)
    cropperImage.src = registerImageObjectURL;
    
    // If image is already loaded (cached), trigger onload manually
    if (cropperImage.complete) {
        registerCropperImageLoadHandler();
    } else {
        cropperImage.onload = registerCropperImageLoadHandler;
    }
    
    // Step 7: Setup cancel button (after recreating it)
    const newCancelBtn = document.getElementById('crop-cancel-btn');
    if (newCancelBtn) {
        newCancelBtn.onclick = function() {
            // Simply close the modal without uploading, cropping, or updating preview
            closeRegisterImageCropper();
            // Reset file input so user can select the same file again if needed
            if (originalEvent && originalEvent.target) {
                originalEvent.target.value = '';
            }
        };
    }
    
    // Step 8: Setup confirm button (after recreating it)
    const newConfirmBtn = document.getElementById('crop-confirm-btn');
    if (newConfirmBtn) {
        newConfirmBtn.onclick = function() {
            cropAndStoreForRegister();
        };
    }
}

// Close image cropper for register page
function closeRegisterImageCropper() {
    const modal = document.getElementById('image-cropper-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.alignItems = '';
        modal.style.justifyContent = '';
    }
    
    // Destroy cropper instance
    if (registerCropper) {
        try {
            registerCropper.destroy();
        } catch (e) {
            console.warn('Error destroying cropper on close:', e);
        }
        registerCropper = null;
    }
    
    // Clean up object URL
    const cropperImage = document.getElementById('cropper-image');
    if (cropperImage) {
        // Remove event handlers
        if (registerCropperImageLoadHandler) {
            cropperImage.removeEventListener('load', registerCropperImageLoadHandler);
            cropperImage.onload = null;
            registerCropperImageLoadHandler = null;
        }
        cropperImage.onerror = null;
        
        // Revoke object URL
        if (registerImageObjectURL) {
            try {
                URL.revokeObjectURL(registerImageObjectURL);
            } catch (e) {
                console.warn('Error revoking object URL:', e);
            }
            registerImageObjectURL = null;
        }
        
        // Clear image src
        cropperImage.src = '';
    }
    
    // Clear state
    registerCropFile = null;
    registerCropFieldName = null;
    registerCropOriginalEvent = null;
}

// Crop and store image for register page
function cropAndStoreForRegister() {
    if (!registerCropper || !registerCropFile || !registerCropFieldName) {
        return;
    }
    
    // Find upload area using field name as fallback
    let uploadArea = null;
    if (registerCropOriginalEvent && registerCropOriginalEvent.target) {
        uploadArea = registerCropOriginalEvent.target.closest('.upload-area');
    }
    
    // If we can't find it from the event, try to find it by field name
    if (!uploadArea && registerCropFieldName) {
        const fieldId = registerCropFieldName === 'company_logo' ? 'company_logo' : 'profile_photo_header';
        const fieldElement = document.getElementById(fieldId);
        if (fieldElement) {
            uploadArea = fieldElement.closest('.upload-area');
        }
    }
    
    if (!uploadArea) {
        showError('アップロードエリアが見つかりません');
        return;
    }
    
    // Store file info in local variables before async operations
    const cropFileName = registerCropFile ? registerCropFile.name : 'cropped_image.png';
    const cropFileType = registerCropFile ? registerCropFile.type : 'image/png';
    
    try {
        // Get cropped canvas
        const canvas = registerCropper.getCroppedCanvas({
            width: 800,
            height: 800,
            imageSmoothingEnabled: true,
            imageSmoothingQuality: 'high'
        });
        
        // Convert canvas to blob
        canvas.toBlob(function(blob) {
            if (!blob) {
                showError('画像のトリミングに失敗しました');
                return;
            }
            
            // Show preview with cropped image
            const reader = new FileReader();
            reader.onload = (event) => {
                const preview = uploadArea.querySelector('.upload-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                }
                
                // Store cropped blob in a data attribute for later upload
                uploadArea.dataset.croppedBlob = event.target.result; // Store as data URL
                uploadArea.dataset.croppedFileName = cropFileName;
            };
            reader.readAsDataURL(blob);
            
            // Close cropper
            closeRegisterImageCropper();
        }, cropFileType, 0.95);
        
    } catch (error) {
        console.error('Crop error:', error);
        showError('画像のトリミング中にエラーが発生しました');
    }
}

// Show image preview (fallback)
function showRegisterImagePreview(file, fieldName, originalEvent) {
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

// Photo upload previews with cropping
document.getElementById('profile_photo_header')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
        showRegisterImageCropper(file, 'profile_photo_header', e);
    }
});

document.getElementById('company_logo')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file && file.type.startsWith('image/')) {
        showRegisterImageCropper(file, 'company_logo', e);
    }
});

document.getElementById('free_image')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = e.target.closest('.upload-area').querySelector('.upload-preview');
            if (preview) {
                // Get image dimensions
                const img = new Image();
                img.onload = () => {
                    const resizeNote = (img.width > 1200 || img.height > 1200) 
                        ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大1200×1200px)</p>` 
                        : '';
                                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
                };
                img.src = event.target.result;
            }
        };
        reader.readAsDataURL(file);
    }
});

// Add free input text textarea for register
// Add a paired item (text + image) for register
function addFreeInputPairForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const itemCount = container.querySelectorAll('.free-input-pair-item').length;
    const newPairItem = document.createElement('div');
    newPairItem.className = 'free-input-pair-item';
    newPairItem.style.marginTop = '2rem';
    newPairItem.style.paddingTop = '2rem';
    newPairItem.style.borderTop = '1px solid #e0e0e0';

    newPairItem.innerHTML = `
        <!-- Text Input -->
        <div class="form-group">
            <label>テキスト</label>
        <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx"></textarea>
        </div>
        <!-- Image/Banner Input -->
        <div class="form-group">
            <label>画像・バナー（リンク付き画像）</label>
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
        </div>
        <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)">削除</button>
    `;
    
    container.appendChild(newPairItem);

    // Initialize file input handler for the new item
    initializeFreeImageUploadForRegister(newPairItem);
    
    // Show delete buttons if there are multiple items
    updateFreeInputPairDeleteButtonsForRegister();
}

// Remove a paired item (text + image) for register
function removeFreeInputPairForRegister(button) {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-pair-item');
    if (items.length <= 1) {
        showWarning('最低1つのセットが必要です。');
        return;
    }
    
    const item = button.closest('.free-input-pair-item');
    if (item) {
        item.remove();
        updateFreeInputPairDeleteButtonsForRegister();
    }
}

// Update delete button visibility for paired items in register
function updateFreeInputPairDeleteButtonsForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-pair-item');
    const deleteButtons = container.querySelectorAll('.free-input-pair-item .btn-delete-small');
    
    if (items.length > 1) {
        deleteButtons.forEach(btn => btn.style.display = 'inline-block');
    } else {
        deleteButtons.forEach(btn => btn.style.display = 'none');
    }
}

// Legacy function kept for backwards compatibility
function removeFreeInputTextForRegister(button) {
    removeFreeInputPairForRegister(button);
}

// Legacy function kept for backwards compatibility
function updateFreeInputDeleteButtonsForRegister() {
    updateFreeInputPairDeleteButtonsForRegister();
}

// Add free image item for register
// Function removed - adding new image items is no longer supported
// function addFreeImageItemForRegister() {
//     const container = document.getElementById('free-images-container');
//     if (!container) return;
//
//     const itemCount = container.querySelectorAll('.free-image-item').length;
//     const newItem = document.createElement('div');
//     newItem.className = 'free-image-item';
//     newItem.innerHTML = `
//         <div class="upload-area" data-upload-id="free_image_${itemCount}">
//             <input type="file" name="free_image[]" accept="image/*" style="display: none;">
//             <div class="upload-preview"></div>
//             <button type="button" class="btn-outline" onclick="this.closest('.upload-area').querySelector('input[type=\\'file\\']').click()">
//                 画像をアップロード
//             </button>
//             <small>ファイルを選択するか、ここにドラッグ&ドロップしてください<br>対応形式：JPEG、PNG、GIF、WebP</small>
//         </div>
//         <div class="form-group" style="margin-top: 0.5rem;">
//             <label>画像のリンク先URL（任意）</label>
//             <input type="url" name="free_image_link[]" class="form-control" placeholder="https://example.com">
//         </div>
//         <button type="button" class="btn-delete-small" onclick="removeFreeImageItemForRegister(this)">削除</button>
//     `;
//
//     container.appendChild(newItem);
//
//     // Initialize file input handler for the new item
//     initializeFreeImageUploadForRegister(newItem);
//
//     // Show delete buttons if there are multiple items
//     updateFreeImageDeleteButtonsForRegister();
// }

// Remove free image item for register
function removeFreeImageItemForRegister(button) {
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
        updateFreeImageDeleteButtonsForRegister();
    }
}

// Update delete button visibility for free images in register
function updateFreeImageDeleteButtonsForRegister() {
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

// Initialize file upload handler for free image items in register
function initializeFreeImageUploadForRegister(item) {
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
                                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
                    };
                    img.src = event.target.result;
                }
            };
            reader.readAsDataURL(file);
        }
    });
}

// Initialize from session storage
window.addEventListener('DOMContentLoaded', () => {
    const savedData = sessionStorage.getItem('registerData');
    if (savedData) {
        formData = JSON.parse(savedData);
    }
    
    // Load completed steps
    const savedCompletedSteps = sessionStorage.getItem('completedSteps');
    if (savedCompletedSteps) {
        completedSteps = new Set(JSON.parse(savedCompletedSteps));
    }
    
    // Initialize greeting buttons
    updateGreetingButtons();
    
    // Initialize drag and drop for greeting items
    initializeGreetingDragAndDrop();
    
    // Initialize tech tool drag and drop
    setTimeout(() => {
        initializeTechToolDragAndDropForRegister();
        updateTechToolButtonsForRegister();
    }, 100);

    // Initialize preview button
    const previewBtn = document.getElementById('preview-btn');
    const closePreviewBtn = document.getElementById('close-preview-btn');
    
    if (previewBtn) {
        previewBtn.addEventListener('click', showPreview);
    }
    
    if (closePreviewBtn) {
        closePreviewBtn.addEventListener('click', hidePreview);
    }
});

// Move tech tool up or down for register
function moveTechToolForRegister(index, direction) {
    const container = document.getElementById('tech-tools-grid');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateTechToolButtonsForRegister();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateTechToolButtonsForRegister();
    }
}

// Update tech tool move buttons for register
function updateTechToolButtonsForRegister() {
    const container = document.getElementById('tech-tools-grid');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveTechToolForRegister(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveTechToolForRegister(${index}, 'down')`);
        }
    });
}

// Initialize drag and drop for tech tools in register
function initializeTechToolDragAndDropForRegister() {
    const container = document.getElementById('tech-tools-grid');
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
                    
                    updateTechToolButtonsForRegister();
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
                    if (node.nodeType === 1 && node.classList && node.classList.contains('tech-tool-banner-card')) {
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

// Initialize communication drag and drop on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize for both message apps and SNS
    setTimeout(() => {
        initializeCommunicationDragAndDrop('message');
        initializeCommunicationDragAndDrop('sns');
    }, 100);
});
