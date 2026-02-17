/* QR → PWA → Add-to-Home-Screen guidance (show when not installed, so reinstall is possible after uninstall) */
(function () {
  const isSecureContext = window.location.protocol === 'https:' || window.location.hostname === 'localhost';
  if (!isSecureContext) return; // PWA requires HTTPS (except localhost)

  // Register Service Worker (scope: current directory)
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {
      // ignore
    });
  }

  const ua = String(window.navigator.userAgent || '');
  const uaLower = ua.toLowerCase();
  const isIOS = /iphone|ipad|ipod/.test(uaLower);
  const isAndroid = /android/.test(uaLower);
  // On iOS, only Safari shows "Add to Home Screen" in the share sheet; Chrome/Chromium do not
  const isIOSSafari = isIOS && !/\bcrios\b|\bfxios\b|\bedgios\b|\bopios\b/i.test(ua);

  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

  const params = new URLSearchParams(window.location.search || '');
  const slug = params.get('slug') || '';

  const banner = document.getElementById('installBanner');
  const installBtn = document.getElementById('installBtn');
  const closeBtn = document.getElementById('installCloseBtn');
  const saveVcfBtn = document.getElementById('saveVcfBtn');
  const iosSteps = document.getElementById('iosInstallSteps');
  const iosCreateHomeIconBtn = document.getElementById('iosCreateHomeIconBtn');
  const modal1 = document.getElementById('pwaIosModal1');
  const modal1Close = document.getElementById('pwaIosModal1Close');
  const modal1CreateBtn = document.getElementById('pwaIosModal1CreateBtn');
  const modal2 = document.getElementById('pwaIosModal2');
  const modal2Url = document.getElementById('pwaIosModal2Url');
  const modal2Cancel = document.getElementById('pwaIosModal2Cancel');
  const modal2Add = document.getElementById('pwaIosModal2Add');
  const modal3 = document.getElementById('pwaIosModalSafari');
  const modal3CopyBtn = document.getElementById('pwaIosModalSafariCopy');
  const modal3CloseBtn = document.getElementById('pwaIosModalSafariClose');

  if (!banner) return;

  const cardSlug = (banner.dataset && banner.dataset.cardSlug) || slug;
  const vcfUrl = cardSlug ? ('vcard.php?slug=' + encodeURIComponent(cardSlug)) : '';
  if (saveVcfBtn && vcfUrl) {
    saveVcfBtn.style.display = 'inline-block';
    saveVcfBtn.addEventListener('click', function () {
      window.location.href = vcfUrl;
    });
  } else if (saveVcfBtn) {
    saveVcfBtn.style.display = 'none';
  }

  // If already running as installed app, never show
  if (isStandalone) {
    return;
  }

  // Show banner whenever opened in browser (not standalone), so user can reinstall after uninstalling
  banner.style.display = 'block';

  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      banner.style.display = 'none';
    });
  }

  // iOS: two-step modal flow (Step 1 → Step 2 → Share sheet or instruction)
  if (isIOS && (modal1 || modal2)) {
    if (installBtn) installBtn.style.display = 'none';
    if (iosSteps) iosSteps.style.display = 'none';
    if (iosCreateHomeIconBtn) iosCreateHomeIconBtn.style.display = 'inline-block';

    function showModal(el) {
      if (el) el.removeAttribute('hidden');
    }
    function hideModal(el) {
      if (el) el.setAttribute('hidden', '');
    }

    if (iosCreateHomeIconBtn) {
      iosCreateHomeIconBtn.addEventListener('click', () => {
        showModal(modal1);
      });
    }

    if (modal1) {
      if (modal1Close) {
        modal1Close.addEventListener('click', () => hideModal(modal1));
      }
      const backdrop1 = modal1.querySelector('.pwa-ios-modal-backdrop');
      if (backdrop1) {
        backdrop1.addEventListener('click', () => hideModal(modal1));
      }
    }

    if (modal1CreateBtn) {
      modal1CreateBtn.addEventListener('click', () => {
        hideModal(modal1);
        if (modal2Url) modal2Url.textContent = window.location.hostname || window.location.href;
        showModal(modal2);
      });
    }

    if (modal2) {
      if (modal2Cancel) {
        modal2Cancel.addEventListener('click', () => hideModal(modal2));
      }
      const backdrop2 = modal2.querySelector('.pwa-ios-modal-backdrop');
      if (backdrop2) {
        backdrop2.addEventListener('click', () => hideModal(modal2));
      }
      if (modal2Add) {
        modal2Add.addEventListener('click', () => {
          const url = window.location.href;
          const title = 'AI名刺';
          const instruction = 'ホーム画面に追加するには、画面下の「共有」ボタンをタップし、「ホーム画面に追加」を選択してください。';
          if (typeof navigator.share === 'function') {
            navigator.share({ title: title, url: url })
              .then(() => { hideModal(modal2); })
              .catch((err) => {
                hideModal(modal2);
                if (err && err.name !== 'AbortError') {
                  if (isIOS && !isIOSSafari && modal3) {
                    showModal(modal3);
                  } else {
                    alert(instruction);
                  }
                }
              });
          } else {
            hideModal(modal2);
            if (isIOS && modal3) {
              showModal(modal3);
            } else {
              alert(instruction);
            }
          }
        });
      }
      if (modal3) {
        if (modal3CloseBtn) {
          modal3CloseBtn.addEventListener('click', () => hideModal(modal3));
        }
        const backdrop3 = modal3.querySelector('.pwa-ios-modal-backdrop');
        if (backdrop3) {
          backdrop3.addEventListener('click', () => hideModal(modal3));
        }
        if (modal3CopyBtn) {
          modal3CopyBtn.addEventListener('click', () => {
            const url = window.location.href;
            if (navigator.clipboard && navigator.clipboard.writeText) {
              navigator.clipboard.writeText(url).then(() => {
                modal3CopyBtn.textContent = 'コピーしました';
                setTimeout(() => { modal3CopyBtn.textContent = 'リンクをコピー'; }, 2000);
              }).catch(() => {
                modal3CopyBtn.textContent = 'コピーしました';
                setTimeout(() => { modal3CopyBtn.textContent = 'リンクをコピー'; }, 2000);
              });
            } else {
              modal3CopyBtn.textContent = 'コピーしました';
              setTimeout(() => { modal3CopyBtn.textContent = 'リンクをコピー'; }, 2000);
            }
          });
        }
      }
    }
    return;
  }

  // Android: semi-automatic install prompt
  let deferredPrompt = null;
  window.addEventListener('beforeinstallprompt', (e) => {
    // Only for Android/Chromium
    if (!isAndroid) return;
    e.preventDefault();
    deferredPrompt = e;
    if (installBtn) installBtn.style.display = 'inline-block';
    if (iosSteps) iosSteps.style.display = 'none';
  });

  if (installBtn) {
    installBtn.addEventListener('click', async () => {
      if (!deferredPrompt) return;
      deferredPrompt.prompt();
      try {
        await deferredPrompt.userChoice;
      } catch (_) {
        // ignore
      }
      deferredPrompt = null;
      banner.style.display = 'none';
    });
  }
})();