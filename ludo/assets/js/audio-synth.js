/**
 * ======================================================
 * AUDIO-SYNTH.JS - ZERO-DEPENDENCY WEB AUDIO SYNTHESIZER
 * Ludo Tournament Platform - Procedural Sound Engine
 * Version: 1.0.0
 * 
 * All sounds are synthesized in real-time using Web Audio API
 * No external audio files required
 * ======================================================
 */

(function() {
    'use strict';

    /**
     * ==============================================
     * LudoAudioEngine - Global Singleton
     * ==============================================
     */
    const LudoAudioEngine = {
        /**
         * Audio context instance
         * @private
         */
        _ctx: null,

        /**
         * Flag indicating if audio context is initialized
         * @private
         */
        _initialized: false,

        /**
         * Flag indicating if audio has been resumed by user gesture
         * @private
         */
        _resumed: false,

        /**
         * Master gain node for volume control
         * @private
         */
        _masterGain: null,

        /**
         * Default master volume (0.0 to 1.0)
         * @private
         */
        _masterVolume: 0.8,

        /**
         * Audio worklet node for advanced processing (if supported)
         * @private
         */
        _workletNode: null,

        /**
         * Initialize the audio context
         * Must be called from a user interaction event
         * @returns {boolean} - True if initialized successfully
         */
        init: function() {
            try {
                // Check if already initialized and resumed
                if (this._initialized && this._ctx && this._ctx.state === 'running') {
                    return true;
                }

                // Create audio context with optimal settings
                const AudioContext = window.AudioContext || window.webkitAudioContext;
                if (!AudioContext) {
                    console.warn('LudoAudioEngine: Web Audio API not supported');
                    return false;
                }

                this._ctx = new AudioContext({
                    latencyHint: 'interactive',
                    sampleRate: 44100
                });

                // Create master gain node
                this._masterGain = this._ctx.createGain();
                this._masterGain.gain.value = this._masterVolume;
                this._masterGain.connect(this._ctx.destination);

                // Set audio context state handler
                this._ctx.onstatechange = function() {
                    if (this._ctx.state === 'suspended') {
                        console.log('LudoAudioEngine: Context suspended');
                    } else if (this._ctx.state === 'running') {
                        console.log('LudoAudioEngine: Context running');
                        this._resumed = true;
                    }
                }.bind(this);

                this._initialized = true;
                console.log('LudoAudioEngine: Initialized successfully');
                return true;

            } catch (e) {
                console.error('LudoAudioEngine: Init error:', e);
                return false;
            }
        },

        /**
         * Resume audio context (must be called from user gesture)
         * @returns {Promise<boolean>}
         */
        resume: function() {
            return new Promise((resolve) => {
                if (!this._initialized) {
                    this.init();
                }

                if (!this._ctx) {
                    resolve(false);
                    return;
                }

                if (this._ctx.state === 'running') {
                    this._resumed = true;
                    resolve(true);
                    return;
                }

                this._ctx.resume().then(() => {
                    this._resumed = true;
                    console.log('LudoAudioEngine: Context resumed');
                    resolve(true);
                }).catch((err) => {
                    console.warn('LudoAudioEngine: Resume failed:', err);
                    resolve(false);
                });
            });
        },

        /**
         * Check if audio is ready to play
         * @returns {boolean}
         */
        isReady: function() {
            return this._initialized && this._ctx && this._ctx.state === 'running';
        },

        /**
         * Ensure audio context is ready, attempt resume if needed
         * @returns {Promise<boolean>}
         */
        ensureReady: function() {
            if (this.isReady()) {
                return Promise.resolve(true);
            }
            return this.resume();
        },

        /**
         * Set master volume
         * @param {number} volume - 0.0 to 1.0
         */
        setVolume: function(volume) {
            this._masterVolume = Math.max(0, Math.min(1, volume));
            if (this._masterGain) {
                this._masterGain.gain.setTargetAtTime(
                    this._masterVolume,
                    this._ctx ? this._ctx.currentTime : 0,
                    0.05
                );
            }
        },

        /**
         * Get master volume
         * @returns {number}
         */
        getVolume: function() {
            return this._masterVolume;
        },

        /**
         * ==============================================
         * SOUND: UI Click (Triangle Beep)
         * ==============================================
         */
        playClick: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const frequency = 880;
                    const duration = 0.06;

                    // Main oscillator - triangle wave for softer click
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = 'triangle';
                    osc.frequency.value = frequency;

                    // Short envelope with quick decay
                    gain.gain.setValueAtTime(0.15 * this._masterVolume, now);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc.connect(gain);
                    gain.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playClick error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Dice Roll (White Noise + Modulation)
         * Simulates dice rattling in a cup
         * ==============================================
         */
        playDiceRoll: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.4;
                    const bufferSize = this._ctx.sampleRate * duration;

                    // Create white noise buffer
                    const buffer = this._ctx.createBuffer(1, bufferSize, this._ctx.sampleRate);
                    const data = buffer.getChannelData(0);

                    // Generate white noise with envelope shaping
                    for (let i = 0; i < bufferSize; i++) {
                        const progress = i / bufferSize;
                        // Amplitude envelope: quick attack, gradual decay
                        const envelope = Math.pow(1 - progress, 1.5) * 1.0;
                        // White noise
                        data[i] = (Math.random() * 2 - 1) * envelope;
                    }

                    // Noise source
                    const noise = this._ctx.createBufferSource();
                    noise.buffer = buffer;

                    // Bandpass filter for more "rattle" character
                    const filter = this._ctx.createBiquadFilter();
                    filter.type = 'bandpass';
                    filter.frequency.value = 1800;
                    filter.Q.value = 0.8;

                    // Gain for noise
                    const gainNoise = this._ctx.createGain();
                    gainNoise.gain.setValueAtTime(0.3 * this._masterVolume, now);
                    gainNoise.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    // Low-frequency oscillator for modulation (rattle effect)
                    const lfo = this._ctx.createOscillator();
                    const lfoGain = this._ctx.createGain();
                    lfo.type = 'square';
                    lfo.frequency.value = 60 + Math.random() * 40; // Random variation

                    lfoGain.gain.value = 0.15;

                    // Frequency modulation on the filter
                    lfo.connect(lfoGain);
                    lfoGain.connect(filter.frequency);

                    // Connect noise through filter to master
                    noise.connect(filter);
                    filter.connect(gainNoise);
                    gainNoise.connect(this._masterGain);

                    // Start everything
                    noise.start(now);
                    noise.stop(now + duration);
                    lfo.start(now);
                    lfo.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playDiceRoll error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Token Move (Upward Frequency Sweep)
         * Chirp effect for token sliding
         * ==============================================
         */
        playTokenMove: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.15;
                    const startFreq = 300;
                    const endFreq = 900;

                    // Main oscillator
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = 'sine';
                    osc.frequency.setValueAtTime(startFreq, now);
                    osc.frequency.exponentialRampToValueAtTime(endFreq, now + duration);

                    // Envelope with subtle attack and decay
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.12 * this._masterVolume, now + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc.connect(gain);
                    gain.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playTokenMove error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Win (Arpeggiated Major Chord Celebration)
         * C Major: C - E - G - C (arpeggio)
         * ==============================================
         */
        playWin: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 523.25, start: 0, dur: 0.15 }, // C5
                        { freq: 659.25, start: 0.12, dur: 0.15 }, // E5
                        { freq: 783.99, start: 0.24, dur: 0.15 }, // G5
                        { freq: 1046.50, start: 0.36, dur: 0.20 }, // C6
                        { freq: 783.99, start: 0.52, dur: 0.15 }, // G5
                        { freq: 659.25, start: 0.64, dur: 0.18 }, // E5
                        { freq: 523.25, start: 0.76, dur: 0.25 } // C5 (final)
                    ];

                    // Create a gain envelope for each note with slight overlap
                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();

                        osc.type = 'sine';
                        osc.frequency.value = note.freq;

                        const startTime = now + note.start;
                        const endTime = startTime + note.dur;

                        // Amplitude envelope with slight vibrato effect on sustained notes
                        const vol = (note.dur > 0.2) ? 0.18 : 0.14;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(vol * this._masterVolume, startTime + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.001, endTime);

                        osc.connect(gain);
                        gain.connect(this._masterGain);

                        osc.start(startTime);
                        osc.stop(endTime + 0.01);
                    });

                } catch (e) {
                    console.warn('LudoAudioEngine: playWin error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Lose (Descending Minor Third - Dissonant)
         * Creates a somber, descending tone sequence
         * ==============================================
         */
        playLose: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.5;

                    // Main oscillator - sawtooth for harsher sound
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = 'sawtooth';
                    // Detune slightly for dissonance
                    osc.detune.value = -12;

                    // Descending frequency sweep: minor third descent
                    osc.frequency.setValueAtTime(440, now);
                    osc.frequency.exponentialRampToValueAtTime(349.23, now + duration);

                    // Low-frequency oscillator for tremolo effect
                    const lfo = this._ctx.createOscillator();
                    const lfoGain = this._ctx.createGain();
                    lfo.type = 'sine';
                    lfo.frequency.value = 4;
                    lfoGain.gain.value = 0.3;

                    lfo.connect(lfoGain);
                    lfoGain.connect(gain.gain);

                    // Main gain envelope - slow attack, long decay
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.08 * this._masterVolume, now + 0.08);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    // Add a second oscillator an octave down for depth
                    const osc2 = this._ctx.createOscillator();
                    const gain2 = this._ctx.createGain();
                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(220, now);
                    osc2.frequency.exponentialRampToValueAtTime(174.61, now + duration);
                    gain2.gain.setValueAtTime(0.001, now);
                    gain2.gain.linearRampToValueAtTime(0.05 * this._masterVolume, now + 0.06);
                    gain2.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc.connect(gain);
                    gain.connect(this._masterGain);

                    osc2.connect(gain2);
                    gain2.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration + 0.02);
                    osc2.start(now);
                    osc2.stop(now + duration + 0.02);
                    lfo.start(now);
                    lfo.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playLose error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Game Start (Short Ascending Fanfare)
         * ==============================================
         */
        playGameStart: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 523.25, start: 0, dur: 0.12 },
                        { freq: 659.25, start: 0.10, dur: 0.12 },
                        { freq: 783.99, start: 0.20, dur: 0.15 }
                    ];

                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();

                        osc.type = 'sine';
                        osc.frequency.value = note.freq;

                        const startTime = now + note.start;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(0.1 * this._masterVolume, startTime + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + note.dur);

                        osc.connect(gain);
                        gain.connect(this._masterGain);

                        osc.start(startTime);
                        osc.stop(startTime + note.dur);
                    });

                } catch (e) {
                    console.warn('LudoAudioEngine: playGameStart error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Game End (Simple Tone)
         * ==============================================
         */
        playGameEnd: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const frequency = 392;
                    const duration = 0.4;

                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = 'sine';
                    osc.frequency.value = frequency;

                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.12 * this._masterVolume, now + 0.04);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc.connect(gain);
                    gain.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playGameEnd error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Capture (Sharp Impact)
         * ==============================================
         */
        playCapture: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.08;

                    // Two oscillators for impact sound
                    const osc1 = this._ctx.createOscillator();
                    const gain1 = this._ctx.createGain();

                    osc1.type = 'square';
                    osc1.frequency.setValueAtTime(600, now);
                    osc1.frequency.exponentialRampToValueAtTime(200, now + duration);

                    gain1.gain.setValueAtTime(0.1 * this._masterVolume, now);
                    gain1.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc1.connect(gain1);
                    gain1.connect(this._masterGain);

                    // Second oscillator for harmonic content
                    const osc2 = this._ctx.createOscillator();
                    const gain2 = this._ctx.createGain();

                    osc2.type = 'sine';
                    osc2.frequency.setValueAtTime(900, now);
                    osc2.frequency.exponentialRampToValueAtTime(400, now + duration);

                    gain2.gain.setValueAtTime(0.06 * this._masterVolume, now);
                    gain2.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc2.connect(gain2);
                    gain2.connect(this._masterGain);

                    osc1.start(now);
                    osc1.stop(now + duration);
                    osc2.start(now);
                    osc2.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playCapture error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Notification (Soft Alert)
         * ==============================================
         */
        playNotification: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const notes = [
                        { freq: 659.25, start: 0, dur: 0.08 },
                        { freq: 523.25, start: 0.10, dur: 0.08 }
                    ];

                    notes.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();

                        osc.type = 'sine';
                        osc.frequency.value = note.freq;

                        const startTime = now + note.start;
                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(0.08 * this._masterVolume, startTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + note.dur);

                        osc.connect(gain);
                        gain.connect(this._masterGain);

                        osc.start(startTime);
                        osc.stop(startTime + note.dur);
                    });

                } catch (e) {
                    console.warn('LudoAudioEngine: playNotification error:', e);
                }
            });
        },

        /**
         * ==============================================
         * SOUND: Error (Harsh Descending Tone)
         * ==============================================
         */
        playError: function() {
            this.ensureReady().then((ready) => {
                if (!ready) return;

                try {
                    const now = this._ctx.currentTime;
                    const duration = 0.3;

                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = 'sawtooth';
                    osc.frequency.setValueAtTime(400, now);
                    osc.frequency.exponentialRampToValueAtTime(200, now + duration);
                    osc.detune.value = -25;

                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(0.06 * this._masterVolume, now + 0.02);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration);

                    osc.connect(gain);
                    gain.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration);

                } catch (e) {
                    console.warn('LudoAudioEngine: playError error:', e);
                }
            });
        },

        /**
         * ==============================================
         * UTILITY: Create custom sound with parameters
         * ==============================================
         */
        createCustomSound: function(options) {
            return this.ensureReady().then((ready) => {
                if (!ready) return false;

                try {
                    const {
                        type = 'sine',
                        frequency = 440,
                        duration = 0.1,
                        volume = 0.1,
                        delay = 0,
                        fadeIn = 0.01,
                        fadeOut = 0.05,
                        modulation = null,
                        filter = null
                    } = options;

                    const now = this._ctx.currentTime + delay;
                    const osc = this._ctx.createOscillator();
                    const gain = this._ctx.createGain();

                    osc.type = type;
                    osc.frequency.value = frequency;

                    // Apply modulation if provided
                    if (modulation) {
                        const lfo = this._ctx.createOscillator();
                        const lfoGain = this._ctx.createGain();
                        lfo.type = modulation.type || 'sine';
                        lfo.frequency.value = modulation.frequency || 5;
                        lfoGain.gain.value = modulation.depth || 50;
                        lfo.connect(lfoGain);
                        lfoGain.connect(osc.frequency);
                        lfo.start(now);
                        lfo.stop(now + duration);
                    }

                    // Apply filter if provided
                    let outputNode = gain;
                    if (filter) {
                        const filt = this._ctx.createBiquadFilter();
                        filt.type = filter.type || 'lowpass';
                        filt.frequency.value = filter.frequency || 2000;
                        filt.Q.value = filter.Q || 1;
                        gain.connect(filt);
                        outputNode = filt;
                    }

                    // Envelope
                    const vol = volume * this._masterVolume;
                    gain.gain.setValueAtTime(0.001, now);
                    gain.gain.linearRampToValueAtTime(vol, now + fadeIn);
                    gain.gain.exponentialRampToValueAtTime(0.001, now + duration - fadeOut);

                    osc.connect(gain);
                    outputNode.connect(this._masterGain);

                    osc.start(now);
                    osc.stop(now + duration);

                    return true;

                } catch (e) {
                    console.warn('LudoAudioEngine: createCustomSound error:', e);
                    return false;
                }
            });
        },

        /**
         * ==============================================
         * UTILITY: Play sequence of notes
         * ==============================================
         */
        playSequence: function(sequence) {
            return this.ensureReady().then((ready) => {
                if (!ready) return false;

                try {
                    const now = this._ctx.currentTime;

                    sequence.forEach((note) => {
                        const osc = this._ctx.createOscillator();
                        const gain = this._ctx.createGain();

                        osc.type = note.type || 'sine';
                        osc.frequency.value = note.frequency || 440;

                        const startTime = now + (note.delay || 0);
                        const duration = note.duration || 0.1;
                        const volume = (note.volume || 0.1) * this._masterVolume;

                        gain.gain.setValueAtTime(0.001, startTime);
                        gain.gain.linearRampToValueAtTime(volume, startTime + 0.01);
                        gain.gain.exponentialRampToValueAtTime(0.001, startTime + duration);

                        osc.connect(gain);
                        gain.connect(this._masterGain);

                        osc.start(startTime);
                        osc.stop(startTime + duration);
                    });

                    return true;

                } catch (e) {
                    console.warn('LudoAudioEngine: playSequence error:', e);
                    return false;
                }
            });
        },

        /**
         * ==============================================
         * UTILITY: Check if audio is supported
         * ==============================================
         */
        isSupported: function() {
            return !!(window.AudioContext || window.webkitAudioContext);
        },

        /**
         * ==============================================
         * UTILITY: Get audio context state
         * ==============================================
         */
        getState: function() {
            return this._ctx ? this._ctx.state : 'uninitialized';
        },

        /**
         * ==============================================
         * UTILITY: Suspend audio context
         * ==============================================
         */
        suspend: function() {
            if (this._ctx && this._ctx.state === 'running') {
                this._ctx.suspend();
            }
        },

        /**
         * ==============================================
         * UTILITY: Auto-init with click/touch listener
         * ==============================================
         */
        autoInit: function() {
            // Initialize on first user interaction
            const initFn = function() {
                LudoAudioEngine.init();
                LudoAudioEngine.resume().then(() => {
                    // Play a subtle click to confirm audio is working
                    setTimeout(() => {
                        LudoAudioEngine.playClick();
                    }, 100);
                });
                // Remove listeners after first interaction
                document.removeEventListener('click', initFn);
                document.removeEventListener('touchstart', initFn);
                document.removeEventListener('keydown', initFn);
            };

            document.addEventListener('click', initFn, { once: false });
            document.addEventListener('touchstart', initFn, { once: false });
            document.addEventListener('keydown', initFn, { once: false });

            // Also try to initialize immediately if document is already interactive
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                // Check if we can auto-init (some browsers allow this)
                setTimeout(() => {
                    if (!this._initialized) {
                        this.init();
                    }
                }, 100);
            }
        }
    };

    /**
     * ==============================================
     * AUTO-INITIALIZE ON PAGE LOAD
     * ==============================================
     */
    // Expose globally
    window.LudoAudioEngine = LudoAudioEngine;

    // Auto-initialize audio context on user interaction
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            LudoAudioEngine.autoInit();
        });
    } else {
        LudoAudioEngine.autoInit();
    }

    console.log('LudoAudioEngine: Loaded successfully');

})();
