(function () {
    var STORAGE_KEY = 'ai_fcard_referral_tracking';
    var PARAMS = ['agent', 'utm_source', 'utm_medium', 'utm_campaign'];

    function cleanValue(value) {
        value = String(value || '').trim();
        if (!value) return '';
        return value.replace(/[\u0000-\u001F\u007F]/g, '').slice(0, 255);
    }

    function readStored() {
        try {
            var raw = window.localStorage ? localStorage.getItem(STORAGE_KEY) : '';
            if (!raw) return null;
            var parsed = JSON.parse(raw);
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (e) {
            return null;
        }
    }

    function writeStored(data) {
        try {
            if (window.localStorage) localStorage.setItem(STORAGE_KEY, JSON.stringify(data));
        } catch (e) {}
    }

    function captureFromUrl() {
        var search = new URLSearchParams(window.location.search || '');
        var captured = {};
        var hasAny = false;
        PARAMS.forEach(function (key) {
            var value = cleanValue(search.get(key));
            captured[key] = value;
            if (value) hasAny = true;
        });
        if (!hasAny) return;

        var existing = readStored();
        if (existing && existing.first_accessed_at) return;

        captured.first_accessed_at = new Date().toISOString();
        writeStored(captured);
    }

    function getReferralTracking() {
        var stored = readStored() || {};
        var payload = {};
        var hasAny = false;
        PARAMS.forEach(function (key) {
            payload[key] = cleanValue(stored[key]);
            if (payload[key]) hasAny = true;
        });
        payload.first_accessed_at = cleanValue(stored.first_accessed_at);
        if (!payload.first_accessed_at) payload.first_accessed_at = '';
        return hasAny || payload.first_accessed_at ? payload : null;
    }

    captureFromUrl();
    window.aiFcardReferralTracking = {
        get: getReferralTracking
    };
})();
