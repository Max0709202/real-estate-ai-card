/**
 * Chat widget on AI business card page.
 * - Starts session on first open, shows agent avatar/name, sends messages, displays replies.
 */
(function () {
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
    var defaultPromptText = "不動産の購入・売却について、何でもお気軽にご質問ください。";
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
    var sessionId = null;
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
    // 担当連絡（人間担当↔顧客）チャネル。AI担当スレッドとは別に保持する。
    var contactMessages = [];
    var contactPendingAttachments = [];

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

    function setInputEnabled(enabled) {
        inputEl.disabled = !enabled;
        sendBtn.disabled = !enabled;
        updateVoiceButtonState();
    }

    function setVoiceStatus(message) {
        if (!voiceStatusEl) return;
        if (!message) {
            voiceStatusEl.hidden = true;
            voiceStatusEl.textContent = '';
            return;
        }
        voiceStatusEl.textContent = message;
        voiceStatusEl.hidden = false;
    }

    function updateVoiceButtonState() {
        if (!voiceBtn) return;
        var canListen = !!SpeechRecognition && !sendingMessage && !sessionStarting && !inputEl.disabled;
        voiceBtn.disabled = !canListen;
        voiceBtn.classList.toggle('is-listening', isListening);
        voiceBtn.setAttribute('aria-pressed', isListening ? 'true' : 'false');
        if (!SpeechRecognition) {
            voiceBtn.title = 'このブラウザは音声入力に対応していません';
            voiceBtn.setAttribute('aria-label', '音声入力は未対応です');
        } else if (isListening) {
            voiceBtn.title = '音声入力を停止';
            voiceBtn.setAttribute('aria-label', '音声入力を停止');
        } else {
            voiceBtn.title = '音声で入力';
            voiceBtn.setAttribute('aria-label', '音声で入力');
        }
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
        entryAwaitingChoice = false;
        greetingShown = true;
        startupData = data || startupData;
        messagesContainer.innerHTML = '';
        showReloadNoticeIfNeeded();
        var customerLabel = customerLabelWithSuffix(startupData, '様');
        var differentPersonLabel = customerLabel === 'お客様' ? '別の方' : customerLabel + '以外の方';
        // ヒアリングで相談内容が溜まっている場合のみ、「今までのご相談内容を表示しますか？」を会話の入口として出す。
        var hasConsultationSummary = !!(startupData && startupData.has_consultation_summary);
        if (hasConsultationSummary) {
            appendBotMessage(customerLabel + '、お帰りなさい。\n\n今までのご相談内容を表示しますか？\n\n「今までの相談内容を表示する」を選ぶと、これまでのご相談を振り返ってからご案内します。そのまま続けてご相談いただくこともできます。\n\n' + differentPersonLabel + 'がご利用される場合は、「別の方として始める」をお選びください。\n\n');
            renderEntryActions([
                { label: '今までの相談内容を表示する', action: 'continue_saved_session' },
                { label: '別の方として始める', action: 'start_as_different_person' }
            ]);
        } else {
            // ヒアリング未実施: 相談内容の表示案内は出さない。
            appendBotMessage(customerLabel + '、お帰りなさい。\n\nこの端末からは、そのまま続きからご相談いただけます。\n\n' + differentPersonLabel + 'がご利用される場合は、「別の方として始める」をお選びください。\n\n');
            renderEntryActions([
                { label: 'このまま相談する', action: 'continue_current_session' },
                { label: '別の方として始める', action: 'start_as_different_person' }
            ]);
        }
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
            setStatus('登録済みの電話番号か確認しています...');
            lookupRegisteredPhone(phone).then(function (lookup) {
                if (lookup && lookup.registered) {
                    removeSmsAuthBox();
                    entryAwaitingChoice = false;
                    registrationFlow = false;
                    renderQuickReplies([]);
                    startupData = Object.assign({}, startupData || {}, lookup);
                    if (lookup.customer_name) {
                        saveCustomerName(lookup.customer_name);
                    }
                    if (lookup.session_id) {
                        sessionId = lookup.session_id;
                        saveSessionId(sessionId);
                    }
                    var returningLabel = customerCasualLabel(startupData || {});
                    var welcomeText = returningLabel === 'お客様' ? 'お帰りなさい。' : returningLabel + '、お帰りなさい。';
                    appendBotMessage(welcomeText + '\n\nこの電話番号は登録済みですので、SMS認証を省略しました。このままご相談いただけます。');
                    appendVoiceAvailabilityNotice();
                    setInputEnabled(true);
                    inputEl.focus();
                    return;
                }
                return sendSmsCode(phone);
            }).catch(function (error) {
                if (window.console && console.warn) console.warn('Phone registration lookup failed; continuing with SMS:', error);
                sendSmsCode(phone);
            });
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

    function lookupRegisteredPhone(phone) {
        return fetch(apiBase + '/phone/lookup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ phone: phone, card_slug: cardSlug, visitor_id: visitorId })
        }).then(function (res) {
            return res.json().catch(function () { return { success: false }; });
        }).then(function (data) {
            if (!data.success || !data.data) return null;
            return data.data;
        });
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
        var loadingRow = appendBotMessage('チャットを接続しています', true);
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
                    // 同じ端末で登録済み（電話・氏名・メール入力済み）なら、リロードしても再入力は求めない。
                    if (data.data.is_resumed) {
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
                if (source.cached) bits.push('キャッシュ');
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

    function finishBotTypewriter(wrap, bubble, text, sources, onComplete) {
        wrap.classList.remove('is-typing');
        bubble.innerHTML = formatBotMessageHtml(text);
        appendMessageSources(wrap, sources);
        scrollMessagesToBottom();
        if (typeof onComplete === 'function') onComplete();
    }

    function startBotTypewriter(wrap, bubble, text, sources, onComplete) {
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
                finishBotTypewriter(wrap, bubble, text, sources, onComplete);
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
        if (shouldType && bubble) {
            startBotTypewriter(wrap, bubble, text, sources, options.onComplete);
        } else {
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
    function contactMsgHtml(m) {
        var time = formatMessageTime(m.created_at || '');
        var atts = widgetAttachmentHtml(m.attachments || []);
        var body = (m.message && m.message !== '[ファイルを送信しました]') ? escapeHtml(m.message) : '';
        if (m.role === 'agent') {
            var img = agentPhoto ? '<img class="chat-msg-avatar" src="' + escapeAttribute(agentPhoto) + '" alt="">' : '<div class="chat-msg-avatar"></div>';
            return '<div class="chat-msg agent">' + img + '<div class="chat-msg-content"><div class="chat-msg-agent-label">' + escapeHtml(agentName || '担当者') + '</div>' + (body ? '<div class="chat-msg-bubble">' + body + '</div>' : '') + atts + '<div class="chat-msg-time">' + time + '</div></div></div>';
        }
        // 顧客（user）
        return '<div class="chat-msg user"><div class="chat-msg-avatar"></div><div>' + (body ? '<div class="chat-msg-bubble">' + body + '</div>' : '') + atts + '<div class="chat-msg-time">' + time + '</div></div></div>';
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
        listEl.innerHTML = contactPendingAttachments.map(function (a, i) {
            return '<span class="chat-widget-pending">' + escapeHtml(a.original_name || 'ファイル')
                + ' <button type="button" data-idx="' + i + '" aria-label="削除">×</button></span>';
        }).join('');
        Array.prototype.forEach.call(listEl.querySelectorAll('button[data-idx]'), function (btn) {
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
        pushContactMessage({ role: 'user', message: storedText, created_at: '', attachments: sentAttachments });
        input.value = '';

        var payload = { session_id: sessionId, visitor_id: visitorId, message: storedText, channel: 'contact' };
        if (attachmentIds.length) payload.attachment_ids = attachmentIds;
        fetch(apiBase + '/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).catch(function () { /* 送信失敗は次回ポーリングで整合 */ });
    }

    function bindContactHandlers() {
        var sendB = document.getElementById('chat-contact-send');
        var input = document.getElementById('chat-contact-input');
        var attachB = document.getElementById('chat-contact-attach');
        var fileI = document.getElementById('chat-contact-file');
        if (sendB) sendB.addEventListener('click', function () { sendContactMessage(); });
        if (input) input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); sendContactMessage(); }
        });
        if (attachB && fileI) {
            attachB.addEventListener('click', function () { fileI.click(); });
            fileI.addEventListener('change', function () {
                if (fileI.files && fileI.files[0]) { uploadContactAttachment(fileI.files[0]); fileI.value = ''; }
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
        html += '<div class="chat-contact-input-wrap">';
        html += '<textarea id="chat-contact-input" class="chat-contact-input" rows="2" placeholder="担当者へのメッセージを入力..." maxlength="2000"></textarea>';
        html += '<div class="chat-contact-input-actions">';
        html += '<button type="button" id="chat-contact-attach" class="chat-widget-attach" aria-label="ファイルを添付" title="ファイルを添付"><span aria-hidden="true">＋</span></button>';
        html += '<input type="file" id="chat-contact-file" accept="image/*,application/pdf,.doc,.docx,.xls,.xlsx" hidden>';
        html += '<button type="button" id="chat-contact-send" class="chat-widget-send" aria-label="送信"><span>送信</span></button>';
        html += '</div></div></div>';
        featurePanel.innerHTML = html;
        renderContactPendingAttachments();
        var msgsEl = document.getElementById('chat-contact-messages');
        if (msgsEl) msgsEl.scrollTop = msgsEl.scrollHeight;
        bindContactHandlers();
    }

    // ===== 担当連絡：担当からの新着ポーリング・通知・未読バッジ =====
    function setContactTabBadge(count) {
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

    function pollAgentMessages() {
        if (!sessionId) return;
        var url = apiBase + '/customer/poll.php?session_id=' + encodeURIComponent(sessionId)
            + '&visitor_id=' + encodeURIComponent(visitorId || '')
            + '&since_id=' + lastAgentMsgId;
        fetch(url)
            .then(function (res) { return res.json().catch(function () { return { success: false }; }); })
            .then(function (data) {
                if (!data.success || !data.data) return;
                var msgs = data.data.messages || [];
                var added = 0;
                msgs.forEach(function (m) {
                    var mid = parseInt(m.id, 10) || 0;
                    if (mid <= lastAgentMsgId) return;
                    lastAgentMsgId = mid;
                    pushContactMessage({ id: mid, role: 'agent', message: m.message || '', created_at: m.created_at || '', attachments: m.attachments || [] });
                    notifyAgentMessage(m.message || '');
                    added++;
                });
                if (added) {
                    // 担当連絡タブを見ていないときは未読バッジを増やす
                    if (panel.hidden || document.hidden || activeChatTab !== 'contact') {
                        agentUnreadCount += added;
                        setContactTabBadge(agentUnreadCount);
                    }
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
        attachListEl.innerHTML = pendingAttachments.map(function (a, i) {
            return '<span class="chat-widget-pending">' + escapeHtml(a.original_name || 'ファイル')
                + ' <button type="button" data-idx="' + i + '" aria-label="削除">×</button></span>';
        }).join('');
        Array.prototype.forEach.call(attachListEl.querySelectorAll('button[data-idx]'), function (btn) {
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
    // an optional 番地 part. This requires an administrative unit (so "東京都心の
    // 物件" / "東京都の人口" do not match) and stops cleanly at the end of the
    // address rather than running into surrounding prose. '県' is excluded from
    // the inner character class so a match cannot bleed into a following
    // prefecture (e.g. "...港区と神奈川県川崎市" stays two separate links).
    var ADDRESS_DELIM = '\\s\\n、。，,「」『』（）()【】\\[\\]＜＞<>＆&"！!？?…：:；;／/';
    var ADDRESS_INNER = '[^' + ADDRESS_DELIM + '県]';
    var ADDRESS_RE = new RegExp(
        '((?:' + ADDRESS_PREFECTURES + ')' +
        '(?:' + ADDRESS_INNER + '{0,6}?(?:市|区|町|村|郡)){1,4}' +
        '(?:' + ADDRESS_INNER + '*?[0-9０-９][0-9０-９条丁目番地号西東南北\\-‐―ー－]*)?)',
        'g'
    );

    function linkifyAddresses(html) {
        return html.replace(ADDRESS_RE, function (addr) {
            var href = 'https://www.google.com/maps/search/?api=1&query=' + encodeURIComponent(addr);
            return '<a href="' + href + '" target="_blank" rel="noopener noreferrer" class="chat-msg-address-link">' + addr + '</a>';
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

    function startVoiceInput() {
        if (!SpeechRecognition || !voiceBtn || sendingMessage || sessionStarting || inputEl.disabled) return;
        if (isListening) {
            stopVoiceInput();
            return;
        }

        recognition = new SpeechRecognition();
        recognition.lang = 'ja-JP';
        recognition.interimResults = true;
        recognition.continuous = false;
        finalVoiceTranscript = '';
        voiceHadError = false;
        isListening = true;
        updateVoiceButtonState();
        setVoiceStatus('聞き取り中です。話し終えると自動で送信します。');

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
            if (combined) inputEl.value = combined;
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
            var text = finalVoiceTranscript.trim();
            if (text && !voiceHadError) {
                setVoiceStatus('音声を認識しました。送信します。');
                sendMessage(text);
            } else if (SpeechRecognition && !voiceHadError) {
                setVoiceStatus('音声入力を終了しました。');
            }
        };

        try {
            recognition.start();
        } catch (e) {
            isListening = false;
            updateVoiceButtonState();
            setVoiceStatus('音声入力を開始できませんでした。もう一度お試しください。');
        }
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

        var sentAttachments = pendingAttachments.slice();
        var attachmentIds = sentAttachments.map(function (a) { return a.attachment_id; });
        pendingAttachments = [];
        renderPendingAttachments();

        // 担当対応中（AI応答を止めている）かどうか。CRM状態に handoff があれば優先。
        var agentMode = !!(startupData && startupData.handoff_mode === 'agent');

        sendingMessage = true;
        setInputEnabled(false);
        appendUserMessage(text, '', sentAttachments);
        inputEl.value = '';
        var loadingRow = (agentMode || !text.trim()) ? null : appendBotMessage('回答を考えています', true);

        var payload = { session_id: sessionId, visitor_id: visitorId, message: text };
        if (attachmentIds.length) payload.attachment_ids = attachmentIds;
        if (options.buttonSelection) payload.button_selection = options.buttonSelection;

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
                    appendBotMessage(data.data.reply, false, data.data.sources || [], '', {
                        typewriter: true,
                        onComplete: function () {
                            renderQuickReplies(data.data.quick_replies || []);
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
        // 担当連絡タブを開いたら担当からの未読をクリア
        if (activeChatTab === 'contact') {
            agentUnreadCount = 0;
            setContactTabBadge(0);
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
        if (!panel.hidden) inputEl.focus();
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

    function loadCrmState(force) {
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
                crmState = { failed: true };
                return crmState;
            })
            .catch(function () {
                crmLoading = false;
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
        var html = '';
        html += '<div class="chat-feature-head"><strong>条件整理</strong><span>AIチャットから抽出した条件を保存します</span></div>';
        html += '<label class="chat-feature-field">相談種別<select name="deal_type"><option value="purchase"' + (dealType === 'purchase' ? ' selected' : '') + '>購入</option><option value="sale"' + (dealType === 'sale' ? ' selected' : '') + '>売却</option><option value="both"' + (dealType === 'both' ? ' selected' : '') + '>購入・売却</option></select></label>';
        html += '<div class="chat-feature-grid">';
        html += '<section><h4>購入条件</h4>';
        html += '<label>購入時期<select name="buyer_purchase_timing">' + optionList(['できるだけ早く', '3か月以内', '6か月以内', '1年以内', '未定'], buyer.purchase_timing, '選択') + '</select></label>';
        html += '<label>引越希望日<input type="date" name="buyer_move_in_date" value="' + crmFields(buyer.move_in_date || '') + '"></label>';
        html += '<label>購入予算上限<input name="buyer_budget_max" placeholder="例：8000万円" value="' + crmFields(buyer.budget_max || '') + '"></label>';
        html += '<label>希望エリア<textarea name="buyer_areas" placeholder="例：中野区&#10;杉並区&#10;武蔵野市">' + crmFields(crmArray(buyer.areas).join('\n')) + '</textarea></label>';
        html += '<label>希望沿線<textarea name="buyer_station_lines" placeholder="例：中央線">' + crmFields(crmArray(buyer.station_lines).join('\n')) + '</textarea></label>';
        html += '<label>希望駅<textarea name="buyer_stations" placeholder="例：中野駅&#10;高円寺駅&#10;阿佐ヶ谷駅">' + crmFields(crmArray(buyer.stations).join('\n')) + '</textarea></label>';
        html += '<label>駅徒歩<select name="buyer_walk_minutes">' + optionList(['5分以内', '10分以内', '15分以内', 'こだわらない'], buyer.walk_minutes, '選択') + '</select></label>';
        html += '<label>種別<select name="buyer_property_type">' + optionList(['マンション', '戸建', 'どちらでも可'], buyer.property_type, '選択') + '</select></label>';
        html += '<label>間取り<select name="buyer_layout">' + optionList(['ワンルーム', '1K', '1LDK', '2LDK', '3LDK', '4LDK', '5LDK以上', 'こだわらない'], buyer.layout, '選択') + '</select></label>';
        html += '<label>面積<input name="buyer_area_min" placeholder="例：60㎡以上" value="' + crmFields(buyer.area_min || '') + '"></label>';
        html += '<label>築年数<select name="buyer_building_age">' + optionList(['新築', '10年以内', '20年以内', '30年以内', 'こだわらない'], buyer.building_age, '選択') + '</select></label>';
        html += '<label>リノベーション希望<select name="buyer_renovation_preference">' + optionList(['リノベーション済み希望', '自らリフォームする予定'], buyer.renovation_preference, '選択') + '</select></label>';
        html += '<label>購入理由<select name="buyer_purchase_reason_select">' + optionList(['家賃がもったいない', '結婚', '出産', '子供の進学', '住み替え', '投資', 'その他'], buyer.purchase_reason, '選択') + '</select><textarea name="buyer_purchase_reason" placeholder="自由入力">' + crmFields(buyer.purchase_reason && ['家賃がもったいない', '結婚', '出産', '子供の進学', '住み替え', '投資', 'その他'].indexOf(buyer.purchase_reason) === -1 ? buyer.purchase_reason : '') + '</textarea></label>';
        html += '</section>';
        html += '<section><h4>売却条件</h4>';
        html += '<label>売却理由<select name="seller_sale_reason_select">' + optionList(['住み替え', '相続', '離婚', '転勤', '資産整理', '投資売却', 'その他'], seller.sale_reason, '選択') + '</select><textarea name="seller_sale_reason" placeholder="自由入力">' + crmFields(seller.sale_reason && ['住み替え', '相続', '離婚', '転勤', '資産整理', '投資売却', 'その他'].indexOf(seller.sale_reason) === -1 ? seller.sale_reason : '') + '</textarea></label>';
        html += '<label>売却希望時期<select name="seller_sale_timing">' + optionList(['できるだけ早く', '3か月以内', '半年以内', '1年以内', '未定'], seller.sale_timing, '選択') + '</select></label>';
        html += '<label>決済希望日<input type="date" name="seller_closing_date" value="' + crmFields(seller.closing_date || '') + '"></label>';
        html += '<label>売却希望価格<input name="seller_sale_price" placeholder="例：5,500万円" value="' + crmFields(seller.sale_price || '') + '"></label>';
        html += '<label>最低売却価格<input name="seller_minimum_price" placeholder="例：5,000万円" value="' + crmFields(seller.minimum_price || '') + '"></label>';
        html += '<label>住宅ローン残債<input name="seller_loan_balance" placeholder="例：3,200万円" value="' + crmFields(seller.loan_balance || '') + '"></label>';
        html += '<label>住み替え予定<select name="seller_relocation_plan">' + optionList(['あり', 'なし', '未定'], seller.relocation_plan, '選択') + '</select></label>';
        html += '<label>売却後の住まい<select name="seller_post_sale_home">' + optionList(['購入予定', '賃貸予定', '実家', '未定'], seller.post_sale_home, '選択') + '</select></label>';
        html += '<label>内覧対応<select name="seller_viewing_availability">' + optionList(['土日可', '平日可', 'いつでも可', '要相談'], seller.viewing_availability, '選択') + '</select></label>';
        html += '<label>アピールポイント<textarea name="seller_appeal_points" placeholder="例：日当たりが良い&#10;管理状態が良い&#10;角部屋&#10;駅近">' + crmFields(crmArray(seller.appeal_points).join('\n')) + '</textarea></label>';
        html += '</section></div>';
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

    function renderPropertiesTab() {
        var c = crmCase();
        if (!c) return renderFeaturePanel('<div class="chat-feature-empty">読み込み中...</div>');
        var items = crmArray((c.properties || {}).items);
        var html = '<div class="chat-feature-head"><strong>物件選定</strong><span>候補の管理</span></div>';
        html += '<div class="chat-feature-list">';
        items.forEach(function (item, idx) {
            html += '<div class="chat-feature-card">';
            html += '<div class="chat-feature-card-head"><strong>' + crmFields(item.name || ('物件 ' + (idx + 1))) + '</strong><span>' + crmFields(item.status || '検討中') + '</span></div>';
            html += '<div class="chat-feature-small">' + crmFields(item.price || '') + ' / ' + crmFields(item.address || '') + '</div>';
            html += '<p>' + crmFields(item.comment || '') + '</p></div>';
        });
        if (!items.length) html += '<div class="chat-feature-empty">候補物件はまだありません。</div>';
        html += '</div>';
        html += '<div class="chat-feature-grid">';
        html += '<label>物件名<input name="property_name"></label>';
        html += '<label>住所<input name="property_address"></label>';
        html += '<label>価格<input name="property_price"></label>';
        html += '<label>ステータス<select name="property_status"><option>購入希望</option><option>内見希望</option><option selected>検討中</option><option>候補外</option></select></label>';
        html += '<label>コメント<textarea name="property_comment"></textarea></label>';
        html += '</div>';
        html += '<div class="chat-feature-actions"><button type="button" class="chat-feature-add" data-add-feature="property">追加</button><button type="button" class="chat-feature-save" data-save-feature="properties">保存</button></div>';
        renderFeaturePanel(html);
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
            renderFeaturePanel('<div class="chat-feature-empty">読み込みに失敗しました。</div>');
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
        var buyerAreas = (root.querySelector('[name="buyer_areas"]') || {}).value || '';
        var buyerStationLines = (root.querySelector('[name="buyer_station_lines"]') || {}).value || '';
        var buyerStations = (root.querySelector('[name="buyer_stations"]') || {}).value || '';
        var sellerAppeal = (root.querySelector('[name="seller_appeal_points"]') || {}).value || '';
        var purchaseReasonFree = ((root.querySelector('[name="buyer_purchase_reason"]') || {}).value || '').trim();
        var purchaseReasonSelect = ((root.querySelector('[name="buyer_purchase_reason_select"]') || {}).value || '').trim();
        var saleReasonFree = ((root.querySelector('[name="seller_sale_reason"]') || {}).value || '').trim();
        var saleReasonSelect = ((root.querySelector('[name="seller_sale_reason_select"]') || {}).value || '').trim();
        function splitList(value) {
            return value ? value.split(/[、,，\n\r]+/).map(function (s) { return s.trim(); }).filter(Boolean) : [];
        }
        return {
            deal_type: (root.querySelector('[name="deal_type"]') || {}).value || 'purchase',
            customer_name: crmCase().customer_name || '',
            ai_summary: crmCase().ai_summary || '',
            conditions: {
                deal_type: (root.querySelector('[name="deal_type"]') || {}).value || 'purchase',
                buyer: {
                    purchase_timing: (root.querySelector('[name="buyer_purchase_timing"]') || {}).value || '',
                    move_in_date: (root.querySelector('[name="buyer_move_in_date"]') || {}).value || '',
                    budget_max: (root.querySelector('[name="buyer_budget_max"]') || {}).value || '',
                    areas: splitList(buyerAreas),
                    station_lines: splitList(buyerStationLines),
                    stations: splitList(buyerStations),
                    walk_minutes: (root.querySelector('[name="buyer_walk_minutes"]') || {}).value || '',
                    property_type: (root.querySelector('[name="buyer_property_type"]') || {}).value || '',
                    layout: (root.querySelector('[name="buyer_layout"]') || {}).value || '',
                    area_min: (root.querySelector('[name="buyer_area_min"]') || {}).value || '',
                    building_age: (root.querySelector('[name="buyer_building_age"]') || {}).value || '',
                    renovation_preference: (root.querySelector('[name="buyer_renovation_preference"]') || {}).value || '',
                    purchase_reason: purchaseReasonFree || purchaseReasonSelect,
                },
                seller: {
                    sale_reason: saleReasonFree || saleReasonSelect,
                    sale_timing: (root.querySelector('[name="seller_sale_timing"]') || {}).value || '',
                    closing_date: (root.querySelector('[name="seller_closing_date"]') || {}).value || '',
                    sale_price: (root.querySelector('[name="seller_sale_price"]') || {}).value || '',
                    minimum_price: (root.querySelector('[name="seller_minimum_price"]') || {}).value || '',
                    loan_balance: (root.querySelector('[name="seller_loan_balance"]') || {}).value || '',
                    relocation_plan: (root.querySelector('[name="seller_relocation_plan"]') || {}).value || '',
                    post_sale_home: (root.querySelector('[name="seller_post_sale_home"]') || {}).value || '',
                    viewing_availability: (root.querySelector('[name="seller_viewing_availability"]') || {}).value || '',
                    appeal_points: splitList(sellerAppeal),
                },
                notes: ''
            }
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
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputEl.value.trim());
        }
    });

    if (tabBar) {
        tabBar.addEventListener('click', function (e) {
            var btn = e.target.closest('.chat-widget-tab');
            if (!btn) return;
            var tab = btn.getAttribute('data-chat-tab') || 'ai';
            if (tab === 'ai') {
                exitFeatureView();
                return;
            }
            if (tab === 'contact') {
                // 担当連絡は専用チャット。CRMロードを挟まず即表示。
                enterFeatureView(tab);
                renderContactThread();
                return;
            }
            enterFeatureView(tab);
            loadCrmState(false).then(function () {
                renderFeatureTab(tab);
            });
        });
    }

    if (featurePanel) {
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
    }
})();
