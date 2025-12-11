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
});

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
        
        // Greetings
        if (data.greetings && Array.isArray(data.greetings) && data.greetings.length > 0) {
            console.log('Displaying greetings:', data.greetings);
            displayGreetings(data.greetings);
        } else {
            console.log('No greetings - displaying defaults');
            displayDefaultGreetings();
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
                if (freeInputData.text) {
                    const freeTextTextarea = personalInfoForm.querySelector('textarea[name="free_input_text"]');
                    if (freeTextTextarea) freeTextTextarea.value = freeInputData.text;
                }
                if (freeInputData.image_link) {
                    const freeImageLinkInput = personalInfoForm.querySelector('input[name="free_image_link"]');
                    if (freeImageLinkInput) freeImageLinkInput.value = freeInputData.image_link;
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
            </div>
            <div class="form-group">
                <label>タイトル</label>
                <input type="text" class="form-control greeting-title" value="${escapeHtml(greeting.title)}" placeholder="タイトル">
            </div>
            <div class="form-group">
                <label>本文</label>
                <textarea class="form-control greeting-content" rows="4" placeholder="本文">${escapeHtml(greeting.content)}</textarea>
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
                <button type="button" class="btn-delete" onclick="deleteGreeting(${greeting.id})">削除</button>
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

    console.log('Tech tools displayed:', techTools);
}

// Display communication methods
function displayCommunicationMethods(methods) {
    const commList = document.getElementById('communication-list');
    if (!commList) return;
    
    const methodNames = {
        'line': 'LINE',
        'messenger': 'Messenger',
        'whatsapp': 'WhatsApp',
        'plus_message': '+メッセージ',
        'chatwork': 'Chatwork',
        'andpad': 'Andpad',
        'instagram': 'Instagram',
        'facebook': 'Facebook',
        'twitter': 'X (Twitter)',
        'youtube': 'YouTube',
        'tiktok': 'TikTok',
        'note': 'note',
        'pinterest': 'Pinterest',
        'threads': 'Threads'
    };
    
    const methodIcons = {
        'line': '<img src="./assets/images/icons/line.png" alt="LINE" class="comm-icon-img">',
        'messenger': '<img src="./assets/images/icons/messenger.png" alt="Messenger" class="comm-icon-img">',
        'whatsapp': '<img src="./assets/images/icons/whatsapp.png" alt="WhatsApp" class="comm-icon-img">',
        'plus_message': '<img src="./assets/images/icons/message.png" alt="+メッセージ" class="comm-icon-img">',
        'chatwork': '<img src="./assets/images/icons/chatwork.png" alt="Chatwork" class="comm-icon-img">',
        'andpad': '<img src="./assets/images/icons/andpad.png" alt="Andpad" class="comm-icon-img">',
        'instagram': '<img src="./assets/images/icons/instagram.png" alt="Instagram" class="comm-icon-img">',
        'facebook': '<img src="./assets/images/icons/facebook.png" alt="Facebook" class="comm-icon-img">',
        'twitter': '<img src="./assets/images/icons/twitter.png" alt="X (Twitter)" class="comm-icon-img">',
        'youtube': '<img src="./assets/images/icons/youtube.png" alt="YouTube" class="comm-icon-img">',
        'tiktok': '<img src="./assets/images/icons/tiktok.png" alt="TikTok" class="comm-icon-img">',
        'note': '<img src="./assets/images/icons/note.png" alt="note" class="comm-icon-img">',
        'pinterest': '<img src="./assets/images/icons/pinterest.png" alt="Pinterest" class="comm-icon-img">',
        'threads': '<img src="./assets/images/icons/threads.png" alt="Threads" class="comm-icon-img">'
    };
    
    commList.innerHTML = '';
    
    methods.forEach(method => {
        const commItem = document.createElement('div');
        commItem.className = 'communication-item';
        commItem.dataset.id = method.id;
        commItem.dataset.methodType = method.method_type;
        
        const isUrlBased = ['instagram', 'facebook', 'twitter', 'youtube', 'tiktok', 'note', 'pinterest', 'threads'].includes(method.method_type);
        const value = isUrlBased ? (method.method_url || '') : (method.method_id || '');
        const placeholder = isUrlBased ? 
            `https://${method.method_type === 'twitter' ? 'x.com' : method.method_type === 'note' ? 'note.com' : method.method_type === 'threads' ? 'threads.net' : method.method_type + '.com'}/...` : 
            `${methodNames[method.method_type] || method.method_type} IDまたはURL`;
        
        commItem.innerHTML = `
        <div class="communication-header" style="display: flex; justify-content: space-between; align-items: center;">
            <label class="communication-checkbox">
                <input type="checkbox" ${method.is_active ? 'checked' : ''} onchange="toggleCommunicationMethod(${method.id}, this.checked)">
                <div class="comm-icon">${methodIcons[method.method_type] || '<img src="./assets/images/icons/message.png" alt="+メッセージ" class="comm-icon-img">'}</div>
                <span>${methodNames[method.method_type] || method.method_type}</span>
            </label>
            <button type="button" class="btn-delete" onclick="deleteCommunicationMethod(${method.id})">削除</button>
        </div>
            <div class="comm-details" style="display: ${method.is_active ? 'block' : 'none'};">
                <input type="${isUrlBased ? 'url' : 'text'}" class="form-control comm-value" value="${escapeHtml(value)}" placeholder="${placeholder}" ${isUrlBased ? 'pattern="https?://.+"' : ''}>
                ${isUrlBased ? '<small style="color: #666; display: block; margin-top: 4px;">有効なURLを入力してください（https://で始まる必要があります）</small>' : ''}
            </div>
        `;
        commList.appendChild(commItem);
    });
}

// Setup navigation
function setupNavigation() {
    const navItems = document.querySelectorAll('.nav-item');
    const sections = document.querySelectorAll('.edit-section');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            
            const targetId = this.getAttribute('href').substring(1);
            
            // Update active nav item
            navItems.forEach(nav => nav.classList.remove('active'));
            this.classList.add('active');
            
            // Show target section
            sections.forEach(section => {
                section.classList.remove('active');
                if (section.id === targetId + '-section') {
                    section.classList.add('active');
                }
            });
        });
    });
    
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

// Handle file upload
async function handleFileUpload(event, fieldName) {
    const file = event.target.files[0];
    if (!file) return;
    
    if (!file.type.startsWith('image/')) {
        showWarning('画像ファイルを選択してください');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    // Determine file type
    let fileType = 'photo';
    if (fieldName === 'company_logo') {
        fileType = 'logo';
    } else if (fieldName === 'free_image') {
        fileType = 'free';
    }
    formData.append('file_type', fileType);
    
    try {
        const response = await fetch('../backend/api/business-card/upload.php', {
            method: 'POST',
            body: formData,
            credentials: 'include'
        });
        
        const result = await response.json();
        
        if (result.success) {
            // Show preview with resize info
            const preview = event.target.closest('.upload-area').querySelector('.upload-preview');
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
                    businessCardData[fieldName] = result.data.file_path;
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
            </div>
            <button type="button" class="btn-delete" onclick="this.closest('.greeting-item').remove(); updateGreetingNumbers(); updateGreetingButtons(); initializeGreetingDragAndDrop();">削除</button>
        </div>
        <div class="form-group">
            <label>タイトル</label>
            <input type="text" class="form-control greeting-title" placeholder="タイトル">
        </div>
        <div class="form-group">
            <label>本文</label>
            <textarea class="form-control greeting-content" rows="4" placeholder="本文"></textarea>
        </div>
    `;
    greetingsList.appendChild(greetingItem);
    updateGreetingButtons();
    initializeGreetingDragAndDrop();
}

// Save greetings
async function saveGreetings() {
    const greetingItems = document.querySelectorAll('#greetings-list .greeting-item');
    const greetings = [];
    
    greetingItems.forEach((item, index) => {
        const title = item.querySelector('.greeting-title').value.trim();
        const content = item.querySelector('.greeting-content').value.trim();
        
        if (title || content) {
            greetings.push({
                title: title,
                content: content,
                display_order: index
            });
        }
    });
    
    try {
        const response = await fetch('../backend/api/business-card/update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ greetings: greetings }),
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

// Delete greeting
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
    const toolCards = techToolsList.querySelectorAll('.tech-tool-banner-card, .tech-tool-card');
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
        
        // Step 2: Format tech tools for database
        const techTools = urlResult.data.tech_tools.map((tool, index) => ({
            tool_type: tool.tool_type,
            tool_url: tool.tool_url,
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

// Toggle communication method
function toggleCommunicationMethod(id, isActive) {
    const item = document.querySelector(`.communication-item[data-id="${id}"]`);
    if (item) {
        const details = item.querySelector('.comm-details');
        details.style.display = isActive ? 'block' : 'none';
    }
}

// Delete communication method
async function deleteCommunicationMethod(id) {
    showConfirm('このコミュニケーション方法を削除しますか？', async () => {
        // Remove from DOM
        const item = document.querySelector(`.communication-item[data-id="${id}"]`);
        if (item) {
            item.remove();
        }
        // Save changes
        await saveCommunicationMethods();
    });
    return;
}

// Add communication method
function addCommunicationMethod() {
    const commList = document.getElementById('communication-list');
    if (!commList) return;
    
    const methodTypes = [
        { type: 'line', name: 'LINE' },
        { type: 'messenger', name: 'Messenger' },
        { type: 'whatsapp', name: 'WhatsApp' },
        { type: 'plus_message', name: '+メッセージ' },
        { type: 'chatwork', name: 'Chatwork' },
        { type: 'andpad', name: 'Andpad' },
        { type: 'instagram', name: 'Instagram' },
        { type: 'facebook', name: 'Facebook' },
        { type: 'twitter', name: 'X (Twitter)' },
        { type: 'youtube', name: 'YouTube' },
        { type: 'tiktok', name: 'TikTok' },
        { type: 'note', name: 'note' },
        { type: 'pinterest', name: 'Pinterest' },
        { type: 'threads', name: 'Threads' }
    ];
    
    // Icon mapping (same as displayCommunicationMethods)
    const methodIcons = {
        'line': '<img src="./assets/images/icons/line.png" alt="LINE" class="comm-icon-img">',
        'messenger': '<img src="./assets/images/icons/messenger.png" alt="Messenger" class="comm-icon-img">',
        'whatsapp': '<img src="./assets/images/icons/whatsapp.png" alt="WhatsApp" class="comm-icon-img">',
        'plus_message': '<img src="./assets/images/icons/message.png" alt="+メッセージ" class="comm-icon-img">',
        'chatwork': '<img src="./assets/images/icons/chatwork.png" alt="Chatwork" class="comm-icon-img">',
        'andpad': '<img src="./assets/images/icons/andpad.png" alt="Andpad" class="comm-icon-img">',
        'instagram': '<img src="./assets/images/icons/instagram.png" alt="Instagram" class="comm-icon-img">',
        'facebook': '<img src="./assets/images/icons/facebook.png" alt="Facebook" class="comm-icon-img">',
        'twitter': '<img src="./assets/images/icons/twitter.png" alt="X (Twitter)" class="comm-icon-img">',
        'youtube': '<img src="./assets/images/icons/youtube.png" alt="YouTube" class="comm-icon-img">',
        'tiktok': '<img src="./assets/images/icons/tiktok.png" alt="TikTok" class="comm-icon-img">',
        'note': '<img src="./assets/images/icons/note.png" alt="note" class="comm-icon-img">',
        'pinterest': '<img src="./assets/images/icons/pinterest.png" alt="Pinterest" class="comm-icon-img">',
        'threads': '<img src="./assets/images/icons/threads.png" alt="Threads" class="comm-icon-img">'
    };
    
    // Get already added method types
    const existingItems = commList.querySelectorAll('.communication-item');
    const existingTypes = Array.from(existingItems).map(item => item.dataset.methodType);
    
    // Find the next method type that hasn't been added yet
    let nextMethod = null;
    for (const method of methodTypes) {
        if (!existingTypes.includes(method.type)) {
            nextMethod = method;
            break;
        }
    }
    
    // If all methods are already added, show message
    if (!nextMethod) {
        showInfo('すべてのコミュニケーション方法が追加済みです');
        return;
    }
    
    // Determine if URL-based
    const isUrlBased = ['instagram', 'facebook', 'twitter', 'youtube', 'tiktok', 'note', 'pinterest', 'threads'].includes(nextMethod.type);
    const inputType = isUrlBased ? 'url' : 'text';
    const placeholder = isUrlBased ? `https://${nextMethod.type === 'twitter' ? 'x.com' : nextMethod.type === 'note' ? 'note.com' : nextMethod.type === 'threads' ? 'threads.net' : nextMethod.type + '.com'}/...` : `${nextMethod.name} IDまたはURL`;
    
    // Get icon for this method
    const icon = methodIcons[nextMethod.type] || '<img src="./assets/images/icons/message.png" alt="+メッセージ" class="comm-icon-img">';
    
    // Create communication item
    const commItem = document.createElement('div');
    commItem.className = 'communication-item';
    commItem.dataset.methodType = nextMethod.type;
    commItem.innerHTML = `
        <label class="communication-checkbox">
            <input type="checkbox" checked onchange="toggleCommunicationMethod(null, this.checked)">
            <div class="comm-icon">${icon}</div>
            <span>${nextMethod.name}</span>
        </label>
        <div class="comm-details" style="display: block;">
            <input type="${inputType}" class="form-control comm-value" placeholder="${placeholder}" ${isUrlBased ? 'pattern="https?://.+"' : ''}>
            ${isUrlBased ? '<small style="color: #666; display: block; margin-top: 4px;">有効なURLを入力してください（https://で始まる必要があります）</small>' : ''}
        </div>
        <button type="button" class="btn-delete" onclick="this.closest('.communication-item').remove()">削除</button>
    `;
    commList.appendChild(commItem);
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
    const commItems = document.querySelectorAll('#communication-list .communication-item');
    const methods = [];
    const errors = [];
    
    commItems.forEach((item, index) => {
        const checkbox = item.querySelector('input[type="checkbox"]');
        if (checkbox && checkbox.checked) {
            const methodType = item.dataset.methodType;
            const valueInput = item.querySelector('.comm-value');
            const value = valueInput ? valueInput.value.trim() : '';
            
            const isUrlBased = ['instagram', 'facebook', 'twitter', 'youtube', 'tiktok', 'note', 'pinterest', 'threads'].includes(methodType);
            
            // Validation for URL-based methods
            if (isUrlBased && value) {
                if (!isValidUrl(value)) {
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
                    errors.push(`${methodNames[methodType] || methodType}のURLが無効です。https://で始まる有効なURLを入力してください。`);
                    // Highlight the invalid input
                    valueInput.style.borderColor = '#dc3545';
                    valueInput.addEventListener('input', function() {
                        if (isValidUrl(this.value.trim())) {
                            this.style.borderColor = '';
                        }
                    });
                    return; // Skip this item if validation fails
                } else {
                    // Reset border color if valid
                    valueInput.style.borderColor = '';
                }
            }
            
            // Validation for non-URL methods (should have a value if checked)
            if (!isUrlBased && !value) {
                const methodNames = {
                    'line': 'LINE',
                    'messenger': 'Messenger',
                    'whatsapp': 'WhatsApp',
                    'plus_message': '+メッセージ',
                    'chatwork': 'Chatwork',
                    'andpad': 'Andpad'
                };
                errors.push(`${methodNames[methodType] || methodType}のIDまたはURLを入力してください。`);
                valueInput.style.borderColor = '#dc3545';
                valueInput.addEventListener('input', function() {
                    if (this.value.trim()) {
                        this.style.borderColor = '';
                    }
                });
                return; // Skip this item if validation fails
            } else if (!isUrlBased && value) {
                valueInput.style.borderColor = '';
            }
            
            methods.push({
                method_type: methodType,
                method_name: methodType,
                method_url: isUrlBased ? value : '',
                method_id: isUrlBased ? '' : value,
                is_active: 1,
                display_order: index
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
    let html = '<div class="preview-card" style="padding: 20px; border: 1px solid #ddd; border-radius: 8px; background: #fff;">';
    
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

