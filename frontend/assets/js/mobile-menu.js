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
                
                // Clone all dropdown items
                const dropdownItems = userDropdown.querySelectorAll('.dropdown-item, .dropdown-divider, .dropdown-section-header, .dropdown-subscription-info, .dropdown-button');
                dropdownItems.forEach(item => {
                    const clonedItem = item.cloneNode(true);
                    // Remove mobile-only-dropdown class for mobile menu (show all items)
                    clonedItem.classList.remove('mobile-only-dropdown');
                    
                    // Handle subscription cancel button
                    if (clonedItem.id === 'header-cancel-subscription-btn') {
                        clonedItem.addEventListener('click', (e) => {
                            e.preventDefault();
                            closeMobileMenu();
                            // Trigger the same handler as desktop version
                            const originalBtn = document.getElementById('header-cancel-subscription-btn');
                            if (originalBtn) {
                                originalBtn.click();
                            }
                        });
                    }
                    
                    userMenuSection.appendChild(clonedItem);
                });
                
                menuNav.appendChild(userMenuSection);
            }
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

