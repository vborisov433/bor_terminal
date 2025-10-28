(function ($, win, doc) {
    'use strict';

    var STORAGE_KEY = 'bor.autoRefresh.enabled';
    var PERIOD_SEC = 50;    // refresh period in seconds
    var tickId = null;  // 1-second interval id
    var secondsLeft = PERIOD_SEC;

    var $toggle, $label, $counter;

    function pad2(n) {
        return (n < 10 ? '0' : '') + n;
    }

    function setLabelColor(on) {
        $label.toggleClass('text-secondary', !on)
            .toggleClass('text-white', on);
    }

    function setCounter(on) {
        if (!on) {
            $counter.text('--')
                .removeClass('text-white')
                .addClass('text-white');
        } else {
            $counter.text(pad2(secondsLeft))
                .removeClass('bg-light text-dark')
                .addClass('text-white');
        }
    }

    function stopCountdown() {
        if (tickId !== null) {
            win.clearInterval(tickId);
            tickId = null;
        }
    }

    function tick() {
        secondsLeft -= 1;
        if (secondsLeft <= 0) {
            // Optional: set to 00 just before reload for visual consistency
            $counter.text('00');
            // Soft reload; change to true to attempt a hard reload (may be ignored by some browsers)
            win.location.reload();
            return;
        }
        setCounter(true);
    }

    function startCountdown() {
        stopCountdown();
        secondsLeft = PERIOD_SEC;
        setCounter(true); // show "60"
        tickId = win.setInterval(tick, 1000);
    }

    function applyState(on) {
        // UI
        $toggle.prop('checked', on);
        setLabelColor(on);
        setCounter(on);

        // Persist
        try {
            win.localStorage.setItem(STORAGE_KEY, on ? '1' : '0');
        } catch (e) {
        }

        // Timer
        if (on) startCountdown(); else stopCountdown();
    }

    $(function () {
        $toggle = $('#autoRefreshToggle');
        $label = $('#autoRefreshLabel');
        $counter = $('#autoRefreshCounter');

        // Restore saved state
        var saved = null;
        try {
            saved = win.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
        }
        var enabled = (saved === '1');

        // Apply on load
        applyState(enabled);

        // Handle user toggle
        $toggle.on('change', function () {
            let checked = $(this).is(':checked');
            if (checked){
                ringBell(1);
            }
            applyState(checked);
        });

        // Clean up on nav away
        $(win).on('beforeunload', stopCountdown);
    });

})(jQuery, window, document);

function ringBell(times) {
    // coerce to a positive integer
    times = Math.max(0, parseInt(times, 10) || 0);
    if (times === 0) return;

    const el = document.getElementById('newNewsAudio');

    if (el) {
        let remaining = times;

        const playNext = () => {
            if (remaining-- <= 0) return;

            // Reset playback head (in case the previous ring hasn't fully finished)
            try {
                el.currentTime = 0;
            } catch (e) {
            }

            el.play()
                .then(() => {
                    // Play next ring after a short gap
                    setTimeout(playNext, 400);
                })
                .catch((e) => {
                    console.error('Failed to play audio', e);
                });
        };

        playNext();
    }
}

/** Audio unlock state machine */
let audioUnlock = {
    unlocked: false,
    attempting: false
};

/** Strict unlock: only run once, and only from a *trusted* user gesture. */
function unlockAudioOnce(event) {
    // Reject if already unlocked or currently attempting
    if (audioUnlock.unlocked || audioUnlock.attempting) return;

    // If called from an event, require a *trusted* user gesture
    if (event && event.isTrusted !== true) return;

    const el = document.getElementById('newNewsAudio');
    if (!el) return;

    audioUnlock.attempting = true;

    // Try muted play so most browsers accept it as a gesture unlock
    el.muted = true;
    el.play().then(() => {

        console.log('Audio unlocked successfully');

        // Pause immediately, reset, unmute, mark unlocked
        el.pause();
        try { el.currentTime = 0; } catch (_) {}
        el.muted = false;
        audioUnlock.unlocked = true;
    }).catch(() => {
        // Even if muted play fails, do NOT mark unlocked
    }).finally(() => {
        audioUnlock.attempting = false;
        // Remove the listeners after the first real attempt
        removeAudioUnlockListeners();
    });
}

/** Attach unlock listeners to real gestures, once */
function addAudioUnlockListeners() {
    document.addEventListener('click', unlockAudioOnce, { once: true, passive: true });
    document.addEventListener('keydown', unlockAudioOnce, { once: true });
    document.addEventListener('touchstart', unlockAudioOnce, { once: true, passive: true });
}

function removeAudioUnlockListeners() {
    document.removeEventListener('click', unlockAudioOnce, { passive: true });
    document.removeEventListener('keydown', unlockAudioOnce);
    document.removeEventListener('touchstart', unlockAudioOnce, { passive: true });
}

// Expose a safe checker (optional)
window.isAudioUnlocked = () => audioUnlock.unlocked;

// Install the one-time gesture listeners on load
addAudioUnlockListeners();

