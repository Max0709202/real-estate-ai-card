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

    var sessionId = null;
    var canUseLoanSim = true;

    function showPanel() {
        panel.removeAttribute('hidden');
        if (!sessionId) startSession();
        if (avatarEl) {
            avatarEl.src = agentPhoto;
            avatarEl.alt = agentName;
        }
        if (agentNameEl) agentNameEl.textContent = agentName;
    }

    function hidePanel() {
        panel.setAttribute('hidden', '');
    }

    function startSession() {
        fetch(apiBase + '/session/start.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ card_slug: cardSlug })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success && data.data) {
                    sessionId = data.data.session_id;
                    canUseLoanSim = data.data.can_use_loan_sim !== false;
                    if (data.data.agent_name) agentName = data.data.agent_name;
                    if (data.data.agent_photo_url) {
                        agentPhoto = data.data.agent_photo_url;
                        if (avatarEl) avatarEl.src = agentPhoto;
                    }
                    appendBotMessage('こんにちは。' + agentName + 'です。不動産に関するご質問や、ご希望（購入・売却・リノベなど）がございましたらお気軽にどうぞ。');
                    if (!canUseLoanSim && quickActions) quickActions.style.display = 'none';
                } else {
                    appendBotMessage('申し訳ございません。いまチャットをご利用いただけません。');
                }
            })
            .catch(function () {
                appendBotMessage('接続できませんでした。しばらくしてからお試しください。');
            });
    }

    function appendBotMessage(text, isLoading) {
        var wrap = document.createElement('div');
        wrap.className = 'chat-msg bot' + (isLoading ? ' chat-msg-loading' : '');
        var time = new Date().toLocaleTimeString('ja-JP', { hour: '2-digit', minute: '2-digit' });
        var img = agentPhoto ? '<img class="chat-msg-avatar" src="' + escapeHtml(agentPhoto) + '" alt="">' : '';
        wrap.innerHTML = img + '<div><div class="chat-msg-bubble">' + escapeHtml(text) + '</div><div class="chat-msg-time">' + time + '</div></div>';
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

    function sendMessage(text) {
        if (!text.trim() || !sessionId) return;
        appendUserMessage(text);
        inputEl.value = '';
        var loadingRow = appendBotMessage('回答を考えています', true);

        fetch(apiBase + '/send.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: sessionId, message: text })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                loadingRow.remove();
                if (data.success && data.data && data.data.reply) {
                    appendBotMessage(data.data.reply, false);
                } else {
                    appendBotMessage(data.message || 'エラーが発生しました。');
                }
            })
            .catch(function () {
                loadingRow.remove();
                appendBotMessage('送信に失敗しました。');
            });
    }

    toggleBtn.addEventListener('click', function () {
        if (panel.hasAttribute('hidden')) showPanel();
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
            var action = btn.getAttribute('data-action');
            if (action === 'loan_repayment') sendMessage('ローン返済額の試算をしたいです。');
            if (action === 'loan_borrow') sendMessage('借入可能額の試算をしたいです。');
        });
    }
})();
