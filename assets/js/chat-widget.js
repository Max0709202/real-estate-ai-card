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

    var toggleBtn = document.getElementById('chat-widget-toggle');
    var panel = document.getElementById('chat-widget-panel');
    var closeBtn = document.getElementById('chat-widget-close');
    var messagesContainer = document.getElementById('chat-widget-messages');
    var inputEl = document.getElementById('chat-widget-input');
    var sendBtn = document.getElementById('chat-widget-send');
    var avatarEl = document.getElementById('chat-widget-avatar');
    var agentNameEl = document.getElementById('chat-widget-agent-name');
    var quickActions = document.getElementById('chat-widget-quick-actions');

    if (!toggleBtn || !panel || !messagesContainer || !inputEl || !sendBtn) return;

    var visitorId = getOrCreateVisitorId();
    var sessionStorageKey = 'ai_fcard_chat_session_' + (cardSlug || 'default');
    var sessionId = getStoredSessionId();
    var canUseLoanSim = true;
    var sessionStarting = false;
    var sendingMessage = false;
    var greetingShown = false;

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

    function getStoredSessionId() {
        var stored = safeStorageGet('ai_fcard_chat_session_' + (cardSlug || 'default'));
        return stored || null;
    }

    function storeSessionId(id) {
        if (id) safeStorageSet(sessionStorageKey, id);
    }

    function setInputEnabled(enabled) {
        inputEl.disabled = !enabled;
        sendBtn.disabled = !enabled;
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
    }

    function showPanel() {
        panel.hidden = false;
        // panel.style.display = 'flex !important';
        toggleBtn.setAttribute('aria-expanded', 'true');
        syncAgentHeader();
        if ((!sessionId || !greetingShown) && !sessionStarting) startSession();
        if (sessionId && greetingShown && !sendingMessage) setInputEnabled(true);
        setTimeout(function () { inputEl.focus(); }, 50);
    }

    function hidePanel() {
        panel.hidden = true;
        // panel.style.display = 'none !important';
        toggleBtn.setAttribute('aria-expanded', 'false');
    }

    function renderQuickReplies(replies) {
        if (!quickActions) return;
        var existing = quickActions.querySelector('.chat-intake-replies');
        if (existing) existing.remove();
        if (!replies || !replies.length) return;

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
            btn.setAttribute('data-reply-label', reply.label || reply.value || '');
            btn.setAttribute('data-reply-value', reply.value || reply.label || '');
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
    }

    function startSession() {
        if (!cardSlug) {
            appendBotMessage('カード情報が見つかりません。ページを再読み込みしてからお試しください。');
            setInputEnabled(false);
            return;
        }

        sessionStarting = true;
        setInputEnabled(false);
        var loadingRow = appendBotMessage('チャットを接続しています', true);

        fetch(apiBase + '/session/start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_slug: cardSlug, visitor_id: visitorId, current_session_id: sessionId || '' })
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
                    storeSessionId(sessionId);
                    if (data.data.visitor_id) visitorId = data.data.visitor_id;
                    canUseLoanSim = data.data.can_use_loan_sim !== false;
                    if (data.data.agent_name) agentName = data.data.agent_name;
                    if (data.data.agent_photo_url) {
                        agentPhoto = data.data.agent_photo_url;
                    }
                    syncAgentHeader();
                    if (!greetingShown) {
                        var history = data.data.messages || [];
                        if (history.length) {
                            history.forEach(function (msg) {
                                if (msg.role === 'bot' || msg.role === 'assistant') appendBotMessage(msg.message || '');
                                else if (msg.role === 'user') appendUserMessage(msg.message || '');
                            });
                        } else {
                            appendBotMessage(data.data.initial_message || ('こんにちは。' + agentName + 'です。不動産に関するご質問や、ご希望（購入・売却・リノベなど）がございましたらお気軽にどうぞ。'));
                        }
                        greetingShown = true;
                    }
                    renderQuickReplies(data.data.quick_replies || []);
                    if (!canUseLoanSim && quickActions) quickActions.style.display = 'none';
                    setInputEnabled(true);
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

    function appendBotMessage(text, isLoading, sources) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-msg bot' + (isLoading ? ' chat-msg-loading' : '');
        var time = new Date().toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        var img = agentPhoto ? '<img class="chat-msg-avatar" src="' + escapeAttribute(agentPhoto) + '" alt="">' : '';
        wrap.innerHTML = img + '<div class="chat-msg-content"><div class="chat-msg-bubble">' + formatBotMessageHtml(text) + '</div><div class="chat-msg-time">' + time + '</div></div>';
        if (sources && sources.length) {
            var sourceBox = document.createElement('div');
            sourceBox.className = 'chat-msg-sources';
            sourceBox.innerHTML = '<span>参照情報</span>' + sources.slice(0, 3).map(function (source) {
                var title = source.title || source.url || 'Source';
                var url = source.url || '#';
                return '<a href="' + escapeAttribute(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(title) + '</a>';
            }).join('');
            var content = wrap.querySelector('.chat-msg-content');
            if (content) content.appendChild(sourceBox);
        }
        messagesContainer.appendChild(wrap);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
        return wrap;
    }

    function appendUserMessage(text) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-msg user';
        var time = new Date().toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        wrap.innerHTML = '<div class="chat-msg-avatar"></div><div><div class="chat-msg-bubble">' + escapeHtml(text) + '</div><div class="chat-msg-time">' + time + '</div></div>';
        messagesContainer.appendChild(wrap);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function escapeHtml(s) {
        var div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }

    function formatBotMessageHtml(s) {
        return escapeHtml(s).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }

    function escapeAttribute(s) {
        return escapeHtml(String(s || '')).replace(/"/g, '&quot;');
    }

    function sendMessage(text) {
        if (!text.trim() || sendingMessage) return;
        renderQuickReplies([]);
        if (!sessionId) {
            appendBotMessage('チャットの接続が完了してから送信してください。');
            if (!sessionStarting) startSession();
            return;
        }

        sendingMessage = true;
        setInputEnabled(false);
        appendUserMessage(text);
        inputEl.value = '';
        var loadingRow = appendBotMessage('回答を考えています', true);

        fetch(apiBase + '/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, visitor_id: visitorId, message: text })
        })
            .then(function (res) {
                return res.json().catch(function () {
                    return { success: false, message: 'サーバーから正しい応答を受け取れませんでした。' };
                });
            })
            .then(function (data) {
                sendingMessage = false;
                loadingRow.remove();
                if (data.success && data.data && data.data.reply) {
                    appendBotMessage(data.data.reply, false, data.data.sources || []);
                    renderQuickReplies(data.data.quick_replies || []);
                } else {
                    if (data.message && data.message.indexOf('セッション') !== -1) {
                        safeStorageRemove(sessionStorageKey);
                        sessionId = null;
                    }
                    appendBotMessage(data.message || 'エラーが発生しました。');
                }
                setInputEnabled(true);
                inputEl.focus();
            })
            .catch(function () {
                sendingMessage = false;
                loadingRow.remove();
                appendBotMessage('送信に失敗しました。');
                setInputEnabled(true);
                inputEl.focus();
            });
    }

    toggleBtn.setAttribute('aria-expanded', 'false');
    toggleBtn.addEventListener('click', function () {
        if (panel.hidden) showPanel();
        else hidePanel();
    });
    closeBtn.addEventListener('click', hidePanel);

    sendBtn.addEventListener('click', function () {
        sendMessage(inputEl.value.trim());
    });
    inputEl.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(inputEl.value.trim());
        }
    });

    if (quickActions) {
        quickActions.addEventListener('click', function (e) {
            var btn = e.target.closest('.chat-quick-btn');
            if (!btn) return;
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
                    return el.getAttribute('data-reply-label') || el.getAttribute('data-reply-value') || '';
                }).filter(Boolean);
                if (selected.length) sendMessage(selected.join('、'));
                return;
            }
            var replyLabel = btn.getAttribute('data-reply-label');
            if (replyLabel) {
                sendMessage(replyLabel);
                return;
            }
            var action = btn.getAttribute('data-action');
            if (action === 'loan_repayment' || action === 'loan_borrow') {
                var base = window.location.pathname.replace(/\/[^/]*$/, '/');
                var url = base + 'loan-simulator.php?slug=' + encodeURIComponent(cardSlug);
                window.location.href = url;
            }
        });
    }
})();
