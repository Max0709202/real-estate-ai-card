/**
 * Registration Form JavaScript
 */

let currentStep = 1;
let formData = {};
let completedSteps = new Set(); // Track which steps have been submitted
let businessCardData = null; // Store loaded business card data

// Load existing business card data on page load
document.addEventListener('DOMContentLoaded', function() {
    loadExistingBusinessCardData();
});

// Load existing business card data from API and populate forms
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
            populateRegistrationForms(businessCardData);
        }
    } catch (error) {
        console.error('Error loading business card data:', error);
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
    
    // Greetings - update existing greeting items
    if (data.greetings && Array.isArray(data.greetings) && data.greetings.length > 0) {
        const greetingItems = document.querySelectorAll('#greetings-container .greeting-item');
        data.greetings.forEach((greeting, index) => {
            if (greetingItems[index]) {
                const titleInput = greetingItems[index].querySelector('input[name="greeting_title[]"]');
                const contentTextarea = greetingItems[index].querySelector('textarea[name="greeting_content[]"]');
                if (titleInput) titleInput.value = greeting.title || '';
                if (contentTextarea) contentTextarea.value = greeting.content || '';
            }
        });
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
    
    // Free input
    if (data.free_input) {
        try {
            const freeInputData = JSON.parse(data.free_input);
            if (freeInputData.text) {
                const freeTextInput = document.querySelector('textarea[name="free_input_text"]');
                if (freeTextInput) freeTextInput.value = freeInputData.text;
            }
            if (freeInputData.image_link) {
                const freeLinkInput = document.querySelector('input[name="free_image_link"]');
                if (freeLinkInput) freeLinkInput.value = freeInputData.image_link;
            }
            if (freeInputData.image) {
                const freeImagePreview = document.querySelector('#free-image-upload .upload-preview');
                if (freeImagePreview) {
                    const imagePath = freeInputData.image.startsWith('http') ? freeInputData.image : '../' + freeInputData.image;
                    freeImagePreview.innerHTML = `<img src="${imagePath}" alt="フリー画像" style="max-width: 200px; max-height: 200px; border-radius: 8px;">`;
                }
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

// Step navigation
function goToStep(step) {
    if (step < 1 || step > 6) return;
    
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

    const index = container.children.length;
    const greetingItem = document.createElement('div');
    greetingItem.className = 'greeting-item';
    greetingItem.dataset.order = index;
    greetingItem.innerHTML = `
        <div class="greeting-header">
            <span class="greeting-number">${index + 1}</span>
            <div class="greeting-actions">
                <button type="button" class="btn-move-up" onclick="moveGreeting(${index}, 'up')" ${index === 0 ? 'disabled' : ''}>↑</button>
                <button type="button" class="btn-move-down" onclick="moveGreeting(${index}, 'down')">↓</button>
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
    container.appendChild(greetingItem);
    updateGreetingNumbers();
    updateGreetingButtons();
    initializeGreetingDragAndDrop();
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
    
    // Handle logo upload
    const logoFile = document.getElementById('company_logo').files[0];
    if (logoFile) {
        const uploadData = new FormData();
        uploadData.append('file', logoFile);
        uploadData.append('file_type', 'logo');
        
        try {
            const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                method: 'POST',
                body: uploadData,
                credentials: 'include'
            });
            
            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                data.company_logo = uploadResult.data.file_path;
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
    
    // Handle profile photo upload
    const photoFile = document.getElementById('profile_photo_header').files[0];
    if (photoFile) {
        const uploadData = new FormData();
        uploadData.append('file', photoFile);
        uploadData.append('file_type', 'photo');
        
        try {
            const uploadResponse = await fetch('../backend/api/business-card/upload.php', {
                method: 'POST',
                body: uploadData,
                credentials: 'include'
            });

            const uploadResult = await uploadResponse.json();
            if (uploadResult.success) {
                data.profile_photo = uploadResult.data.file_path;
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
    const greetingItems = document.querySelectorAll('.greeting-item');
    const greetings = [];
    greetingItems.forEach((item, index) => {
        const title = item.querySelector('input[name="greeting_title[]"]').value;
        const content = item.querySelector('textarea[name="greeting_content[]"]').value;
        if (title || content) {
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
            goToStep(2);
        } else {
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
    
    // Combine free input fields
    let freeInputData = {
        text: data.free_input_text || '',
        image_link: data.free_image_link || ''
    };
    
    // Handle free image upload
    const freeImageFile = document.getElementById('free_image').files[0];
    if (freeImageFile) {
        const uploadData = new FormData();
        uploadData.append('file', freeImageFile);
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
                const relativePath = fullPath.split('/php/')[1] || fullPath;
                freeInputData.image = relativePath;
                // Log resize info
                if (uploadResult.data.was_resized) {
                    console.log('Free image auto-resized:', uploadResult.data);
                }
            }
        } catch (error) {
            console.error('Upload error:', error);
        }
    } else if (businessCardData && businessCardData.free_input) {
        // Preserve existing free image
        try {
            const existingFreeInput = JSON.parse(businessCardData.free_input);
            if (existingFreeInput.image) {
                freeInputData.image = existingFreeInput.image;
            }
        } catch (e) {
            console.error('Error parsing free_input:', e);
        }
    }
    
    // Store free input as JSON
    data.free_input = JSON.stringify(freeInputData);
    delete data.free_input_text;
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
    
    const selectedTools = Array.from(document.querySelectorAll('input[name="tech_tools[]"]:checked'))
        .map(cb => cb.value);
    
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
        
        // Step 2: Format tech tools for database
        const techToolsForDB = urlResult.data.tech_tools.map((tool, index) => ({
            tool_type: tool.tool_type,
            tool_url: tool.tool_url,
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
                window.location.href = 'payment.php?' + params.toString();
            } else {
                // Bank transfer - redirect to bank transfer info page
                const params = new URLSearchParams({
                    payment_id: result.data.payment_id,
                    pi: result.data.stripe_payment_intent_id || ''
                });
                window.location.href = 'bank-transfer-info.php?' + params.toString();
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

// Generate preview HTML
function generatePreview(data) {
    const techToolNames = {
        'mdb': '全国マンションデータベース',
        'rlp': '物件提案ロボ',
        'llp': '土地情報ロボ',
        'ai': 'AIマンション査定',
        'slp': 'セルフィン',
        'olp': 'オーナーコネクト'
    };
    
    const techToolIcons = {
        'mdb': '🏢',
        'rlp': '🤖',
        'llp': '🏞️',
        'ai': '📊',
        'slp': '🔍',
        'olp': '💼'
    };
    
    let html = '<div class="preview-card">';
    
    // Header Section
    html += '<div class="preview-header-section">';
    if (data.company_logo) {
        html += `<div class="preview-logo"><img src="${escapeHtml(data.company_logo)}" alt="ロゴ"></div>`;
    }
    const companyName = data.company_name || data.company_name_profile || '';
    if (companyName) {
        html += `<h1 class="preview-company-name">${escapeHtml(companyName)}</h1>`;
    }
    html += '</div>';
    
    // Profile Section
    html += '<div class="preview-profile-section">';
    if (data.profile_photo) {
        html += `<div class="preview-photo"><img src="${escapeHtml(data.profile_photo)}" alt="プロフィール写真"></div>`;
    }
    
    html += '<div class="preview-person-info">';
    if (data.name) {
        html += `<h2 class="preview-person-name">${escapeHtml(data.name)}</h2>`;
    }
    if (data.position) {
        html += `<p class="preview-position">${escapeHtml(data.position)}</p>`;
    }
    if (data.branch_department) {
        html += `<p class="preview-department">${escapeHtml(data.branch_department)}</p>`;
    }
    if (data.qualifications) {
        html += `<p class="preview-qualifications">${escapeHtml(data.qualifications)}</p>`;
    }
    html += '</div>';
    html += '</div>';
    
    // Company Information
    const companyInfoItems = [];
    if (data.company_postal_code || data.company_address) {
        let addressText = '';
        if (data.company_postal_code) addressText += `〒${escapeHtml(data.company_postal_code)} `;
        if (data.company_address) addressText += escapeHtml(data.company_address);
        companyInfoItems.push({label: '住所', value: addressText});
    }
    if (data.company_phone) {
        companyInfoItems.push({label: '連絡先', value: escapeHtml(data.company_phone)});
    }
    if (data.mobile_phone) {
        companyInfoItems.push({label: '携帯番号', value: escapeHtml(data.mobile_phone)});
    }
    if (data.company_website) {
        companyInfoItems.push({label: 'HP', value: `<a href="${escapeHtml(data.company_website)}" target="_blank">${escapeHtml(data.company_website)}</a>`});
    }
    if (data.real_estate_license_registration_number) {
        let licenseText = '';
        if (data.real_estate_license_prefecture) licenseText += escapeHtml(data.real_estate_license_prefecture);
        if (data.real_estate_license_renewal_number) licenseText += `(${escapeHtml(data.real_estate_license_renewal_number)})`;
        licenseText += `第${escapeHtml(data.real_estate_license_registration_number)}号`;
        companyInfoItems.push({label: '宅建業者番号', value: licenseText});
    }
    
    if (companyInfoItems.length > 0) {
        html += '<div class="preview-company-info">';
        companyInfoItems.forEach(item => {
            html += `<div class="preview-info-item"><strong>${item.label}</strong><span>${item.value}</span></div>`;
        });
        html += '</div>';
    }
    
    // Personal Information
    const personalInfoItems = [];
    if (data.birth_date) {
        personalInfoItems.push({label: '誕生日', value: escapeHtml(data.birth_date)});
    }
    if (data.current_residence || data.hometown) {
        let residenceText = '';
        if (data.current_residence) residenceText += escapeHtml(data.current_residence);
        if (data.current_residence && data.hometown) residenceText += ' / ';
        if (data.hometown) residenceText += escapeHtml(data.hometown);
        personalInfoItems.push({label: '現在の居住地/出身地', value: residenceText});
    }
    if (data.alma_mater) {
        personalInfoItems.push({label: '出身校', value: escapeHtml(data.alma_mater)});
    }
    if (data.hobbies) {
        personalInfoItems.push({label: '趣味', value: escapeHtml(data.hobbies)});
    }
    
    if (personalInfoItems.length > 0) {
        html += '<div class="preview-personal-info">';
        personalInfoItems.forEach(item => {
            html += `<div class="preview-info-item"><strong>${item.label}</strong><span>${item.value}</span></div>`;
        });
        html += '</div>';
    }
    
    // Greetings
    if (data.greetings && data.greetings.length > 0) {
        html += '<div class="preview-greetings">';
        data.greetings.forEach(greeting => {
            if (greeting.title || greeting.content) {
                html += '<div class="preview-greeting-item">';
                if (greeting.title) {
                    html += `<h3>${escapeHtml(greeting.title)}</h3>`;
                }
                if (greeting.content) {
                    html += `<p>${escapeHtml(greeting.content).replace(/\n/g, '<br>')}</p>`;
                }
                html += '</div>';
            }
        });
        html += '</div>';
    }
    
    // Tech Tools
    if (data.tech_tools && data.tech_tools.length > 0) {
        html += '<div class="preview-tech-tools">';
        html += '<h2>おすすめサービス</h2>';
        html += '<div class="preview-tech-tools-grid">';
        data.tech_tools.forEach(tool => {
            const toolName = techToolNames[tool] || tool;
            const toolIcon = techToolIcons[tool] || '📋';
            html += `<div class="preview-tech-tool-item">`;
            html += `<div class="preview-tool-icon">${toolIcon}</div>`;
            html += `<h4>${escapeHtml(toolName)}</h4>`;
            html += `<button class="preview-tool-btn">詳細はこちら</button>`;
            html += `</div>`;
        });
        html += '</div>';
        html += '</div>';
    }
    
    // Communication / SNS
    if (data.communication && Object.keys(data.communication).length > 0) {
        html += '<div class="preview-communication">';
        html += '<hr>';
        html += '<h3>コミュニケーション + SNS</h3>';
        html += '<div class="preview-comm-grid">';
        
        Object.values(data.communication).forEach(comm => {
            if (comm.url || comm.id) {
                html += '<div class="preview-comm-item">';
                html += `<div class="preview-comm-icon">${comm.icon}</div>`;
                html += `<div class="preview-comm-name">${escapeHtml(comm.name)}</div>`;
                html += `<button class="preview-comm-btn">詳細はこちら</button>`;
                html += '</div>';
            }
        });
        
        html += '</div>';
        html += '</div>';
    }
    
    // Free Input
    if (data.free_input_text) {
        html += '<div class="preview-free-input">';
        html += `<p>${escapeHtml(data.free_input_text).replace(/\n/g, '<br>')}</p>`;
        html += '</div>';
    }
    
    if (data.free_image) {
        html += '<div class="preview-free-image">';
        const imageLink = data.free_image_link ? `href="${escapeHtml(data.free_image_link)}" target="_blank"` : '';
        html += `<a ${imageLink}><img src="${escapeHtml(data.free_image)}" alt="フリー画像"></a>`;
        html += '</div>';
    }
    
    html += '</div>';
    return html;
}

function escapeHtml(text) {
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


// Photo upload previews
document.getElementById('profile_photo_header')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = e.target.closest('.upload-area').querySelector('.upload-preview');
            if (preview) {
                // Get image dimensions
                const img = new Image();
                img.onload = () => {
                    const resizeNote = (img.width > 800 || img.height > 800) 
                        ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大800×800px)</p>` 
                        : '';
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">${resizeNote}`;
                };
                img.src = event.target.result;
            }
        };
        reader.readAsDataURL(file);
    }
});

document.getElementById('company_logo')?.addEventListener('change', (e) => {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = (event) => {
            const preview = e.target.closest('.upload-area').querySelector('.upload-preview');
            if (preview) {
                // Get image dimensions
                const img = new Image();
                img.onload = () => {
                    const resizeNote = (img.width > 400 || img.height > 400) 
                        ? `<p style="font-size: 0.75rem; color: #666; margin-top: 0.5rem;">アップロード時に自動リサイズされます (最大400×400px)</p>` 
                        : '';
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">${resizeNote}`;
                };
                img.src = event.target.result;
            }
        };
        reader.readAsDataURL(file);
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
                    preview.innerHTML = `<img src="${event.target.result}" alt="Preview" style="max-width: 200px; max-height: 200px; border-radius: 8px;">${resizeNote}`;
                };
                img.src = event.target.result;
            }
        };
        reader.readAsDataURL(file);
    }
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
