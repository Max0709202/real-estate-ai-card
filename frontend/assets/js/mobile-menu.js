/**
 * Mobile Menu Functionality
 * Handles hamburger menu toggle and navigation
 */

(function() {
    'use strict';

    // Create mobile menu elements
    function initMobileMenu() {
        const nav = document.querySelector('.nav');
        if (!nav || window.innerWidth > 768) return;

        // Check if mobile menu already exists
        if (document.querySelector('.mobile-menu-toggle')) return;

        // Create hamburger button
        const menuToggle = document.createElement('button');
        menuToggle.className = 'mobile-menu-toggle';
        menuToggle.setAttribute('aria-label', 'メニューを開く');
        menuToggle.setAttribute('aria-expanded', 'false');
        menuToggle.innerHTML = `
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M3 12H21M3 6H21M3 18H21" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
            </svg>
        `;

        // Create overlay
        const overlay = document.createElement('div');
        overlay.className = 'mobile-menu-overlay';
        overlay.setAttribute('aria-hidden', 'true');

        // Create menu panel
        const menuPanel = document.createElement('div');
        menuPanel.className = 'mobile-menu-panel';
        menuPanel.setAttribute('role', 'navigation');
        menuPanel.setAttribute('aria-label', 'メインナビゲーション');

        // Get header content for logo
        const headerContent = document.querySelector('.header-content');
        const logo = headerContent?.querySelector('.logo');

        // Create menu header
        const menuHeader = document.createElement('div');
        menuHeader.className = 'mobile-menu-header';
        if (logo) {
            menuHeader.appendChild(logo.cloneNode(true));
        }

        const closeButton = document.createElement('button');
        closeButton.className = 'mobile-menu-close';
        closeButton.setAttribute('aria-label', 'メニューを閉じる');
        closeButton.innerHTML = '×';
        menuHeader.appendChild(closeButton);

        // Create menu nav
        const menuNav = document.createElement('div');
        menuNav.className = 'mobile-menu-nav';

        // Get all nav links (excluding buttons and user menu)
        const navLinks = nav.querySelectorAll('a:not(.btn-primary):not(.btn-secondary):not(.user-menu a)');
        navLinks.forEach(link => {
            const menuLink = link.cloneNode(true);
            menuLink.addEventListener('click', () => {
                closeMobileMenu();
            });
            menuNav.appendChild(menuLink);
        });

        // Add user menu items (person icon menu) to mobile menu
        const userMenu = nav.querySelector('.user-menu');
        if (userMenu) {
            const userDropdown = userMenu.querySelector('.user-dropdown');
            if (userDropdown) {
                // Create user menu section
                const userMenuSection = document.createElement('div');
                userMenuSection.className = 'mobile-user-menu-section';
                
                // Clone existing dropdown items (all items including email/password reset)
                const dropdownItems = userDropdown.querySelectorAll('.dropdown-item');

                dropdownItems.forEach(item => {
                    const clonedItem = item.cloneNode(true);
                    // Update "お支払い一覧" to "お支払い画面"
                    const span = clonedItem.querySelector('span');
                    if (span && span.textContent === 'お支払い一覧') {
                        span.textContent = 'お支払い画面';
                    }
                    userMenuSection.appendChild(clonedItem);
                });
                
                // Add subscription cancel button (before logout, if user has active subscription)
                const clonedLogoutLink = userMenuSection.querySelector('#logout-link');
                if (clonedLogoutLink && window.hasActiveSubscription) {
                    const cancelBtn = createSubscriptionCancelButton();
                    userMenuSection.insertBefore(cancelBtn, clonedLogoutLink);
                } else if (window.hasActiveSubscription) {
                    const cancelBtn = createSubscriptionCancelButton();
                    userMenuSection.appendChild(cancelBtn);
                }
                
                menuNav.appendChild(userMenuSection);
            }
        }
        
        // Function to create subscription cancel button
        function createSubscriptionCancelButton() {
            const cancelBtn = document.createElement('a');
            cancelBtn.type = 'button';
            cancelBtn.className = 'dropdown-item cancel-subscription-btn';
            cancelBtn.innerHTML = '<span>利用を停止する</span>';
            
            cancelBtn.addEventListener('click', async (e) => {
                e.preventDefault();
                e.stopPropagation();
                closeMobileMenu();
                
                if (!confirm('利用を停止しますか？\n\n期間終了時に停止されます。即座に停止する場合は「OK」を押した後、確認画面で選択してください。')) {
                    return;
                }
                
                const cancelImmediately = confirm('即座にキャンセルしますか？\n\n「OK」: 即座にキャンセル\n「キャンセル」: 期間終了時にキャンセル');
                
                cancelBtn.disabled = true;
                const originalText = cancelBtn.querySelector('span')?.textContent || '利用を停止する';
                if (cancelBtn.querySelector('span')) {
                    cancelBtn.querySelector('span').textContent = '処理中...';
                } else {
                    cancelBtn.textContent = '処理中...';
                }
                
                try {
                    const apiUrl = window.location.origin + '/php/backend/api/mypage/cancel.php';
                    const cancelResponse = await fetch(apiUrl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        credentials: 'include',
                        body: JSON.stringify({
                            cancel_immediately: cancelImmediately
                        })
                    });
                    
                    const cancelResult = await cancelResponse.json();
                    
                    if (cancelResult.success) {
                        alert(cancelResult.message || '利用を停止しました');
                        window.location.reload();
                    } else {
                        alert(cancelResult.message || '利用停止に失敗しました');
                        cancelBtn.disabled = false;
                        if (cancelBtn.querySelector('span')) {
                            cancelBtn.querySelector('span').textContent = originalText;
                        } else {
                            cancelBtn.textContent = originalText;
                        }
                    }
                } catch (error) {
                    console.error('Error canceling subscription:', error);
                    alert('エラーが発生しました');
                    cancelBtn.disabled = false;
                    if (cancelBtn.querySelector('span')) {
                        cancelBtn.querySelector('span').textContent = originalText;
                    } else {
                        cancelBtn.textContent = originalText;
                    }
                }
            });
            
            return cancelBtn;
        }

        // Add buttons if they exist
        const loginBtn = nav.querySelector('.btn-secondary');
        const registerBtn = nav.querySelector('.btn-primary');
        
        if (loginBtn && !nav.querySelector('.user-menu')) {
            const menuLoginBtn = loginBtn.cloneNode(true);
            menuLoginBtn.className = 'btn-secondary';
            menuNav.appendChild(menuLoginBtn);
        }
        
        if (registerBtn) {
            const menuRegisterBtn = registerBtn.cloneNode(true);
            menuRegisterBtn.className = 'btn-primary';
            menuNav.appendChild(menuRegisterBtn);
        }

        // Assemble menu panel
        menuPanel.appendChild(menuHeader);
        menuPanel.appendChild(menuNav);

        // Insert toggle button before nav
        if (nav.parentNode) {
            nav.parentNode.insertBefore(menuToggle, nav);
        }

        // Append overlay and panel to body
        document.body.appendChild(overlay);
        document.body.appendChild(menuPanel);

        // Event listeners
        menuToggle.addEventListener('click', (e) => {
            e.stopPropagation();
            openMobileMenu();
        });

        closeButton.addEventListener('click', () => {
            closeMobileMenu();
        });

        overlay.addEventListener('click', () => {
            closeMobileMenu();
        });

        // Close on escape key
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && menuPanel.classList.contains('active')) {
                closeMobileMenu();
            }
        });

        // Prevent body scroll when menu is open
        function openMobileMenu() {
            menuPanel.classList.add('active');
            overlay.classList.add('active');
            menuToggle.setAttribute('aria-expanded', 'true');
            overlay.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeMobileMenu() {
            menuPanel.classList.remove('active');
            overlay.classList.remove('active');
            menuToggle.setAttribute('aria-expanded', 'false');
            overlay.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        // Handle window resize
        let resizeTimer;
        window.addEventListener('resize', () => {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(() => {
                if (window.innerWidth > 768) {
                    closeMobileMenu();
                }
            }, 250);
        });
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMobileMenu);
    } else {
        initMobileMenu();
    }

    // Re-initialize on dynamic content changes
    if (typeof MutationObserver !== 'undefined') {
        const observer = new MutationObserver(() => {
            if (window.innerWidth <= 768 && !document.querySelector('.mobile-menu-toggle')) {
                initMobileMenu();
            }
        });

        observer.observe(document.body, {
            childList: true,
            subtree: true
        });
    }
})();

