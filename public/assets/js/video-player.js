// Video Player - adapted from v3 VideoPlayer.vue
(function() {
    const DEFAULT_OPTIONS = {
        speedOptions: [0.25, 0.5, 0.75, 1, 1.25, 1.5, 2]
    };

    class VideoPlayer {
        constructor(container, src, options = {}) {
            this.container = typeof container === 'string' ? document.querySelector(container) : container;
            this.src = src;
            this.options = { ...DEFAULT_OPTIONS, ...options };
            this.isPlaying = false;
            this.hasStarted = false;
            this.isBuffering = false;
            this.isFullscreen = false;
            this.isMuted = false;
            this.currentTime = 0;
            this.duration = 0;
            this.volume = 1;
            this.playbackSpeed = 1;
            this.controlsVisible = true;
            this.progressPercent = 0;
            this.bufferPercent = 0;
            this.initialError = false;
            this.showSeekTooltip = false;
            this.seekTooltipTime = '0:00';
            this.seekTooltipLeft = 0;
            this.hideControlsTimer = null;
            this.videoEl = null;
            this.progressRef = null;
            this.debugVideo = false;

            this.init();
        }

        init() {
            this.render();
            this.videoEl = this.container.querySelector('video');
            this.progressRef = this.container.querySelector('.progress-bar');

            this.videoEl.addEventListener('loadedmetadata', this.onLoadedMetadata.bind(this));
            this.videoEl.addEventListener('play', this.onPlay.bind(this));
            this.videoEl.addEventListener('pause', this.onPause.bind(this));
            this.videoEl.addEventListener('timeupdate', this.onTimeUpdate.bind(this));
            this.videoEl.addEventListener('waiting', this.onWaiting.bind(this));
            this.videoEl.addEventListener('canplay', this.onCanPlay.bind(this));
            this.videoEl.addEventListener('playing', this.onPlaying.bind(this));
            this.videoEl.addEventListener('progress', this.onProgress.bind(this));
            this.videoEl.addEventListener('volumechange', this.onVolumeChange.bind(this));
            this.videoEl.addEventListener('error', this.onError.bind(this));
            this.videoEl.addEventListener('ended', this.onEnded.bind(this));
            this.videoEl.addEventListener('seeking', this.onSeeking.bind(this));
            this.videoEl.addEventListener('seeked', this.onSeeked.bind(this));

            this.setupControls();
            this.container.addEventListener('keydown', this.handleKeydown.bind(this));
            this.container.addEventListener('dblclick', this.handleDoubleTap.bind(this));
            this.container.addEventListener('mousemove', this.showControls.bind(this));
        }

        render() {
            this.container.innerHTML = `
                <div class="video-player-wrapper" tabindex="0">
                    <video class="video-element" preload="metadata" playsinline webkit-playsinline src="${this.src}"></video>
                    <div class="spinner" style="display:none;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;z-index:10;">
                        <div class="w-12 h-12 border-[3px] border-white/20 border-t-white rounded-full animate-spin"></div>
                    </div>
                    <div class="poster-overlay" style="display:none;position:absolute;inset:0;cursor:pointer;z-index:20;align-items:center;justify-content:center;background:rgba(0,0,0,0.3);">
                        <div class="play-btn" style="width:64px;height:64px;background:rgba(59,130,246,0.9);border-radius:50%;display:flex;align-items:center;justify-content:center;">
                            <svg style="width:32px;height:32px;color:white;margin-left:4px;" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                        </div>
                    </div>
                    <div class="error-overlay" style="display:none;position:absolute;inset:0;background:rgba(0,0,0,0.6);z-index:20;align-items:center;justify-content:center;">
                        <div class="text-center">
                            <p class="text-white/70 text-sm mb-2">Unable to load video</p>
                            <button class="retry-btn text-blue-400 hover:text-blue-300 text-sm font-medium">Retry</button>
                        </div>
                    </div>
                    <div class="controls" style="position:absolute;bottom:0;left:0;right:0;background:linear-gradient(transparent,rgba(0,0,0,0.7));padding:12px 16px;display:flex;flex-direction:column;gap:8px;z-index:30;opacity:0;transition:opacity 0.3s;">
                        <div class="progress-bar" style="position:relative;height:4px;background:rgba(255,255,255,0.3);border-radius:2px;cursor:pointer;">
                            <div class="buffer-bar" style="position:absolute;inset:0;background:rgba(255,255,255,0.3);border-radius:2px;"></div>
                            <div class="play-bar" style="position:absolute;inset:0;background:#3b82f6;border-radius:2px;"></div>
                            <div class="thumb" style="position:absolute;top:50%;width:12px;height:12px;background:#3b82f6;border-radius:50%;transform:translate(-50%,-50%);opacity:0;transition:opacity 0.2s;"></div>
                            <div class="seek-tooltip" style="position:absolute;top:-24px;background:#1e293b;color:white;font-size:11px;font-weight:500;padding:2px 6px;border-radius:4px;white-space:nowrap;display:none;"></div>
                        </div>
                        <div class="controls-row" style="display:flex;align-items:center;justify-content:space-between;color:white;">
                            <div class="controls-left" style="display:flex;align-items:center;gap:12px;">
                                <button class="play-pause-btn" title="Play (k)">
                                    <svg class="play-icon" width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                    <svg class="pause-icon" style="display:none;" width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"/></svg>
                                </button>
                                <div class="volume-control" style="display:flex;align-items:center;gap:4px;">
                                    <button class="mute-btn" title="Mute (m)">
                                        <svg class="vol-high" width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M3 9v6h4l5 5V4L9 9H3zm13.5 3c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM14 3.23v2.06c2.89.86 5 3.54 5 6.71s-2.11 5.85-5 6.71v2.06c4.01-.91 7-4.49 7-8.77s-2.99-7.86-7-8.77z"/></svg>
                                        <svg class="vol-low" style="display:none;" width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M18.5 12c0-1.77-1.02-3.29-2.5-4.03v8.05c1.48-.73 2.5-2.25 2.5-4.02zM5 9v6h4l5 5V4L9 9H5z"/></svg>
                                        <svg class="vol-mute" style="display:none;" width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M16.5 12c0-1.77-1.02-3.29-2.5-4.03v2.21l2.45 2.45c.03-.2.05-.41.05-.63zm2.5 0c0 .94-.2 1.82-.54 2.64l1.51 1.51C20.63 14.91 21 13.5 21 12c0-4.28-2.99-7.86-7-8.77v2.06c2.89.86 5 3.54 5 6.71zM4.27 3L3 4.27 7.73 9H3v6h4l5 5v-6.73l4.25 4.25c-.67.52-1.42.93-2.25 1.18v2.06a8.99 8.99 0 003.69-1.81L19.73 21 21 19.73l-9-9L4.27 3zM12 4L9.91 6.09 12 8.18V4z"/></svg>
                                    </button>
                                    <div class="volume-slider-wrapper" style="width:0;overflow:hidden;transition:width 0.2s;">
                                        <input type="range" class="volume-slider" min="0" max="1" step="0.05" value="1" style="width:60px;height:4px;background:rgba(255,255,255,0.3);border-radius:2px;appearance:none;">
                                    </div>
                                </div>
                                <span class="time-display" style="font-size:12px;font-family:monospace;">0:00 / 0:00</span>
                            </div>
                            <div class="controls-right" style="display:flex;align-items:center;gap:8px;">
                                <div class="speed-control" style="position:relative;">
                                    <button class="speed-btn" style="font-size:12px;padding:2px 8px;background:rgba(255,255,255,0.1);border-radius:4px;">1x</button>
                                    <div class="speed-menu" style="display:none;position:absolute;bottom:100%;right:0;background:#1e293b;border-radius:8px;padding:4px;min-width:80px;box-shadow:0 4px 12px rgba(0,0,0,0.3);">
                                        ${this.options.speedOptions.map(s => `<button class="speed-option" data-speed="${s}" style="width:100%;padding:6px 12px;text-align:left;color:white;background:none;border:none;cursor:pointer;">${s}x</button>`).join('')}
                                    </div>
                                </div>
                                <button class="pip-btn" title="Picture-in-Picture">
                                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 6a2 2 0 012-2h12a2 2 0 012 2v12a2 2 0 01-2 2H6a2 2 0 01-2-2V6z"/><path d="M14 14h4v4h-4z"/></svg>
                                </button>
                                <button class="fullscreen-btn" title="Fullscreen (f)">
                                    <svg class="fs-enter" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3.75 3.75v4.5m0-4.5h4.5m-4.5 0L9 9M3.75 20.25v-4.5m0 4.5h4.5m-4.5 0L9 15M20.25 3.75h-4.5m4.5 0v4.5m0-4.5L15 9m5.25 11.25h-4.5m4.5 0v-4.5m0 4.5L15 15"/></svg>
                                    <svg class="fs-exit" style="display:none;" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 9V4.5M9 9H4.5M9 9L3.75 3.75M9 15v4.5M9 15H4.5M9 15l-5.25 5.25M15 9h4.5M15 9V4.5M15 9l5.25-5.25M15 15h4.5M15 15v4.5m0-4.5l5.25 5.25"/></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;

            this.elements = {
                wrapper: this.container.querySelector('.video-player-wrapper'),
                video: this.videoEl,
                spinner: this.container.querySelector('.spinner'),
                posterOverlay: this.container.querySelector('.poster-overlay'),
                errorOverlay: this.container.querySelector('.error-overlay'),
                controls: this.container.querySelector('.controls'),
                playPauseBtn: this.container.querySelector('.play-pause-btn'),
                playIcon: this.container.querySelector('.play-icon'),
                pauseIcon: this.container.querySelector('.pause-icon'),
                progressBar: this.container.querySelector('.progress-bar'),
                playBar: this.container.querySelector('.play-bar'),
                bufferBar: this.container.querySelector('.buffer-bar'),
                thumb: this.container.querySelector('.thumb'),
                seekTooltip: this.container.querySelector('.seek-tooltip'),
                volumeSliderWrapper: this.container.querySelector('.volume-slider-wrapper'),
                volumeSlider: this.container.querySelector('.volume-slider'),
                muteBtn: this.container.querySelector('.mute-btn'),
                volHigh: this.container.querySelector('.vol-high'),
                volLow: this.container.querySelector('.vol-low'),
                volMute: this.container.querySelector('.vol-mute'),
                timeDisplay: this.container.querySelector('.time-display'),
                speedBtn: this.container.querySelector('.speed-btn'),
                speedMenu: this.container.querySelector('.speed-menu'),
                pipBtn: this.container.querySelector('.pip-btn'),
                fullscreenBtn: this.container.querySelector('.fullscreen-btn'),
                fsEnter: this.container.querySelector('.fs-enter'),
                fsExit: this.container.querySelector('.fs-exit'),
                retryBtn: this.container.querySelector('.retry-btn')
            };

            this.bindEvents();
        }

        bindEvents() {
            const el = this.videoEl;
            const { playPauseBtn, posterOverlay, retryBtn, progressBar, muteBtn, volumeSlider, speedBtn, speedMenu, pipBtn, fullscreenBtn } = this.elements;

            playPauseBtn.addEventListener('click', () => this.togglePlay());
            posterOverlay.addEventListener('click', () => this.play());
            retryBtn.addEventListener('click', () => { this.initialError = false; el.load(); });

            progressBar.addEventListener('click', this.seekTo.bind(this));
            progressBar.addEventListener('mousemove', this.onProgressHover.bind(this));
            progressBar.addEventListener('mouseenter', () => { this.showSeekTooltip = true; });
            progressBar.addEventListener('mouseleave', () => { this.showSeekTooltip = false; });

            muteBtn.addEventListener('click', this.toggleMute.bind(this));
            volumeSlider.addEventListener('input', (e) => { el.volume = e.target.value; el.muted = e.target.value === "0"; });

            speedBtn.addEventListener('click', () => { speedMenu.style.display = speedMenu.style.display === 'block' ? 'none' : 'block'; });
            speedMenu.querySelectorAll('.speed-option').forEach(btn => {
                btn.addEventListener('click', () => {
                    const speed = parseFloat(btn.dataset.speed);
                    this.setSpeed(speed);
                    speedMenu.style.display = 'none';
                });
            });

            pipBtn.addEventListener('click', this.togglePiP.bind(this));
            fullscreenBtn.addEventListener('click', this.toggleFullscreen.bind(this));
        }

        onLoadedMetadata() {
            this.duration = this.videoEl.duration || 0;
            this.volume = this.videoEl.volume;
            this.isMuted = this.videoEl.muted;
            if (this.options.autoplay) {
                this.videoEl.play().catch(() => {});
            }
        }

        onPlay() {
            this.isPlaying = true;
            this.hasStarted = true;
            this.initialError = false;
            this.elements.posterOverlay.style.display = 'none';
            this.elements.spinner.style.display = 'none';
            this.elements.controls.style.opacity = '1';
        }

        onPause() {
            this.isPlaying = false;
            this.elements.controls.style.opacity = '1';
        }

        onTimeUpdate() {
            this.currentTime = this.videoEl.currentTime;
            this.duration = this.videoEl.duration || 0;
            this.progressPercent = this.duration > 0 ? (this.currentTime / this.duration) * 100 : 0;
        }

        onWaiting() {
            this.isBuffering = true;
        }

        onCanPlay() {
            this.isBuffering = false;
        }

        onPlaying() {
            this.isBuffering = false;
        }

        onProgress() {
            const ranges = this.videoEl.buffered;
            if (ranges.length > 0) {
                const bufferedEnd = ranges.end(ranges.length - 1);
                this.bufferPercent = this.duration > 0 ? (bufferedEnd / this.duration) * 100 : 0;
            }
        }

        onVolumeChange() {
            this.volume = this.videoEl.volume;
            this.isMuted = this.videoEl.muted;
        }

        onError() {
            this.initialError = true;
        }

        onEnded() {
            this.isPlaying = false;
        }

        onSeeking() {}
        onSeeked() {}

        togglePlay() {
            if (this.videoEl.paused) {
                this.videoEl.play().catch(() => {});
            } else {
                this.videoEl.pause();
            }
        }

        play() {
            this.videoEl.play().catch(() => {});
        }

        seekTo(e) {
            const rect = this.progressBar.getBoundingClientRect();
            const x = (e.clientX - rect.left) / rect.width;
            this.videoEl.currentTime = x * (this.duration || 0);
        }

        onProgressHover(e) {
            const rect = this.progressBar.getBoundingClientRect();
            const x = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            this.seekTooltipLeft = x * 100;
            this.seekTooltipTime = this.formatTime(x * (this.duration || 0));
            this.elements.seekTooltip.textContent = this.seekTooltipTime;
            this.elements.seekTooltip.style.left = this.seekTooltipLeft + '%';
        }

        toggleMute() {
            this.videoEl.muted = !this.videoEl.muted;
        }

        setVolume(e) {
            const val = parseFloat(e.target.value);
            this.videoEl.volume = val;
            if (val > 0 && this.videoEl.muted) this.videoEl.muted = false;
        }

        setSpeed(speed) {
            this.playbackSpeed = speed;
            this.videoEl.playbackRate = speed;
            this.elements.speedBtn.textContent = speed + 'x';
        }

        togglePiP() {
            if (document.pictureInPictureElement) {
                document.exitPictureInPicture();
            } else {
                this.videoEl.requestPictureInPicture();
            }
        }

        toggleFullscreen() {
            if (!document.fullscreenElement) {
                this.container.requestFullscreen?.();
                this.isFullscreen = true;
            } else {
                document.exitFullscreen?.();
                this.isFullscreen = false;
            }
        }

        showControls() {
            this.controlsVisible = true;
            clearTimeout(this.hideControlsTimer);
            if (this.isPlaying) {
                this.hideControlsTimer = setTimeout(() => {
                    this.controlsVisible = false;
                }, 3000);
            }
        }

        handleKeydown(e) {
            const el = this.videoEl;
            switch (e.key) {
                case ' ':
                case 'k':
                    e.preventDefault();
                    this.togglePlay();
                    break;
                case 'ArrowLeft':
                    e.preventDefault();
                    el.currentTime = Math.max(0, el.currentTime - 10);
                    break;
                case 'ArrowRight':
                    e.preventDefault();
                    el.currentTime = Math.min(this.duration, el.currentTime + 10);
                    break;
                case 'ArrowUp':
                    e.preventDefault();
                    el.volume = Math.min(1, el.volume + 0.1);
                    break;
                case 'ArrowDown':
                    e.preventDefault();
                    el.volume = Math.max(0, el.volume - 0.1);
                    break;
                case 'f':
                    e.preventDefault();
                    this.toggleFullscreen();
                    break;
                case 'm':
                    e.preventDefault();
                    this.toggleMute();
                    break;
            }
        }

        handleDoubleTap(e) {
            const rect = this.container.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const third = rect.width / 3;
            if (x < third) {
                this.videoEl.currentTime = Math.max(0, this.videoEl.currentTime - 10);
            } else if (x > rect.width - third) {
                this.videoEl.currentTime = Math.min(this.duration, this.videoEl.currentTime + 10);
            }
        }

        formatTime(seconds) {
            if (!seconds || !isFinite(seconds)) return '0:00';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = Math.floor(seconds % 60);
            if (h > 0) return `${h}:${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
            return `${m}:${String(s).padStart(2, '0')}`;
        }

        // Expose API
        destroy() {
            this.videoEl.pause();
            this.videoEl.removeAttribute('src');
            this.videoEl.load();
            this.container.innerHTML = '';
        }
    }

    window.VideoPlayer = VideoPlayer;
})();
