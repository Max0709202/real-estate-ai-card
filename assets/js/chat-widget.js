/**
 * Chat widget on AI business card page.
 * - Starts session on first open, shows agent avatar/name, sends messages, displays replies.
 */
(function () {
    // 旧ブラウザ（IE11・一部の組込み/社内ブラウザ）向け Element.closest フォールバック。
    // これが無いとイベント委譲（タブ・機能ボタン）のハンドラが closest 呼び出しで例外を投げ、
    // AI担当以外のボタンが全て無反応になる。モダンブラウザには影響しない。
    if (window.Element && !Element.prototype.closest) {
        Element.prototype.closest = function (sel) {
            var el = this;
            var matches = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
            if (!matches) return null;
            while (el && el.nodeType === 1) {
                if (matches.call(el, sel)) return el;
                el = el.parentElement || el.parentNode;
            }
            return null;
        };
    }

    var root = document.getElementById('chat-widget-root');
    if (!root) return;

    var cardSlug = root.getAttribute('data-card-slug') || '';
    var agentName = root.getAttribute('data-agent-name') || '担当者';
    var agentPhoto = root.getAttribute('data-agent-photo') || '';
    var apiBase = root.getAttribute('data-api-base') || (window.location.origin + '/backend/api/chat');
    var siteBase = apiBase.replace(/\/backend\/api\/chat\/?$/, '');
    var chatOnly = root.getAttribute('data-chat-only') === '1' || document.body.classList.contains('chat-only-mode');

    var toggleBtn = document.getElementById('chat-widget-toggle');
    var panel = document.getElementById('chat-widget-panel');
    var closeBtn = document.getElementById('chat-widget-close');
    var refreshBtn = document.getElementById('chat-widget-refresh');
    var messagesContainer = document.getElementById('chat-widget-messages');
    var inputEl = document.getElementById('chat-widget-input');
    var sendBtn = document.getElementById('chat-widget-send');
    var voiceBtn = document.getElementById('chat-widget-voice');
    var voiceStatusEl = document.getElementById('chat-widget-voice-status');
    var avatarEl = document.getElementById('chat-widget-avatar');
    var agentNameEl = document.getElementById('chat-widget-agent-name');
    var toggleAvatarEl = document.getElementById('chat-widget-toggle-avatar');
    var toggleLabelEl = document.getElementById('chat-widget-toggle-label');
    var quickActions = document.getElementById('chat-widget-quick-actions');
    var featurePanel = document.getElementById('chat-widget-feature-panel');
    var tabBar = document.querySelector('.chat-widget-tabbar');
    var defaultPromptText = "不動産のことなら、何でもお気軽にご相談ください。";
    var entryNoticeText = "こんにちは。\nAI{agent}です。\n\nこちらのAIエージェントでは、物件探しや進捗管理、私とのやり取りをスムーズに進めるために、ご相談内容を安全に保存し、スマートフォンの変更時や別の端末からアクセスした場合でも引き継げるよう、最初にSMS認証・メールアドレス・お名前の登録をお願いしています。\n\n最初にSMS認証を行います。電話番号を入力し、届いた認証コードを入力してください。";
    var firstConsultationNoticeText = "気になることを、そのまま文章で送ってください。\n\nまだ具体的に決まっていなくても大丈夫です。会話の流れの中で、必要なことだけ少しずつ確認します。\n\n※右下のマイクボタンから音声入力もご利用いただけます。\n\n※AIによるサービスのため、回答内容に誤りが含まれる場合があります。";
    var previousConfirmedNoticeText = "ありがとうございます。前回のご相談内容を確認しました。\n前回の内容をもとに、このまま続きからご案内できます。";
    var registeredPhoneNoticeText = "おかえりなさい、{customer}。\n\n前回のご相談内容をもとに、続きからご案内します。";
    var reloadNoticeText = "ページを再読み込みしました。チャットを再接続しましたので、前回の相談がある場合は続きから再開できます。";
    var nameRegistrationText = "続いて、お名前を「姓」「名」の順で入力してください。「姓」と「名」の間にはスペースを入れて下さい。";
    var emailRegistrationText = "続いてメールアドレスを入力して下さい。\n\nよく使うドメインは、下のボタンから選んで入力できます。";
    var registrationCompleteText = "ご登録ありがとうございました。\n\nこれで、スマートフォンの機種変更時や別の端末からでも、SMS認証で続きからご相談いただけます。";
    var pwaModalIds = ['pwaIosModal1', 'pwaIosModal2', 'pwaIosModalSafari'];

    if (!toggleBtn || !panel || !messagesContainer || !inputEl || !sendBtn) return;

    var visitorId = getOrCreateVisitorId();
    // メール通知のリンク（card.php?...&open=contact|property）から開いた場合、
    // セッション復帰後に該当タブを自動表示する。
    var deepLinkTab = (function () {
        try {
            var v = new URLSearchParams(window.location.search).get('open');
            return (v === 'contact' || v === 'property') ? v : null;
        } catch (e) { return null; }
    })();
    var deepLinkHandled = false;
    var sessionId = null;
    var sessionGeo = null; // 同一セッションで再利用するGPS座標 {lat, lon}
    var canUseLoanSim = true;
    var sessionStarting = false;
    var sendingMessage = false;
    var greetingShown = false;
    var startupData = null;
    var entryAwaitingChoice = false;
    var registrationFlow = false;
    var firebaseConfigPromise = null;
    var firebaseAppReady = false;
    var firebaseConfirmationResult = null;
    var botTypingTimer = null;
    var reducedMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition || null;
    var recognition = null;
    var isListening = false;
    var finalVoiceTranscript = '';
    var voiceHadError = false;
    // 現在音声入力中の対象（null = AI担当の入力欄）。担当連絡でも音声入力を使うため、
    // 音声エンジンを入力欄ごとに切り替えられるようにする。
    var voiceTarget = null;
    var pageWasReloaded = detectPageReload();
    var reloadNoticeShown = false;
    var quickActionsAwaitingBottomScroll = false;
    var quickActionsUserScrollIntent = false;
    var quickActionsScrollFrame = null;
    var crmState = null;
    var crmLoading = false;
    var activeChatTab = 'ai';
    var attachBtn = document.getElementById('chat-widget-attach');
    var fileInput = document.getElementById('chat-widget-file');
    var attachListEl = document.getElementById('chat-widget-attach-list');
    var lastAgentMsgId = 0;
    var agentPollTimer = null;
    var agentUnreadCount = 0;
    var pendingAttachments = [];
    // Web Push（ホーム画面アイコンのバッジ）連携用に現在のセッション情報を公開する。
    window.__aiFcardChat = window.__aiFcardChat || {};
    window.__aiFcardChat.getSession = function () {
        return sessionId ? { sessionId: sessionId, visitorId: visitorId || '', apiBase: apiBase } : null;
    };
    // 担当連絡（人間担当↔顧客）チャネル。AI担当スレッドとは別に保持する。
    var contactMessages = [];
    var contactPendingAttachments = [];
    // 顧客自身の発言のうち、担当者が既読にした最大ID（id <= これ の自分の発言は「既読」表示）。
    var contactLastReadUserId = 0;

    function detectPageReload() {
        try {
            if (window.performance && typeof window.performance.getEntriesByType === 'function') {
                var nav = window.performance.getEntriesByType('navigation');
                if (nav && nav.length) return nav[0].type === 'reload';
            }
            if (window.performance && window.performance.navigation) {
                return window.performance.navigation.type === 1;
            }
        } catch (e) {}
        return false;
    }

    function safeStorageGet(key) {
        try { return window.localStorage ? localStorage.getItem(key) : null; } catch (e) { return null; }
    }

    function safeStorageSet(key, value) {
        try { if (window.localStorage) localStorage.setItem(key, value); } catch (e) {}
    }

    function safeStorageRemove(key) {
        try { if (window.localStorage) localStorage.removeItem(key); } catch (e) {}
    }

    function createClientId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        return 'v-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 12) + Math.random().toString(36).slice(2, 12);
    }

    function getOrCreateVisitorId() {
        var key = 'ai_fcard_chat_visitor_id';
        var existing = safeStorageGet(key);
        if (existing) return existing;
        var id = createClientId();
        safeStorageSet(key, id);
        return id;
    }

    function getSessionStorageKey() {
        return 'ai_fcard_chat_session_id:' + (cardSlug || 'default') + ':' + visitorId;
    }

    function getCustomerNameStorageKey() {
        return 'ai_fcard_chat_customer_name:' + (cardSlug || 'default') + ':' + visitorId;
    }

    function getSavedSessionId() {
        var saved = safeStorageGet(getSessionStorageKey()) || '';
        return /^[A-Fa-f0-9-]{36}$/.test(saved) ? saved : '';
    }

    function saveSessionId(id) {
        if (/^[A-Fa-f0-9-]{36}$/.test(id || '')) safeStorageSet(getSessionStorageKey(), id);
    }

    function clearSavedSessionId() {
        safeStorageRemove(getSessionStorageKey());
    }

    function getSavedCustomerName() {
        return cleanCustomerName(safeStorageGet(getCustomerNameStorageKey()));
    }

    function saveCustomerName(name) {
        var cleaned = cleanCustomerName(name);
        if (cleaned) safeStorageSet(getCustomerNameStorageKey(), cleaned);
    }

    function resetVisitorIdentity() {
        clearSavedSessionId();
        visitorId = createClientId();
        safeStorageSet('ai_fcard_chat_visitor_id', visitorId);
    }

    // ユーザー認識（接続→SMS認証などの本人特定）が済むまでは、AI担当以外の機能タブを
    // 触らせない。認識前に別タブへ遷移すると処理がスタックし、誰の履歴か分からないまま
    // 操作が進んでしまうため。準備完了 = セッション確立済み かつ 挨拶表示済み かつ
    // 接続中/入口選択待ち/本人情報登録中 のいずれでもない状態。
    function chatFeaturesReady() {
        return !!sessionId && greetingShown && !sessionStarting && !entryAwaitingChoice && !registrationFlow;
    }

    function updateTabLockState() {
        if (!tabBar) return;
        var locked = !chatFeaturesReady();
        Array.prototype.forEach.call(tabBar.querySelectorAll('.chat-widget-tab'), function (btn) {
            var isAi = btn.getAttribute('data-chat-tab') === 'ai';
            var lock = locked && !isAi;
            btn.classList.toggle('is-locked', lock);
            btn.setAttribute('aria-disabled', lock ? 'true' : 'false');
        });
    }

    function setInputEnabled(enabled) {
        inputEl.disabled = !enabled;
        sendBtn.disabled = !enabled;
        updateVoiceButtonState();
        updateTabLockState();
    }

    function setVoiceStatus(message) {
        var el = (voiceTarget && voiceTarget.statusEl) || voiceStatusEl;
        if (!el) return;
        if (!message) {
            el.hidden = true;
            el.textContent = '';
            return;
        }
        el.textContent = message;
        el.hidden = false;
    }

    function updateVoiceButtonState() {
        var btn = (voiceTarget && voiceTarget.btn) || voiceBtn;
        var inp = (voiceTarget && voiceTarget.input) || inputEl;
        if (!btn) return;
        var canListen = !!SpeechRecognition && !sendingMessage && !sessionStarting && !inp.disabled;
        btn.disabled = !canListen;
        btn.classList.toggle('is-listening', isListening);
        btn.setAttribute('aria-pressed', isListening ? 'true' : 'false');
        if (!SpeechRecognition) {
            btn.title = 'このブラウザは音声入力に対応していません';
            btn.setAttribute('aria-label', '音声入力は未対応です');
        } else if (isListening) {
            btn.title = '音声入力を停止';
            btn.setAttribute('aria-label', '音声入力を停止');
        } else {
            btn.title = '音声で入力';
            btn.setAttribute('aria-label', '音声で入力');
        }
    }

    // 音声入力の対象記述子。AI担当と担当連絡で同じ音声エンジンを使い回す。
    function aiVoiceTarget() {
        return {
            input: inputEl,
            btn: voiceBtn,
            statusEl: voiceStatusEl,
            send: function (text) { sendMessage(text); }
        };
    }
    function contactVoiceTarget() {
        return {
            input: document.getElementById('chat-contact-input'),
            btn: document.getElementById('chat-contact-voice'),
            statusEl: document.getElementById('chat-contact-voice-status'),
            send: function (text) {
                var ci = document.getElementById('chat-contact-input');
                if (ci) ci.value = text;
                sendContactMessage();
            }
        };
    }

    function syncAgentHeader() {
        if (avatarEl) {
            if (agentPhoto) {
                avatarEl.src = agentPhoto;
                avatarEl.alt = agentName;
                avatarEl.style.display = '';
            } else {
                avatarEl.removeAttribute('src');
                avatarEl.alt = '';
                avatarEl.style.display = 'none';
            }
        }
        if (agentNameEl) agentNameEl.textContent = agentName;
        if (toggleLabelEl) toggleLabelEl.textContent = agentName + ' AIエージェント';
        if (toggleAvatarEl) {
            if (agentPhoto && toggleAvatarEl.tagName === 'IMG') {
                toggleAvatarEl.src = agentPhoto;
                toggleAvatarEl.alt = '';
            } else if (!agentPhoto && toggleAvatarEl.tagName !== 'IMG') {
                toggleAvatarEl.textContent = (agentName || 'AI').charAt(0);
            }
        }
    }

    function showPanel() {
        panel.hidden = false;
        toggleBtn.setAttribute('aria-expanded', 'true');
        syncAgentHeader();
        if ((!sessionId || !greetingShown) && !sessionStarting) startSession();
        if (sessionId && greetingShown && !sendingMessage && !entryAwaitingChoice) setInputEnabled(true);
        syncQuickActionsAfterRender();
        setTimeout(function () { inputEl.focus(); }, 50);
    }

    function hidePanel() {
        if (chatOnly) {
            panel.hidden = false;
            toggleBtn.setAttribute('aria-expanded', 'true');
            return;
        }
        panel.hidden = true;
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    function appendVoiceAvailabilityNotice() {
        var existing = messagesContainer.querySelector('.chat-widget-voice-notice');
        if (existing) existing.remove();
        if (!voiceBtn || !SpeechRecognition) return;

        var notice = document.createElement('div');
        notice.className = 'chat-widget-voice-notice';
        notice.innerHTML = '<span class="chat-widget-voice-notice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"></path><path d="M17.3 11c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.42 2.72 6.23 6.1 6.65V21h1.8v-3.35c3.38-.42 6.1-3.23 6.1-6.65h-1.7z"></path></svg></span><span>マイクアイコンから音声入力をご利用いただけます</span>';
        messagesContainer.appendChild(notice);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function getVisiblePwaModal() {
        for (var i = 0; i < pwaModalIds.length; i++) {
            var modal = document.getElementById(pwaModalIds[i]);
            if (!modal || modal.hasAttribute('hidden')) continue;
            var style = window.getComputedStyle ? window.getComputedStyle(modal) : null;
            if (style && (style.display === 'none' || style.visibility === 'hidden')) continue;
            return modal;
        }
        return null;
    }

    function updateChatPositionForPwaModal() {
        var modal = getVisiblePwaModal();
        if (!modal) {
            root.classList.remove('is-pwa-modal-visible');
            root.style.removeProperty('--chat-widget-pwa-bottom');
            return;
        }

        var modalBox = modal.querySelector('.pwa-ios-modal-box');
        var offset = 360;
        if (modalBox && typeof modalBox.getBoundingClientRect === 'function') {
            var rect = modalBox.getBoundingClientRect();
            if (rect.height > 0) {
                offset = Math.ceil(window.innerHeight - rect.top + 12);
            }
        }
        var toggleHeight = toggleBtn.offsetHeight || 58;
        var maxOffset = Math.max(96, window.innerHeight - toggleHeight - 12);
        offset = Math.max(96, Math.min(offset, maxOffset));
        root.style.setProperty('--chat-widget-pwa-bottom', offset + 'px');
        root.classList.add('is-pwa-modal-visible');
    }

    function isVisibleElement(el) {
        if (!el || !document.documentElement.contains(el)) return false;
        if (el.hidden) return false;
        var style = window.getComputedStyle ? window.getComputedStyle(el) : null;
        if (style && (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0')) return false;
        return !!(el.offsetWidth || el.offsetHeight || (el.getClientRects && el.getClientRects().length));
    }

    function updateChatPositionForInstallBanner() {
        var installCloseBtn = document.getElementById('installCloseBtn');
        root.classList.toggle('is-install-banner-visible', isVisibleElement(installCloseBtn));
    }

    function watchInstallBanner() {
        var scheduleUpdate = function () {
            window.requestAnimationFrame(updateChatPositionForInstallBanner);
        };
        var installBanner = document.getElementById('installBanner');
        var installCloseBtn = document.getElementById('installCloseBtn');

        if (window.MutationObserver) {
            var observer = new MutationObserver(scheduleUpdate);
            if (installBanner) observer.observe(installBanner, { attributes: true, childList: true, subtree: true, attributeFilter: ['hidden', 'style', 'class'] });
            if (installCloseBtn) observer.observe(installCloseBtn, { attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
            if (document.body) observer.observe(document.body, { childList: true, subtree: true });
        }

        window.addEventListener('resize', scheduleUpdate);
        window.addEventListener('orientationchange', scheduleUpdate);
        scheduleUpdate();
    }

    function watchPwaModals() {
        var scheduleUpdate = function () {
            window.requestAnimationFrame(updateChatPositionForPwaModal);
        };

        pwaModalIds.forEach(function (id) {
            var modal = document.getElementById(id);
            if (!modal) return;
            if (window.MutationObserver) {
                var observer = new MutationObserver(scheduleUpdate);
                observer.observe(modal, { attributes: true, attributeFilter: ['hidden', 'style', 'class'] });
            }
        });

        window.addEventListener('resize', scheduleUpdate);
        window.addEventListener('orientationchange', scheduleUpdate);
        scheduleUpdate();
    }

    function runOnNextFrame(callback) {
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(callback);
        } else {
            setTimeout(callback, 16);
        }
    }

    function getLatestBotBubble() {
        var bubbles = messagesContainer.querySelectorAll(".chat-msg.bot:not(.chat-msg-loading) .chat-msg-bubble");
        return bubbles.length ? bubbles[bubbles.length - 1] : null;
    }

    function isMessagesScrolledToBottom() {
        var remaining = messagesContainer.scrollHeight - messagesContainer.scrollTop - messagesContainer.clientHeight;
        return remaining <= 2;
    }

    function isLatestBotBubbleFullyVisible() {
        var bubble = getLatestBotBubble();
        if (!bubble || panel.hidden || messagesContainer.clientHeight <= 0) return true;
        var containerRect = messagesContainer.getBoundingClientRect();
        var bubbleRect = bubble.getBoundingClientRect();
        return bubbleRect.top >= containerRect.top - 1 && bubbleRect.bottom <= containerRect.bottom + 1;
    }

    function shouldDelayQuickActionsUntilBottom() {
        if (!quickActions || panel.hidden || messagesContainer.clientHeight <= 0) return false;
        return !isLatestBotBubbleFullyVisible();
    }

    function setQuickActionsWaitingForBottom(waiting) {
        if (!quickActions) return;
        quickActionsAwaitingBottomScroll = !!waiting;
        if (waiting) quickActionsUserScrollIntent = false;
        quickActions.classList.toggle("is-hidden-until-bottom", quickActionsAwaitingBottomScroll);
        quickActions.setAttribute("aria-hidden", quickActionsAwaitingBottomScroll ? "true" : "false");
    }

    function syncQuickActionsAfterRender() {
        if (!quickActions) return;
        setQuickActionsWaitingForBottom(false);
        runOnNextFrame(function () {
            if (shouldDelayQuickActionsUntilBottom()) {
                setQuickActionsWaitingForBottom(true);
            } else {
                scrollMessagesToBottom();
            }
        });
    }

    function scheduleQuickActionsRevealCheck() {
        if (!quickActions || quickActionsScrollFrame) return;
        quickActionsScrollFrame = true;
        runOnNextFrame(function () {
            quickActionsScrollFrame = null;
            if (!quickActionsAwaitingBottomScroll || !quickActionsUserScrollIntent || !isMessagesScrolledToBottom()) return;
            setQuickActionsWaitingForBottom(false);
            scrollMessagesToBottom();
        });
    }

    function noteQuickActionsScrollIntent(checkImmediately) {
        quickActionsUserScrollIntent = true;
        if (checkImmediately) scheduleQuickActionsRevealCheck();
    }

    function clearDynamicQuickActions() {
        if (!quickActions) return;
        var dynamicItems = quickActions.querySelectorAll('.chat-intake-replies, .chat-widget-default-prompt, .chat-widget-entry-actions');
        Array.prototype.forEach.call(dynamicItems, function (item) { item.remove(); });
    }

    function showDefaultQuickPrompt() {
        if (!quickActions) return;
        clearDynamicQuickActions();
        var prompt = document.createElement('div');
        prompt.className = 'chat-widget-default-prompt';
        prompt.textContent = defaultPromptText;
        quickActions.insertBefore(prompt, quickActions.firstChild);
        syncQuickActionsAfterRender();
    }

    function cleanCustomerName(value) {
        return value ? String(value).trim() : '';
    }

    function customerNameFromData(data) {
        if (!data) return '';

        var leadData = data.lead && data.lead.structured_data ? data.lead.structured_data : null;
        if (typeof leadData === 'string') {
            try {
                leadData = JSON.parse(leadData);
            } catch (e) {
                leadData = null;
            }
        }
        var candidates = [
            data.customer_name,
            data.customerName,
            data.contact && data.contact.customer_name,
            typeof data.customer === 'string' ? data.customer : (data.customer && (data.customer.customer_name || data.customer.name)),
            data.lead && data.lead.customer_name,
            leadData && (leadData.customer_name || leadData.customerName),
            getSavedCustomerName()
        ];

        for (var i = 0; i < candidates.length; i++) {
            var name = cleanCustomerName(candidates[i]);
            if (name) return name;
        }

        var lastName = cleanCustomerName(data.customer_last_name || (leadData && leadData.customer_last_name));
        var firstName = cleanCustomerName(data.customer_first_name || (leadData && leadData.customer_first_name));
        return cleanCustomerName((lastName + ' ' + firstName).trim());
    }

    function customerLabelWithSuffix(data, suffix) {
        var rawCustomer = customerNameFromData(data);
        if (!rawCustomer) return 'お客様';
        return /(様|さん)$/.test(rawCustomer) ? rawCustomer : rawCustomer + suffix;
    }

    function personalize(text, data) {
        var customer = customerLabelWithSuffix(data, '様');
        return String(text || '')
            .replace(/\{agent\}/g, agentName || '担当者')
            .replace(/\{customer\}/g, customer);
    }

    function customerCasualLabel(data) {
        return customerLabelWithSuffix(data, 'さん');
    }

    function renderEntryActions(actions) {
        if (!quickActions) return;
        clearDynamicQuickActions();
        var group = document.createElement('div');
        group.className = 'chat-widget-entry-actions';
        actions.forEach(function (action) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chat-quick-btn chat-entry-btn';
            btn.setAttribute('data-entry-action', action.action);
            btn.textContent = action.label;
            group.appendChild(btn);
        });
        quickActions.insertBefore(group, quickActions.firstChild);
        syncQuickActionsAfterRender();
    }

    function showReloadNoticeIfNeeded() {
        if (!pageWasReloaded || reloadNoticeShown) return;
        appendBotMessage(reloadNoticeText);
        reloadNoticeShown = true;
    }

    function showFirstTimeEntry(data) {
        entryAwaitingChoice = true;
        greetingShown = true;
        registrationFlow = true;
        startupData = data || startupData;
        messagesContainer.innerHTML = '';
        showReloadNoticeIfNeeded();
        // 最初のアクセス時は、SMS認証→お名前→メールアドレスの順で個人情報の登録をお願いする。
        appendBotMessage(personalize(entryNoticeText, startupData));
        showSmsAuth('upfront');
    }

    function showReturningDeviceEntry(data) {
        startupData = data || startupData;
        var customerLabel = customerLabelWithSuffix(startupData, '様');
        // 本人を特定できない（お名前を解決できない）状態では「おかえりなさい／このまま
        // 相談する」を出さない。誰か分からないまま相談を続けると身元不明の履歴が残るため、
        // 必ずSMS認証（ユーザー認識）からやり直す。
        if (customerLabel === 'お客様') {
            showFirstTimeEntry(startupData);
            return;
        }
        entryAwaitingChoice = false;
        greetingShown = true;
        messagesContainer.innerHTML = '';
        showReloadNoticeIfNeeded();
        appendBotMessage(customerLabel + '、おかえりなさい。\n\nこのまま前回の続きからご利用いただけます。\n\n別の方がご利用の場合は、下のボタンを押してSMS認証からお進みいただくと、前回の続きからご利用いただけます。');
        renderEntryActions([
            { label: '別の方の場合はこちら', action: 'use_as_someone_else' }
        ]);
        setInputEnabled(true);
        inputEl.focus();
    }

    function beginFirstConsultation(data) {
        entryAwaitingChoice = false;
        startupData = data || startupData || {};
        renderQuickReplies([]);
        appendBotMessage(firstConsultationNoticeText);
        appendVoiceAvailabilityNotice();
        appendBotMessage('近いテーマがあれば選べます。選ばずに、そのまま相談内容を書いていただいても大丈夫です。');
        renderQuickReplies(startupData.quick_replies || []);
        greetingShown = true;
        setInputEnabled(true);
        // セッション確定後、この端末の機能タブ内容を確定したセッションで読み直す。
        crmState = null;
        loadCrmState(true);
    }

    function continueSavedConsultation(data, alreadyConfirmed) {
        entryAwaitingChoice = false;
        startupData = data || startupData || {};
        renderQuickReplies([]);
        messagesContainer.innerHTML = '';
        if (alreadyConfirmed) {
            appendBotMessage(previousConfirmedNoticeText);
        }
        var previousMessages = Array.isArray(startupData.messages) ? startupData.messages : [];
        if (previousMessages.length) {
            renderSessionMessages(previousMessages);
        }
        appendBotMessage(startupData.resume_message || '前回の内容を確認しました。続きからご案内します。');
        renderQuickReplies(startupData.quick_replies || []);
        greetingShown = true;
        setInputEnabled(true);
        // 別端末でSMS認証して共有セッションへ切り替わった直後は、機能タブ（条件整理・進捗管理・
        // 物件選定・担当連絡等）の内容を共有セッションで読み直し、端末間で同じ内容を表示する。
        crmState = null;
        loadCrmState(true);
    }

    function continueProfileRegistration(data) {
        startupData = data || startupData || {};
        entryAwaitingChoice = true;
        registrationFlow = true;
        renderQuickReplies([]);
        appendBotMessage('ご本人情報の登録がまだ完了していません。続けてお名前とメールアドレスを登録してください。');
        if (startupData.has_name) {
            showEmailForm();
        } else {
            showNameForm();
        }
    }

    function loadScriptOnce(src) {
        return new Promise(function (resolve, reject) {
            var existing = document.querySelector('script[src="' + src + '"]');
            if (existing) {
                if (existing.getAttribute('data-loaded') === '1') resolve();
                else existing.addEventListener('load', resolve, { once: true });
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.async = true;
            script.onload = function () { script.setAttribute('data-loaded', '1'); resolve(); };
            script.onerror = reject;
            document.head.appendChild(script);
        });
    }

    function getFirebaseConfig() {
        if (firebaseConfigPromise) return firebaseConfigPromise;
        firebaseConfigPromise = fetch(apiBase + '/firebase-config.php')
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data.success || !data.data || !data.data.configured) throw new Error('firebase-not-configured');
                return data.data.config;
            });
        return firebaseConfigPromise;
    }

    function ensureFirebaseReady() {
        if (firebaseAppReady && window.firebase && window.firebase.auth) return Promise.resolve();
        return getFirebaseConfig().then(function (config) {
            return loadScriptOnce('https://www.gstatic.com/firebasejs/10.12.5/firebase-app-compat.js')
                .then(function () { return loadScriptOnce('https://www.gstatic.com/firebasejs/10.12.5/firebase-auth-compat.js'); })
                .then(function () {
                    if (!window.firebase.apps || !window.firebase.apps.length) {
                        window.firebase.initializeApp(config);
                    }
                    firebaseAppReady = true;
                });
        });
    }

    function removeSmsAuthBox() {
        var existing = messagesContainer.querySelector('.chat-sms-auth-box');
        if (existing) existing.remove();
    }

    function showSmsAuth(reason, initialPhone) {
        entryAwaitingChoice = true;
        renderQuickReplies([]);
        removeSmsAuthBox();
        if (window.chatRecaptchaVerifier && window.chatRecaptchaVerifier.clear) {
            try { window.chatRecaptchaVerifier.clear(); } catch (e) {}
            window.chatRecaptchaVerifier = null;
        }
        var smsIntro = (reason === 'register' || reason === 'upfront')
            ? ''
            : '前回のご相談を安全に確認するため、SMS認証を行います。電話番号を入力し、届いた認証コードを入力してください。';
        if (smsIntro) appendBotMessage(smsIntro);
        var box = document.createElement('div');
        box.className = 'chat-sms-auth-box';
        box.innerHTML = '<label>電話番号</label><div class="chat-sms-row"><input type="tel" class="chat-sms-phone" placeholder="例：09012345678"><button type="button" class="chat-sms-send">SMS送信</button></div><label>認証コード</label><div class="chat-sms-row"><input type="text" class="chat-sms-code" inputmode="numeric" placeholder="6桁のコード"><button type="button" class="chat-sms-verify" disabled>認証する</button></div><div class="chat-sms-status" aria-live="polite"></div><div id="chat-firebase-recaptcha"></div>';
        messagesContainer.appendChild(box);
        scrollMessagesToBottom();
        setInputEnabled(false);

        var phoneInput = box.querySelector('.chat-sms-phone');
        if (phoneInput && initialPhone) phoneInput.value = String(initialPhone);
        var codeInput = box.querySelector('.chat-sms-code');
        var sendButton = box.querySelector('.chat-sms-send');
        var verifyButton = box.querySelector('.chat-sms-verify');
        var status = box.querySelector('.chat-sms-status');
        var setStatus = function (message) { status.textContent = message || ''; };

        function sendSmsCode(phone) {
            setStatus('SMSを送信しています...（送信先: ' + phone + '）');
            return ensureFirebaseReady().then(function () {
                var auth = window.firebase.auth();
                // invisible reCAPTCHA は使い回せないため、送信のたびに作り直す（再送信を可能にする）。
                if (window.chatRecaptchaVerifier && window.chatRecaptchaVerifier.clear) {
                    try { window.chatRecaptchaVerifier.clear(); } catch (e) {}
                    window.chatRecaptchaVerifier = null;
                }
                window.chatRecaptchaVerifier = new window.firebase.auth.RecaptchaVerifier('chat-firebase-recaptcha', { size: 'invisible' });
                return auth.signInWithPhoneNumber(phone, window.chatRecaptchaVerifier);
            }).then(function (confirmationResult) {
                firebaseConfirmationResult = confirmationResult;
                // 送信後も「SMS再送信」を押せるようにし、コード期限切れ・未着でも取り直せるようにする。
                sendButton.disabled = false;
                sendButton.textContent = 'SMS再送信';
                verifyButton.disabled = false;
                setStatus('SMSを送信しました。届いた認証コードを入力してください。');
            }).catch(function (error) {
                if (window.console && console.warn) console.warn('Firebase SMS send failed:', { phone: phone, error: error });
                sendButton.disabled = false;
                verifyButton.disabled = true;
                firebaseConfirmationResult = null;
                setStatus(firebaseSmsErrorMessage(error, phone));
                if (window.chatRecaptchaVerifier && window.chatRecaptchaVerifier.clear) {
                    try { window.chatRecaptchaVerifier.clear(); } catch (e) {}
                    window.chatRecaptchaVerifier = null;
                }
            });
        }

        sendButton.addEventListener('click', function () {
            var phone = normalizePhoneInput(phoneInput.value);
            if (!phone) {
                setStatus('電話番号を入力してください。');
                return;
            }
            sendButton.disabled = true;
            verifyButton.disabled = true;
            firebaseConfirmationResult = null;
            sendSmsCode(phone);
        });

        verifyButton.addEventListener('click', function () {
            if (!firebaseConfirmationResult) return;
            var code = codeInput.value.trim();
            if (!code) {
                setStatus('認証コードを入力してください。');
                return;
            }
            verifyButton.disabled = true;
            setStatus('認証しています...');
            firebaseConfirmationResult.confirm(code).then(function (result) {
                return result.user.getIdToken();
            }).then(function (idToken) {
                return verifyPhoneOnServer(idToken, reason);
            }).catch(function () {
                // 認証失敗時は再入力に加えて、新しいコードの取り直し（SMS再送信）も可能にする。
                verifyButton.disabled = false;
                sendButton.disabled = false;
                setStatus('認証に失敗しました。コードをご確認のうえ、もう一度「認証する」を押すか、「SMS再送信」で新しいコードを取得してください。');
            });
        });
    }

    function normalizePhoneInput(value) {
        var raw = String(value || '').trim().replace(/[－ー―−\s]/g, '-');
        if (!raw) return '';
        if (raw.charAt(0) === '+') return raw.replace(/-/g, '');
        var digits = raw.replace(/\D/g, '');
        if (!digits) return '';
        if (digits.indexOf('81') === 0) return '+' + digits;
        if (digits.charAt(0) === '0') return '+81' + digits.slice(1);
        if (digits.length === 10 && /^[2-9]/.test(digits)) return '+1' + digits;
        if (digits.length === 11 && digits.charAt(0) === '1') return '+' + digits;
        return '+' + digits;
    }

    function shouldOpenSmsAuthFromReply(reply, userText) {
        var replyText = String(reply || '');
        if (replyText.indexOf('SMS認証フォームを表示します') !== -1) return true;
        return !!normalizePhoneInput(userText) && replyText.indexOf('最後に、携帯電話番号をご入力ください') !== -1;
    }

    function firebaseSmsErrorMessage(error, normalizedPhone) {
        var code = error && error.code ? String(error.code) : '';
        var suffix = code ? '（Firebase: ' + code + ' / 送信先: ' + normalizedPhone + '）' : '（送信先: ' + normalizedPhone + '）';
        if (code.indexOf('invalid-phone-number') !== -1) {
            return '電話番号の形式が正しくありません。日本の携帯番号は070/080/090から始まる11桁、または+81から入力してください。' + suffix;
        }
        if (code.indexOf('operation-not-allowed') !== -1) {
            return 'Firebase側でSMS送信が許可されていません。Phone認証の有効化、SMSリージョンで日本（+81）の許可、課金設定をご確認ください。' + suffix;
        }
        if (code.indexOf('unauthorized-domain') !== -1) {
            return 'このドメインがFirebase Authenticationの承認済みドメインに登録されていません。' + suffix;
        }
        if (code.indexOf('too-many-requests') !== -1 || code.indexOf('quota-exceeded') !== -1) {
            return 'SMS送信回数が多すぎる、またはSMS送信枠を超えています。時間をおいて再度お試しください。' + suffix;
        }
        if (code.indexOf('captcha-check-failed') !== -1 || code.indexOf('missing-app-credential') !== -1 || code.indexOf('invalid-app-credential') !== -1) {
            return 'reCAPTCHA認証に失敗しました。ページを再読み込みして、もう一度お試しください。' + suffix;
        }
        return 'SMS送信に失敗しました。Firebase設定、SMSリージョン、課金設定、送信制限をご確認ください。' + suffix;
    }

    function verifyPhoneOnServer(idToken, reason) {
        return fetch(apiBase + '/phone/verify.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id_token: idToken, card_slug: cardSlug, visitor_id: visitorId, reason: reason || '', current_session_id: sessionId || '' })
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' }; });
        }).then(function (data) {
            removeSmsAuthBox();
            if (!data.success || !data.data) {
                appendBotMessage(data.message || 'SMS認証を確認できませんでした。');
                if (reason === 'upfront') {
                    showSmsAuth('upfront');
                } else if (reason === 'register') {
                    renderQuickReplies([{ label: 'もう一度SMS認証する', value: 'sms_register', action: 'sms_register' }]);
                } else {
                    renderEntryActions([{ label: 'もう一度SMS認証する', action: reason === 'other' ? 'use_as_someone_else' : 'continue_previous_sms' }]);
                }
                return;
            }
            sessionId = data.data.session_id;
            saveSessionId(sessionId);
            startupData = data.data;
            if (data.data.customer_name) {
                saveCustomerName(data.data.customer_name);
            }
            if (data.data.needs_profile) {
                continueProfileRegistration(startupData);
                return;
            }
            if (reason === 'upfront') {
                if (data.data.matched) {
                    // 登録済みの電話番号: 別端末・機種変更でも前回の相談を引き継いで再開する。
                    registrationFlow = false;
                    continueSavedConsultation(startupData, true);
                } else {
                    // 新規登録: 続けてお名前→メールアドレスを登録してもらう。
                    showNameForm();
                }
                return;
            }
            if (data.data.registration_completed) {
                entryAwaitingChoice = false;
                renderQuickReplies([]);
                appendBotMessage('ご登録ありがとうございました。\n\n次回以降は、\n・スマートフォン\n・タブレット\n・パソコン\nなど別のデバイスから接続した場合でも、SMS認証を行うことで、これまでのご相談内容を引き継いでご利用いただけます。');
                setInputEnabled(true);
                inputEl.focus();
            } else if (data.data.matched) {
                if (reason === 'other') appendBotMessage(personalize(registeredPhoneNoticeText, startupData));
                continueSavedConsultation(startupData, reason !== 'other');
            } else {
                appendBotMessage('この電話番号で登録された前回のご相談は見つかりませんでした。初めてのご相談としてご案内します。');
                beginFirstConsultation(startupData);
            }
        });
    }

    function removeProfileForm() {
        var existing = messagesContainer.querySelector('.chat-profile-form');
        if (existing) existing.remove();
    }

    function saveProfile(fields) {
        var payload = { session_id: sessionId, visitor_id: visitorId, card_slug: cardSlug };
        if (fields.name) payload.name = fields.name;
        if (fields.email) payload.email = fields.email;
        return fetch(apiBase + '/profile/save.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' }; });
        }).then(function (data) {
            if (!data.success) throw new Error(data.message || '登録できませんでした。');
            return data.data || {};
        });
    }

    function showNameForm() {
        entryAwaitingChoice = true;
        registrationFlow = true;
        renderQuickReplies([]);
        removeProfileForm();
        setInputEnabled(false);
        appendBotMessage(nameRegistrationText);
        var box = document.createElement('div');
        box.className = 'chat-profile-form chat-profile-name';
        box.innerHTML = '<label>お名前（姓 名）</label><input type="text" class="chat-profile-input chat-profile-name-input" placeholder="例：山田 太郎" autocomplete="name"><div class="chat-profile-status" aria-live="polite"></div><button type="button" class="chat-profile-submit">登録する</button>';
        messagesContainer.appendChild(box);
        scrollMessagesToBottom();

        var input = box.querySelector('.chat-profile-name-input');
        var submit = box.querySelector('.chat-profile-submit');
        var status = box.querySelector('.chat-profile-status');
        var submitName = function () {
            var name = (input.value || '').trim();
            if (!name) { status.textContent = 'お名前を入力してください。'; return; }
            submit.disabled = true;
            status.textContent = '登録しています...';
            saveProfile({ name: name }).then(function (result) {
                box.remove();
                if (result && result.customer_name) {
                    startupData = startupData || {};
                    startupData.customer_name = result.customer_name;
                }
                appendUserMessage(name);
                showEmailForm();
            }).catch(function (error) {
                submit.disabled = false;
                status.textContent = (error && error.message) || 'お名前を登録できませんでした。姓と名の間にスペースを入れてご入力ください。';
            });
        };
        submit.addEventListener('click', submitName);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitName(); }
        });
        setTimeout(function () { input.focus(); }, 50);
    }

    function showEmailForm() {
        entryAwaitingChoice = true;
        registrationFlow = true;
        renderQuickReplies([]);
        removeProfileForm();
        setInputEnabled(false);
        appendBotMessage(emailRegistrationText);
        var domains = ['@gmail.com', '@icloud.com', '@yahoo.co.jp', '@docomo.ne.jp', '@ezweb.ne.jp', '@softbank.ne.jp'];
        var chips = domains.map(function (domain) {
            return '<button type="button" class="chat-email-domain-btn" data-domain="' + domain + '">' + domain + '</button>';
        }).join('');
        var box = document.createElement('div');
        box.className = 'chat-profile-form chat-profile-email';
        box.innerHTML = '<label>メールアドレス</label><input type="email" class="chat-profile-input chat-profile-email-input" inputmode="email" autocomplete="email" placeholder="例：yamada@example.com"><div class="chat-email-domains">' + chips + '</div><div class="chat-profile-status" aria-live="polite"></div><button type="button" class="chat-profile-submit">登録する</button>';
        messagesContainer.appendChild(box);
        scrollMessagesToBottom();

        var input = box.querySelector('.chat-profile-email-input');
        var submit = box.querySelector('.chat-profile-submit');
        var status = box.querySelector('.chat-profile-status');
        Array.prototype.forEach.call(box.querySelectorAll('.chat-email-domain-btn'), function (chip) {
            chip.addEventListener('click', function () {
                var domain = chip.getAttribute('data-domain') || '';
                var current = (input.value || '').trim();
                var at = current.indexOf('@');
                if (at !== -1) current = current.slice(0, at);
                input.value = current + domain;
                input.focus();
            });
        });
        var submitEmail = function () {
            var email = (input.value || '').trim();
            if (!email) { status.textContent = 'メールアドレスを入力してください。'; return; }
            submit.disabled = true;
            status.textContent = '登録しています...';
            saveProfile({ email: email }).then(function () {
                box.remove();
                appendUserMessage(email);
                registrationFlow = false;
                appendBotMessage(registrationCompleteText);
                beginFirstConsultation(startupData);
            }).catch(function (error) {
                submit.disabled = false;
                status.textContent = (error && error.message) || 'メールアドレスの形式をご確認ください。';
            });
        };
        submit.addEventListener('click', submitEmail);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); submitEmail(); }
        });
        setTimeout(function () { input.focus(); }, 50);
    }

    function renderQuickReplies(replies) {
        if (!quickActions) return;
        clearDynamicQuickActions();
        if (!replies || !replies.length) {
            showDefaultQuickPrompt();
            return;
        }

        var isMulti = replies.some(function (reply) { return !!reply.multi_select; });
        var group = document.createElement('div');
        group.className = 'chat-intake-replies' + (isMulti ? ' chat-intake-replies-multi' : '');
        if (isMulti) {
            var hint = document.createElement('div');
            hint.className = 'chat-intake-multi-hint';
            hint.textContent = '複数選択できます。選び終わったら「決定」を押してください。';
            group.appendChild(hint);
        }
        replies.forEach(function (reply) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chat-quick-btn chat-intake-reply';
            if (reply.action) {
                btn.setAttribute('data-action', reply.action);
                btn.setAttribute('data-action-value', reply.value || reply.action);
            } else {
                btn.setAttribute('data-reply-label', reply.label || reply.value || '');
                btn.setAttribute('data-reply-value', reply.value || reply.label || '');
            }
            if (reply.field) btn.setAttribute('data-reply-field', reply.field);
            if (isMulti) btn.setAttribute('data-multi-select', '1');
            btn.textContent = reply.label || reply.value || '';
            group.appendChild(btn);
        });
        if (isMulti) {
            var submit = document.createElement('button');
            submit.type = 'button';
            submit.className = 'chat-quick-btn chat-intake-submit';
            submit.setAttribute('data-multi-submit', '1');
            submit.disabled = true;
            submit.textContent = '決定';
            group.appendChild(submit);
        }
        quickActions.insertBefore(group, quickActions.firstChild);
        syncQuickActionsAfterRender();
    }

    function startSession(reset, keepSavedSession) {
        if (reset) {
            sessionId = null;
            sessionGeo = null;
            greetingShown = false;
            canUseLoanSim = true;
            crmState = null;
            lastAgentMsgId = 0;
            pendingAttachments = [];
            contactMessages = [];
            contactPendingAttachments = [];
            renderPendingAttachments();
            exitFeatureView();
            messagesContainer.innerHTML = '';
            renderQuickReplies([]);
            setVoiceStatus('');
            inputEl.value = '';
            if (!keepSavedSession) clearSavedSessionId();
            if (quickActions) quickActions.style.display = '';
        }

        if (!cardSlug) {
            appendBotMessage('カード情報が見つかりません。ページを再読み込みしてからお試しください。');
            setInputEnabled(false);
            return;
        }

        sessionStarting = true;
        setInputEnabled(false);
        var loadingRow = appendBotMessage('AIエージェントに接続中です', true);
        var savedSessionId = (reset && !keepSavedSession) ? '' : getSavedSessionId();

        fetch(apiBase + '/session/start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_slug: cardSlug, visitor_id: visitorId, current_session_id: savedSessionId, resume: !reset || !!keepSavedSession })
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' };
                });
            })
            .then(function (data) {
                sessionStarting = false;
                loadingRow.remove();
                if (data.success && data.data) {
                    sessionId = data.data.session_id;
                    if (data.data.visitor_id) visitorId = data.data.visitor_id;
                    saveSessionId(sessionId);
                    canUseLoanSim = data.data.can_use_loan_sim !== false;
                    if (data.data.agent_name) agentName = data.data.agent_name;
                if (data.data.agent_photo_url) {
                    agentPhoto = data.data.agent_photo_url;
                }
                syncAgentHeader();
                startupData = data.data;
                if (data.data.customer_name) {
                    saveCustomerName(data.data.customer_name);
                }
                if (!greetingShown) {
                    // ユーザー認識（本人特定）を最優先する。端末に過去セッションが
                    // 残っていても、SMS認証＋氏名＋メールの登録が完了していない＝誰の
                    // 履歴か確定できない場合は「おかえりなさい／このまま相談する」を
                    // 出さず、必ずSMS認証から始める（誰か分からないまま相談させない）。
                    if (data.data.registration_complete) {
                            showReturningDeviceEntry(data.data);
                        } else {
                            showFirstTimeEntry(data.data);
                        }
                    }
                    crmState = data.data.crm_case ? { session: data.data, case: data.data.crm_case, data: data.data } : null;
                    loadCrmState(false);
                    if (!canUseLoanSim && quickActions) quickActions.style.display = 'none';
                    startAgentPoll();
                    maybeRequestNotificationPermission();
                    // メール通知リンク（&open=contact|property）から開いた場合、該当タブを自動表示。
                    if (deepLinkTab && !deepLinkHandled) {
                        deepLinkHandled = true;
                        try { renderFeatureTab(deepLinkTab); } catch (e) {}
                    }
                } else {
                    appendBotMessage(data.message || '申し訳ございません。いまチャットをご利用いただけません。');
                    setInputEnabled(false);
                }
            })
            .catch(function () {
                sessionStarting = false;
                loadingRow.remove();
                appendBotMessage('接続できませんでした。しばらくしてからお試しください。');
                setInputEnabled(false);
            });
    }

    function formatMessageTime(createdAt) {
        var date = createdAt ? new Date(String(createdAt).replace(' ', 'T')) : new Date();
        if (isNaN(date.getTime())) date = new Date();
        return date.toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
    }

    function renderSessionMessages(messages) {
        messages.forEach(function (message) {
            var role = message.role || '';
            var channel = message.channel || 'ai';
            var atts = message.attachments || [];
            var mid = parseInt(message.id, 10) || 0;
            // 担当連絡チャネルは別スレッド（contactMessages）に積む。
            if (channel === 'contact') {
                contactMessages.push({ id: mid, role: role, message: message.message || '', created_at: message.created_at || '', attachments: atts });
                if (role === 'agent' && mid > lastAgentMsgId) lastAgentMsgId = mid;
                return;
            }
            // AI担当チャネル
            if (role === 'user') {
                appendUserMessage(message.message || '', message.created_at || '', atts);
            } else if (role === 'bot' || role === 'assistant') {
                appendBotMessage(message.message || '', false, null, message.created_at || '');
            }
        });
        if (activeChatTab === 'contact') renderContactThread();
    }

    function scrollMessagesToBottom() {
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Android のソフトキーボードはレイアウト viewport を縮めない場合があるため、
    // 実際に見えている VisualViewport をチャットの高さ・位置へ反映する。
    var isAndroidDevice = /Android/i.test(navigator.userAgent || '');
    var chatViewportBaselineHeight = window.innerHeight || document.documentElement.clientHeight || 0;

    function syncChatVisualViewport() {
        if (!isAndroidDevice) {
            root.classList.remove('is-keyboard-open');
            root.style.removeProperty('--chat-visual-viewport-height');
            root.style.removeProperty('--chat-visual-viewport-top');
            return;
        }
        var viewport = window.visualViewport;
        var visibleHeight = viewport ? viewport.height : window.innerHeight;
        var offsetTop = viewport ? viewport.offsetTop : 0;
        var layoutHeight = Math.max(window.innerHeight || 0, document.documentElement.clientHeight || 0);
        var active = document.activeElement;
        var isChatInput = !!(active && root.contains(active) && (
            active.matches('.chat-widget-input, input, textarea, select')
        ));
        if (!isChatInput) {
            chatViewportBaselineHeight = Math.max(chatViewportBaselineHeight, layoutHeight, visibleHeight);
        }
        var keyboardOpen = isChatInput && (
            layoutHeight - visibleHeight > 120 || chatViewportBaselineHeight - visibleHeight > 120
        );

        root.style.setProperty('--chat-visual-viewport-height', Math.round(visibleHeight) + 'px');
        root.style.setProperty('--chat-visual-viewport-top', Math.round(offsetTop) + 'px');
        root.classList.toggle('is-keyboard-open', keyboardOpen);

        if (keyboardOpen) {
            runOnNextFrame(function () {
                if (active && typeof active.scrollIntoView === 'function') {
                    active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
                }
                if (active === inputEl) scrollMessagesToBottom();
                var contactMessagesEl = panel.querySelector('.chat-contact-messages');
                if (active && active.id === 'chat-contact-input' && contactMessagesEl) {
                    contactMessagesEl.scrollTop = contactMessagesEl.scrollHeight;
                }
            });
        }
    }

    function appendMessageSources(wrap, sources) {
        if (!sources || !sources.length) return;
        var sourceBox = document.createElement('div');
        sourceBox.className = 'chat-msg-sources';
        sourceBox.innerHTML = '<span style="display:none;">参照情報</span>' + sources.slice(0, 3).map(function (source) {
            var title = source.title || source.url || 'Source';
            var url = source.url || '';
            var meta = '';
            if (source.from_api) {
                var bits = [];
                if (typeof source.record_count === 'number') bits.push(source.record_count + '件');
                if (source.fetched_at) bits.push(String(source.fetched_at).slice(0, 16) + '取得');
                if (bits.length) meta = ' <span class="chat-msg-source-meta">(' + escapeHtml(bits.join('・')) + ')</span>';
            }
            if (!url) return '<span class="chat-msg-source-label">' + escapeHtml(title) + '</span>' + meta;
            return '<a href="' + escapeAttribute(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(title) + '</a>' + meta;
        }).join('');
        var content = wrap.querySelector('.chat-msg-content');
        if (content) content.appendChild(sourceBox);
    }

    function prefersReducedMotion() {
        return !!(reducedMotionQuery && reducedMotionQuery.matches);
    }

    function getBotTypewriterStep(totalLength) {
        if (totalLength > 1400) return 4;
        if (totalLength > 800) return 3;
        if (totalLength > 500) return 2;
        return 1;
    }

    function getBotTypewriterDelay(character, totalLength) {
        if (totalLength > 1000) {
            if (/[\n。！？!?]/.test(character)) return 35;
            if (/[、,]/.test(character)) return 20;
            return 5;
        }
        if (/[\n。！？!?]/.test(character)) return 120;
        if (/[、,]/.test(character)) return 60;
        if (/[）)]/.test(character)) return 35;
        return 18;
    }

    function appendBubbleCandidateReplies(bubble, replies) {
        if (!bubble || !replies || !replies.length) return;
        var candidates = replies.filter(function (reply) {
            return reply && reply.field === 'mansion_lookup';
        });
        if (!candidates.length) return;
        var group = document.createElement('div');
        group.className = 'chat-bubble-candidates';
        candidates.forEach(function (reply) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'chat-quick-btn chat-bubble-candidate-btn';
            btn.textContent = reply.label || reply.value || '';
            btn.addEventListener('click', function () {
                if (sendingMessage) return;
                group.querySelectorAll('button').forEach(function (candidateBtn) {
                    candidateBtn.disabled = true;
                });
                var label = reply.label || reply.value || '';
                sendMessage(label, {
                    buttonSelection: {
                        label: label,
                        value: reply.value || label,
                        field: 'mansion_lookup',
                        multi_select: false
                    }
                });
            });
            group.appendChild(btn);
        });
        bubble.appendChild(group);
    }

    function extractBubbleCandidateReplies(text) {
        var replies = [];
        var seen = {};
        String(text || '').split(/\r?\n/).forEach(function (rawLine) {
            var line = rawLine.trim();
            var match = line.match(/^(?:[-・●▪◦]|[0-9０-９]{1,2}[.．、)）])\s*[「『"]?(.{2,80}?)[」』"]?\s*[（(]([^）)]{1,80}(?:都|道|府|県|市|区|町|村)[^）)]*)[）)]\s*$/u);
            if (!match) return;
            var name = match[1].trim();
            var location = match[2].trim();
            if (!name || /^(?:所在地|住所|築年月|構造|規模|総戸数|アクセス)$/.test(name)) return;
            var key = name + '|' + location;
            if (seen[key] || replies.length >= 5) return;
            seen[key] = true;
            replies.push({
                label: name + '（' + location + '）',
                value: name + ' ' + location,
                field: 'mansion_lookup'
            });
        });
        return replies;
    }

    function finishBotTypewriter(wrap, bubble, text, sources, bubbleReplies, onComplete) {
        wrap.classList.remove('is-typing');
        bubble.innerHTML = formatBotMessageHtml(text);
        appendBubbleCandidateReplies(bubble, bubbleReplies);
        appendMessageSources(wrap, sources);
        scrollMessagesToBottom();
        if (typeof onComplete === 'function') onComplete();
    }

    function startBotTypewriter(wrap, bubble, text, sources, bubbleReplies, onComplete) {
        var index = 0;
        var totalLength = text.length;
        var step = getBotTypewriterStep(totalLength);

        if (botTypingTimer) {
            clearTimeout(botTypingTimer);
            botTypingTimer = null;
        }

        wrap.classList.add('is-typing');
        bubble.innerHTML = '';

        function tick() {
            index = Math.min(index + step, totalLength);
            bubble.innerHTML = formatBotMessageHtml(text.slice(0, index));
            scrollMessagesToBottom();

            if (index >= totalLength) {
                botTypingTimer = null;
                finishBotTypewriter(wrap, bubble, text, sources, bubbleReplies, onComplete);
                return;
            }

            botTypingTimer = setTimeout(function () {
                tick();
            }, getBotTypewriterDelay(text.charAt(index - 1), totalLength));
        }

        botTypingTimer = setTimeout(function () {
            tick();
        }, 90);
    }

    function appendBotMessage(text, isLoading, sources, createdAt, options) {
        text = String(text || '');
        options = options || {};
        var wrap = document.createElement('div');
        wrap.className = 'chat-msg bot' + (isLoading ? ' chat-msg-loading' : '');
        var time = formatMessageTime(createdAt);
        var img = agentPhoto ? '<img class="chat-msg-avatar" src="' + escapeAttribute(agentPhoto) + '" alt="">' : '';
        var shouldType = !!(options.typewriter && !isLoading && !createdAt && text && !prefersReducedMotion());
        wrap.innerHTML = img + '<div class="chat-msg-content"><div class="chat-msg-bubble">' + (shouldType ? '' : formatBotMessageHtml(text)) + '</div><div class="chat-msg-time">' + time + '</div></div>';
        messagesContainer.appendChild(wrap);
        var bubble = wrap.querySelector('.chat-msg-bubble');
        var bubbleReplies = options.bubbleReplies || extractBubbleCandidateReplies(text);
        if (shouldType && bubble) {
            startBotTypewriter(wrap, bubble, text, sources, bubbleReplies, options.onComplete);
        } else {
            appendBubbleCandidateReplies(bubble, bubbleReplies);
            appendMessageSources(wrap, sources);
            scrollMessagesToBottom();
            if (typeof options.onComplete === 'function') options.onComplete();
        }
        return wrap;
    }

    function widgetAttachmentHtml(atts) {
        if (!atts || !atts.length) return '';
        var html = '<div class="chat-msg-attachments">';
        atts.forEach(function (att) {
            var url = appendVisitorToUrl(att.url || '');
            if (att.is_image) {
                html += '<a class="chat-msg-attach chat-msg-attach-image" href="' + escapeAttribute(url) + '" target="_blank" rel="noopener"><img src="' + escapeAttribute(url) + '" alt="' + escapeAttribute(att.original_name || '画像') + '"></a>';
            } else {
                var icon = att.kind === 'pdf' ? '📄' : (att.kind === 'word' ? '📝' : (att.kind === 'excel' ? '📊' : '📎'));
                html += '<a class="chat-msg-attach chat-msg-attach-file" href="' + escapeAttribute(url) + '" target="_blank" rel="noopener">' + icon + ' ' + escapeHtml(att.original_name || 'ファイル') + '</a>';
            }
        });
        html += '</div>';
        return html;
    }

    function appendVisitorToUrl(url) {
        if (!url) return '';
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        return url + sep + 'session_id=' + encodeURIComponent(sessionId || '') + '&visitor_id=' + encodeURIComponent(visitorId || '');
    }

    function chatFormatBytes(n) {
        n = parseInt(n, 10) || 0;
        if (n < 1024) return n + ' B';
        if (n < 1048576) return Math.round(n / 1024) + ' KB';
        return (n / 1048576).toFixed(1) + ' MB';
    }

    // 送信前プレビュー用カード（画像はサムネイル、その他はアイコン）。
    function pendingAttachCardHtml(a, i) {
        var thumb;
        if (a.is_image && a.url) {
            thumb = '<img src="' + escapeAttribute(appendVisitorToUrl(a.url)) + '" alt="">';
        } else {
            var icon = a.kind === 'pdf' ? '📄' : (a.kind === 'word' ? '📝' : (a.kind === 'excel' ? '📊' : '📎'));
            thumb = '<span class="chat-pending-icon" aria-hidden="true">' + icon + '</span>';
        }
        return '<div class="chat-pending-card">'
            + '<div class="chat-pending-thumb">' + thumb + '</div>'
            + '<div class="chat-pending-meta"><span class="chat-pending-name">' + escapeHtml(a.original_name || 'ファイル') + '</span>'
            + '<span class="chat-pending-size">' + chatFormatBytes(a.byte_size) + '</span></div>'
            + '<button type="button" class="chat-pending-x" data-idx="' + i + '" aria-label="削除">×</button>'
            + '</div>';
    }

    // クリップボードからの貼り付けでファイルを添付（Slackのように画像を貼り付け可能にする）。
    function handlePasteToAttach(e, uploadFn) {
        var cd = e.clipboardData || window.clipboardData;
        if (!cd) return;
        var items = cd.items || [];
        var files = [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file') {
                var f = items[i].getAsFile();
                if (f) files.push(f);
            }
        }
        // items が取れない環境向けに files も確認
        if (!files.length && cd.files && cd.files.length) {
            for (var j = 0; j < cd.files.length; j++) files.push(cd.files[j]);
        }
        if (!files.length) return;
        e.preventDefault();
        files.forEach(function (f) { uploadFn(f); });
    }

    function appendUserMessage(text, createdAt, attachments) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-msg user';
        var time = formatMessageTime(createdAt);
        var body = (text && text !== '[ファイルを送信しました]') ? escapeHtml(text) : '';
        var atts = widgetAttachmentHtml(attachments);
        wrap.innerHTML = '<div class="chat-msg-avatar"></div><div>' + (body ? '<div class="chat-msg-bubble">' + body + '</div>' : '') + atts + '<div class="chat-msg-time">' + time + '</div></div>';
        messagesContainer.appendChild(wrap);
        scrollMessagesToBottom();
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    // ===== 担当連絡（人間担当↔顧客）チャネルのチャットUI =====
    function contactReadMarkHtml(m) {
        var id = parseInt(m.id, 10) || 0;
        var read = id > 0 && id <= contactLastReadUserId;
        return '<span class="chat-contact-read"' + (read ? '' : ' hidden') + '>既読</span>';
    }

    function contactMsgHtml(m) {
        var time = formatMessageTime(m.created_at || '');
        var mid = parseInt(m.id, 10) || 0;
        var isDeleted = !!(m.deleted || m.deleted_at);
        var isEdited = !!(m.edited || m.edited_at);
        var editedMark = isEdited ? '<span class="chat-msg-edited">編集済み</span>' : '';

        if (m.role === 'agent') {
            var img = agentPhoto ? '<img class="chat-msg-avatar" src="' + escapeAttribute(agentPhoto) + '" alt="">' : '<div class="chat-msg-avatar"></div>';
            if (isDeleted) {
                return '<div class="chat-msg agent" data-msg-id="' + mid + '">' + img + '<div class="chat-msg-content"><div class="chat-msg-agent-label">' + escapeHtml(agentName || '担当者') + '</div><div class="chat-msg-bubble chat-msg-bubble-deleted">送信が取り消されました</div><div class="chat-msg-time">' + time + '</div></div></div>';
            }
            var aBody = (m.message && m.message !== '[ファイルを送信しました]') ? escapeHtml(m.message) : '';
            var aAtts = widgetAttachmentHtml(m.attachments || []);
            return '<div class="chat-msg agent" data-msg-id="' + mid + '">' + img + '<div class="chat-msg-content"><div class="chat-msg-agent-label">' + escapeHtml(agentName || '担当者') + '</div>' + (aBody ? '<div class="chat-msg-bubble">' + aBody + '</div>' : '') + aAtts + '<div class="chat-msg-time">' + time + editedMark + '</div></div></div>';
        }

        // 顧客（user）＝自分の発言。
        if (isDeleted) {
            return '<div class="chat-msg user" data-msg-id="' + mid + '"><div class="chat-msg-avatar"></div><div><div class="chat-msg-bubble chat-msg-bubble-deleted">送信を取り消しました</div><div class="chat-msg-time">' + time + '</div></div></div>';
        }
        var body = (m.message && m.message !== '[ファイルを送信しました]') ? escapeHtml(m.message) : '';
        var atts = widgetAttachmentHtml(m.attachments || []);
        // 確定IDがある自分の発言にだけ「編集」「取り消し」操作を付ける。
        var actions = mid > 0
            ? '<div class="chat-contact-msg-actions"><button type="button" class="chat-contact-msg-action" data-contact-edit="' + mid + '">編集</button><button type="button" class="chat-contact-msg-action" data-contact-del="' + mid + '">取り消し</button></div>'
            : '';
        return '<div class="chat-msg user" data-msg-id="' + mid + '"><div class="chat-msg-avatar"></div><div>' + (body ? '<div class="chat-msg-bubble">' + body + '</div>' : '') + atts + '<div class="chat-msg-time">' + time + editedMark + '<span class="chat-contact-read-wrap">' + contactReadMarkHtml(m) + '</span></div>' + actions + '</div></div>';
    }

    // contactMessages から指定IDのメッセージを取得（Array.find非依存）。
    function getContactMsg(mid) {
        for (var i = 0; i < contactMessages.length; i++) {
            if ((parseInt(contactMessages[i].id, 10) || 0) === mid) return contactMessages[i];
        }
        return null;
    }

    // ローカルモデルを更新して担当連絡スレッドを再描画する。
    function patchContactMsg(mid, patch) {
        var m = getContactMsg(mid);
        if (!m) return;
        for (var k in patch) { if (Object.prototype.hasOwnProperty.call(patch, k)) m[k] = patch[k]; }
        if (activeChatTab === 'contact') renderContactThread();
    }

    // 自分の発言をインライン編集モードにする。
    function enterContactEdit(mid) {
        var m = getContactMsg(mid);
        if (!m) return;
        var el = document.getElementById('chat-contact-messages');
        var node = el ? el.querySelector('.chat-msg.user[data-msg-id="' + mid + '"]') : null;
        if (!node) return;
        var current = (m.message && m.message !== '[ファイルを送信しました]') ? m.message : '';
        node.innerHTML = '<div class="chat-msg-avatar"></div><div class="chat-contact-edit">'
            + '<textarea class="chat-contact-edit-input" maxlength="2000"></textarea>'
            + '<div class="chat-contact-edit-actions">'
            + '<button type="button" class="chat-contact-edit-cancel" data-contact-edit-cancel="1">キャンセル</button>'
            + '<button type="button" class="chat-contact-edit-save" data-contact-edit-save="' + mid + '">保存</button>'
            + '</div></div>';
        var ta = node.querySelector('.chat-contact-edit-input');
        if (ta) {
            ta.value = current;
            ta.focus();
            try { ta.selectionStart = ta.selectionEnd = ta.value.length; } catch (e) {}
        }
    }

    // 編集を保存する。
    function saveContactEdit(mid) {
        if (!sessionId || mid <= 0) return;
        var el = document.getElementById('chat-contact-messages');
        var node = el ? el.querySelector('.chat-msg.user[data-msg-id="' + mid + '"]') : null;
        var ta = node ? node.querySelector('.chat-contact-edit-input') : null;
        if (!ta) return;
        var text = ta.value.trim();
        if (!text) { alert('メッセージを入力してください。'); return; }
        fetch(apiBase + '/customer/edit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, visitor_id: visitorId, message_id: mid, message: text })
        })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                if (data && data.success) {
                    patchContactMsg(mid, { message: text, edited: 1 });
                } else {
                    alert((data && data.message) || 'メッセージを編集できませんでした。');
                    if (activeChatTab === 'contact') renderContactThread();
                }
            })
            .catch(function () { alert('通信エラーが発生しました。'); if (activeChatTab === 'contact') renderContactThread(); });
    }

    // 自分の発言を取り消す（unsend）。
    function deleteContactMessage(mid) {
        if (!sessionId || mid <= 0) return;
        if (!window.confirm('このメッセージの送信を取り消しますか？')) return;
        fetch(apiBase + '/customer/delete.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, visitor_id: visitorId, message_id: mid })
        })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                if (data && data.success) {
                    patchContactMsg(mid, { deleted: 1, message: '', attachments: [] });
                } else {
                    alert((data && data.message) || 'メッセージを取り消せませんでした。');
                }
            })
            .catch(function () { alert('通信エラーが発生しました。'); });
    }

    // ポーリング結果（last_read_user_id）に応じて、自分の発言の「既読」表示を更新する。
    function refreshContactReadMarks() {
        var el = document.getElementById('chat-contact-messages');
        if (!el) return;
        Array.prototype.forEach.call(el.querySelectorAll('.chat-msg.user[data-msg-id]'), function (node) {
            var id = parseInt(node.getAttribute('data-msg-id'), 10) || 0;
            var mark = node.querySelector('.chat-contact-read');
            if (mark && id > 0 && id <= contactLastReadUserId) mark.hidden = false;
        });
    }

    function pushContactMessage(m) {
        contactMessages.push(m);
        if (activeChatTab === 'contact') {
            var el = document.getElementById('chat-contact-messages');
            if (el) {
                var empty = el.querySelector('.chat-contact-empty');
                if (empty) empty.remove();
                el.insertAdjacentHTML('beforeend', contactMsgHtml(m));
                el.scrollTop = el.scrollHeight;
            }
        }
    }

    function renderContactPendingAttachments() {
        var listEl = document.getElementById('chat-contact-attach-list');
        if (!listEl) return;
        if (!contactPendingAttachments.length) { listEl.innerHTML = ''; return; }
        listEl.innerHTML = contactPendingAttachments.map(pendingAttachCardHtml).join('');
        Array.prototype.forEach.call(listEl.querySelectorAll('.chat-pending-x'), function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                if (idx >= 0) { contactPendingAttachments.splice(idx, 1); renderContactPendingAttachments(); }
            });
        });
    }

    function uploadContactAttachment(file) {
        if (!sessionId) return;
        var fd = new FormData();
        fd.append('session_id', sessionId);
        fd.append('visitor_id', visitorId || '');
        fd.append('uploaded_by', 'customer');
        fd.append('file', file);
        fetch(apiBase + '/attachment/upload.php', { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.data) {
                    contactPendingAttachments.push(data.data);
                    renderContactPendingAttachments();
                }
            })
            .catch(function () { /* アップロード失敗は無視 */ });
    }

    function sendContactMessage() {
        var input = document.getElementById('chat-contact-input');
        if (!input || !sessionId) return;
        var text = input.value.trim();
        var hasAttachments = contactPendingAttachments.length > 0;
        if (!text && !hasAttachments) return;

        var sentAttachments = contactPendingAttachments.slice();
        var attachmentIds = sentAttachments.map(function (a) { return a.attachment_id; });
        contactPendingAttachments = [];
        renderContactPendingAttachments();

        var storedText = text || '[ファイルを送信しました]';
        var sentMsg = { id: 0, role: 'user', message: storedText, created_at: '', attachments: sentAttachments };
        pushContactMessage(sentMsg);
        input.value = '';

        var payload = { session_id: sessionId, visitor_id: visitorId, message: storedText, channel: 'contact' };
        if (attachmentIds.length) payload.attachment_ids = attachmentIds;
        fetch(apiBase + '/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (res) { return res.json().catch(function () { return null; }); })
            .then(function (data) {
                // 返ってきた message_id を、今送ったバブルに割り当てる（既読判定に使う）。
                if (data && data.success && data.data && data.data.message_id) {
                    sentMsg.id = parseInt(data.data.message_id, 10) || 0;
                    var el = document.getElementById('chat-contact-messages');
                    if (el) {
                        var nodes = el.querySelectorAll('.chat-msg.user[data-msg-id="0"]');
                        if (nodes.length) nodes[nodes.length - 1].setAttribute('data-msg-id', sentMsg.id);
                    }
                }
            })
            .catch(function () { /* 送信失敗は次回ポーリングで整合 */ });
    }

    function bindContactHandlers() {
        var sendB = document.getElementById('chat-contact-send');
        var input = document.getElementById('chat-contact-input');
        var attachB = document.getElementById('chat-contact-attach');
        var fileI = document.getElementById('chat-contact-file');
        var voiceB = document.getElementById('chat-contact-voice');
        if (sendB) sendB.addEventListener('click', function () { sendContactMessage(); });
        if (voiceB) {
            voiceB.addEventListener('click', function () { startVoiceInput(contactVoiceTarget()); });
            if (!SpeechRecognition) {
                voiceB.disabled = true;
                voiceB.title = 'このブラウザは音声入力に対応していません';
                voiceB.setAttribute('aria-label', '音声入力は未対応です');
            }
        }
        if (input) {
            // Enterでは送信しない（改行を入力）。送信は送信ボタン、または音声で「送信」と発話した時のみ。
            input.addEventListener('paste', function (e) { handlePasteToAttach(e, uploadContactAttachment); });
        }
        if (attachB && fileI) {
            attachB.addEventListener('click', function () { fileI.click(); });
            fileI.addEventListener('change', function () {
                if (fileI.files && fileI.files[0]) { uploadContactAttachment(fileI.files[0]); fileI.value = ''; }
            });
        }
        // 自分の発言の「編集」「取り消し」、編集フォームの保存/キャンセルをまとめて委譲。
        var msgsEl = document.getElementById('chat-contact-messages');
        if (msgsEl) {
            msgsEl.addEventListener('click', function (e) {
                var t = e.target;
                if (!t || !t.closest) return;
                var editBtn = t.closest('[data-contact-edit]');
                if (editBtn) { enterContactEdit(parseInt(editBtn.getAttribute('data-contact-edit'), 10) || 0); return; }
                var delBtn = t.closest('[data-contact-del]');
                if (delBtn) { deleteContactMessage(parseInt(delBtn.getAttribute('data-contact-del'), 10) || 0); return; }
                var saveBtn = t.closest('[data-contact-edit-save]');
                if (saveBtn) { saveContactEdit(parseInt(saveBtn.getAttribute('data-contact-edit-save'), 10) || 0); return; }
                var cancelBtn = t.closest('[data-contact-edit-cancel]');
                if (cancelBtn) { if (activeChatTab === 'contact') renderContactThread(); return; }
            });
        }
    }

    function renderContactThread() {
        if (!featurePanel) return;
        featurePanel.hidden = false;
        var listHtml = contactMessages.length
            ? contactMessages.map(contactMsgHtml).join('')
            : '<div class="chat-contact-empty">担当者へのご連絡はこちらから送信できます。担当者からの返信もこの画面に表示されます。</div>';
        var html = '';
        html += '<div class="chat-feature-toolbar"><button type="button" class="chat-feature-back" data-feature-back="1">← AI担当</button></div>';
        html += '<div class="chat-contact-view">';
        html += '<div class="chat-contact-head"><strong>担当連絡</strong><span>' + escapeHtml(agentName || '担当者') + '（担当者）と直接やり取りできます</span></div>';
        html += '<div class="chat-contact-messages" id="chat-contact-messages">' + listHtml + '</div>';
        html += '<div class="chat-contact-attach-list" id="chat-contact-attach-list"></div>';
        html += '<div id="chat-contact-voice-status" class="chat-widget-voice-status" aria-live="polite" hidden></div>';
        // 入力欄はAI担当とまったく同じ見た目・構成にするため、AI担当と同じクラスを使う
        // （テキスト＋音声＋添付＋送信）。IDのみ担当連絡用に分ける。
        // 送信ボタンのアイコンはAI担当の送信ボタンから流用し、画像パスを揃える。
        var aiSendIcon = document.querySelector('#chat-widget-send .chat-widget-send-icon');
        var sendIconHtml = aiSendIcon ? aiSendIcon.outerHTML : '';
        html += '<div class="chat-widget-input-wrap">';
        html += '<textarea id="chat-contact-input" class="chat-widget-input" rows="2" placeholder="担当者へのメッセージを入力..." maxlength="2000"></textarea>';
        html += '<div class="chat-widget-input-actions">';
        html += '<div style="display:flex; gap:10px">'
        html += '<button type="button" id="chat-contact-voice" class="chat-widget-voice" aria-label="音声で入力" title="音声で入力" aria-pressed="false"><span class="chat-widget-voice-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false" role="img"><path d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3z"></path><path d="M17.3 11c0 3-2.54 5.1-5.3 5.1S6.7 14 6.7 11H5c0 3.42 2.72 6.23 6.1 6.65V21h1.8v-3.35c3.38-.42 6.1-3.23 6.1-6.65h-1.7z"></path></svg></span></button>';
        html += '<button type="button" id="chat-contact-attach" class="chat-widget-attach" aria-label="ファイルを添付" title="ファイルを添付"><span aria-hidden="true">＋</span></button>';
        html += '<input type="file" id="chat-contact-file" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx" hidden>';
        html += '</div>'
        html += '<button type="button" id="chat-contact-send" class="chat-widget-send" aria-label="送信"><span>送信</span>' + sendIconHtml + '</button>';
        html += '</div></div></div>';
        featurePanel.innerHTML = html;
        renderContactPendingAttachments();
        var msgsEl = document.getElementById('chat-contact-messages');
        if (msgsEl) msgsEl.scrollTop = msgsEl.scrollHeight;
        bindContactHandlers();
    }

    // ===== 担当連絡：担当からの新着ポーリング・通知・未読バッジ =====
    function setContactTabBadge(count) {
        // ホーム画面アイコンのアプリバッジ（PWA）にも未読数を反映（対応環境のみ）。
        if (window.PushBadge && window.PushBadge.setBadge) { try { window.PushBadge.setBadge(count); } catch (e) {} }
        if (!tabBar) return;
        var btn = tabBar.querySelector('.chat-widget-tab[data-chat-tab="contact"]');
        if (!btn) return;
        var badge = btn.querySelector('.chat-tab-unread');
        if (count > 0) {
            if (!badge) {
                badge = document.createElement('span');
                badge.className = 'chat-tab-unread';
                btn.appendChild(badge);
            }
            badge.textContent = count;
        } else if (badge) {
            badge.remove();
        }
    }

    function notifyAgentMessage(text) {
        // タブが非表示のときだけブラウザ通知（許可済みの場合）
        try {
            if (document.hidden && window.Notification && Notification.permission === 'granted') {
                var n = new Notification((agentName || '担当者') + 'からのメッセージ', {
                    body: (text || '').slice(0, 120),
                    icon: agentPhoto || (siteBase + '/icon-192.png')
                });
                n.onclick = function () { window.focus(); n.close(); };
            }
        } catch (e) { /* 通知失敗は無視 */ }
    }

    function maybeRequestNotificationPermission() {
        try {
            if (window.Notification && Notification.permission === 'default') {
                Notification.requestPermission();
            }
        } catch (e) { /* noop */ }
    }

    // 担当連絡タブを開いたときに、チャネルの全履歴（顧客の送信＋担当の返信）をサーバーから取得して
    // スレッドを再構築する。これにより、入口フロー（おかえりなさい等）やリロード後でも、
    // 顧客自身が送ったメッセージが必ず表示される（ポーリングは担当の新着しか取得しないため）。
    function loadContactHistory() {
        if (!sessionId) return;
        var url = apiBase + '/customer/poll.php?session_id=' + encodeURIComponent(sessionId)
            + '&visitor_id=' + encodeURIComponent(visitorId || '')
            + '&history=1&mark_read=1';
        fetch(url)
            .then(function (res) { return res.json().catch(function () { return { success: false }; }); })
            .then(function (data) {
                if (!data.success || !data.data) return;
                var msgs = data.data.messages || [];
                contactMessages = msgs.map(function (m) {
                    return {
                        id: parseInt(m.id, 10) || 0,
                        role: m.role === 'agent' ? 'agent' : 'user',
                        message: m.message || '',
                        created_at: m.created_at || '',
                        attachments: m.attachments || [],
                        edited: m.edited ? 1 : 0,
                        deleted: m.deleted ? 1 : 0
                    };
                });
                // ポーリングが既読済みの担当発言を再度追加しないよう、最大の担当発言IDを記録。
                contactMessages.forEach(function (m) {
                    if (m.role === 'agent' && m.id > lastAgentMsgId) lastAgentMsgId = m.id;
                });
                var lru = parseInt(data.data.last_read_user_id, 10);
                if (!isNaN(lru) && lru > contactLastReadUserId) contactLastReadUserId = lru;
                var unread = parseInt(data.data.unread_count, 10);
                agentUnreadCount = isNaN(unread) ? 0 : unread;
                setContactTabBadge(agentUnreadCount);
                if (activeChatTab === 'contact') renderContactThread();
            })
            .catch(function () { /* 取得失敗時は既存表示を維持 */ });
    }

    function pollAgentMessages() {
        if (!sessionId) return;
        // 顧客が担当連絡タブを実際に見ているときだけ既読化する。
        var viewing = !panel.hidden && !document.hidden && activeChatTab === 'contact';
        var url = apiBase + '/customer/poll.php?session_id=' + encodeURIComponent(sessionId)
            + '&visitor_id=' + encodeURIComponent(visitorId || '')
            + '&since_id=' + lastAgentMsgId
            + (viewing ? '&mark_read=1' : '');
        fetch(url)
            .then(function (res) { return res.json().catch(function () { return { success: false }; }); })
            .then(function (data) {
                if (!data.success || !data.data) return;
                var msgs = data.data.messages || [];
                msgs.forEach(function (m) {
                    var mid = parseInt(m.id, 10) || 0;
                    if (mid <= lastAgentMsgId) return;
                    lastAgentMsgId = mid;
                    pushContactMessage({ id: mid, role: 'agent', message: m.message || '', created_at: m.created_at || '', attachments: m.attachments || [], edited: m.edited ? 1 : 0, deleted: m.deleted ? 1 : 0 });
                    notifyAgentMessage(m.message || '');
                });
                // バッジはサーバーの「本当の未読件数」（read_at IS NULL）で表示する。
                // 担当連絡タブを見ているときは mark_read 済みのため 0 が返る。
                var unread = parseInt(data.data.unread_count, 10);
                agentUnreadCount = isNaN(unread) ? 0 : unread;
                setContactTabBadge(agentUnreadCount);
                // 担当者が自分の発言を既読にしたら「既読」表示を更新する。
                var lru = parseInt(data.data.last_read_user_id, 10);
                if (!isNaN(lru) && lru > contactLastReadUserId) {
                    contactLastReadUserId = lru;
                    refreshContactReadMarks();
                }
            })
            .catch(function () { /* ネットワークエラーは無視して次回へ */ });
    }

    function startAgentPoll() {
        stopAgentPoll();
        agentPollTimer = setInterval(function () {
            // アクティブ時は短間隔、非アクティブ時は間引く
            if (document.hidden) {
                if ((Date.now() / 1000 | 0) % 4 !== 0) return; // 約20秒に1回
            }
            pollAgentMessages();
        }, 5000);
    }

    function stopAgentPoll() {
        if (agentPollTimer) { clearInterval(agentPollTimer); agentPollTimer = null; }
    }

    // ===== 顧客側 添付アップロード =====
    function renderPendingAttachments() {
        if (!attachListEl) return;
        if (!pendingAttachments.length) { attachListEl.innerHTML = ''; return; }
        attachListEl.innerHTML = pendingAttachments.map(pendingAttachCardHtml).join('');
        Array.prototype.forEach.call(attachListEl.querySelectorAll('.chat-pending-x'), function (btn) {
            btn.addEventListener('click', function () {
                var idx = parseInt(btn.getAttribute('data-idx'), 10);
                if (idx >= 0) { pendingAttachments.splice(idx, 1); renderPendingAttachments(); }
            });
        });
    }

    function uploadCustomerAttachment(file) {
        if (!sessionId) { appendBotMessage('チャットの接続が完了してから添付してください。'); return; }
        var fd = new FormData();
        fd.append('session_id', sessionId);
        fd.append('visitor_id', visitorId || '');
        fd.append('uploaded_by', 'customer');
        fd.append('file', file);
        setVoiceStatus('ファイルをアップロード中...');
        fetch(apiBase + '/attachment/upload.php', { method: 'POST', body: fd })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                setVoiceStatus('');
                if (data.success && data.data) {
                    pendingAttachments.push(data.data);
                    renderPendingAttachments();
                } else {
                    appendBotMessage(data.message || 'ファイルのアップロードに失敗しました。');
                }
            })
            .catch(function () { setVoiceStatus(''); appendBotMessage('ファイルのアップロードに失敗しました。'); });
    }

    var ADDRESS_PREFECTURES = '北海道|青森県|岩手県|宮城県|秋田県|山形県|福島県|茨城県|栃木県|群馬県|埼玉県|千葉県|東京都|神奈川県|新潟県|富山県|石川県|福井県|山梨県|長野県|岐阜県|静岡県|愛知県|三重県|滋賀県|京都府|大阪府|兵庫県|奈良県|和歌山県|鳥取県|島根県|岡山県|広島県|山口県|徳島県|香川県|愛媛県|高知県|福岡県|佐賀県|長崎県|熊本県|大分県|宮崎県|鹿児島県|沖縄県';

    // Turn Japanese addresses into Google Maps links. Matches text that starts
    // with a prefecture name, followed by one or more 市/区/町/村/郡 segments and
    // a REQUIRED full 番地 (street/lot number). This requires an administrative
    // unit (so "東京都心の物件" / "東京都の人口" do not match) and stops cleanly at
    // the end of the address rather than running into surrounding prose. '県' is
    // excluded from the inner character class so a match cannot bleed into a
    // following prefecture (e.g. "...港区と神奈川県川崎市" stays two separate links).
    //
    // The 番地 must contain at least two number groups joined by a hyphen or a
    // 丁目/番/号/条 separator (e.g. "弥平2-20-3" or "弥平2丁目20番3号"). A bare 丁目
    // number such as "弥平2" is not a pinpoint location, so it no longer matches
    // and gets no red pin / link. This rule is applied uniformly to every address.
    var ADDRESS_DELIM = '\\s\\n、。，,「」『』（）()【】\\[\\]＜＞<>＆&"！!？?…：:；;／/';
    var ADDRESS_INNER = '[^' + ADDRESS_DELIM + '県]';
    var ADDRESS_RE = new RegExp(
        '((?:' + ADDRESS_PREFECTURES + ')' +
        '(?:' + ADDRESS_INNER + '{0,6}?(?:市|区|町|村|郡)){1,4}' +
        ADDRESS_INNER + '*?[0-9０-９]+(?:[条丁目番地号西東南北\\-‐―ー－]+[0-9０-９]+)+[条丁目番地号]*)',
        'g'
    );

    // Prefix each address with a red pin that links to Google Maps. Only the pin
    // is a link; the address text itself stays plain (per spec). 'addr' has
    // already been HTML-escaped by formatBotMessageHtml, so escape it again for
    // the aria-label/title attribute to neutralise any stray double quotes.
    // Google マップは全角数字や特殊なハイフン（‐ ― ー －）が混じると住所を正しく
    // ジオコーディングできず、ピンがずれる／検索できないことがある。表示テキスト自体は
    // 元のまま（addr）に保ちつつ、リンク先クエリだけ正規化する。addr は
    // formatBotMessageHtml で HTML エスケープ済みなので、まず実体参照を戻してから
    // 半角数字・標準ハイフンへ変換する。
    function addressForMapsQuery(addr) {
        return String(addr || '')
            .replace(/&amp;/g, '&')
            .replace(/&lt;/g, '<')
            .replace(/&gt;/g, '>')
            .replace(/&quot;/g, '"')
            .replace(/&#0?39;/g, "'")
            .replace(/[０-９]/g, function (ch) { return String.fromCharCode(ch.charCodeAt(0) - 0xFEE0); })
            .replace(/[‐‑‒–—―ー－−]/g, '-');
    }

    function linkifyAddresses(html) {
        return html.replace(ADDRESS_RE, function (addr) {
            var href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addressForMapsQuery(addr));
            var label = escapeAttribute(addr);
            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer" class="chat-msg-address-pin" aria-label="Googleマップで開く: ' + label + '" title="Googleマップで開く: ' + label + '">📍</a>' + addr;
        });
    }

    function formatBotMessageHtml(s) {
        var html = escapeHtml(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        return linkifyAddresses(html);
    }

    function escapeAttribute(s) {
        return escapeHtml(String(s || '')).replace(/"/g, '&quot;');
    }

    function stopVoiceInput() {
        if (!recognition || !isListening) return;
        try { recognition.stop(); } catch (e) {}
    }

    // 音声入力の末尾に含まれる「送信」コマンドを検出する。
    // 返り値 { send: 送信コマンドを含むか, body: コマンド語を除いた送信本文 }
    function detectSendCommand(text) {
        // 末尾の句読点・空白を除いた上で、単独の「送信」/「そうしん」で終わる場合のみコマンドとみなす。
        // 「送信してください」等は本文とみなし誤送信を防ぐ（ユーザーは末尾で「送信」と発話する運用）。
        var raw = (text || '').replace(/[\s、。．，！？!?…]+$/g, '');
        var m = raw.match(/(送信|そうしん)$/);
        if (!m) return { send: false, body: (text || '').trim() };
        var body = raw.slice(0, m.index).replace(/[\s、。．，]+$/g, '').trim();
        return { send: true, body: body };
    }

    function startVoiceInput(target) {
        // クリックイベント等が渡ってきた場合（target.input を持たない）はAI担当を既定とする。
        var t = (target && target.input) ? target : aiVoiceTarget();
        if (!SpeechRecognition || !t.btn || !t.input || sendingMessage || sessionStarting || t.input.disabled) return;
        if (isListening) {
            stopVoiceInput();
            return;
        }

        voiceTarget = t;
        // 音声開始前の入力内容を保持（「送信」だけ発話された場合に既存内容を送るため）。
        var voicePreValue = (t.input && typeof t.input.value === 'string') ? t.input.value : '';
        recognition = new SpeechRecognition();
        recognition.lang = 'ja-JP';
        recognition.interimResults = true;
        recognition.continuous = false;
        finalVoiceTranscript = '';
        voiceHadError = false;
        isListening = true;
        updateVoiceButtonState();
        setVoiceStatus('聞き取り中です。最後に「送信」とお話しになると送信します。');

        recognition.onresult = function (event) {
            var interim = '';
            for (var i = event.resultIndex; i < event.results.length; i++) {
                var transcript = event.results[i][0] && event.results[i][0].transcript ? event.results[i][0].transcript : '';
                if (event.results[i].isFinal) {
                    finalVoiceTranscript += transcript;
                } else {
                    interim += transcript;
                }
            }
            var combined = (finalVoiceTranscript + interim).trim();
            if (combined && t.input) t.input.value = combined;
        };

        recognition.onerror = function (event) {
            voiceHadError = true;
            var message = '音声を認識できませんでした。もう一度お試しください。';
            if (event && event.error === 'not-allowed') message = 'マイクの利用が許可されていません。ブラウザの設定をご確認ください。';
            if (event && event.error === 'no-speech') message = '音声が聞き取れませんでした。もう一度お試しください。';
            setVoiceStatus(message);
        };

        recognition.onend = function () {
            isListening = false;
            updateVoiceButtonState();
            // 次回の状態更新がAI担当ボタンに戻るよう、対象をリセットする。
            voiceTarget = null;
            if (voiceHadError) return;
            var text = finalVoiceTranscript.trim();
            if (!text) { setVoiceStatus('音声入力を終了しました。'); return; }

            var cmd = detectSendCommand(text);
            if (cmd.send) {
                // 末尾に「送信」を検出 → 本文を送信（本文が無ければ音声開始前の入力内容を送信）。
                var body = cmd.body || voicePreValue.trim();
                if (body) {
                    if (t.input) t.input.value = body;
                    setVoiceStatus('「送信」を認識しました。送信します。');
                    t.send(body);
                } else {
                    if (t.input) t.input.value = voicePreValue;
                    setVoiceStatus('送信する内容が聞き取れませんでした。');
                }
            } else {
                // 「送信」が無ければ送信せず、認識テキストを入力欄に残して待機する。
                if (t.input) t.input.value = text;
                setVoiceStatus('入力しました。送信ボタンを押すか、最後に「送信」とお話しください。');
            }
        };

        try {
            recognition.start();
        } catch (e) {
            isListening = false;
            updateVoiceButtonState();
            setVoiceStatus('音声入力を開始できませんでした。もう一度お試しください。');
            voiceTarget = null;
        }
    }

    // 現在位置を前提とした質問をすべて同一フローで捕捉する。土地/情報語の有無に関わらず、
    // 「現在地」「ここはどこ」「この場所」「この土地」等は常にGPS取得フローへ回す（LLMには
    // そのまま投げない）。現在地系の質問に対するAIの自由回答（「現在地は分かりません」等）を
    // 防ぐのが目的。
    function isCurrentLocationIntent(text) {
        if (!text) return false;
        var t = String(text).replace(/\s+/g, '');
        return /(現在地|現在位置|現在の場所|今いる場所|今の場所|今いるところ|いまいる場所|ここはどこ|ここはどの|ここの(土地|場所|情報|住所|地域|エリア)|この場所|この土地|この付近|この周辺|ここら辺|この辺り|この辺|周辺の情報)/.test(t);
    }

    // GPS取得失敗の理由を切り分けて、具体的な対処を案内する。原因が分からないと
    // 「位置情報が取得できない」だけで詰まってしまうため、許可拒否／取得不可／
    // タイムアウト／非セキュア接続 を区別してメッセージを出す。
    function geolocationErrorMessage(err) {
        if (typeof window !== 'undefined' && window.isSecureContext === false) {
            return '現在地を取得できませんでした。安全な接続（https）でないため、位置情報を利用できません。住所を直接ご入力ください。';
        }
        var code = err && err.code;
        if (code === 1) { // PERMISSION_DENIED
            return '位置情報の利用が許可されていないため、現在地を取得できませんでした。\n\nお使いの端末・ブラウザの設定で位置情報を「許可」に変更してから、もう一度お試しください。（例：iPhoneは「設定 > プライバシーとセキュリティ > 位置情報サービス」、Safariのサイト設定など）\n\nそのまま住所を直接ご入力いただくこともできます。';
        }
        if (code === 2) { // POSITION_UNAVAILABLE
            return '現在地を特定できませんでした。電波やGPSの状況が良い場所で、もう一度お試しください。住所を直接ご入力いただくこともできます。';
        }
        if (code === 3) { // TIMEOUT
            return '現在地の取得に時間がかかり、完了できませんでした。もう一度お試しいただくか、住所を直接ご入力ください。';
        }
        return '現在地を取得できませんでした。位置情報を許可するか、住所をご入力ください。';
    }

    // 現在地に関する質問の共通エントリ。
    // 「現在地」と聞かれるたびに、必ずその場で最新のGPS座標を取り直す。
    //
    // 単発の getCurrentPosition は、特に iOS Safari やスタンドアロンPWAで、GPSチップが
    // 新しい測位を終える前に「OSが保持している前回の位置」を即座に返すことがあり、
    // maximumAge:0 を指定しても前回の座標＝前回の住所が出続ける（＝ご報告の症状）。
    // そこで watchPosition で測位を継続し、精度が改善した新しい読み取りを待って採用する。
    // 十分な精度（GOOD_ACCURACY以下）が得られたら即採用、最長 MAX_WAIT_MS まで待って
    // その時点の最良読み取りを採用する。粗すぎる（MAX_ACCURACY超）場合はWi‑Fi/IP由来の
    // 概略位置とみなし、正確な位置情報を有効化して取り直すよう促す。
    function startCurrentLocationFlow() {
        if (!navigator.geolocation) {
            appendBotMessage('お使いのブラウザが位置情報（GPS）に対応していないため、現在地を取得できませんでした。住所を直接ご入力ください。');
            return;
        }
        // 初回のみ許可のお願いを表示（許可済みなら毎回出さない）。
        if (!sessionGeo) {
            appendBotMessage('現在地の情報を取得するため、位置情報（GPS）の利用を許可してください。');
        }
        var waiting = appendBotMessage('現在地を取得しています', true);

        var GOOD_ACCURACY = 100;   // これ以下（m）なら十分正確とみなし即採用
        var MAX_ACCURACY = 3000;   // これ超（m）はGPSでなくWi‑Fi/IP由来の概略位置とみなし不採用
        var MAX_WAIT_MS = 12000;   // 最良読み取りを待つ最長時間

        var watchId = null;
        var timer = null;
        var settled = false;
        var best = null; // { lat, lon, accuracy }

        function cleanup() {
            if (watchId !== null && navigator.geolocation.clearWatch) {
                try { navigator.geolocation.clearWatch(watchId); } catch (e) {}
            }
            watchId = null;
            if (timer) { clearTimeout(timer); timer = null; }
        }

        function useFix(fix) {
            if (settled) return;
            settled = true;
            cleanup();
            if (waiting) waiting.remove();
            // 採用したGPSの緯度・経度・精度をログ出力（現在地ずれの原因切り分け用）。
            if (window.console && console.log) console.log('[chat-widget] geolocation fix (used):', { latitude: fix.lat, longitude: fix.lon, accuracy: fix.accuracy });
            // 常に最新の測位結果で上書きし、そのGPS座標（緯度経度）をそのまま照会に使う。
            sessionGeo = { lat: fix.lat, lon: fix.lon, accuracy: fix.accuracy };
            runCurrentLocationLandInfo(sessionGeo);
        }

        function fail(err) {
            if (settled) return;
            settled = true;
            cleanup();
            if (waiting) waiting.remove();
            if (window.console && console.warn) console.warn('[chat-widget] geolocation failed:', { code: err && err.code, message: err && err.message, bestAccuracy: best && best.accuracy, secureContext: window.isSecureContext });
            // 概略位置しか得られなかった場合は、その旨を明示して取り直しを促す（誤った住所を出さない）。
            if (best && typeof best.accuracy === 'number' && best.accuracy > MAX_ACCURACY) {
                appendBotMessage('現在地を十分な精度で取得できませんでした（推定誤差 約' + Math.round(best.accuracy) + 'm）。\n\nWi‑Fiや通信環境から推定したおおよその位置のため、実際の現在地とずれている可能性があります。お手数ですが、端末の「正確な位置情報（高精度／Precise Location）」をオンにし、ブラウザに位置情報を「許可」したうえで、もう一度お試しください。\n\n（iPhone：設定 > プライバシーとセキュリティ > 位置情報サービス をオン、対象ブラウザで「正確な位置情報」をオン。Android：位置情報を「高精度」に設定）\n\nお急ぎの場合は、住所を直接ご入力いただくこともできます。');
                return;
            }
            appendBotMessage(geolocationErrorMessage(err));
        }

        watchId = navigator.geolocation.watchPosition(
            function (pos) {
                var acc = (typeof pos.coords.accuracy === 'number') ? pos.coords.accuracy : 999999;
                var reading = { lat: pos.coords.latitude, lon: pos.coords.longitude, accuracy: acc };
                // 各読み取りをログ出力（座標が更新されているか＝キャッシュ固定でないかの確認用）。
                if (window.console && console.log) console.log('[chat-widget] geolocation reading:', reading);
                if (!best || acc < best.accuracy) best = reading;
                // 十分な精度が得られたら即採用（それ以上待たない）。
                if (acc <= GOOD_ACCURACY) useFix(reading);
            },
            function (err) {
                // 許可拒否など致命的なエラーは即失敗。取得不可・タイムアウトは待機満了時に判定する。
                if (err && err.code === 1) fail(err);
            },
            // maximumAge:0 でブラウザ／OSのキャッシュ座標を使わせず、継続測位で新しい読み取りを得る。
            { enableHighAccuracy: true, maximumAge: 0, timeout: MAX_WAIT_MS }
        );

        timer = setTimeout(function () {
            // 最長待機に到達。実用的な精度（MAX_ACCURACY以下）の読み取りがあれば採用、なければ失敗扱い。
            if (best && typeof best.accuracy === 'number' && best.accuracy <= MAX_ACCURACY) {
                useFix(best);
            } else {
                fail({ code: 3 }); // TIMEOUT 相当
            }
        }, MAX_WAIT_MS);
    }

    // 取得済み座標を使って土地情報を取得する。ユーザー発言は呼び出し側で既に表示済みのため、
    // ここでは二重表示しない（skipUserEcho）。
    function runCurrentLocationLandInfo(geo) {
        sendMessage('現在地の土地情報を教えてください', { geo: geo, skipUserEcho: true });
    }

    function sendMessage(text, options) {
        options = options || {};
        var hasAttachments = pendingAttachments.length > 0;
        if ((!text.trim() && !hasAttachments) || sendingMessage) return;
        if (isListening) {
            stopVoiceInput();
            return;
        }
        renderQuickReplies([]);
        if (!sessionId) {
            appendBotMessage('チャットの接続が完了してから送信してください。');
            if (!sessionStarting) startSession();
            return;
        }

        // 現在地に関する質問はLLMへ投げず、必ずアプリ側のGPS取得フローへ回す（決定的処理）。
        // options.geo 付きの送信（=GPS取得後の本送信）は対象外にして無限ループを防ぐ。
        if (!options.geo && isCurrentLocationIntent(text)) {
            appendUserMessage(text, '', []);
            inputEl.value = '';
            renderQuickReplies([]);
            startCurrentLocationFlow();
            return;
        }

        var sentAttachments = pendingAttachments.slice();
        var attachmentIds = sentAttachments.map(function (a) { return a.attachment_id; });
        pendingAttachments = [];
        renderPendingAttachments();

        // 担当対応中（AI応答を止めている）かどうか。CRM状態に handoff があれば優先。
        var agentMode = !!(startupData && startupData.handoff_mode === 'agent');

        sendingMessage = true;
        setInputEnabled(false);
        // 現在地フロー（skipUserEcho）では呼び出し側で既にユーザー発言を表示済みのため重複表示しない。
        if (!options.skipUserEcho) appendUserMessage(text, '', sentAttachments);
        inputEl.value = '';
        var loadingRow = (agentMode || (!text.trim() && !options.geo)) ? null : appendBotMessage(options.geo ? '現在地の土地情報を取得しています' : '回答を考えています', true);

        var payload = { session_id: sessionId, visitor_id: visitorId, message: text };
        if (attachmentIds.length) payload.attachment_ids = attachmentIds;
        if (options.buttonSelection) payload.button_selection = options.buttonSelection;
        if (options.geo && isFinite(options.geo.lat) && isFinite(options.geo.lon)) {
            payload.latitude = options.geo.lat;
            payload.longitude = options.geo.lon;
        }

        fetch(apiBase + '/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' };
                });
            })
            .then(function (data) {
                if (loadingRow) loadingRow.remove();
                var finishSend = function () {
                    sendingMessage = false;
                    setInputEnabled(true);
                    updateVoiceButtonState();
                    setVoiceStatus('');
                    inputEl.focus();
                };
                // 担当対応中：AI応答は返らず、担当者に届けられた。担当の返信はポーリングで届く。
                if (data.success && data.data && data.data.agent_mode) {
                    if (startupData) startupData.handoff_mode = 'agent';
                    finishSend();
                    pollAgentMessages();
                    return;
                }
                if (data.success && data.data && (data.data.sms_auth_required || shouldOpenSmsAuthFromReply(data.data.reply, text))) {
                    sendingMessage = false;
                    updateVoiceButtonState();
                    setVoiceStatus('');
                    appendBotMessage(data.data.reply || 'SMS認証フォームを表示します。電話番号を入力して認証を進めてください。');
                    showSmsAuth('register', data.data.sms_auth_phone || text || '');
                    return;
                }
                if (data.success && data.data && data.data.reply) {
                    var responseReplies = data.data.quick_replies || [];
                    var bubbleReplies = responseReplies.filter(function (reply) {
                        return reply && reply.field === 'mansion_lookup';
                    });
                    var footerReplies = responseReplies.filter(function (reply) {
                        return !reply || reply.field !== 'mansion_lookup';
                    });
                    appendBotMessage(data.data.reply, false, data.data.sources || [], '', {
                        typewriter: true,
                        bubbleReplies: bubbleReplies,
                        onComplete: function () {
                            renderQuickReplies(footerReplies);
                            finishSend();
                        }
                    });
                } else {
                    if (data.message && data.message.indexOf('セッション') !== -1) {
                        sessionId = null;
                        clearSavedSessionId();
                    }
                    appendBotMessage(data.message || 'エラーが発生しました。');
                    finishSend();
                }
            })
            .catch(function () {
                sendingMessage = false;
                if (loadingRow) loadingRow.remove();
                appendBotMessage('送信に失敗しました。');
                setInputEnabled(true);
                updateVoiceButtonState();
                inputEl.focus();
            });
    }

    function setActiveChatTab(tab) {
        if (!tabBar) return;
        activeChatTab = tab || 'ai';
        // 担当連絡タブを開いたら担当からの未読をクリア（サーバー側も既読化）
        if (activeChatTab === 'contact') {
            agentUnreadCount = 0;
            setContactTabBadge(0);
            pollAgentMessages();
        }
        Array.prototype.forEach.call(tabBar.querySelectorAll('.chat-widget-tab'), function (btn) {
            var active = btn.getAttribute('data-chat-tab') === tab;
            btn.classList.toggle('is-active', active);
            if (active) btn.setAttribute('aria-current', 'page');
            else btn.removeAttribute('aria-current');
        });
    }

    function featureTabLabel(tab) {
        if (!tabBar) return '戻る';
        var btn = tabBar.querySelector('.chat-widget-tab[data-chat-tab="' + tab + '"]');
        return btn ? (btn.textContent || '戻る').trim() : '戻る';
    }

    function exitFeatureView() {
        activeChatTab = 'ai';
        setActiveChatTab('ai');
        if (featurePanel) {
            featurePanel.hidden = true;
            featurePanel.innerHTML = '';
        }
        panel.classList.remove('is-feature-view');
        // AI担当タブに戻ったときは、担当連絡と同様にキーボードを開かず、
        // 直近の会話（最新メッセージ）を表示する。自動フォーカスはしない。
        if (!panel.hidden) scrollMessagesToBottom();
    }

    function enterFeatureView(tab) {
        panel.classList.add('is-feature-view');
        setActiveChatTab(tab);
        if (featurePanel) featurePanel.hidden = false;
    }

    function showConstructionNotice(tabLabel) {
        setActiveChatTab('ai');
        if (featurePanel) featurePanel.hidden = true;
        panel.classList.remove('is-feature-view');
        appendBotMessage('「' + tabLabel + '」を読み込めませんでした。');
    }

    function apiCrmUrl(path) {
        return apiBase.replace(/\/?$/, '') + '/crm/' + path;
    }

    var sessionRevalidating = false;
    // サーバがセッションを認識できない（保存済み session_id が実在しない等）とき、
    // session/start をやり直して有効な session_id を張り直す。これにより
    // 「読み込みに失敗しました」で固定されず、機能タブが自動復帰できる。
    function revalidateSession() {
        if (sessionRevalidating) return Promise.resolve(false);
        sessionRevalidating = true;
        return fetch(apiBase + '/session/start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_slug: cardSlug, visitor_id: visitorId, current_session_id: getSavedSessionId() || '', resume: true })
        })
            .then(function (res) { return res.json().catch(function () { return { success: false }; }); })
            .then(function (data) {
                sessionRevalidating = false;
                if (data && data.success && data.data && data.data.session_id) {
                    var newId = data.data.session_id;
                    var changed = newId !== sessionId;
                    sessionId = newId;
                    if (data.data.visitor_id) visitorId = data.data.visitor_id;
                    saveSessionId(sessionId);
                    return changed;
                }
                return false;
            })
            .catch(function () { sessionRevalidating = false; return false; });
    }

    function loadCrmState(force, _healed) {
        if (!sessionId) return Promise.resolve(null);
        if (crmLoading && !force) return Promise.resolve(crmState);
        crmLoading = true;
        return fetch(apiCrmUrl('get.php?session_id=' + encodeURIComponent(sessionId)))
            .then(function (res) { return res.json(); })
            .then(function (data) {
                crmLoading = false;
                if (data && data.success && data.data && data.data.case) {
                    crmState = data.data;
                    if (activeChatTab !== 'ai' && featurePanel && !featurePanel.hidden) {
                        renderFeatureTab(activeChatTab);
                    }
                    return crmState;
                }
                // セッション不整合の可能性 → 一度だけセッションを張り直して再試行する。
                if (!_healed) {
                    return revalidateSession().then(function () {
                        return loadCrmState(true, true);
                    });
                }
                crmState = { failed: true };
                return crmState;
            })
            .catch(function () {
                crmLoading = false;
                if (!_healed) {
                    return revalidateSession().then(function () {
                        return loadCrmState(true, true);
                    });
                }
                crmState = { failed: true };
                return crmState;
            });
    }

    function saveCrmFeature(feature, payload) {
        if (!sessionId) return Promise.reject(new Error('session missing'));
        return fetch(apiCrmUrl('save.php'), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, feature: feature, payload: payload || {} })
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (!data.success || !data.data || !data.data.case) throw new Error(data.message || '保存できませんでした');
            crmState = data.data;
            return crmState;
        });
    }

    function crmCase() {
        return crmState && crmState.case ? crmState.case : null;
    }

    function crmFields(value) {
        return escapeHtml(value == null ? '' : String(value));
    }

    function crmAttr(value) {
        return crmFields(value).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function crmArray(value) {
        return Array.isArray(value) ? value : [];
    }

    function crmJson(value) {
        try {
            return JSON.stringify(value, null, 2);
        } catch (e) {
            return '{}';
        }
    }

    function renderFeaturePanel(html) {
        if (!featurePanel) return;
        featurePanel.hidden = false;
        var label = featureTabLabel(activeChatTab);
        featurePanel.innerHTML = '<div class="chat-feature-toolbar"><button type="button" class="chat-feature-back" data-feature-back="1">← AI担当</button></div><div class="chat-feature-body">' + html + '</div>';
    }

    function renderConditionsTab() {
        var c = crmCase();
        if (!c) return renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
        var cond = c.conditions || {};
        var buyer = cond.buyer || {};
        var seller = cond.seller || {};
        var renter = cond.renter || {};
        var dealType = c.deal_type || 'purchase';
        function selected(value, current) {
            return String(value || '') === String(current || '') ? ' selected' : '';
        }
        function optionList(options, current, placeholder) {
            var out = placeholder ? '<option value="">' + escapeHtml(placeholder) + '</option>' : '';
            options.forEach(function (option) {
                out += '<option value="' + escapeAttribute(option) + '"' + selected(option, current) + '>' + escapeHtml(option) + '</option>';
            });
            return out;
        }
        function checkList(name, options, current) {
            var cur = crmArray(current).map(String);
            var out = '<div class="chat-feature-checks">';
            options.forEach(function (option) {
                var checked = cur.indexOf(String(option)) !== -1 ? ' checked' : '';
                out += '<label class="chat-feature-check"><input type="checkbox" name="' + name + '" value="' + escapeAttribute(option) + '"' + checked + '>' + escapeHtml(option) + '</label>';
            });
            out += '</div>';
            return out;
        }

        function buyerSection() {
            var s = '<section><h4>購入条件</h4>';
            s += '<label>購入時期<select name="buyer_purchase_timing">' + optionList(['できるだけ早く', '3か月以内', '6か月以内', '1年以内', '未定'], buyer.purchase_timing, '選択') + '</select></label>';
            s += '<label>引越希望日<input type="date" name="buyer_move_in_date" value="' + crmFields(buyer.move_in_date || '') + '"></label>';
            s += '<label>購入予算上限<input name="buyer_budget_max" placeholder="例：8000万円" value="' + crmFields(buyer.budget_max || '') + '"></label>';
            s += '<label>希望エリア<textarea name="buyer_areas" placeholder="例：中野区&#10;杉並区&#10;武蔵野市">' + crmFields(crmArray(buyer.areas).join('\n')) + '</textarea></label>';
            s += '<label>希望沿線<textarea name="buyer_station_lines" placeholder="例：中央線">' + crmFields(crmArray(buyer.station_lines).join('\n')) + '</textarea></label>';
            s += '<label>希望駅<textarea name="buyer_stations" placeholder="例：中野駅&#10;高円寺駅&#10;阿佐ヶ谷駅">' + crmFields(crmArray(buyer.stations).join('\n')) + '</textarea></label>';
            s += '<label>駅徒歩<select name="buyer_walk_minutes">' + optionList(['5分以内', '10分以内', '15分以内', 'こだわらない'], buyer.walk_minutes, '選択') + '</select></label>';
            s += '<label>種別<select name="buyer_property_type">' + optionList(['マンション', '戸建', 'どちらでも可'], buyer.property_type, '選択') + '</select></label>';
            s += '<label>間取り<select name="buyer_layout">' + optionList(['ワンルーム', '1K', '1LDK', '2LDK', '3LDK', '4LDK', '5LDK以上', 'こだわらない'], buyer.layout, '選択') + '</select></label>';
            s += '<label>面積<input name="buyer_area_min" placeholder="例：60㎡以上" value="' + crmFields(buyer.area_min || '') + '"></label>';
            s += '<label>築年数<select name="buyer_building_age">' + optionList(['新築', '10年以内', '20年以内', '30年以内', 'こだわらない'], buyer.building_age, '選択') + '</select></label>';
            s += '<label>リノベーション希望<select name="buyer_renovation_preference">' + optionList(['リノベーション済み希望', '自らリフォームする予定'], buyer.renovation_preference, '選択') + '</select></label>';
            s += '<label>購入理由<select name="buyer_purchase_reason_select">' + optionList(['家賃がもったいない', '結婚', '出産', '子供の進学', '住み替え', '投資', 'その他'], buyer.purchase_reason, '選択') + '</select><textarea name="buyer_purchase_reason" placeholder="自由入力">' + crmFields(buyer.purchase_reason && ['家賃がもったいない', '結婚', '出産', '子供の進学', '住み替え', '投資', 'その他'].indexOf(buyer.purchase_reason) === -1 ? buyer.purchase_reason : '') + '</textarea></label>';
            s += '</section>';
            return s;
        }

        function sellerSection() {
            var s = '<section><h4>売却条件</h4>';
            s += '<label>売却理由<select name="seller_sale_reason_select">' + optionList(['住み替え', '相続', '離婚', '転勤', '資産整理', '投資売却', 'その他'], seller.sale_reason, '選択') + '</select><textarea name="seller_sale_reason" placeholder="自由入力">' + crmFields(seller.sale_reason && ['住み替え', '相続', '離婚', '転勤', '資産整理', '投資売却', 'その他'].indexOf(seller.sale_reason) === -1 ? seller.sale_reason : '') + '</textarea></label>';
            s += '<label>売却希望時期<select name="seller_sale_timing">' + optionList(['できるだけ早く', '3か月以内', '半年以内', '1年以内', '未定'], seller.sale_timing, '選択') + '</select></label>';
            s += '<label>決済希望日<input type="date" name="seller_closing_date" value="' + crmFields(seller.closing_date || '') + '"></label>';
            s += '<label>売却希望価格<input name="seller_sale_price" placeholder="例：5,500万円" value="' + crmFields(seller.sale_price || '') + '"></label>';
            s += '<label>最低売却価格<input name="seller_minimum_price" placeholder="例：5,000万円" value="' + crmFields(seller.minimum_price || '') + '"></label>';
            s += '<label>住宅ローン残債<input name="seller_loan_balance" placeholder="例：3,200万円" value="' + crmFields(seller.loan_balance || '') + '"></label>';
            s += '<label>住み替え予定<select name="seller_relocation_plan">' + optionList(['あり', 'なし', '未定'], seller.relocation_plan, '選択') + '</select></label>';
            s += '<label>売却後の住まい<select name="seller_post_sale_home">' + optionList(['購入予定', '賃貸予定', '実家', '未定'], seller.post_sale_home, '選択') + '</select></label>';
            s += '<label>内覧対応<select name="seller_viewing_availability">' + optionList(['土日可', '平日可', 'いつでも可', '要相談'], seller.viewing_availability, '選択') + '</select></label>';
            s += '<label>アピールポイント<textarea name="seller_appeal_points" placeholder="例：日当たりが良い&#10;管理状態が良い&#10;角部屋&#10;駅近">' + crmFields(crmArray(seller.appeal_points).join('\n')) + '</textarea></label>';
            s += '</section>';
            return s;
        }

        function renterSection() {
            var rentOptions = ['～5万円', '～6万円', '～7万円', '～8万円', '～9万円', '～10万円', '～12万円', '～15万円', '～18万円', '～20万円', '～25万円', '～30万円', '～40万円', '～50万円', '50万円以上'];
            var rentIsPreset = renter.rent_max && rentOptions.indexOf(renter.rent_max) !== -1;
            var s = '<section><h4>賃貸希望条件</h4>';
            s += '<label>入居希望時期<select name="renter_move_in_timing">' + optionList(['すぐに', '1か月以内', '2か月以内', '3か月以内', '半年以内', '良い物件があれば', '未定'], renter.move_in_timing, '選択') + '</select></label>';
            s += '<label>引越希望日<input type="date" name="renter_move_date" value="' + crmFields(renter.move_date || '') + '"></label>';
            s += '<label>家賃上限（管理費込）<select name="renter_rent_max_select">' + optionList(rentOptions, rentIsPreset ? renter.rent_max : '', '選択') + '</select><input name="renter_rent_max_free" placeholder="自由入力可（例：13万円）" value="' + crmAttr(!rentIsPreset ? (renter.rent_max || '') : '') + '"></label>';
            s += '<label>希望エリア（複数選択可）<textarea name="renter_areas" placeholder="例：中野区&#10;杉並区&#10;フリーワード">' + crmFields(crmArray(renter.areas).join('\n')) + '</textarea></label>';
            s += '<label>希望沿線（複数選択可）<textarea name="renter_station_lines" placeholder="例：中央線&#10;丸ノ内線">' + crmFields(crmArray(renter.station_lines).join('\n')) + '</textarea></label>';
            s += '<label>希望駅（複数選択可）<textarea name="renter_stations" placeholder="例：中野駅&#10;高円寺駅">' + crmFields(crmArray(renter.stations).join('\n')) + '</textarea></label>';
            s += '<label>駅徒歩<select name="renter_walk_minutes">' + optionList(['指定なし', '5分以内', '7分以内', '10分以内', '15分以内', '20分以内'], renter.walk_minutes, '選択') + '</select></label>';
            s += '<label>種別<select name="renter_property_type">' + optionList(['マンション', 'アパート', '戸建て', 'テラスハウス', 'メゾネット', 'タウンハウス', '指定なし'], renter.property_type, '選択') + '</select></label>';
            s += '<div class="chat-feature-checkgroup"><span class="chat-feature-checklabel">間取り（複数選択可）</span>' + checkList('renter_layouts', ['ワンルーム', '1K', '1DK', '1LDK', '2K', '2DK', '2LDK', '3K', '3DK', '3LDK', '4LDK以上', '指定なし'], renter.layouts) + '</div>';
            s += '<label>専有面積<select name="renter_area_min">' + optionList(['指定なし', '20㎡以上', '25㎡以上', '30㎡以上', '40㎡以上', '50㎡以上', '60㎡以上', '70㎡以上', '80㎡以上', '100㎡以上'], renter.area_min, '選択') + '</select></label>';
            s += '<label>築年数<select name="renter_building_age">' + optionList(['指定なし', '新築', '3年以内', '5年以内', '10年以内', '15年以内', '20年以内', '30年以内', '築年数は気にしない'], renter.building_age, '選択') + '</select></label>';
            s += '<div class="chat-feature-checkgroup"><span class="chat-feature-checklabel">こだわり条件（複数選択可）</span>';
            s += '<div class="chat-feature-checksub">人気条件</div>' + checkList('renter_features', ['バストイレ別', '独立洗面台', '室内洗濯機置場', 'オートロック', '宅配ボックス', 'エレベーター', '2階以上', '角部屋', '南向き'], renter.features);
            s += '<div class="chat-feature-checksub">キッチン</div>' + checkList('renter_features', ['システムキッチン', 'ガスコンロ', 'IHコンロ', '2口以上コンロ'], renter.features);
            s += '<div class="chat-feature-checksub">バス・収納</div>' + checkList('renter_features', ['追焚き', '浴室乾燥機', '温水洗浄便座', 'ウォークインクローゼット'], renter.features);
            s += '<div class="chat-feature-checksub">その他</div>' + checkList('renter_features', ['ペット可', '楽器可', 'SOHO・事務所利用可', '駐車場', '駐輪場', 'バイク置場', 'インターネット無料', '即入居可', '敷金なし', '礼金なし'], renter.features);
            s += '</div>';
            s += '<label>引越し理由<select name="renter_move_reason">' + optionList(['就職・転職', '転勤', '通勤時間を短くしたい', '通学', '結婚', '同棲', '出産・子育て', '家族が増える', '独立・一人暮らし', '実家を出る', '更新のタイミング', '家賃を下げたい', '家賃を上げて住み替えたい', '部屋が狭い', '部屋を広くしたい', '設備を良くしたい', '周辺環境を変えたい', 'ペットを飼いたい', 'その他'], renter.move_reason, '選択') + '</select></label>';
            s += '</section>';
            return s;
        }

        var html = '';
        html += '<div class="chat-feature-head"><strong>条件整理</strong><span>AIチャットから抽出した条件を保存します</span></div>';
        html += '<label class="chat-feature-field">相談種別<select name="deal_type"><option value="purchase"' + (dealType === 'purchase' ? ' selected' : '') + '>購入</option><option value="sale"' + (dealType === 'sale' ? ' selected' : '') + '>売却</option><option value="both"' + (dealType === 'both' ? ' selected' : '') + '>買い替え</option><option value="rent"' + (dealType === 'rent' ? ' selected' : '') + '>賃貸</option></select></label>';
        html += '<div class="chat-feature-grid">';
        if (dealType === 'sale') {
            html += sellerSection();
        } else if (dealType === 'both') {
            // 買い替え: 上に売却の入力画面、下に購入の入力画面。
            html += sellerSection() + buyerSection();
        } else if (dealType === 'rent') {
            html += renterSection();
        } else {
            html += buyerSection();
        }
        html += '</div>';
        html += '<div class="chat-feature-actions"><button type="button" class="chat-feature-save" data-save-feature="conditions">保存</button><button type="button" class="chat-feature-sync" data-sync-feature="conditions">チャットから再読込</button></div>';
        html += '<div class="chat-feature-summary"><strong>整理結果</strong><p>' + crmFields(c.conditions_summary || '未整理') + '</p></div>';
        renderFeaturePanel(html);
    }

    function renderProgressTab() {
        var c = crmCase();
        if (!c) return renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
        var p = c.progress || {};
        var stages = crmArray(p.stages);
        var html = '<div class="chat-feature-head"><strong>進捗管理</strong><span>進捗率 ' + crmFields(p.progress_percent || 0) + '%</span></div>';
        html += '<label class="chat-feature-field">基準日<input type="date" name="target_date" value="' + crmFields(p.target_date || '') + '"></label>';
        html += '<label class="chat-feature-field">現在のステージ<input name="current_stage" value="' + crmFields(p.current_stage || '') + '"></label>';
        html += '<label class="chat-feature-field">AIコメント<textarea name="ai_comment">' + crmFields(p.ai_comment || '') + '</textarea></label>';
        html += '<div class="chat-feature-list">';
        stages.forEach(function (stage, idx) {
            html += '<div class="chat-feature-list-row"><div><strong>' + crmFields(stage.label || '') + '</strong><div>' + crmFields(stage.date || '') + '</div></div>';
            html += '<input type="date" data-stage-key="' + crmFields(stage.key || ('stage_' + idx)) + '" value="' + crmFields((p.manual_overrides && p.manual_overrides[stage.key]) || '') + '"></div>';
        });
        html += '</div>';
        html += '<div class="chat-feature-actions"><button type="button" class="chat-feature-save" data-save-feature="progress">保存</button></div>';
        renderFeaturePanel(html);
    }

    // ===== 物件選定（顧客側・新API）=====
    var PUI = window.PropertyUI;
    function propApi(path, opts) {
        opts = opts || {};
        return fetch(siteBase + '/backend/api/property' + path, opts).then(function (r) { return r.json(); });
    }
    function propAuthQS() {
        return 'session_id=' + encodeURIComponent(sessionId || '') + '&visitor_id=' + encodeURIComponent(visitorId || '');
    }

    function renderPropertiesTab(_healed) {
        if (!PUI) { renderFeaturePanel('<div class="chat-feature-empty">読み込みに失敗しました。</div>'); return; }
        if (!sessionId) {
            renderFeaturePanel('<div class="chat-feature-empty">まず「AI担当」で会話を始めると、提案物件をここで確認できます。</div>');
            return;
        }
        renderFeaturePanel('<div class="prop-wrap" id="prop-cust"><div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div></div>');
        propApi('/list.php?' + propAuthQS()).then(function (res) {
            var box = featurePanel.querySelector('#prop-cust');
            if (!box) return;
            if (!res || !res.success) {
                // セッション不整合の可能性 → 一度だけセッションを張り直して再試行。
                if (!_healed) { revalidateSession().then(function () { renderPropertiesTab(true); }); return; }
                propShowLoadError(box); return;
            }
            var html = '<div class="prop-toolbar"><h4>物件選定</h4></div>';
            html += '<div class="prop-method" id="prop-cust-urlbtn" style="margin-bottom:12px">' +
                '<span class="prop-method__icon prop-method__icon--url">' + PUI.icon('url') + '</span>' +
                '<span class="prop-method__body"><span class="prop-method__title">物件URLを共有</span>' +
                '<span class="prop-method__desc">SUUMO・HOME\'S・アットホーム等のURLを貼り付けて登録</span></span>' +
                '<span class="prop-method__chev">' + PUI.icon('chev') + '</span></div>';
            var items = (res.success && res.data.properties) ? res.data.properties : [];
            if (!items.length) html += '<div class="prop-empty">提案された物件・共有した物件がここに表示されます。</div>';
            else html += '<div class="prop-list">' + items.map(function (p) { return PUI.cardHtml(p, { fav: true, sessionId: sessionId, visitorId: visitorId }); }).join('') + '</div>';
            box.innerHTML = html;
            box.querySelector('#prop-cust-urlbtn').addEventListener('click', propOpenUrlShare);
            box.querySelectorAll('.prop-card').forEach(function (c) {
                c.addEventListener('click', function () { propOpenDetail(parseInt(c.getAttribute('data-prop-id'), 10)); });
            });
        }).catch(function () {
            // 通信エラー時に「読み込み中」のまま固まらないようにし、再読み込みで復帰できるようにする。
            if (!_healed) { revalidateSession().then(function () { renderPropertiesTab(true); }); return; }
            var box = featurePanel.querySelector('#prop-cust');
            if (box) propShowLoadError(box);
        });
    }

    function propShowLoadError(box) {
        box.innerHTML = '<div class="prop-empty">読み込みに失敗しました。<br><button type="button" class="prop-btn prop-btn--primary" id="prop-cust-retry">再読み込みする</button></div>';
        var rb = box.querySelector('#prop-cust-retry');
        if (rb) rb.addEventListener('click', renderPropertiesTab);
    }

    function propOpenUrlShare() {
        var html = '<div class="prop-field full"><label>物件URL</label>' +
            '<input type="url" id="prop-cust-url" placeholder="https://suumo.jp/..."></div>' +
            '<div class="prop-msg prop-msg--info">URLを送ると担当者と共有され、物件情報が自動で登録されます。</div>' +
            '<div class="prop-form-actions"><button type="button" class="prop-btn prop-btn--primary" id="prop-cust-url-go">共有して登録</button></div>';
        var m = PUI.modal('物件URLを共有', html);
        m.body.querySelector('#prop-cust-url-go').addEventListener('click', function () {
            var url = m.body.querySelector('#prop-cust-url').value.trim();
            if (!/^https?:\/\//i.test(url)) { alert('有効なURLを入力してください'); return; }
            var btn = m.body.querySelector('#prop-cust-url-go'); btn.disabled = true; btn.innerHTML = '<span class="prop-spinner"></span> 登録中...';
            propApi('/analyze-url.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ session_id: sessionId, visitor_id: visitorId, url: url }) })
                .then(function (res) {
                    m.close();
                    if (!res.success) { alert(res.message || '登録に失敗しました'); return; }
                    renderPropertiesTab();
                }).catch(function () { m.close(); alert('通信に失敗しました'); });
        });
    }

    function propOpenDetail(id) {
        renderFeaturePanel('<div class="prop-wrap" id="prop-cust"><div class="prop-empty"><span class="prop-spinner"></span> 読み込み中...</div></div>');
        propApi('/get.php?id=' + id + '&' + propAuthQS()).then(function (res) {
            if (!res.success) { featurePanel.querySelector('#prop-cust').innerHTML = '<div class="prop-empty">取得に失敗しました。</div>'; return; }
            propRenderDetail(res.data.property);
        });
    }

    function propRenderDetail(p) {
        var statusChips = Object.keys(PUI.STATUS).filter(function (k) { return PUI.STATUS[k].role === 'customer'; }).map(function (k) {
            var s = PUI.STATUS[k]; var on = p.status === k;
            return '<button type="button" class="prop-status-opt' + (on ? ' is-selected' : '') + '" data-cust-status="' + k + '" style="color:' + s.color + '">' +
                '<span class="prop-badge--icon" style="color:' + s.color + '">' + PUI.icon(s.icon) + '</span>' + PUI.esc(s.label) + '</button>';
        }).join('');
        var html = '<div class="prop-wrap" id="prop-cust">' +
            '<div class="prop-toolbar"><button type="button" class="prop-btn prop-btn--ghost" id="prop-cust-back">← 物件一覧</button></div>' +
            PUI.detailHeaderHtml(p) +
            '<div class="prop-section-title">あなたの検討ステータス</div>' +
            '<div class="prop-status-grid" id="prop-cust-status">' + statusChips + '</div>' +
            '<div class="prop-tabs">' +
                '<button class="prop-tab is-active" data-ctab="basic">基本情報</button>' +
                '<button class="prop-tab" data-ctab="hazard">ハザード等情報</button>' +
                '<button class="prop-tab" data-ctab="flyer">販売図面</button>' +
                '<button class="prop-tab" data-ctab="photo">写真・資料</button>' +
            '</div>' +
            '<div class="prop-tabpane is-active" data-cpane="basic">' + PUI.basicInfoHtml(p, false) + '</div>' +
            '<div class="prop-tabpane" data-cpane="hazard">' + PUI.hazardHtml(p.hazard, p.hazard_fetched_at) + '</div>' +
            '<div class="prop-tabpane" data-cpane="flyer">' + PUI.galleryHtml(p.flyers, { sessionId: sessionId, visitorId: visitorId, emptyText: '販売図面はまだありません。' }) + '</div>' +
            '<div class="prop-tabpane" data-cpane="photo">' + PUI.galleryHtml(p.photos, { sessionId: sessionId, visitorId: visitorId, emptyText: '写真・資料はまだありません。' }) + '</div>' +
            '<div class="prop-form-actions" style="margin-top:16px"><button type="button" class="prop-btn prop-btn--primary" id="prop-cust-viewing">' + PUI.icon('calendar') + '内見予約を依頼する</button></div>' +
        '</div>';
        renderFeaturePanel(html);
        var box = featurePanel.querySelector('#prop-cust');
        box.querySelector('#prop-cust-back').addEventListener('click', renderPropertiesTab);
        PUI.bindLightbox(box);
        box.querySelectorAll('[data-cust-status]').forEach(function (b) {
            b.addEventListener('click', function () {
                var st = b.getAttribute('data-cust-status');
                if (p.status === st) st = '';
                propApi('/status.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id, status: st, visitor_id: visitorId }) })
                    .then(function (res) { if (res.success) { p.status = res.data.property.status; propRenderDetail(p); } });
            });
        });
        var tabs = box.querySelectorAll('.prop-tab');
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                tabs.forEach(function (x) { x.classList.remove('is-active'); });
                box.querySelectorAll('.prop-tabpane').forEach(function (x) { x.classList.remove('is-active'); });
                t.classList.add('is-active');
                box.querySelector('[data-cpane="' + t.getAttribute('data-ctab') + '"]').classList.add('is-active');
            });
        });
        box.querySelector('#prop-cust-viewing').addEventListener('click', function () {
            var note = prompt('内見のご希望（日程・時間帯など）があればご記入ください（任意）', '');
            if (note === null) return;
            propApi('/viewing-request.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ property_id: p.id, session_id: sessionId, visitor_id: visitorId, note: note }) })
                .then(function (res) {
                    if (!res.success) { alert(res.message || '依頼に失敗しました'); return; }
                    alert('内見予約を依頼しました。担当連絡をご確認ください。');
                    if (typeof renderFeatureTab === 'function') { setActiveChatTab('contact'); renderFeatureTab('contact'); }
                });
        });
    }

    function renderToolsTab() {
        var c = crmCase();
        if (!c) return renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
        var tools = (c.tools && Array.isArray(c.tools)) ? c.tools : [];
        var loanRepaymentUrl = siteBase + '/loan-simulator.php?slug=' + encodeURIComponent(cardSlug) + '&form=repayment';
        var loanBorrowUrl = siteBase + '/loan-simulator.php?slug=' + encodeURIComponent(cardSlug) + '&form=borrow-income';
        var loanRepaymentImage = siteBase + '/assets/images/lp_icon/loan_repayment.png';
        var loanBorrowImage = siteBase + '/assets/images/lp_icon/loan_borrowable.png';
        var html = '<div class="chat-feature-head"><strong>ツール</strong><span>リンク集</span></div>';
        html += '<div class="chat-feature-tools chat-feature-tools-grid">';
        tools.forEach(function (tool) {
            html += '<a class="chat-feature-tool-card" href="' + crmAttr(tool.tool_url || '#') + '" target="_blank" rel="noopener noreferrer">';
            html += '<span class="chat-feature-tool-image"><img src="' + crmAttr(tool.image_url || '') + '" alt="' + crmAttr(tool.tool_name || 'ツール') + '" loading="lazy"></span>';
            html += '<span class="chat-feature-tool-description">' + crmFields(tool.description || '') + '</span>';
            html += '<span class="chat-feature-tool-button">' + crmFields(tool.button_label || '利用') + '</span>';
            html += '</a>';
        });
        html += '<a class="chat-feature-tool-card chat-feature-tool-card-loan" href="' + crmAttr(loanRepaymentUrl) + '" target="_blank" rel="noopener noreferrer">';
        html += '<span class="chat-feature-tool-image"><img src="' + crmAttr(loanRepaymentImage) + '" alt="ローンシミュレーター 月額返済額計算" loading="lazy"></span>';
        html += '<span class="chat-feature-tool-description">借入額と金利から毎月の返済額を瞬時に計算。住宅購入後の資金計画に役立ちます。</span>';
        html += '<span class="chat-feature-tool-button">シミュレーション</span>';
        html += '</a>';
        html += '<a class="chat-feature-tool-card chat-feature-tool-card-loan" href="' + crmAttr(loanBorrowUrl) + '" target="_blank" rel="noopener noreferrer">';
        html += '<span class="chat-feature-tool-image"><img src="' + crmAttr(loanBorrowImage) + '" alt="ローンシミュレーター 借入可能額計算" loading="lazy"></span>';
        html += '<span class="chat-feature-tool-description">年収や返済額から借入可能額を瞬時に試算。購入できる物件価格の目安が分かります。</span>';
        html += '<span class="chat-feature-tool-button">シミュレーション</span>';
        html += '</a>';
        html += '</div>';
        renderFeaturePanel(html);
    }

    function renderSchedulesTab() {
        var c = crmCase();
        if (!c) return renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
        var schedules = c.schedules || {};
        var normal = crmArray(schedules.normal);
        var viewings = crmArray(schedules.viewings);
        var html = '<div class="chat-feature-head"><strong>日程調整</strong><span>候補日時の管理</span></div>';
        html += '<div class="chat-feature-grid">';
        html += '<label>種類<select name="schedule_kind"><option>面談</option><option>内覧</option><option>重要事項説明</option><option>売買契約</option><option>引き渡し（決済）</option></select></label>';
        html += '<label>日時<input type="datetime-local" name="schedule_datetime"></label>';
        html += '<label>ステータス<select name="schedule_status"><option>候補</option><option>担当者確認中</option><option>顧客確認待ち</option><option>売主確認待ち</option><option>確定済み</option><option>変更依頼中</option><option>キャンセル済み</option></select></label>';
        html += '<label>メモ<textarea name="schedule_message"></textarea></label>';
        html += '</div>';
        html += '<div class="chat-feature-actions"><button type="button" class="chat-feature-add" data-add-feature="schedule">追加</button><button type="button" class="chat-feature-save" data-save-feature="schedules">保存</button></div>';
        html += '<div class="chat-feature-list">';
        normal.forEach(function (item) {
            html += '<div class="chat-feature-card"><strong>' + crmFields(item.kind || '日程') + '</strong><div>' + crmFields(item.datetime || '') + '</div><p>' + crmFields(item.status || '') + '</p></div>';
        });
        viewings.forEach(function (item) {
            html += '<div class="chat-feature-card"><strong>' + crmFields(item.property_name || '内覧') + '</strong><div>' + crmFields(item.time || '') + '</div><p>' + crmFields(item.address || '') + '</p></div>';
        });
        html += '</div>';
        renderFeaturePanel(html);
    }

    function renderFeatureTab(tab) {
        if (!featurePanel) return;
        if (tab === 'ai') {
            exitFeatureView();
            return;
        }
        enterFeatureView(tab);
        featurePanel.hidden = false;
        if (tab === 'contact') {
            // 担当連絡は専用チャットUI。CRM状態のロードは不要。
            renderContactThread();
            loadContactHistory();
            return;
        }
        if (tab === 'property') {
            // 物件選定は専用API。CRM状態に依存しない。
            renderPropertiesTab();
            return;
        }
        if (!crmState) {
            renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
            if (!crmLoading) {
                loadCrmState(true).then(function () { renderFeatureTab(tab); });
            }
            return;
        }
        if (crmState.failed) {
            // 失敗状態を固定表示にせず、再読み込みで復帰できるようにする。
            // （複数端末での共有セッション確定前に一度失敗しても、押し直せば読み込める）
            renderFeaturePanel('<div class="chat-feature-empty">読み込みに失敗しました。<br><button type="button" class="chat-feature-retry" data-crm-retry="1">再読み込みする</button></div>');
            var retryBtn = featurePanel.querySelector('[data-crm-retry]');
            if (retryBtn) retryBtn.addEventListener('click', function () {
                crmState = null;
                renderFeatureTab(tab);
            });
            return;
        }
        if (tab === 'conditions') renderConditionsTab();
        else if (tab === 'progress') renderProgressTab();
        else if (tab === 'property') renderPropertiesTab();
        else if (tab === 'tools') renderToolsTab();
        else if (tab === 'schedule') renderSchedulesTab();
        else renderFeaturePanel('<div class="chat-feature-empty">準備中です。</div>');
    }

    function collectConditionsPayload() {
        var root = featurePanel;
        function has(name) { return !!root.querySelector('[name="' + name + '"]'); }
        function val(name) { var el = root.querySelector('[name="' + name + '"]'); return el ? (el.value || '') : ''; }
        function checks(name) {
            return Array.prototype.map.call(root.querySelectorAll('[name="' + name + '"]:checked'), function (el) { return el.value; });
        }
        function splitList(value) {
            return value ? value.split(/[、,，\n\r]+/).map(function (s) { return s.trim(); }).filter(Boolean) : [];
        }
        // 既存の条件を土台にして、画面に表示されているセクションだけ上書きする。
        // （相談種別の切替で非表示になったセクションのデータを消さないため）
        var existing = (crmCase() && crmCase().conditions) ? crmCase().conditions : {};
        var conditions;
        try { conditions = JSON.parse(JSON.stringify(existing)); } catch (e) { conditions = {}; }
        conditions.buyer = conditions.buyer || {};
        conditions.seller = conditions.seller || {};
        conditions.renter = conditions.renter || {};
        if (typeof conditions.notes !== 'string') conditions.notes = '';

        var dealType = val('deal_type') || (crmCase().deal_type || 'purchase');
        conditions.deal_type = dealType;

        if (has('buyer_purchase_timing')) {
            var purchaseReasonFree = val('buyer_purchase_reason').trim();
            var purchaseReasonSelect = val('buyer_purchase_reason_select').trim();
            conditions.buyer = {
                purchase_timing: val('buyer_purchase_timing'),
                move_in_date: val('buyer_move_in_date'),
                budget_max: val('buyer_budget_max'),
                areas: splitList(val('buyer_areas')),
                station_lines: splitList(val('buyer_station_lines')),
                stations: splitList(val('buyer_stations')),
                walk_minutes: val('buyer_walk_minutes'),
                property_type: val('buyer_property_type'),
                layout: val('buyer_layout'),
                area_min: val('buyer_area_min'),
                building_age: val('buyer_building_age'),
                renovation_preference: val('buyer_renovation_preference'),
                purchase_reason: purchaseReasonFree || purchaseReasonSelect
            };
        }

        if (has('seller_sale_timing')) {
            var saleReasonFree = val('seller_sale_reason').trim();
            var saleReasonSelect = val('seller_sale_reason_select').trim();
            conditions.seller = {
                sale_reason: saleReasonFree || saleReasonSelect,
                sale_timing: val('seller_sale_timing'),
                closing_date: val('seller_closing_date'),
                sale_price: val('seller_sale_price'),
                minimum_price: val('seller_minimum_price'),
                loan_balance: val('seller_loan_balance'),
                relocation_plan: val('seller_relocation_plan'),
                post_sale_home: val('seller_post_sale_home'),
                viewing_availability: val('seller_viewing_availability'),
                appeal_points: splitList(val('seller_appeal_points'))
            };
        }

        if (has('renter_move_in_timing')) {
            var rentFree = val('renter_rent_max_free').trim();
            var rentSelect = val('renter_rent_max_select').trim();
            conditions.renter = {
                move_in_timing: val('renter_move_in_timing'),
                move_date: val('renter_move_date'),
                rent_max: rentFree || rentSelect,
                areas: splitList(val('renter_areas')),
                station_lines: splitList(val('renter_station_lines')),
                stations: splitList(val('renter_stations')),
                walk_minutes: val('renter_walk_minutes'),
                property_type: val('renter_property_type'),
                layouts: checks('renter_layouts'),
                area_min: val('renter_area_min'),
                building_age: val('renter_building_age'),
                features: checks('renter_features'),
                move_reason: val('renter_move_reason')
            };
        }

        return {
            deal_type: dealType,
            customer_name: crmCase().customer_name || '',
            ai_summary: crmCase().ai_summary || '',
            conditions: conditions
        };
    }

    function collectProgressPayload() {
        var root = featurePanel;
        var overrides = {};
        Array.prototype.forEach.call(root.querySelectorAll('[data-stage-key]'), function (input) {
            overrides[input.getAttribute('data-stage-key')] = input.value || '';
        });
        return {
            deal_type: crmCase().deal_type || 'purchase',
            progress: {
                deal_type: crmCase().deal_type || 'purchase',
                target_date: (root.querySelector('[name="target_date"]') || {}).value || '',
                current_stage: (root.querySelector('[name="current_stage"]') || {}).value || '',
                progress_percent: crmCase().progress ? crmCase().progress.progress_percent : 0,
                ai_comment: (root.querySelector('[name="ai_comment"]') || {}).value || '',
                manual_overrides: overrides
            }
        };
    }

    function collectPropertiesPayload() {
        var root = featurePanel;
        return {
            deal_type: crmCase().deal_type || 'purchase',
            properties: {
                items: crmArray((crmCase().properties || {}).items).concat([{
                    id: Date.now(),
                    name: (root.querySelector('[name="property_name"]') || {}).value || '',
                    address: (root.querySelector('[name="property_address"]') || {}).value || '',
                    price: (root.querySelector('[name="property_price"]') || {}).value || '',
                    status: (root.querySelector('[name="property_status"]') || {}).value || '検討中',
                    comment: (root.querySelector('[name="property_comment"]') || {}).value || ''
                }]).filter(function (item) { return item.name || item.address || item.price || item.comment; })
            }
        };
    }

    function collectSchedulesPayload() {
        var root = featurePanel;
        return {
            deal_type: crmCase().deal_type || 'purchase',
            schedules: {
                deal_type: crmCase().deal_type || 'purchase',
                normal: crmArray((crmCase().schedules || {}).normal).concat([{
                    id: Date.now(),
                    kind: (root.querySelector('[name="schedule_kind"]') || {}).value || '',
                    datetime: (root.querySelector('[name="schedule_datetime"]') || {}).value || '',
                    status: (root.querySelector('[name="schedule_status"]') || {}).value || '',
                    message: (root.querySelector('[name="schedule_message"]') || {}).value || ''
                }]),
                viewings: crmArray((crmCase().schedules || {}).viewings),
                seller_viewing_availability: crmArray((crmCase().schedules || {}).seller_viewing_availability)
            }
        };
    }

    updateVoiceButtonState();
    if (!SpeechRecognition && voiceBtn) {
        voiceBtn.classList.add('is-unsupported');
    }

    if (!chatOnly) {
        watchPwaModals();
        watchInstallBanner();
    }

    messagesContainer.addEventListener("scroll", scheduleQuickActionsRevealCheck);
    messagesContainer.addEventListener("wheel", function () { noteQuickActionsScrollIntent(true); }, { passive: true });
    messagesContainer.addEventListener("touchmove", function () { noteQuickActionsScrollIntent(true); }, { passive: true });
    messagesContainer.addEventListener("pointerdown", function () { noteQuickActionsScrollIntent(false); }, { passive: true });
    window.addEventListener("resize", syncQuickActionsAfterRender);
    window.addEventListener("orientationchange", syncQuickActionsAfterRender);

    // focusin は担当連絡など後から描画される入力欄も拾う。
    root.addEventListener('focusin', function () {
        syncChatVisualViewport();
        setTimeout(syncChatVisualViewport, 100);
        setTimeout(syncChatVisualViewport, 300);
    });
    root.addEventListener('focusout', function () {
        setTimeout(syncChatVisualViewport, 100);
    });
    window.addEventListener('resize', syncChatVisualViewport);
    window.addEventListener('orientationchange', syncChatVisualViewport);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncChatVisualViewport);
        window.visualViewport.addEventListener('scroll', syncChatVisualViewport);
    }
    syncChatVisualViewport();

    toggleBtn.setAttribute('aria-expanded', chatOnly ? 'true' : 'false');
    toggleBtn.addEventListener('click', function () {
        if (panel.hidden) showPanel();
        else hidePanel();
    });
    closeBtn.addEventListener('click', hidePanel);
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () {
            if (sessionStarting || sendingMessage) return;
            if (isListening) stopVoiceInput();
            startSession(true, true);
        });
    }

    sendBtn.addEventListener('click', function () {
        sendMessage(inputEl.value.trim());
    });
    if (voiceBtn) {
        voiceBtn.addEventListener('click', startVoiceInput);
    }
    if (attachBtn && fileInput) {
        attachBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files[0]) {
                uploadCustomerAttachment(fileInput.files[0]);
                fileInput.value = '';
            }
        });
    }
    inputEl.addEventListener('paste', function (e) { handlePasteToAttach(e, uploadCustomerAttachment); });
    // PC（物理キーボード）ではEnterで送信、Shift+Enterで改行する。チャットの標準操作に
    // 合わせ、PCで「送信ボタンを押さないと送れない＝AIエージェントが使えない」状態を解消する。
    // スマホ（タッチ端末）ではEnterは改行のままにし、従来どおり送信ボタンで送信する
    // （ソフトキーボードのEnterは改行が自然なため）。
    // 日本語IMEの変換確定Enter（isComposing / keyCode 229）では送信しない。
    var chatInputIsTouchDevice = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
    inputEl.addEventListener('keydown', function (e) {
        if (chatInputIsTouchDevice) return;
        var isEnter = e.key === 'Enter' || e.keyCode === 13;
        if (!isEnter || e.shiftKey) return;
        if (e.isComposing || e.keyCode === 229) return; // IME変換確定中は送信しない
        e.preventDefault();
        sendMessage(inputEl.value.trim());
    });

    if (tabBar) {
        tabBar.addEventListener('click', function (e) {
            try {
                var target = e.target && e.target.nodeType === 1 ? e.target : e.srcElement;
                var btn = target && target.closest ? target.closest('.chat-widget-tab') : null;
                if (!btn) return;
                var tab = btn.getAttribute('data-chat-tab') || 'ai';
                if (tab === 'ai') {
                    exitFeatureView();
                    return;
                }
                // 接続中・ユーザー認識中は、AI担当以外のタブを開かせない。
                // 認識前に遷移するとスタックし、誰の履歴か分からないまま操作が進むため。
                if (!chatFeaturesReady()) {
                    return;
                }
                if (tab === 'contact') {
                    // 担当連絡は専用チャット。CRMロードを挟まず即表示し、全履歴をサーバーから取得。
                    enterFeatureView(tab);
                    renderContactThread();
                    loadContactHistory();
                    return;
                }
                enterFeatureView(tab);
                loadCrmState(false).then(function () {
                    renderFeatureTab(tab);
                });
            } catch (err) {
                // 黙って死なせない：以降のクリックは生かしつつ原因をコンソールに残す。
                if (window.console && console.error) console.error('[chat-widget] tab click failed:', err);
            }
        });
    }

    // 汎用の確認モーダル（はい/いいえのラベルを指定可能）。Promise<boolean> を返す。
    function showConfirmDialog(message, yesLabel, noLabel) {
        return new Promise(function (resolve) {
            var overlay = document.createElement('div');
            overlay.className = 'chat-confirm-overlay';
            var box = document.createElement('div');
            box.className = 'chat-confirm-box';
            var msg = document.createElement('p');
            msg.className = 'chat-confirm-message';
            msg.textContent = message;
            var actions = document.createElement('div');
            actions.className = 'chat-confirm-actions';
            var yesBtn = document.createElement('button');
            yesBtn.type = 'button';
            yesBtn.className = 'chat-confirm-yes';
            yesBtn.textContent = yesLabel;
            var noBtn = document.createElement('button');
            noBtn.type = 'button';
            noBtn.className = 'chat-confirm-no';
            noBtn.textContent = noLabel;
            var settled = false;
            function done(result) {
                if (settled) return;
                settled = true;
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                resolve(result);
            }
            yesBtn.addEventListener('click', function () { done(true); });
            noBtn.addEventListener('click', function () { done(false); });
            // 背景クリックは安全側（変更しない）に倒す。
            overlay.addEventListener('click', function (e) { if (e.target === overlay) done(false); });
            actions.appendChild(yesBtn);
            actions.appendChild(noBtn);
            box.appendChild(msg);
            box.appendChild(actions);
            overlay.appendChild(box);
            panel.appendChild(overlay);
        });
    }

    // セクション（buyer/seller/renter）に入力値があるか判定する。
    function conditionSectionHasData(section) {
        if (!section || typeof section !== 'object') return false;
        return Object.keys(section).some(function (key) {
            var v = section[key];
            if (Array.isArray(v)) return v.length > 0;
            return v !== '' && v != null;
        });
    }

    // 指定の相談種別に対応する入力欄に、削除対象となる入力値があるか判定する。
    function dealTypeHasData(conditions, dealType) {
        conditions = conditions || {};
        if (dealType === 'rent') return conditionSectionHasData(conditions.renter);
        if (dealType === 'sale') return conditionSectionHasData(conditions.seller);
        if (dealType === 'both') return conditionSectionHasData(conditions.seller) || conditionSectionHasData(conditions.buyer);
        return conditionSectionHasData(conditions.buyer);
    }

    function emptyConditions(dealType) {
        return { deal_type: dealType, buyer: {}, seller: {}, renter: {}, notes: '' };
    }

    if (featurePanel) {
        // 相談種別の変更で、入力欄の出し分け（購入/売却/買い替え/賃貸）を切り替える。
        // 相談種別は1項目のみ。入力済みの内容がある状態で種別を変えるときは確認し、
        // 「はい」なら今までの条件整理を削除・リセットしてから切り替える（複数種別の混在でAIが混乱するのを防ぐ）。
        featurePanel.addEventListener('change', function (e) {
            var sel = e.target;
            if (!sel || sel.name !== 'deal_type' || activeChatTab !== 'conditions') return;
            var c = crmCase();
            if (!c) { renderConditionsTab(); return; }
            var oldType = c.deal_type || 'purchase';
            var newType = sel.value;
            if (oldType === newType) return;

            // 表示中に入力された値も含めて、変更前の種別にデータがあるか判定する。
            var draft = collectConditionsPayload();
            if (!dealTypeHasData(draft.conditions, oldType)) {
                // 削除対象のデータが無ければ確認不要でそのまま切替（入力値は保持）。
                c.conditions = draft.conditions;
                c.deal_type = newType;
                renderConditionsTab();
                return;
            }

            showConfirmDialog(
                '相談種別は1項目しか選択できません。相談種別を変更した場合は、今まで記録が消去されますがよろしいですか？',
                'はい、相談種別を変更します',
                'いいえ、相談種別を変更しません'
            ).then(function (ok) {
                if (!ok) {
                    // 変更しない：ドロップダウンを元の種別に戻す。
                    sel.value = oldType;
                    return;
                }
                // 変更する：今までの条件整理の内容を削除・リセットして切替。
                var reset = emptyConditions(newType);
                c.conditions = reset;
                c.deal_type = newType;
                renderConditionsTab();
                // サーバー側の記録も消去して整合を取る。
                saveCrmFeature('conditions', {
                    deal_type: newType,
                    customer_name: c.customer_name || '',
                    ai_summary: c.ai_summary || '',
                    conditions: reset
                }).then(function () {
                    renderConditionsTab();
                }).catch(function () {
                    appendBotMessage('相談種別の変更を保存できませんでした。');
                });
            });
        });
        featurePanel.addEventListener('click', function (e) {
            var backBtn = e.target.closest('[data-feature-back]');
            if (backBtn) {
                exitFeatureView();
                return;
            }
            var saveBtn = e.target.closest('[data-save-feature]');
            var syncBtn = e.target.closest('[data-sync-feature]');
            var addBtn = e.target.closest('[data-add-feature]');

            if (syncBtn) {
                var syncFeature = syncBtn.getAttribute('data-sync-feature');
                if (syncFeature === 'conditions') {
                    syncBtn.disabled = true;
                    saveCrmFeature('sync', {}).then(function () {
                        renderConditionsTab();
                    }).catch(function () {
                        appendBotMessage('チャットからの再読込に失敗しました。');
                    }).finally(function () {
                        syncBtn.disabled = false;
                    });
                }
                return;
            }
            if (saveBtn) {
                var feature = saveBtn.getAttribute('data-save-feature');
                var payload = null;
                if (feature === 'conditions') payload = collectConditionsPayload();
                else if (feature === 'progress') payload = collectProgressPayload();
                else if (feature === 'properties') payload = collectPropertiesPayload();
                else if (feature === 'schedules') payload = collectSchedulesPayload();
                if (!payload) return;
                saveBtn.disabled = true;
                saveCrmFeature(feature, payload).then(function () {
                    if (feature === 'conditions') renderConditionsTab();
                    else if (feature === 'progress') renderProgressTab();
                    else if (feature === 'properties') renderPropertiesTab();
                    else if (feature === 'schedules') renderSchedulesTab();
                }).catch(function () {
                    appendBotMessage('保存に失敗しました。');
                }).finally(function () {
                    saveBtn.disabled = false;
                });
                return;
            }
            if (addBtn) {
                var addFeature = addBtn.getAttribute('data-add-feature');
                var payloadAdd = null;
                if (addFeature === 'property') payloadAdd = collectPropertiesPayload();
                else if (addFeature === 'schedule') payloadAdd = collectSchedulesPayload();
                if (!payloadAdd) return;
                addBtn.disabled = true;
                saveCrmFeature(addFeature === 'property' ? 'properties' : 'schedules', payloadAdd).then(function () {
                    if (addFeature === 'property') renderPropertiesTab();
                    else renderSchedulesTab();
                }).catch(function () {
                    appendBotMessage('追加に失敗しました。');
                }).finally(function () {
                    addBtn.disabled = false;
                });
                return;
            }
        });
    }

    if (quickActions) {
        quickActions.addEventListener('click', function (e) {
            var btn = e.target.closest('.chat-quick-btn');
            if (!btn) return;
            var entryAction = btn.getAttribute('data-entry-action');
            if (entryAction) {
                if (entryAction === 'first_consultation') {
                    beginFirstConsultation(startupData || {});
                } else if (entryAction === 'continue_previous_sms') {
                    showSmsAuth('continue');
                } else if (entryAction === 'continue_saved_session') {
                    continueSavedConsultation(startupData || {}, false);
                } else if (entryAction === 'continue_current_session') {
                    beginFirstConsultation(startupData || {});
                } else if (entryAction === 'start_as_different_person') {
                    resetVisitorIdentity();
                    sessionId = null;
                    greetingShown = false;
                    startSession(true);
                } else if (entryAction === 'use_as_someone_else') {
                    showSmsAuth('other');
                }
                return;
            }
            var multiGroup = btn.closest('.chat-intake-replies-multi');
            if (btn.getAttribute('data-multi-select') === '1' && multiGroup) {
                btn.classList.toggle('is-selected');
                btn.setAttribute('aria-pressed', btn.classList.contains('is-selected') ? 'true' : 'false');
                var submitBtn = multiGroup.querySelector('[data-multi-submit="1"]');
                if (submitBtn) submitBtn.disabled = multiGroup.querySelectorAll('.chat-intake-reply.is-selected').length === 0;
                return;
            }
            if (btn.getAttribute('data-multi-submit') === '1' && multiGroup) {
                var selected = Array.prototype.slice.call(multiGroup.querySelectorAll('.chat-intake-reply.is-selected')).map(function (el) {
                    return {
                        label: el.getAttribute('data-reply-label') || el.getAttribute('data-reply-value') || '',
                        value: el.getAttribute('data-reply-value') || el.getAttribute('data-reply-label') || '',
                        field: el.getAttribute('data-reply-field') || ''
                    };
                }).filter(function (item) { return !!item.label; });
                if (selected.length) {
                    sendMessage(selected.map(function (item) { return item.label; }).join('、'), {
                        buttonSelection: {
                            label: selected.map(function (item) { return item.label; }).join('、'),
                            value: selected.map(function (item) { return item.value; }).join('、'),
                            field: selected[0].field || '',
                            multi_select: true,
                            selected: selected
                        }
                    });
                }
                return;
            }
            var action = btn.getAttribute('data-action') || '';
            var replyLabel = btn.getAttribute('data-reply-label') || '';
            var replyValue = btn.getAttribute('data-reply-value') || btn.getAttribute('data-action-value') || '';
            var isSmsRegister = action === 'sms_register'
                || replyValue === 'sms_register'
                || replyLabel === '携帯電話番号を入力してSMS認証する'
                || replyLabel === 'もう一度SMS認証する';
            if (isSmsRegister) {
                showSmsAuth('register');
                return;
            }
            if (replyLabel) {
                sendMessage(replyLabel, {
                    buttonSelection: {
                        label: replyLabel,
                        value: replyValue || replyLabel,
                        field: btn.getAttribute('data-reply-field') || '',
                        multi_select: false
                    }
                });
                return;
            }
            if (action === 'loan_repayment' || action === 'loan_borrow') {
                var base = window.location.pathname.replace(/\/[^/]*$/, '/');
                var url = base + 'loan-simulator.php?slug=' + encodeURIComponent(cardSlug);
                window.location.href = url;
            }
        });
    }
    if (chatOnly) {
        showPanel();
    } else if (deepLinkTab) {
        // メール通知のリンクから来訪 → パネルを自動で開く（セッション復帰後に該当タブを表示）。
        showPanel();
    }
})();
