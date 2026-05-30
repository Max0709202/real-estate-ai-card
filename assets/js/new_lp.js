/**
 * New LP - Scripts for lp.php
 */
(function() {
    'use strict';

    function playHeroVideo(video) {
        var playPromise = video.play();
        if (playPromise && typeof playPromise.catch === 'function') {
            playPromise.catch(function() {
                // Autoplay can still be deferred by the browser; retry when data or focus is available.
            });
        }
    }

    function primeHeroVideo(video) {
        video.muted = true;
        video.defaultMuted = true;
        video.playsInline = true;
        video.autoplay = true;
        video.preload = 'auto';

        if (video.networkState === HTMLMediaElement.NETWORK_EMPTY) {
            video.load();
        }
        playHeroVideo(video);

        ['loadeddata', 'canplay', 'canplaythrough'].forEach(function(eventName) {
            video.addEventListener(eventName, function() {
                if (video.paused) playHeroVideo(video);
            }, { once: true });
        });

        document.addEventListener('visibilitychange', function() {
            if (!document.hidden && video.paused) playHeroVideo(video);
        });
        window.addEventListener('pageshow', function() {
            if (video.paused) playHeroVideo(video);
        });
    }

    function init() {
        var heroVideo = document.getElementById('new-lp-sec-1-video');
        if (heroVideo) {
            primeHeroVideo(heroVideo);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
