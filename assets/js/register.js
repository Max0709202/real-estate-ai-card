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
    if (typeof window !== 'undefined' && window.invitationToken && window.userType === 'existing') {
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
    
    // Set up mutual exclusivity for architect qualification checkboxes
    setupArchitectCheckboxMutualExclusivity();
    
    // Check URL parameter for step navigation (e.g., ?step=6 for payment)
    const urlParams = new URLSearchParams(window.location.search);
    const stepParam = urlParams.get('step');
    if (stepParam) {
        const stepNumber = parseInt(stepParam);
        if (stepNumber >= 1 && stepNumber <= 6) {
            // Navigate to the specified step
            setTimeout(() => {
                goToStep(stepNumber, true);
            }, 100);
        }
    }

    // 停止されたアカウントの場合、決済画面（step-6）に自動誘導
    if (typeof window !== 'undefined' && window.isCanceledAccount) {
        setTimeout(() => {
            goToStep(6);
        }, 500);
    }
    
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
                setTimeout(() => capitalizeFirstLetterForRegister(e.target), 0);
            });
            
            // Handle input event - skip during IME composition
            field.addEventListener('input', function(e) {
                // Skip if IME is composing or if event has isComposing flag
                if (isComposing || e.isComposing) {
                    return;
                }
                // Use setTimeout to ensure the value is updated before capitalization
                setTimeout(() => capitalizeFirstLetterForRegister(e.target), 0);
            });
            
            // Handle keyup for more reliable capitalization on PC
            field.addEventListener('keyup', function(e) {
                // Skip during IME composition
                if (isComposing || e.isComposing) {
                    return;
                }
                // Only capitalize on regular character keys (not special keys)
                if (e.key.length === 1 && /[a-zA-Z]/.test(e.key)) {
                    setTimeout(() => capitalizeFirstLetterForRegister(e.target), 0);
                }
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

// Set up mutual exclusivity for architect qualification checkboxes
// Only one of the three architect checkboxes can be checked at a time
// 宅地建物取引士 checkbox remains independent
function setupArchitectCheckboxMutualExclusivity() {
    const architectCheckboxes = [
        document.querySelector('input[name="qualification_kenchikushi_1"]'), // 一級建築士
        document.querySelector('input[name="qualification_kenchikushi_2"]'), // 二級建築士
        document.querySelector('input[name="qualification_kenchikushi_3"]')  // 木造建築士
    ];

    // Filter out null checkboxes (in case step 3 hasn't been rendered yet)
    const validCheckboxes = architectCheckboxes.filter(cb => cb !== null);

    if (validCheckboxes.length === 0) {
        return; // Checkboxes don't exist yet, will be set up when step 3 is shown
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

// Capitalize first letter helper function for register page
function capitalizeFirstLetterForRegister(input) {
    if (!input || !input.value) return;
    
    let value = input.value.trim();
    
    if (value.length > 0) {
        // 最初の文字が小文字（a-z）の場合は大文字に変換
        const firstChar = value.charAt(0);
        if (firstChar >= 'a' && firstChar <= 'z') {
            const cursorPosition = input.selectionStart || input.value.length;
            value = firstChar.toUpperCase() + value.slice(1);
            input.value = value;
            // カーソル位置を復元（ただし、先頭に挿入された場合は調整）
            const newCursorPos = cursorPosition > 0 ? cursorPosition : value.length;
            try {
                input.setSelectionRange(newCursorPos, newCursorPos);
            } catch (e) {
                // Some browsers may not support setSelectionRange on all input types
            }
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
        const response = await fetch('backend/api/business-card/get.php', {
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
            logoPreview.innerHTML = `<img src="${logoPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px;" onerror="this.style.display='none';">`;
            // Store existing image path for later use
            const logoUploadArea = document.querySelector('#logo-upload');
            if (logoUploadArea) {
                logoUploadArea.dataset.existingImage = data.company_logo;
            }
        }
    }
    
    // Profile photo preview
    if (data.profile_photo) {
        const photoPreview = document.querySelector('#photo-upload-header .upload-preview');
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
            photoPreview.innerHTML = `<img src="${photoPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px;" onerror="this.style.display='none';">`;
            // Store existing image path for later use
            const photoUploadArea = document.querySelector('#photo-upload-header');
            if (photoUploadArea) {
                photoUploadArea.dataset.existingImage = data.profile_photo;
            }
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
                        <div class="free-input-pair-header">
                            <span class="free-input-pair-number">${i + 1}</span>
                            <div class="free-input-pair-actions">
                                <button type="button" class="btn-move-up" onclick="moveFreeInputPairForRegister(${i}, 'up')" ${i === 0 ? 'disabled' : ''}>↑</button>
                                <button type="button" class="btn-move-down" onclick="moveFreeInputPairForRegister(${i}, 'down')" ${i === pairCount - 1 ? 'disabled' : ''}>↓</button>
                            </div>
                            <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)" ${pairCount <= 1 ? 'style="display: none;"' : ''}>削除</button>
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
                initializeFreeImageUploadForRegister(pairItem);
                
                // Initialize drag and drop for upload area
                const uploadArea = pairItem.querySelector('.upload-area');
                if (uploadArea) {
                    initializeDragAndDropForUploadAreaForRegister(uploadArea);
                }
            }
            
            // Initialize drag and drop for reordering after all items are loaded
            setTimeout(() => {
                initializeFreeInputPairDragAndDropForRegister();
                updateFreeInputPairButtonsForRegister();
                updateFreeInputPairNumbersForRegister();
            }, 100);
        } catch (e) {
            console.error('Error parsing free_input:', e);
        }
    }
    
    // Step 4: Tech Tools - Reorder based on display_order from database
    if (data.tech_tools && Array.isArray(data.tech_tools) && data.tech_tools.length > 0) {
        // Sort saved tech tools by display_order
        const sortedSavedTools = [...data.tech_tools].sort((a, b) => {
            const orderA = a.display_order !== undefined ? parseInt(a.display_order) : 999;
            const orderB = b.display_order !== undefined ? parseInt(b.display_order) : 999;
            return orderA - orderB;
        });
        
        // Get all tech tool cards from the grid
        const grid = document.getElementById('tech-tools-grid');
        if (grid) {
            const allCards = Array.from(grid.querySelectorAll('.tech-tool-banner-card'));
            const cardMap = {};
            allCards.forEach(card => {
                cardMap[card.dataset.toolType] = card;
            });
            
            // Create ordered list: selected tools first (in display_order), then unselected tools
            const selectedToolTypes = sortedSavedTools.map(tool => tool.tool_type);
            const defaultOrder = ['mdb', 'rlp', 'llp', 'ai', 'slp', 'olp', 'alp'];
            const unselectedToolTypes = defaultOrder.filter(toolType => 
                !selectedToolTypes.includes(toolType) && cardMap[toolType]
            );
            const finalOrder = [...selectedToolTypes, ...unselectedToolTypes];
            
            // Reorder cards in DOM
            finalOrder.forEach(toolType => {
                if (cardMap[toolType]) {
                    grid.appendChild(cardMap[toolType]);
                }
            });
            
            // Check checkboxes for active tools
            sortedSavedTools.forEach(tool => {
                if (tool.is_active) {
                    const checkbox = cardMap[tool.tool_type]?.querySelector(`input[name="tech_tools[]"][value="${tool.tool_type}"]`);
                    if (checkbox) checkbox.checked = true;
                    const card = cardMap[tool.tool_type];
                    if (card) card.classList.add('selected');
                }
            });
            
            // Update button indices after reordering
            setTimeout(() => {
                updateTechToolButtonsForRegister();
            }, 100);
        } else {
            // Fallback: just check checkboxes if grid not found
            data.tech_tools.forEach(tool => {
                if (tool.is_active) {
                    const checkbox = document.querySelector(`input[name="tech_tools[]"][value="${tool.tool_type}"]`);
                    if (checkbox) checkbox.checked = true;
                }
            });
        }
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

    // Set up mutual exclusivity for architect checkboxes after populating data
    setTimeout(() => {
        setupArchitectCheckboxMutualExclusivity();
    }, 100);
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
                    // Trim to prevent unwanted periods/whitespace
                    saveData.company_name = String(saveData.company_name_profile).trim();
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
                if (formData3.get('qualification_kenchikushi_1')) {
                    qualifications.push('一級建築士');
                }
                if (formData3.get('qualification_kenchikushi_2')) {
                    qualifications.push('二級建築士');
                }
                if (formData3.get('qualification_kenchikushi_3')) {
                    qualifications.push('木造建築士');
                }
                if (saveData.qualifications_other) {
                    qualifications.push(saveData.qualifications_other);
                }
                saveData.qualifications = qualifications.join('、');
                delete saveData.qualification_takken;
                delete saveData.qualification_kenchikushi_1;
                delete saveData.qualification_kenchikushi_2;
                delete saveData.qualification_kenchikushi_3;
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
            const response = await fetch('backend/api/business-card/update.php', {
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
    
    // Sync company name from step 1 (header-greeting) to step 2 (company-profile) when step 2 becomes active
    if (step === 2) {
        setTimeout(() => {
            const companyNameInput = document.querySelector('#header-greeting-form input[name="company_name"]');
            const companyProfileInput = document.querySelector('input[name="company_name_profile"]');
            if (companyProfileInput) {
                // Prioritize live input from step 1; fall back to database value
                const valueToUse = (companyNameInput && companyNameInput.value.trim())
                    ? companyNameInput.value.trim()
                    : (businessCardData && businessCardData.company_name) || '';
                companyProfileInput.value = valueToUse;
            }
        }, 100);
    }
    
    // Initialize free input pair drag and drop when step 3 becomes active
    if (step === 3) {
        setTimeout(() => {
            initializeFreeInputPairDragAndDropForRegister();
            // Set up mutual exclusivity for architect checkboxes
            setupArchitectCheckboxMutualExclusivity();
        }, 200);
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
        const response = await fetch(`backend/api/utils/postal-code-lookup.php?postal_code=${postalCode}`);
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
        const response = await fetch('backend/api/utils/license-lookup.php', {
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
                // Trim the company name to remove any accidental periods or whitespace
                const companyName = String(result.data.company_name).trim();
                document.querySelector('input[name="company_name_profile"]').value = companyName;
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
// Track submission state for each form to prevent double submissions (especially important for iPhone)
let isSubmittingStep1 = false;
let isSubmittingStep2 = false;
let isSubmittingStep3 = false;
let isSubmittingStep4 = false;
let isSubmittingStep5 = false;

document.getElementById('header-greeting-form')?.addEventListener('submit', async (e) => {
    // CRITICAL: Always prevent default form submission FIRST
    e.preventDefault();
    e.stopPropagation();

    // Prevent double submission (especially important for mobile/iOS)
    if (isSubmittingStep1) {
        console.log('Already submitting step 1, ignoring duplicate submission');
        return false;
    }
    isSubmittingStep1 = true;

    // Disable submit button and show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalButtonText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // IMPORTANT: Remove file input values from data object to prevent overwriting existing images
    // File inputs in FormData become empty File objects when no file is selected
    // These would be sent as empty/null values and overwrite existing database values
    delete data.company_logo;
    delete data.profile_photo;
    
    // Handle logo upload (check for cropped image first)
    const logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
    const logoFile = document.getElementById('company_logo').files[0];
    if (logoFile || (logoUploadArea && logoUploadArea.dataset.croppedBlob)) {
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
        } else {
            uploadData.append('file', logoFile);
        }
        uploadData.append('file_type', 'logo');
        
        try {
            const uploadUrl = (typeof window.getUploadUrl === 'function') ? window.getUploadUrl() : (window.BASE_URL ? window.BASE_URL + '/backend/api/business-card/upload.php' : window.location.origin + '/php/backend/api/business-card/upload.php');
            const UPLOAD_TIMEOUT_MS = 120000; // 2 minutes - image processing can be slow
            let uploadResponse;
            let lastError;
            for (let attempt = 0; attempt < 2; attempt++) {
                const uploadController = new AbortController();
                const uploadTimeoutId = setTimeout(() => uploadController.abort(), UPLOAD_TIMEOUT_MS);
                try {
                    uploadResponse = await fetch(uploadUrl, {
                        method: 'POST',
                        body: uploadData,
                        credentials: 'include',
                        signal: uploadController.signal
                    });
                    clearTimeout(uploadTimeoutId);
                    break;
                } catch (err) {
                    clearTimeout(uploadTimeoutId);
                    lastError = err;
                    if (attempt === 0 && (err.name === 'AbortError' || (err.message && err.message.includes('fetch')))) {
                        await new Promise(r => setTimeout(r, 1000)); // Wait 1s before retry
                        continue;
                    }
                    throw err;
                }
            }

            if (!uploadResponse.ok) {
                throw new Error(`HTTP error! status: ${uploadResponse.status}`);
            }
            
            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                // Extract relative path from absolute URL for database storage
                let relativePath = uploadResult.data.file_path;
                if (relativePath.startsWith('http://') || relativePath.startsWith('https://')) {
                    // Remove BASE_URL prefix to get relative path
                    if (typeof window !== 'undefined' && window.BASE_URL) {
                        // Remove BASE_URL if it's included
                        relativePath = relativePath.replace(window.BASE_URL, '').replace(/^\/+/, '');
                    } else {
                        // Fallback: extract path after domain
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
                }
                // Ensure path starts with backend/ if it's an upload path
                if (!relativePath.startsWith('backend/') && relativePath.includes('uploads/')) {
                    relativePath = 'backend/' + relativePath.replace(/^.*?uploads\//, 'uploads/');
                }
                data.company_logo = relativePath;
                console.log('Logo uploaded and saved to database:', relativePath);
                // Update preview after successful upload
                const logoUploadArea = document.querySelector('[data-upload-id="company_logo"]');
                if (logoUploadArea) {
                    const preview = logoUploadArea.querySelector('.upload-preview');
                    if (preview) {
                        // Construct full URL for display
                        let displayPath = relativePath;
                        // Remove BASE_URL if already included to avoid duplication
                        if (typeof window !== 'undefined' && window.BASE_URL && displayPath.startsWith(window.BASE_URL)) {
                            displayPath = displayPath.replace(window.BASE_URL + '/', '').replace(window.BASE_URL, '');
                        }
                        // Construct full URL
                        if (!displayPath.startsWith('http')) {
                            if (typeof window !== 'undefined' && window.BASE_URL) {
                                displayPath = window.BASE_URL + '/' + displayPath.replace(/^\/+/, '');
                            } else {
                                displayPath = '../' + displayPath;
                            }
                        }
                        preview.innerHTML = `<img src="${displayPath}" alt="ロゴ" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;" onerror="this.style.display='none';">`;
                        // Store the relative path for later use
                        logoUploadArea.dataset.existingImage = relativePath;
                        logoUploadArea.dataset.uploadedPath = relativePath;
                    }
                }
                // Log resize info
                if (uploadResult.data.was_resized) {
                    console.log('Logo auto-resized:', uploadResult.data);
                }
            }
        } catch (error) {
            console.error('Logo upload error:', error);
            const msg = (error && error.message === 'Failed to fetch')
                ? 'サーバーに接続できません。ネットワーク接続とURLを確認してください。'
                : (error && error.message) || 'ロゴのアップロードに失敗しました。';
            if (typeof showError === 'function') {
                showError(msg);
            } else {
                alert(msg);
            }
            isSubmittingStep1 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
            return;
        }
    } else {
        // Preserve existing logo - check multiple sources
        const logoUploadAreaForPreserve = document.querySelector('[data-upload-id="company_logo"]');
        if (logoUploadAreaForPreserve && logoUploadAreaForPreserve.dataset.uploadedPath) {
            // Use recently uploaded path (from this session)
            data.company_logo = logoUploadAreaForPreserve.dataset.uploadedPath;
        } else if (logoUploadAreaForPreserve && logoUploadAreaForPreserve.dataset.existingImage) {
            // Use existing image from database
            data.company_logo = logoUploadAreaForPreserve.dataset.existingImage;
    } else if (businessCardData && businessCardData.company_logo) {
            // Fallback to businessCardData
        data.company_logo = businessCardData.company_logo;
        }
        // If none found, don't set company_logo - API will preserve existing value
    }
    
    // Handle profile photo upload (check for cropped image first)
    const photoUploadArea = document.querySelector('[data-upload-id="profile_photo_header"]');
    const photoFile = document.getElementById('profile_photo_header').files[0];
    if (photoFile || (photoUploadArea && photoUploadArea.dataset.croppedBlob)) {
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
        } else {
            uploadData.append('file', photoFile);
        }
        uploadData.append('file_type', 'photo');
        
        try {
            const uploadUrl = (typeof window.getUploadUrl === 'function') ? window.getUploadUrl() : (window.BASE_URL ? window.BASE_URL + '/backend/api/business-card/upload.php' : window.location.origin + '/php/backend/api/business-card/upload.php');
            const UPLOAD_TIMEOUT_MS = 120000;
            let uploadResponse;
            for (let attempt = 0; attempt < 2; attempt++) {
                const uploadController = new AbortController();
                const uploadTimeoutId = setTimeout(() => uploadController.abort(), UPLOAD_TIMEOUT_MS);
                try {
                    uploadResponse = await fetch(uploadUrl, {
                        method: 'POST',
                        body: uploadData,
                        credentials: 'include',
                        signal: uploadController.signal
                    });
                    clearTimeout(uploadTimeoutId);
                    break;
                } catch (err) {
                    clearTimeout(uploadTimeoutId);
                    if (attempt === 0 && (err.name === 'AbortError' || (err.message && err.message.includes('fetch')))) {
                        await new Promise(r => setTimeout(r, 1000));
                        continue;
                    }
                    throw err;
                }
            }

            if (!uploadResponse.ok) {
                throw new Error(`HTTP error! status: ${uploadResponse.status}`);
            }
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
                // Update preview after successful upload
                const photoUploadAreaForPreview = document.querySelector('[data-upload-id="profile_photo_header"]');
                if (photoUploadAreaForPreview) {
                    const preview = photoUploadAreaForPreview.querySelector('.upload-preview');
                    if (preview) {
                        // Construct full URL for display
                        let displayPath = relativePath;
                        // Remove BASE_URL if already included to avoid duplication
                        if (typeof window !== 'undefined' && window.BASE_URL && displayPath.startsWith(window.BASE_URL)) {
                            displayPath = displayPath.replace(window.BASE_URL + '/', '').replace(window.BASE_URL, '');
                        }
                        // Construct full URL
                        if (!displayPath.startsWith('http')) {
                            if (typeof window !== 'undefined' && window.BASE_URL) {
                                displayPath = window.BASE_URL + '/' + displayPath.replace(/^\/+/, '');
                            } else {
                                displayPath = '../' + displayPath;
                            }
                        }
                        preview.innerHTML = `<img src="${displayPath}" alt="プロフィール写真" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;" onerror="this.style.display='none';">`;
                        // Store the relative path for later use
                        photoUploadAreaForPreview.dataset.existingImage = relativePath;
                        photoUploadAreaForPreview.dataset.uploadedPath = relativePath;
                    }
                }
                // Log resize info
                if (uploadResult.data.was_resized) {
                    console.log('Profile photo auto-resized:', uploadResult.data);
                }
            }
        } catch (error) {
            console.error('Photo upload error:', error);
            const msg = (error && error.message === 'Failed to fetch')
                ? 'サーバーに接続できません。ネットワーク接続とURLを確認してください。'
                : (error && error.message) || 'プロフィール写真のアップロードに失敗しました。';
            if (typeof showError === 'function') {
                showError(msg);
            } else {
                alert(msg);
            }
            isSubmittingStep1 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
            return;
        }
    } else {
        // Preserve existing profile photo - check multiple sources
        const photoUploadAreaForPreserve = document.querySelector('[data-upload-id="profile_photo_header"]');
        if (photoUploadAreaForPreserve && photoUploadAreaForPreserve.dataset.uploadedPath) {
            // Use recently uploaded path (from this session)
            data.profile_photo = photoUploadAreaForPreserve.dataset.uploadedPath;
        } else if (photoUploadAreaForPreserve && photoUploadAreaForPreserve.dataset.existingImage) {
            // Use existing image from database
            data.profile_photo = photoUploadAreaForPreserve.dataset.existingImage;
    } else if (businessCardData && businessCardData.profile_photo) {
            // Fallback to businessCardData
        data.profile_photo = businessCardData.profile_photo;
        }
        // If none found, don't set profile_photo - API will preserve existing value
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
        // Add timeout for mobile networks (30 seconds) and keepalive for iOS
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        const response = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include',
            signal: controller.signal,
            keepalive: true // Important for iOS to ensure request completes
        });
        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        if (result.success) {
            // Reload business card data to get updated company name
            await loadExistingBusinessCardData();
            goToStep(2);
        } else {
            showError('更新に失敗しました: ' + result.message);
            isSubmittingStep1 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        const errorMessage = error.message || 'ネットワークエラーが発生しました。接続を確認してください。';
        if (error.name === 'AbortError') {
            showError('タイムアウトしました。もう一度お試しください。');
        } else {
            showError('エラーが発生しました: ' + errorMessage);
        }
        isSubmittingStep1 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
    }
});

// Step 2: Company Profile
document.getElementById('company-profile-form')?.addEventListener('submit', async (e) => {
    // CRITICAL: Always prevent default form submission FIRST
    e.preventDefault();
    e.stopPropagation();

    // Prevent double submission
    if (isSubmittingStep2) {
        console.log('Already submitting step 2, ignoring duplicate submission');
        return false;
    }
    isSubmittingStep2 = true;

    // Disable submit button and show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalButtonText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }
    
    // Validate required fields for real estate license
    const prefecture = document.getElementById('license_prefecture').value;
    const renewal = document.getElementById('license_renewal').value;
    const registration = document.getElementById('license_registration').value.trim();
    
    if (!prefecture || !renewal || !registration) {
        showError('宅建業者番号（都道府県、更新番号、登録番号）は必須項目です。');
        isSubmittingStep2 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
        return false;
    }
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // Merge company_name from profile step and trim to prevent unwanted periods/whitespace
    if (data.company_name_profile) {
        data.company_name = String(data.company_name_profile).trim();
        delete data.company_name_profile;
    }
    
    formData = { ...formData, ...data };
    completedSteps.add(2); // Mark step 2 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
    try {
        // Add timeout for mobile networks (30 seconds) and keepalive for iOS
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        const response = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include',
            signal: controller.signal,
            keepalive: true // Important for iOS to ensure request completes
        });
        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        if (result.success) {
            goToStep(3);
        } else {
            showError('更新に失敗しました: ' + result.message);
            isSubmittingStep2 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        const errorMessage = error.message || 'ネットワークエラーが発生しました。接続を確認してください。';
        if (error.name === 'AbortError') {
            showError('タイムアウトしました。もう一度お試しください。');
        } else {
            showError('エラーが発生しました: ' + errorMessage);
        }
        isSubmittingStep2 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
    }
});

// Step 3: Personal Information
document.getElementById('personal-info-form')?.addEventListener('submit', async (e) => {
    // CRITICAL: Always prevent default form submission FIRST
    e.preventDefault();
    e.stopPropagation();

    // Prevent double submission
    if (isSubmittingStep3) {
        console.log('Already submitting step 3, ignoring duplicate submission');
        return false;
    }
    isSubmittingStep3 = true;

    // Disable submit button and show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalButtonText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }
    
    const formDataObj = new FormData(e.target);
    const data = Object.fromEntries(formDataObj);
    
    // IMPORTANT: Remove file input values from data object to prevent issues
    // File inputs in FormData become empty File objects or arrays when no file is selected
    delete data['free_image[]'];
    delete data.free_image;
    
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
    if (formDataObj.get('qualification_kenchikushi_1')) {
        qualifications.push('一級建築士');
    }
    if (formDataObj.get('qualification_kenchikushi_2')) {
        qualifications.push('二級建築士');
    }
    if (formDataObj.get('qualification_kenchikushi_3')) {
        qualifications.push('木造建築士');
    }
    if (data.qualifications_other) {
        qualifications.push(data.qualifications_other);
    }
    data.qualifications = qualifications.join('、');
    
    // Remove individual qualification fields
    delete data.qualification_takken;
    delete data.qualification_kenchikushi_1;
    delete data.qualification_kenchikushi_2;
    delete data.qualification_kenchikushi_3;
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
                const uploadUrl = (typeof window.getUploadUrl === 'function') ? window.getUploadUrl() : (window.BASE_URL ? window.BASE_URL + '/backend/api/business-card/upload.php' : window.location.origin + '/php/backend/api/business-card/upload.php');
                const UPLOAD_TIMEOUT_MS = 120000;
                let uploadResponse;
                for (let attempt = 0; attempt < 2; attempt++) {
                    const uploadController = new AbortController();
                    const uploadTimeoutId = setTimeout(() => uploadController.abort(), UPLOAD_TIMEOUT_MS);
                    try {
                        uploadResponse = await fetch(uploadUrl, {
                            method: 'POST',
                            body: uploadData,
                            credentials: 'include',
                            signal: uploadController.signal
                        });
                        clearTimeout(uploadTimeoutId);
                        break;
                    } catch (err) {
                        clearTimeout(uploadTimeoutId);
                        if (attempt === 0 && (err.name === 'AbortError' || (err.message && err.message.includes('fetch')))) {
                            await new Promise(r => setTimeout(r, 1000));
                            continue;
                        }
                        throw err;
                    }
                }
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
        // Add timeout for mobile networks (30 seconds) and keepalive for iOS
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        const response = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include',
            signal: controller.signal,
            keepalive: true // Important for iOS to ensure request completes
        });
        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        if (result.success) {
            goToStep(4);
        } else {
            showError('更新に失敗しました: ' + result.message);
            isSubmittingStep3 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        const errorMessage = error.message || 'ネットワークエラーが発生しました。接続を確認してください。';
        if (error.name === 'AbortError') {
            showError('タイムアウトしました。もう一度お試しください。');
        } else {
            showError('エラーが発生しました: ' + errorMessage);
        }
        isSubmittingStep3 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
    }
});

// Step 4: Tech Tools Selection
document.getElementById('tech-tools-form')?.addEventListener('submit', async (e) => {
    // CRITICAL: Always prevent default form submission FIRST
    e.preventDefault();
    e.stopPropagation();

    // Prevent double submission
    if (isSubmittingStep4) {
        console.log('Already submitting step 4, ignoring duplicate submission');
        return false;
    }
    isSubmittingStep4 = true;

    // Disable submit button and show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalButtonText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }
    
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
        isSubmittingStep4 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
        return false;
    }
    
    formData.tech_tools = selectedTools;
    completedSteps.add(4); // Mark step 4 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    try {
    // Generate tech tool URLs and save to database
    await generateTechToolUrls(selectedTools);

        // Clear dirty flag to prevent "unsaved changes" popup
    
    goToStep(5);
    } catch (error) {
        console.error('Error saving tech tools:', error);
        showError('テックツールの保存に失敗しました');
        isSubmittingStep4 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
    }
});

// Generate Tech Tool URLs and save to database
async function generateTechToolUrls(selectedTools) {
    try {
        // Step 1: Generate URLs with timeout and keepalive for iOS
        const urlController = new AbortController();
        const urlTimeoutId = setTimeout(() => urlController.abort(), 30000);

        const urlResponse = await fetch('backend/api/tech-tools/generate-urls.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ selected_tools: selectedTools }),
            credentials: 'include',
            signal: urlController.signal,
            keepalive: true // Important for iOS
        });
        clearTimeout(urlTimeoutId);

        if (!urlResponse.ok) {
            throw new Error(`HTTP error! status: ${urlResponse.status}`);
        }
        
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
        
        // Step 3: Save to database with timeout and keepalive for iOS
        const saveController = new AbortController();
        const saveTimeoutId = setTimeout(() => saveController.abort(), 30000);

        const saveResponse = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ tech_tools: techToolsForDB }),
            credentials: 'include',
            signal: saveController.signal,
            keepalive: true // Important for iOS
        });
        clearTimeout(saveTimeoutId);

        if (!saveResponse.ok) {
            throw new Error(`HTTP error! status: ${saveResponse.status}`);
        }
        
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
    // CRITICAL: Always prevent default form submission FIRST
    e.preventDefault();
    e.stopPropagation();

    // Prevent double submission
    if (isSubmittingStep5) {
        console.log('Already submitting step 5, ignoring duplicate submission');
        return false;
    }
    isSubmittingStep5 = true;

    // Disable submit button and show loading state
    const submitButton = e.target.querySelector('button[type="submit"]');
    const originalButtonText = submitButton ? submitButton.textContent : '';
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }
    
    const formDataObj = new FormData(e.target);
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
                        const url = formDataObj.get(methodInfo.urlField) || '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: url,
                            method_id: '',
                            display_order: displayOrder++
                        });
                    } else {
                        const id = formDataObj.get(methodInfo.idField) || '';
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
                        const url = formDataObj.get(methodInfo.urlField) || '';
                        communicationMethods.push({
                            method_type: methodType,
                            method_name: methodType,
                            method_url: url,
                            method_id: '',
                            display_order: displayOrder++
                        });
                    } else {
                        const id = formDataObj.get(methodInfo.idField) || '';
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
    
    formData = { ...formData, ...data };
    completedSteps.add(5); // Mark step 5 as completed
    sessionStorage.setItem('registerData', JSON.stringify(formData));
    sessionStorage.setItem('completedSteps', JSON.stringify(Array.from(completedSteps)));
    
    // Update business card
    try {
        // Add timeout for mobile networks (30 seconds) and keepalive for iOS
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 30000);

        const response = await fetch('backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data),
            credentials: 'include',
            signal: controller.signal,
            keepalive: true // Important for iOS to ensure request completes
        });
        clearTimeout(timeoutId);

        // Check if response is OK
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        if (result.success) {
            goToStep(6);
        } else {
            showError('更新に失敗しました: ' + result.message);
            isSubmittingStep5 = false;
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.textContent = originalButtonText;
            }
        }
    } catch (error) {
        console.error('Error:', error);
        const errorMessage = error.message || 'ネットワークエラーが発生しました。接続を確認してください。';
        if (error.name === 'AbortError') {
            showError('タイムアウトしました。もう一度お試しください。');
        } else {
            showError('エラーが発生しました: ' + errorMessage);
        }
        isSubmittingStep5 = false;
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = originalButtonText;
        }
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
        const response = await fetch('backend/api/payment/create-intent.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                payment_method: paymentMethod,
                payment_type: (typeof window !== 'undefined' && window.isCanceledAccount) ? 'new' : (formData.user_type || window.userType)
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

// Preview functionality
let isPreviewMode = false;

// Show preview - Display card.php in modal (same as edit.php)
async function showPreview() {
    // Load saved business card data to get slug and check if data exists
    let savedData = null;
    if (typeof loadExistingBusinessCardData === 'function') {
        await loadExistingBusinessCardData();
        savedData = window.businessCardData || businessCardData;
    } else {
        // Fallback: fetch data directly
        try {
            const response = await fetch('backend/api/business-card/get.php', {
                method: 'GET',
                credentials: 'include'
            });
            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    savedData = result.data;
                }
            }
        } catch (error) {
            console.error('Error loading business card data:', error);
        }
    }

    // Check if we have any data to display
    const hasData = savedData && (
        savedData.company_name ||
        savedData.name ||
        (savedData.greetings && savedData.greetings.length > 0) ||
        savedData.company_logo ||
        savedData.profile_photo ||
        savedData.real_estate_license_prefecture ||
        savedData.company_address ||
        Object.keys(savedData).length > 5 // More than just id, user_id, etc.
    );

    if (!hasData || !savedData || !savedData.url_slug) {
        if (typeof showWarning === 'function') {
            showWarning('表示するデータがありません。まず情報を入力して保存してください。');
        } else {
            alert('表示するデータがありません。まず情報を入力して保存してください。');
        }
        return;
    }

    // Get the URL slug
    const urlSlug = savedData.url_slug;

    // Detect if user is on PC (desktop) - if so, load mobile version
    const isPC = window.innerWidth > 768;

    // Create modal overlay
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'modal-overlay preview-modal';
    modalOverlay.id = 'register-preview-modal';
    modalOverlay.style.cssText = 'visibility: visible; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; opacity: 0; transition: opacity 0.3s;';

    // Create modal content
    const modalContent = document.createElement('div');
    modalContent.className = 'preview-modal-content';
    modalContent.id = 'preview-modal-content';
    // On PC, set initial width to mobile size, but allow expansion
    if (isPC) {
        modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center; min-width: 375px;';
            } else {
    modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: 100%; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column;';
    }
    
    // Create iframe to load card.php with preview mode
    const iframe = document.createElement('iframe');
    iframe.src = `card.php?slug=${encodeURIComponent(urlSlug)}&preview=1&preview_from_pc=${isPC ? '1' : '0'}`;
    // On PC, set iframe to mobile width to show smart version
    if (isPC) {
        iframe.style.cssText = 'width: 375px; height: 100%; border: none; flex: 1; min-height: 600px; max-width: 375px; margin: 0 auto;';
        modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center;';
            } else {
    iframe.style.cssText = 'width: 100%; height: 100%; border: none; flex: 1; min-height: 600px;';
    }
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('scrolling', 'yes');

    // Close button
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'preview-modal-close';
    closeButton.innerHTML = '×';
    closeButton.style.cssText = 'position: absolute; top: 1rem; right: 1rem; background: #fff; border: 2px solid #ddd; border-radius: 50%; width: 40px; height: 40px; cursor: pointer; font-size: 1.5rem; line-height: 1; display: flex; align-items: center; justify-content: center; color: #666; transition: all 0.3s; z-index: 10001;';
    closeButton.onmouseover = function() {
        this.style.background = '#f0f0f0';
        this.style.borderColor = '#999';
    };
    closeButton.onmouseout = function() {
        this.style.background = '#fff';
        this.style.borderColor = '#ddd';
    };
    closeButton.onclick = function() {
        hidePreview();
    };

    // Assemble modal
    modalContent.appendChild(iframe);
    modalContent.appendChild(closeButton);
    modalOverlay.appendChild(modalContent);

    // Close on overlay click
    modalOverlay.onclick = function(e) {
        if (e.target === modalOverlay) {
            hidePreview();
        }
    };

    // Add to body
    document.body.appendChild(modalOverlay);

    // Trigger animation
    setTimeout(() => {
        modalOverlay.style.opacity = '1';
    }, 10);

    // Handle resize to switch between PC/mobile views (optional UX enhancement)
    window.addEventListener('resize', function resizeHandler() {
        const modalContentEl = document.getElementById('preview-modal-content');
        const iframeEl = modalContentEl ? modalContentEl.querySelector('iframe') : null;
        if (!modalContentEl) {
            window.removeEventListener('resize', resizeHandler);
            return;
        }
        const newIsPC = window.innerWidth > 768;
        if (newIsPC) {
            modalContentEl.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center; min-width: 375px;';
            if (iframeEl) {
                iframeEl.style.cssText = 'width: 375px; height: 100%; border: none; flex: 1; min-height: 600px; max-width: 375px; margin: 0 auto;';
            }
        }
    });

    isPreviewMode = true;
}

// Hide preview - Close the modal
function hidePreview() {
    const modalOverlay = document.getElementById('register-preview-modal');
    if (modalOverlay) {
        modalOverlay.style.opacity = '0';
        setTimeout(() => {
            if (document.body.contains(modalOverlay)) {
                document.body.removeChild(modalOverlay);
        }
        }, 300);
    }
    isPreviewMode = false;
}


// Ensure image cropper modal exists in DOM - create if missing (same as edit.js)
function ensureRegisterCropperModalExists() {
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

// Show image cropper modal for register page (same flow as edit.js)
function showRegisterImageCropper(file, fieldName, originalEvent) {
    try {
        const { modal, cropperContainer } = ensureRegisterCropperModalExists();

        if (!modal || !cropperContainer) {
            console.warn('[showRegisterImageCropper] Modal or container not found after ensure, falling back to preview');
            showRegisterImagePreview(file, fieldName, originalEvent);
            return;
        }

        // Ensure modal is direct child of body - prevents it from being hidden by section layout (same as edit.js)
        try {
            if (modal.parentElement !== document.body) {
                document.body.appendChild(modal);
            }
        } catch (e) {
            console.error('[showRegisterImageCropper] Failed to append modal to body:', e);
        }

        // Ensure modal is visible immediately (same as edit.js)
    modal.style.display = 'flex';
    modal.style.visibility = 'visible';
    modal.style.opacity = '1';
    modal.classList.add('show');
    modal.style.zIndex = '10001';

    // Close when clicking overlay background (same as edit.js)
    const overlayClickHandler = function(e) {
        if (e.target === modal) {
            closeRegisterImageCropper();
            modal.removeEventListener('click', overlayClickHandler);
        }
    };
    modal.removeEventListener('click', overlayClickHandler);
    modal.addEventListener('click', overlayClickHandler);

        // Revoke previous object URL before creating new one (same as edit.js)
        if (registerImageObjectURL) {
            try {
                URL.revokeObjectURL(registerImageObjectURL);
            } catch (e) {
                console.warn('[showRegisterImageCropper] Error revoking previous object URL:', e);
            }
            registerImageObjectURL = null;
        }

        try {
            registerImageObjectURL = URL.createObjectURL(file);
        } catch (e) {
            console.error('[showRegisterImageCropper] Failed to create object URL:', e);
            if (typeof showError === 'function') {
                showError('画像の読み込みに失敗しました: ' + (e.message || ''));
            } else {
                alert('画像の読み込みに失敗しました: ' + (e.message || ''));
            }
            return;
        }

        registerCropFile = file;
    registerCropFieldName = fieldName;
    registerCropOriginalEvent = originalEvent;

    // Helper: setup cancel/confirm button handlers (same as edit.js)
    function setupRegisterCropperButtons() {
        const newCancelBtn = document.getElementById('crop-cancel-btn');
        const newConfirmBtn = document.getElementById('crop-confirm-btn');
        if (newCancelBtn) {
            newCancelBtn.onclick = function() {
                if (file && originalEvent && originalEvent.target) {
                    const uploadArea = originalEvent.target.closest('.upload-area');
                    if (uploadArea) {
                        const reader = new FileReader();
                        reader.onload = (event) => {
                            uploadArea.dataset.originalFile = JSON.stringify({ name: file.name, type: file.type, size: file.size });
                            uploadArea.dataset.originalFileData = event.target.result;
                            uploadArea.dataset.originalFieldName = fieldName;
                            const preview = uploadArea.querySelector('.upload-preview');
                            if (preview) {
                                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                            }
                        };
                        reader.readAsDataURL(file);
                    }
                }
                closeRegisterImageCropper();
            };
        }
        if (newConfirmBtn) {
            newConfirmBtn.onclick = function() { cropAndStoreForRegister(); };
        }
    }

    // Case A: Cropper already exists - use replace() for 2nd+ image (same as edit.js)
    if (registerCropper) {
        try {
            registerCropper.replace(registerImageObjectURL);
            setupRegisterCropperButtons();
            return;
        } catch (e) {
            console.warn('Cropper replace failed, falling back to full reinit:', e);
            try {
                registerCropper.destroy();
            } catch (e2) { /* ignore */ }
            registerCropper = null;
        }
    }

    // Case B: No cropper - full init. Reset container (same as edit.js)
    registerCropperImageLoadHandler = null;
    cropperContainer.innerHTML = '';
    const newImg = document.createElement('img');
    newImg.id = 'cropper-image';
    newImg.style.cssText = 'max-width: 100%; max-height: 60vh; display: block; object-fit: contain; width: auto; height: auto;';
    cropperContainer.appendChild(newImg);

    // Remove old event listeners from buttons (clone to clear) - same as edit.js
    const cancelBtn = document.getElementById('crop-cancel-btn');
    const confirmBtn = document.getElementById('crop-confirm-btn');
    if (cancelBtn && cancelBtn.parentNode) {
        cancelBtn.parentNode.replaceChild(cancelBtn.cloneNode(true), cancelBtn);
    }
    if (confirmBtn && confirmBtn.parentNode) {
        confirmBtn.parentNode.replaceChild(confirmBtn.cloneNode(true), confirmBtn);
    }

    // Set up image load handler (same as edit.js)
    setTimeout(() => {
        registerCropperImageLoadHandler = function() {
            if (registerCropper) {
                try {
                    registerCropper.destroy();
                } catch (e) {
                    console.warn('Error destroying cropper in onload:', e);
                }
            }
            setTimeout(() => {
                const aspectRatio = fieldName === 'company_logo' ? 1 : 1;
                try {
                    if (typeof Cropper === 'undefined') {
                        throw new Error('Cropper.js is not loaded');
                    }
                    registerCropper = new Cropper(newImg, {
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
                    showRegisterImagePreview(file, fieldName, originalEvent);
                    closeRegisterImageCropper();
                }
            }, 50);
        };

        newImg.onerror = function() {
            console.error('Error loading image');
            if (typeof showError === 'function') {
                showError('画像の読み込みに失敗しました');
            } else {
                alert('画像の読み込みに失敗しました');
            }
            closeRegisterImageCropper();
        };

        newImg.src = registerImageObjectURL;

        if (newImg.complete) {
            const currentSrc = newImg.src;
            newImg.src = '';
            setTimeout(() => {
                newImg.src = currentSrc;
                if (newImg.complete && registerCropperImageLoadHandler) {
                    registerCropperImageLoadHandler();
                }
            }, 10);
        } else {
            newImg.onload = registerCropperImageLoadHandler;
        }
    }, 150);

        setupRegisterCropperButtons();
    } catch (e) {
        console.error('[showRegisterImageCropper] Unhandled error:', e);
        if (typeof showError === 'function') {
            showError('画像トリミングの表示に失敗しました: ' + (e.message || ''));
        } else {
            alert('画像トリミングの表示に失敗しました: ' + (e.message || ''));
        }
        closeRegisterImageCropper();
    }
}

// Close image cropper for register page
function closeRegisterImageCropper() {
    const modal = document.getElementById('image-cropper-modal');
    if (modal) {
        modal.style.display = 'none';
        modal.style.visibility = 'hidden';
        modal.style.opacity = '0';
        modal.classList.remove('show');
    }

    // Reset file inputs so change event fires on next upload (same as edit.js)
    const logoInput = document.getElementById('company_logo');
    const photoInput = document.getElementById('profile_photo_header');
    if (logoInput) logoInput.value = '';
    if (photoInput) photoInput.value = '';

    if (registerCropper) {
        try {
            registerCropper.destroy();
        } catch (e) {
            console.warn('Error destroying cropper on close:', e);
        }
        registerCropper = null;
    }

    const cropperImage = document.getElementById('cropper-image');
    if (cropperImage) {
        if (registerCropperImageLoadHandler) {
            cropperImage.removeEventListener('load', registerCropperImageLoadHandler);
            cropperImage.onload = null;
            registerCropperImageLoadHandler = null;
        }
        cropperImage.onerror = null;

        if (registerImageObjectURL) {
            try {
                URL.revokeObjectURL(registerImageObjectURL);
            } catch (e) {
                console.warn('Error revoking object URL:', e);
            }
            registerImageObjectURL = null;
        }

        cropperImage.src = '';
    }

    registerCropFile = null;
    registerCropFieldName = null;
    registerCropOriginalEvent = null;
}

// Crop and store image for register page (stores blob, uploads on form save)
function cropAndStoreForRegister() {
    if (!registerCropper || !registerCropFile || !registerCropFieldName) {
        return;
    }

    let uploadArea = null;
    if (registerCropOriginalEvent && registerCropOriginalEvent.target) {
        uploadArea = registerCropOriginalEvent.target.closest('.upload-area');
    }

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
    
    const cropFileName = registerCropFile ? registerCropFile.name : 'cropped_image.png';
    const cropFileType = registerCropFile ? registerCropFile.type : 'image/png';

    try {
        const canvas = registerCropper.getCroppedCanvas({
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
                uploadArea.dataset.croppedFieldName = registerCropFieldName;
            };
            reader.readAsDataURL(blob);

            closeRegisterImageCropper(); // Also resets file inputs for next upload
        }, cropFileType, 0.95);
    } catch (error) {
        console.error('Crop error:', error);
        showError('画像のトリミング中にエラーが発生しました');
    }
}

// Show image preview (fallback - stores file, uploads on form save)
function showRegisterImagePreview(file, fieldName, originalEvent) {
    const uploadArea = originalEvent.target.closest('.upload-area');
    if (!uploadArea) return;
    
    const reader = new FileReader();
    reader.onload = (event) => {
        const img = new Image();
        img.onload = () => {
            // Check if resizing is needed
            let resizeNote = '';
            if (img.width > 800 || img.height > 800) {
                resizeNote = '<br><small style="color: #666;">※画像は自動的にリサイズされます</small>';
            }
            const preview = uploadArea.querySelector('.upload-preview');
            if (preview) {
                preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">${resizeNote}`;
            }
        };
        img.src = event.target.result;
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

// Photo upload previews with cropping for register page
// Use event delegation to handle file changes (same as edit.js - works for subsequent uploads)
document.addEventListener('change', function(e) {
    if (e.target.id === 'profile_photo_header' || e.target.id === 'company_logo') {
        const file = e.target.files?.[0];
        if (file) {
            if (file.type && file.type.startsWith('image/')) {
                const fieldName = e.target.id === 'company_logo' ? 'company_logo' : 'profile_photo_header';
                showRegisterImageCropper(file, fieldName, e);
            } else {
                console.warn('Invalid file type:', file.type);
                if (typeof showWarning === 'function') {
                    showWarning('画像ファイルを選択してください');
                } else {
                    alert('画像ファイルを選択してください');
                }
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
                        let resizeNote = '';
                        if (img.width > 800 || img.height > 800) {
                            resizeNote = '<br><small style="color: #666;">※画像は自動的にリサイズされます</small>';
                        }
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

// Initialize free input pairs - add button handler using event delegation
document.addEventListener('DOMContentLoaded', function() {
    // Use event delegation for add/remove buttons
    const container = document.querySelector('.section-content');
    if (!container) return;

    container.addEventListener('click', function(e) {
        // Handle add button
        if (e.target.id === 'add-free-input-pair' || e.target.closest('#add-free-input-pair')) {
            addFreeInputPair();
        }
        // Handle remove button
        if (e.target.classList.contains('remove-pair-btn') || e.target.closest('.remove-pair-btn')) {
            const btn = e.target.classList.contains('remove-pair-btn') ? e.target : e.target.closest('.remove-pair-btn');
            const pairItem = btn.closest('.free-input-pair-item');
            if (pairItem) {
                removeFreeInputPair(pairItem);
            }
        }
    });
});

// Initialize file inputs for free input pairs
function initializeFreeInputFileInput(pairItem) {
    const fileInput = pairItem.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const uploadArea = pairItem.querySelector('.upload-area');
        if (!uploadArea) return;
        
        const reader = new FileReader();
        reader.onload = (event) => {
            const img = new Image();
            img.onload = () => {
                let resizeNote = '';
                if (img.width > 800 || img.height > 800) {
                    resizeNote = '<br><small style="color: #666;">※画像は自動的にリサイズされます</small>';
                }
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
    });
}

// Initialize drag and drop for free input pairs
function initializeFreeInputDragAndDrop(pairItem) {
    const uploadArea = pairItem.querySelector('.upload-area');
    const fileInput = pairItem.querySelector('input[type="file"]');
    if (!uploadArea || !fileInput) return;
    
    // Remove existing listeners if any (by cloning the element)
    const newFileInput = fileInput.cloneNode(true);
    fileInput.parentNode.replaceChild(newFileInput, fileInput);
    
    newFileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = (event) => {
            const img = new Image();
            img.onload = () => {
                let resizeNote = '';
                if (img.width > 800 || img.height > 800) {
                    resizeNote = '<br><small style="color: #666;">※画像は自動的にリサイズされます</small>';
                }
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
    });
    
    // Drag and drop setup
    if (uploadArea.dataset.dragInitialized === 'true') return;
    uploadArea.dataset.dragInitialized = 'true';

    uploadArea.addEventListener('dragenter', function(e) {
        e.preventDefault();
        e.stopPropagation();
    });
    
    uploadArea.addEventListener('dragover', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.add('drag-over');
    });
    
    uploadArea.addEventListener('dragleave', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('drag-over');
    });
    
    uploadArea.addEventListener('drop', function(e) {
        e.preventDefault();
        e.stopPropagation();
        uploadArea.classList.remove('drag-over');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            const file = files[0];
            if (file.type.startsWith('image/')) {
                try {
                    const dataTransfer = new DataTransfer();
                    dataTransfer.items.add(file);
                    newFileInput.files = dataTransfer.files;

                    const event = new Event('change', { bubbles: true, cancelable: true });
                    newFileInput.dispatchEvent(event);
                } catch (error) {
                    console.warn('DataTransfer not supported, using fallback:', error);
                    try {
                        newFileInput.files = files;
                        const event = new Event('change', { bubbles: true, cancelable: true });
                        newFileInput.dispatchEvent(event);
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
    
    uploadArea.addEventListener('click', function(e) {
        if (e.target.tagName !== 'BUTTON' && e.target.tagName !== 'IMG') {
            newFileInput.click();
        }
    });
}

// Restore form data from sessionStorage on page load
document.addEventListener('DOMContentLoaded', function() {
    const savedData = sessionStorage.getItem('registerData');
    if (savedData) {
        try {
            const data = JSON.parse(savedData);
            console.log('Restoring form data from sessionStorage:', Object.keys(data));
            
            // Note: Only restore simple text fields
            // Image blob URLs will be invalid after page refresh
        } catch (error) {
            console.error('Error restoring form data:', error);
        }
    }
    
    const savedCompletedSteps = sessionStorage.getItem('completedSteps');
    if (savedCompletedSteps) {
        try {
            const steps = JSON.parse(savedCompletedSteps);
            completedSteps = new Set(steps);
            console.log('Restored completed steps:', steps);
            // Keep step 1 active on load - user can click step indicators to jump to completed steps
        } catch (error) {
            console.error('Error restoring completed steps:', error);
        }
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
    // No border/margin on new item (it's at the top)

    const currentCount = container.querySelectorAll('.free-input-pair-item').length;
    newPairItem.innerHTML = `
        <div class="free-input-pair-header">
            <span class="free-input-pair-number">${currentCount + 1}</span>
            <div class="free-input-pair-actions">
                <button type="button" class="btn-move-up" onclick="moveFreeInputPairForRegister(${currentCount}, 'up')">↑</button>
                <button type="button" class="btn-move-down" onclick="moveFreeInputPairForRegister(${currentCount}, 'down')">↓</button>
            </div>
            <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)">削除</button>
        </div>
        <!-- Text Input -->
        <div class="form-group">
            <label>テキスト</label>
        <textarea name="free_input_text[]" class="form-control" rows="4" placeholder="自由に入力してください。&#10;例：YouTubeリンク: https://www.youtube.com/watch?v=xxxxx"></textarea>
        </div>
        <!-- Image Input -->
        <div class="form-group">
            <label>画像</label>
            <div class="upload-area" data-upload-id="free_image_${currentCount}">
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
    `;
    
    container.appendChild(newPairItem);
    updateFreeInputPairNumbersForRegister();
    updateFreeInputPairButtonsForRegister();
    
    // Initialize drag and drop for the new upload area
    const uploadArea = newPairItem.querySelector('.upload-area');
    if (uploadArea) {
        initializeDragAndDropForUploadAreaForRegister(uploadArea);
    }
}

// Remove a paired item for register
function removeFreeInputPairForRegister(button) {
    const pairItem = button.closest('.free-input-pair-item');
    if (!pairItem) return;
    
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    // Check if this is the last item
    const items = container.querySelectorAll('.free-input-pair-item');
    if (items.length <= 1) {
        if (typeof showWarning === 'function') {
            showWarning('少なくとも1つのアイテムが必要です');
        } else {
            alert('少なくとも1つのアイテムが必要です');
        }
        return;
    }
    
    pairItem.remove();
    updateFreeInputPairNumbersForRegister();
    updateFreeInputPairButtonsForRegister();
}

// Update delete buttons visibility for register
function updateFreeInputPairDeleteButtonsForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-pair-item');
    items.forEach((item, index) => {
        const deleteBtn = item.querySelector('.btn-delete-small');
        if (deleteBtn) {
            // Show delete button only if there's more than one item
            deleteBtn.style.display = items.length > 1 ? 'block' : 'none';
        }
    });
}

// Remove free input text
function removeFreeInputTextForRegister(button) {
    // Not used anymore, keep for backwards compatibility
}

// Initialize drag and drop for free input pairs
function initializeFreeInputPairDragAndDropForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    let draggedElement = null;
    let isInitializing = false;
    
    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;
        
        const items = container.querySelectorAll('.free-input-pair-item');
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
        const items = container.querySelectorAll('.free-input-pair-item');
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
                container.querySelectorAll('.free-input-pair-item').forEach(item => {
                    item.classList.remove('drag-over');
                });
            });
            
            item.addEventListener('dragover', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.dataTransfer.dropEffect = 'move';
                this.classList.add('drag-over');
            });
            
            item.addEventListener('dragleave', function(e) {
                this.classList.remove('drag-over');
            });
            
            item.addEventListener('drop', function(e) {
                e.preventDefault();
                e.stopPropagation();
                this.classList.remove('drag-over');
                
                if (draggedElement !== this) {
                    const allItems = Array.from(container.querySelectorAll('.free-input-pair-item'));
                    const draggedIndex = allItems.indexOf(draggedElement);
                    const dropIndex = allItems.indexOf(this);
                    
                    if (draggedIndex < dropIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }
                    
                    updateFreeInputPairNumbersForRegister();
                    updateFreeInputPairButtonsForRegister();
                }
            });
        });
    }
    
    makeItemsDraggable();
    
    // Observe for new items
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                makeItemsDraggable();
            }
        });
    });
    
    observer.observe(container, { childList: true });
}

// Move free input pair up or down
function moveFreeInputPairForRegister(index, direction) {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateFreeInputPairNumbersForRegister();
        updateFreeInputPairButtonsForRegister();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateFreeInputPairNumbersForRegister();
        updateFreeInputPairButtonsForRegister();
    }
}

// Update free input pair move buttons
function updateFreeInputPairButtonsForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveFreeInputPairForRegister(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveFreeInputPairForRegister(${index}, 'down')`);
        }
    });
    
    // Also update delete buttons visibility
    updateFreeInputPairDeleteButtonsForRegister();
}

// Update free input pair numbers
function updateFreeInputPairNumbersForRegister() {
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

// Update delete buttons visibility
function updateFreeInputDeleteButtonsForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-input-pair-item');
    items.forEach((item, index) => {
        const deleteBtn = item.querySelector('.btn-delete-small');
        if (deleteBtn) {
            // Don't show delete button if it's the last remaining item
            deleteBtn.style.display = items.length > 1 ? '' : 'none';
        }
    });
}

// Free image functions
function removeFreeImageItemForRegister(button) {
    const item = button.closest('.free-image-item');
    if (!item) return;
    
    const container = document.getElementById('free-images-container');
    const items = container.querySelectorAll('.free-image-item');
    
    if (items.length <= 1) {
        if (typeof showWarning === 'function') {
            showWarning('少なくとも1枚の画像アップロードエリアが必要です');
        } else {
            alert('少なくとも1枚の画像アップロードエリアが必要です');
        }
        return;
    }
    
    item.remove();
    updateFreeImageDeleteButtonsForRegister();
}

function updateFreeImageDeleteButtonsForRegister() {
    const container = document.getElementById('free-images-container');
    if (!container) return;
    
    const items = container.querySelectorAll('.free-image-item');
    items.forEach((item, index) => {
        const deleteBtn = item.querySelector('.btn-delete-small');
        if (deleteBtn) {
            deleteBtn.style.display = items.length > 1 ? '' : 'none';
        }
    });
}

function initializeFreeImageUploadForRegister(item) {
    const uploadArea = item.querySelector('.upload-area');
    const fileInput = item.querySelector('input[type="file"]');
    if (!uploadArea || !fileInput) return;
    
    fileInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file && file.type.startsWith('image/')) {
            const reader = new FileReader();
            reader.onload = (event) => {
                const preview = uploadArea.querySelector('.upload-preview');
                if (preview) {
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px; object-fit: contain;">`;
                }
            };
            reader.readAsDataURL(file);
        }
    });
}

// Initialize drag and drop for a specific upload area (register page)
function initializeDragAndDropForUploadAreaForRegister(uploadArea) {
    if (!uploadArea) return;
    
    const fileInput = uploadArea.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    // Check if already initialized to avoid duplicate listeners
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
                this.classList.remove('drag-over');
                
                if (draggedElement !== this) {
                    const allItems = Array.from(container.querySelectorAll('.tech-tool-banner-card'));
                    const draggedIndex = allItems.indexOf(draggedElement);
                    const dropIndex = allItems.indexOf(this);
                    
                    if (draggedIndex < dropIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }
                    
                    updateTechToolButtonsForRegister();
                }
            });
        });
    }
    
    makeItemsDraggable();
    
    // Observe for new items
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                makeItemsDraggable();
            }
        });
    });
    
    observer.observe(container, { childList: true });
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
                this.classList.remove('drag-over');
                
                if (draggedElement !== this) {
                    const allItems = Array.from(container.querySelectorAll(`.communication-item[data-comm-type="${type}"]`));
                    const draggedIndex = allItems.indexOf(draggedElement);
                    const dropIndex = allItems.indexOf(this);
                    
                    if (draggedIndex < dropIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }
                    
                    updateCommunicationButtons(type);
                }
            });
        });
    }
    
    makeItemsDraggable();
    
    // Observe for new items
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                makeItemsDraggable();
            }
        });
    });
    
    observer.observe(container, { childList: true });
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
                <li>1) Facebookアプリを開く</li>
                <li>2) 自分のプロフィールを開く</li>
                <li>3) 画面上部またはプロフィール内の「…（三点）」をタップ</li>
                <li>4) プロフィール設定画面一番下の「プロフィールリンクをコピー」からURLを取得</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>1) Facebookにログイン</li>
                <li>2) 画面左上の自分の名前をクリックしてプロフィールを開く</li>
                <li>3) ブラウザ上部のURL欄に表示されているアドレスがプロフィールリンクです<br>（例：https://www.facebook.com/ユーザー名）</li>
            </ol>
        `,
        chatwork: `
            <p>【Chatwork】</p>
            <p>Chatwork ID（チャットワークID）は初期設定では未設定の場合があります。</p>
            <p>●スマートフォンアプリの場合</p>
            <ol>
                <li>1)  Chatworkアプリを開く</li>
                <li>2) 「アカウント」→「プロフィール」を開く</li>
                <li>3) 「Chatwork ID を伝える」をタップ</li>
                <li>4) 「Chatwork ID を共有」を押す</li>
                <li>5) 表示された情報からコピーします</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>1) Chatworkにログイン</li>
                <li>2) 右上の自分のアイコンをクリック</li>
                <li>3) 「プロフィール」を開く</li>
                <li>4) プロフィール画面のリンクをコピーして利用します</li>
            </ol>
        `
    };

    const title = titles[helpType] || '設定方法';
    const bodyHtml = bodies[helpType] || '';

    // Remove existing communication help modal if any
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

    // Trigger show animation
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

// Attach click handlers to communication help buttons
document.addEventListener('DOMContentLoaded', function() {
    const helpButtons = document.querySelectorAll('.comm-help-button');
    helpButtons.forEach(button => {
        button.addEventListener('click', function() {
            const helpType = this.getAttribute('data-help-type');
            if (helpType) {
                showCommunicationHelpModal(helpType);
            }
        });
    });
});

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
    
    // Initialize free input pair drag and drop
    setTimeout(() => {
        initializeFreeInputPairDragAndDropForRegister();
    }, 200);
    
    // Initialize tech tool drag and drop
    setTimeout(() => {
        initializeTechToolDragAndDropForRegister();
        updateTechToolButtonsForRegister();
    }, 100);

    // Initialize communication drag and drop
    setTimeout(() => {
        initializeCommunicationDragAndDrop('message');
        initializeCommunicationDragAndDrop('sns');
    }, 300);
    
    // Initialize preview button
    const previewBtn = document.getElementById('preview-btn');
    if (previewBtn) {
        previewBtn.addEventListener('click', showPreview);
    }
    
    // Initialize close preview button
    const closePreviewBtn = document.getElementById('close-preview-btn');
    if (closePreviewBtn) {
        closePreviewBtn.addEventListener('click', hidePreview);
    }
});

// Build card HTML from data (used for preview/display)
function buildCardHtmlForRegister(data) {
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
    let hasHeaderContent = false;
    if (data.company_logo) {
        html += `<img src="${escapeHtml(data.company_logo)}" alt="ロゴ" class="company-logo">`;
        hasHeaderContent = true;
    }
    const companyName = data.company_name || data.company_name_profile || '';
    if (companyName) {
        html += `<h1 class="company-name">${escapeHtml(companyName)}</h1>`;
        hasHeaderContent = true;
    }
    html += '</div>';
    if (hasHeaderContent) {
        html += '<hr>';
    }
    
    // Card Body
    html += '<div class="card-body">';
    
    // Profile photo and greeting section (matching card.php)
    html += '<div class="profile-greeting-section">';
    let hasProfileGreetingContent = false;
    if (data.profile_photo) {
        html += '<div class="profile-photo-container">';
        html += `<img src="${escapeHtml(data.profile_photo)}" alt="プロフィール写真" class="profile-photo">`;
        html += '</div>';
        hasProfileGreetingContent = true;
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
            hasProfileGreetingContent = true;
        }
    }
    html += '</div>'; // greeting-content
    html += '</div>'; // profile-greeting-section
    html += '</div>'; // card-body
    if (hasProfileGreetingContent) {
        html += '<hr>';
    }
    
    // Responsive two-column info layout (matching card.php)
    html += '<div class="card-body">';
    html += '<div class="info-responsive-grid">';

    let hasInfoContent = false;

    // Company name
    if (companyName) {
        html += '<div class="info-section company-info">';
        html += '<h3>会社名</h3>';
        html += `<p>${escapeHtml(companyName)}</p>`;
        html += '</div>';
        hasInfoContent = true;
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
        hasInfoContent = true;
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
        hasInfoContent = true;
    }
    
    // Company phone
    if (data.company_phone) {
        html += '<div class="info-section">';
        html += '<h3>会社電話番号</h3>';
        html += `<p>${escapeHtml(data.company_phone)}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Website
    if (data.company_website) {
        html += '<div class="info-section">';
        html += '<h3>HP</h3>';
        html += `<p><a href="${escapeHtml(data.company_website)}" target="_blank">${escapeHtml(data.company_website)}</a></p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Department / Position
    if (data.branch_department || data.position) {
        html += '<div class="info-section">';
        html += '<h3>部署 / 役職</h3>';
        let deptPosition = [data.branch_department, data.position].filter(Boolean).join(' / ');
        html += `<p>${escapeHtml(deptPosition)}</p>`;
        html += '</div>';
        hasInfoContent = true;
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
        hasInfoContent = true;
    }
    
    // Mobile phone
    if (data.mobile_phone) {
        html += '<div class="info-section">';
        html += '<h3>携帯番号</h3>';
        html += `<p>${escapeHtml(data.mobile_phone)}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Birthday
    if (data.birth_date) {
        html += '<div class="info-section">';
        html += '<h3>生年月日</h3>';
        html += `<p>${escapeHtml(data.birth_date)}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Residence / Hometown
    if (data.current_residence || data.hometown) {
        html += '<div class="info-section">';
        html += '<h3>現在の居住地 / 出身地</h3>';
        let residenceText = [data.current_residence, data.hometown].filter(Boolean).join(' / ');
        html += `<p>${escapeHtml(residenceText)}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Alma mater
    if (data.alma_mater) {
        html += '<div class="info-section">';
        html += '<h3>出身大学</h3>';
        html += `<p>${escapeHtml(data.alma_mater).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Qualifications
    if (data.qualifications) {
        html += '<div class="info-section">';
        html += '<h3>資格</h3>';
        html += `<p>${escapeHtml(data.qualifications).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
        hasInfoContent = true;
    }
    
    // Hobbies
    if (data.hobbies) {
        html += '<div class="info-section">';
        html += '<h3>趣味</h3>';
        html += `<p>${escapeHtml(data.hobbies).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
        hasInfoContent = true;
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
        hasInfoContent = true;
    }
    
    html += '</div>'; // info-responsive-grid

    if (hasInfoContent) {
        html += '<hr>';
    }

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
    
    // Only show HR if tech tools section was displayed
    if (data.tech_tools && data.tech_tools.length > 0) {
        html += '<hr>';
    }
    
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

// Show preview - Display card.php in modal (same as edit.php)
async function showPreview() {
    // Load saved business card data to get slug and check if data exists
    let savedData = null;
    if (typeof loadExistingBusinessCardData === 'function') {
        await loadExistingBusinessCardData();
        savedData = window.businessCardData || businessCardData;
    } else {
        // Fallback: fetch data directly
        try {
            const response = await fetch('backend/api/business-card/get.php', {
                method: 'GET',
                credentials: 'include'
            });
            if (response.ok) {
                const result = await response.json();
                if (result.success) {
                    savedData = result.data;
                }
            }
        } catch (error) {
            console.error('Error loading business card data:', error);
        }
    }

    // Check if we have any data to display
    const hasData = savedData && (
        savedData.company_name ||
        savedData.name ||
        (savedData.greetings && savedData.greetings.length > 0) ||
        savedData.company_logo ||
        savedData.profile_photo ||
        savedData.real_estate_license_prefecture ||
        savedData.company_address ||
        Object.keys(savedData).length > 5 // More than just id, user_id, etc.
    );

    if (!hasData || !savedData || !savedData.url_slug) {
        if (typeof showWarning === 'function') {
            showWarning('表示するデータがありません。まず情報を入力して保存してください。');
        } else {
            alert('表示するデータがありません。まず情報を入力して保存してください。');
        }
        return;
    }

    // Get the URL slug
    const urlSlug = savedData.url_slug;

    // Detect if user is on PC (desktop) - if so, load mobile version
    const isPC = window.innerWidth > 768;

    // Create modal overlay
    const modalOverlay = document.createElement('div');
    modalOverlay.className = 'modal-overlay preview-modal';
    modalOverlay.id = 'register-preview-modal';
    modalOverlay.style.cssText = 'visibility: visible; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 10000; display: flex; align-items: center; justify-content: center; padding: 20px; overflow-y: auto; opacity: 0; transition: opacity 0.3s;';

    // Create modal content
    const modalContent = document.createElement('div');
    modalContent.className = 'preview-modal-content';
    modalContent.id = 'preview-modal-content';
    // On PC, set initial width to mobile size, but allow expansion
    if (isPC) {
        modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center; min-width: 375px;';
    } else {
    modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: 100%; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column;';
    }
    
    // Create iframe to load card.php with preview mode
    const iframe = document.createElement('iframe');
    iframe.src = `card.php?slug=${encodeURIComponent(urlSlug)}&preview=1&preview_from_pc=${isPC ? '1' : '0'}`;
    // On PC, set iframe to mobile width to show smart version
    if (isPC) {
        iframe.style.cssText = 'width: 375px; height: 100%; border: none; flex: 1; min-height: 600px; max-width: 375px; margin: 0 auto;';
        modalContent.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center;';
    } else {
    iframe.style.cssText = 'width: 100%; height: 100%; border: none; flex: 1; min-height: 600px;';
    }
    iframe.setAttribute('frameborder', '0');
    iframe.setAttribute('scrolling', 'yes');

    // Close button
    const closeButton = document.createElement('button');
    closeButton.type = 'button';
    closeButton.className = 'preview-modal-close';
    closeButton.innerHTML = '×';
    closeButton.style.cssText = 'position: absolute; top: 1rem; right: 1rem; background: #fff; border: 2px solid #ddd; border-radius: 50%; width: 40px; height: 40px; cursor: pointer; font-size: 1.5rem; line-height: 1; display: flex; align-items: center; justify-content: center; color: #666; transition: all 0.3s; z-index: 10001;';
    closeButton.onmouseover = function() {
        this.style.background = '#f0f0f0';
        this.style.borderColor = '#999';
    };
    closeButton.onmouseout = function() {
        this.style.background = '#fff';
        this.style.borderColor = '#ddd';
    };

    modalContent.appendChild(iframe);
    modalContent.appendChild(closeButton);
    modalOverlay.appendChild(modalContent);
    document.body.appendChild(modalOverlay);

    // Show modal with animation
    setTimeout(() => {
        modalOverlay.style.opacity = '1';
    }, 10);

    // Close function
    function closeModal() {
        modalOverlay.style.opacity = '0';
        setTimeout(() => {
            if (document.body.contains(modalOverlay)) {
                document.body.removeChild(modalOverlay);
            }
        }, 300);
    }

    // Close button handler
    closeButton.addEventListener('click', closeModal);

    // Close on overlay click
    modalOverlay.addEventListener('click', (e) => {
        if (e.target === modalOverlay) {
            closeModal();
        }
    });

    // Close on Escape key
    const escapeHandler = (e) => {
        if (e.key === 'Escape') {
            closeModal();
            document.removeEventListener('keydown', escapeHandler);
        }
    };
    document.addEventListener('keydown', escapeHandler);
    
    // Listen for view change messages from iframe
    window.addEventListener('message', function(event) {
        // Security: Only accept messages from same origin (or use specific origin check)
        if (event.data && event.data.type === 'card-view-changed') {
            const modalContentEl = document.getElementById('preview-modal-content');
            const iframeEl = modalContentEl ? modalContentEl.querySelector('iframe') : null;
            
            if (modalContentEl && event.data.view === 'desktop') {
                // Expand modal for desktop view
                modalContentEl.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center; min-width: 1200px;';
                if (iframeEl) {
                    iframeEl.style.cssText = 'width: 1200px; height: 100%; border: none; flex: 1; min-height: 600px; max-width: 1200px; margin: 0 auto;';
                }
            } else if (modalContentEl && event.data.view === 'mobile') {
                // Shrink modal for mobile view
                modalContentEl.style.cssText = 'background: #fff; border-radius: 12px; max-width: 90%; width: auto; max-height: 90vh; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.3); position: relative; display: flex; flex-direction: column; align-items: center; min-width: 375px;';
                if (iframeEl) {
                    iframeEl.style.cssText = 'width: 375px; height: 100%; border: none; flex: 1; min-height: 600px; max-width: 375px; margin: 0 auto;';
                }
            }
        }
    });

    isPreviewMode = true;
}

// Hide preview - Close the modal
function hidePreview() {
    const modalOverlay = document.getElementById('register-preview-modal');
    if (modalOverlay) {
        modalOverlay.style.opacity = '0';
        setTimeout(() => {
            if (document.body.contains(modalOverlay)) {
                document.body.removeChild(modalOverlay);
        }
        }, 300);
    }
    isPreviewMode = false;
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

// Add free input text textarea for register
// Add a paired item (text + image) for register
function addFreeInputPairForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const itemCount = container.querySelectorAll('.free-input-pair-item').length;
    const newPairItem = document.createElement('div');
    newPairItem.className = 'free-input-pair-item';
    // No border/margin on new item (it's at the top)

    const currentCount = container.querySelectorAll('.free-input-pair-item').length;
    newPairItem.innerHTML = `
        <div class="free-input-pair-header">
            <span class="free-input-pair-number">${currentCount + 1}</span>
            <div class="free-input-pair-actions">
                <button type="button" class="btn-move-up" onclick="moveFreeInputPairForRegister(${currentCount}, 'up')">↑</button>
                <button type="button" class="btn-move-down" onclick="moveFreeInputPairForRegister(${currentCount}, 'down')">↓</button>
            </div>
            <button type="button" class="btn-delete-small" onclick="removeFreeInputPairForRegister(this)">削除</button>
        </div>
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
    `;
    
    // Insert at the top of the container (before the first child, or append if no children exist)
    if (container.firstChild) {
        container.insertBefore(newPairItem, container.firstChild);
        // Add border to the second item (previously first) if it exists
        const nextItem = newPairItem.nextElementSibling;
        if (nextItem && nextItem.classList.contains('free-input-pair-item')) {
            nextItem.style.marginTop = '2rem';
            nextItem.style.paddingTop = '2rem';
            nextItem.style.borderTop = '1px solid #e0e0e0';
        }
    } else {
        container.appendChild(newPairItem);
    }

    // Initialize file input handler for the new item
    initializeFreeImageUploadForRegister(newPairItem);
    
    // Initialize drag and drop for the new upload area
    const uploadArea = newPairItem.querySelector('.upload-area');
    if (uploadArea) {
        initializeDragAndDropForUploadAreaForRegister(uploadArea);
    }
    
    // Initialize drag and drop for reordering
    initializeFreeInputPairDragAndDropForRegister();
    
    // Update buttons and numbers
    updateFreeInputPairButtonsForRegister();
    updateFreeInputPairNumbersForRegister();
    
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
        // Reinitialize drag and drop after removal
        initializeFreeInputPairDragAndDropForRegister();
        updateFreeInputPairButtonsForRegister();
        updateFreeInputPairNumbersForRegister();
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

// Initialize free input pair drag and drop for reordering (register page)
function initializeFreeInputPairDragAndDropForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;

    let draggedElement = null;
    let isInitializing = false;

    function makeItemsDraggable() {
        if (isInitializing) return;
        isInitializing = true;

        const items = container.querySelectorAll('.free-input-pair-item');
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
        const items = container.querySelectorAll('.free-input-pair-item');
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
                container.querySelectorAll('.free-input-pair-item').forEach(item => {
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
                    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
                    const targetIndex = items.indexOf(this);
                    const draggedIndexCurrent = items.indexOf(draggedElement);

                    if (draggedIndexCurrent < targetIndex) {
                        container.insertBefore(draggedElement, this.nextSibling);
                    } else {
                        container.insertBefore(draggedElement, this);
                    }

                    draggedElement.dataset.dragInitialized = 'false';
                    this.dataset.dragInitialized = 'false';

                    // Update borders and spacing after reorder
                    updateFreeInputPairBordersForRegister();
                    attachDragListeners();
                }
            });
        });
    }

    function updateFreeInputPairBordersForRegister() {
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
    updateFreeInputPairBordersForRegister();
    updateFreeInputPairButtonsForRegister();
    updateFreeInputPairNumbersForRegister();
}

// Move free input pair up/down using arrows (register page)
function moveFreeInputPairForRegister(index, direction) {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    
    if (direction === 'up' && index > 0) {
        const currentItem = items[index];
        const prevItem = items[index - 1];
        container.insertBefore(currentItem, prevItem);
        updateFreeInputPairButtonsForRegister();
        updateFreeInputPairNumbersForRegister();
    } else if (direction === 'down' && index < items.length - 1) {
        const currentItem = items[index];
        const nextItem = items[index + 1];
        container.insertBefore(nextItem, currentItem);
        updateFreeInputPairButtonsForRegister();
        updateFreeInputPairNumbersForRegister();
    }
}

// Update free input pair arrow buttons state (register page)
function updateFreeInputPairButtonsForRegister() {
    const container = document.getElementById('free-input-pairs-container');
    if (!container) return;
    
    const items = Array.from(container.querySelectorAll('.free-input-pair-item'));
    items.forEach((item, index) => {
        const upBtn = item.querySelector('.btn-move-up');
        const downBtn = item.querySelector('.btn-move-down');
        
        if (upBtn) {
            upBtn.disabled = index === 0;
            upBtn.setAttribute('onclick', `moveFreeInputPairForRegister(${index}, 'up')`);
        }
        if (downBtn) {
            downBtn.disabled = index === items.length - 1;
            downBtn.setAttribute('onclick', `moveFreeInputPairForRegister(${index}, 'down')`);
        }
    });
}

// Update free input pair numbers (register page)
function updateFreeInputPairNumbersForRegister() {
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

// Initialize drag and drop for a specific upload area (register page)
function initializeDragAndDropForUploadAreaForRegister(uploadArea) {
    if (!uploadArea) return;
    
    const fileInput = uploadArea.querySelector('input[type="file"]');
    if (!fileInput) return;
    
    // Check if already initialized to avoid duplicate listeners
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
    
    // Initialize free input pair drag and drop
    setTimeout(() => {
        initializeFreeInputPairDragAndDropForRegister();
    }, 200);
    
    // Initialize tech tool drag and drop
    setTimeout(() => {
        initializeTechToolDragAndDropForRegister();
        updateTechToolButtonsForRegister();
    }, 100);

    // Initialize preview buttons (both desktop and mobile versions)
    const previewBtns = document.querySelectorAll('.btn-preview');
    previewBtns.forEach(btn => {
        if (!btn.dataset.listenerAdded) {
            btn.addEventListener('click', showPreview);
            btn.dataset.listenerAdded = 'true';
        }
    });

    // Also initialize by specific IDs for desktop and mobile
    const previewBtnDesktop = document.getElementById('preview-btn-desktop');
    const previewBtnMobile = document.getElementById('preview-btn-mobile');

    if (previewBtnDesktop && !previewBtnDesktop.dataset.listenerAdded) {
        previewBtnDesktop.addEventListener('click', showPreview);
        previewBtnDesktop.dataset.listenerAdded = 'true';
    }

    if (previewBtnMobile && !previewBtnMobile.dataset.listenerAdded) {
        previewBtnMobile.addEventListener('click', showPreview);
        previewBtnMobile.dataset.listenerAdded = 'true';
    }

    // Close preview button (for old preview container, kept for backwards compatibility)
    const closePreviewBtn = document.getElementById('close-preview-btn');
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
                <li>1) Facebookアプリを開く</li>
                <li>2) 自分のプロフィールを開く</li>
                <li>3) 画面上部またはプロフィール内の「…（三点）」をタップ</li>
                <li>4) プロフィール設定画面一番下の「プロフィールリンクをコピー」からURLを取得</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>1) Facebookにログイン</li>
                <li>2) 画面左上の自分の名前をクリックしてプロフィールを開く</li>
                <li>3) ブラウザ上部のURL欄に表示されているアドレスがプロフィールリンクです<br>（例：https://www.facebook.com/ユーザー名）</li>
            </ol>
        `,
        chatwork: `
            <p>【Chatwork】</p>
            <p>Chatwork ID（チャットワークID）は初期設定では未設定の場合があります。</p>
            <p>●スマートフォンアプリの場合</p>
            <ol>
                <li>1)  Chatworkアプリを開く</li>
                <li>2) 「アカウント」→「プロフィール」を開く</li>
                <li>3) 「Chatwork ID を伝える」をタップ</li>
                <li>4) 「Chatwork ID を共有」を押す</li>
                <li>5) 表示された情報からコピーします</li>
            </ol>
            <p>●PC（ブラウザ）の場合</p>
            <ol>
                <li>1) Chatworkにログイン</li>
                <li>2) 右上の自分のアイコンをクリック</li>
                <li>3) 「プロフィール」を開く</li>
                <li>4) プロフィール画面のリンクをコピーして利用します</li>
            </ol>
        `
    };

    const title = titles[helpType] || '設定方法';
    const bodyHtml = bodies[helpType] || '';

    // Remove existing communication help modal if any
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

    // Trigger show animation
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

// Attach click handlers to communication help buttons
document.addEventListener('DOMContentLoaded', function() {
    const helpButtons = document.querySelectorAll('.comm-help-button');
    helpButtons.forEach(button => {
        button.addEventListener('click', function() {
            const helpType = this.getAttribute('data-help-type');
            if (helpType) {
                showCommunicationHelpModal(helpType);
            }
        });
    });
});
