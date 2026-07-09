/* QR -> PWA -> Add-to-Home-Screen and address-book guidance */
(function () {
  const isSecureContext = window.location.protocol === 'https:' || window.location.hostname === 'localhost';
  if (!isSecureContext) return; // PWA requires HTTPS (except localhost)

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(() => {
      // ignore
    });
  }

  const ua = String(window.navigator.userAgent || '');
  const uaLower = ua.toLowerCase();
  const isIOS = /iphone|ipad|ipod/.test(uaLower);
  const isAndroid = /android/.test(uaLower);
  const isIOSChrome = isIOS && /\bcrios\b/i.test(ua);
  const isIOSFirefox = isIOS && /\bfxios\b/i.test(ua);
  const isIOSEdge = isIOS && /\bedgios\b/i.test(ua);
  const isIOSOpera = isIOS && /\bopios\b/i.test(ua);
  const isIOSInAppBrowser = isIOS && /\b(line|fbav|fban|instagram|micromessenger)\b/i.test(ua);
  const isIOSSafari = isIOS && !isIOSChrome && !isIOSFirefox && !isIOSEdge && !isIOSOpera && !isIOSInAppBrowser;

  const isStandalone =
    window.matchMedia('(display-mode: standalone)').matches ||
    window.navigator.standalone === true;

  const params = new URLSearchParams(window.location.search || '');
  const slug = params.get('slug') || '';

  const banner = document.getElementById('installBanner');
  if (!banner) return;

  const installBtn = document.getElementById('installBtn');
  const closeBtn = document.getElementById('installCloseBtn');
  const saveVcfBtn = document.getElementById('saveVcfBtn');
  const bannerTitle = banner.querySelector('.pwa-banner-text strong');
  const bannerVcfMsg = banner.querySelector('.pwa-banner-vcf-msg');
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
  const androidGuideModal = document.getElementById('pwaAndroidGuideModal');
  const androidGuideClose = document.getElementById('pwaAndroidGuideClose');

  const cardSlug = (banner.dataset && banner.dataset.cardSlug) || slug;
  const vcfUrl = cardSlug ? ('vcard.php?slug=' + encodeURIComponent(cardSlug)) : '';
  const storageSuffix = cardSlug || window.location.pathname;
  const homeInstalledKey = 'aiFcard.homeInstalled:' + storageSuffix;
  const contactSavedKey = 'aiFcard.contactSaved:' + storageSuffix;
  let androidInstallPromptChecked = !isAndroid;
  let androidInstallPromptUnavailable = false;
  let deferredPrompt = null;

  function getFlag(key) {
    try {
      return window.localStorage && localStorage.getItem(key) === '1';
    } catch (_) {
      return false;
    }
  }

  function setFlag(key) {
    try {
      if (window.localStorage) localStorage.setItem(key, '1');
    } catch (_) {
      // ignore
    }
  }

  function show(el, display) {
    if (el) el.style.display = display || 'inline-block';
  }

  function hide(el) {
    if (el) el.style.display = 'none';
  }

  function isHomeStepComplete() {
    return isStandalone || getFlag(homeInstalledKey);
  }

  function isContactStepComplete() {
    return getFlag(contactSavedKey);
  }

  function syncBanner() {
    const waitingForAndroidPrompt = isAndroid && !androidInstallPromptChecked && !isHomeStepComplete();
    const needsHomeStep = !isHomeStepComplete() && !waitingForAndroidPrompt;
    const needsContactStep = !!vcfUrl && !isContactStepComplete();

    if ((waitingForAndroidPrompt || !needsHomeStep) && !needsContactStep) {
      hide(banner);
      return;
    }

    show(banner, 'block');

    if (bannerTitle) {
      bannerTitle.textContent = needsHomeStep
        ? '名刺をホーム画面に追加すると、いつでも1タップで開けます'
        : '連絡先をアドレス帳に保存できます';
    }

    if (bannerVcfMsg) {
      bannerVcfMsg.style.display = needsContactStep ? 'block' : 'none';
      bannerVcfMsg.textContent = '連絡先をアドレス帳に保存すると、この名刺の住所が保存されます。';
    }

    if (needsContactStep) {
      show(saveVcfBtn);
    } else {
      hide(saveVcfBtn);
    }

    if (!needsHomeStep) {
      hide(installBtn);
      hide(iosCreateHomeIconBtn);
      hide(iosSteps);
    } else if (isIOS) {
      hide(installBtn);
      hide(iosSteps);
      show(iosCreateHomeIconBtn);
    } else if (isAndroid) {
      hide(iosCreateHomeIconBtn);
      hide(iosSteps);
      if (deferredPrompt || androidInstallPromptUnavailable) {
        if (installBtn) {
          installBtn.textContent = deferredPrompt ? 'ホームに追加' : '追加方法を見る';
          show(installBtn);
        }
      } else {
        hide(installBtn);
      }
    } else {
      hide(installBtn);
      hide(iosCreateHomeIconBtn);
      hide(iosSteps);
    }
  }

  function showModal(el) {
    if (el) el.removeAttribute('hidden');
  }

  function hideModal(el) {
    if (el) el.setAttribute('hidden', '');
  }

  if (isStandalone) {
    setFlag(homeInstalledKey);
  }

  if (saveVcfBtn && vcfUrl) {
    saveVcfBtn.addEventListener('click', function () {
      setFlag(contactSavedKey);
      syncBanner();
      window.location.href = vcfUrl;
    });
  } else {
    hide(saveVcfBtn);
  }

  syncBanner();

  if (closeBtn) {
    closeBtn.addEventListener('click', () => {
      hide(banner);
    });
  }

  if (isIOS && (modal1 || modal2)) {
    hide(installBtn);
    hide(iosSteps);
    if (!isHomeStepComplete()) show(iosCreateHomeIconBtn);

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
        if (isIOSSafari) {
          if (modal2Url) modal2Url.textContent = window.location.hostname || window.location.href;
          showModal(modal2);
        } else if (modal3) {
          showModal(modal3);
        }
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
          hideModal(modal2);
        });
      }
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
    return;
  }

  window.addEventListener('beforeinstallprompt', (e) => {
    if (!isAndroid) return;
    e.preventDefault();
    androidInstallPromptChecked = true;
    deferredPrompt = e;
    syncBanner();
    if (!isHomeStepComplete()) show(installBtn);
    hide(iosSteps);
  });

  if (installBtn) {
    installBtn.addEventListener('click', async () => {
      if (!deferredPrompt) {
        if (isAndroid && androidGuideModal) showModal(androidGuideModal);
        return;
      }
      deferredPrompt.prompt();
      try {
        const choice = await deferredPrompt.userChoice;
        if (choice && choice.outcome === 'accepted') {
          setFlag(homeInstalledKey);
        }
      } catch (_) {
        // ignore
      }
      deferredPrompt = null;
      syncBanner();
    });
  }

  window.addEventListener('appinstalled', () => {
    setFlag(homeInstalledKey);
    syncBanner();
  });

  if (isAndroid) {
    window.setTimeout(() => {
      if (!deferredPrompt && !isHomeStepComplete()) {
        androidInstallPromptUnavailable = true;
        androidInstallPromptChecked = true;
        syncBanner();
      }
    }, 1500);
  }

  if (androidGuideModal) {
    if (androidGuideClose) {
      androidGuideClose.addEventListener('click', () => hideModal(androidGuideModal));
    }
    const androidBackdrop = androidGuideModal.querySelector('.pwa-ios-modal-backdrop');
    if (androidBackdrop) {
      androidBackdrop.addEventListener('click', () => hideModal(androidGuideModal));
    }
  }
})();
