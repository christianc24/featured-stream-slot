(() => {
	'use strict';
	const CLOSED_KEY = 'ssp-closed';
	const LAYOUT_KEY = 'ssp-player-layout-v7';
	const TWITCH_NS = 'twitch-embed-player-proxy';
	const TWITCH_EVENT_NS = 'twitch-embed';
	const MAX_WIDTH = 900;
	const REMOUNT_AFTER_MS = 12000;

	const boot = () => {
		const player = document.getElementById('ssp-player');
		if (!player || player.dataset.sspReady === '1') return;
		player.dataset.sspReady = '1';

		try {
			if (sessionStorage.getItem(CLOSED_KEY)) {
				player.remove();
				return;
			}
		} catch (e) {}

		const isMobile = () => window.matchMedia('(max-width: 767px)').matches;
		const clamp = (value, min, max) => Math.min(Math.max(min, max), Math.max(min, value));
		const embed = player.querySelector('#ssp-embed');
		const offline = player.querySelector('[data-ssp-offline]');
		const provider = player.dataset.sspProvider || 'other';
		const wantsAutoplay = player.dataset.sspAutoplay === '1';
		// Twitch measures the window in its own pixels: never narrower than minWidth
		// inside; shown smaller through zoom, down to minZoom.
		const minWidth = Math.max(240, parseInt(player.dataset.sspMinWidth, 10) || 240);
		const minZoom = clamp(parseFloat(player.dataset.sspMinZoom) || 1, 0.5, 1);
		const minVisual = Math.round(minWidth * minZoom);
		let embedOrigin = '*';
		try { if (embed) embedOrigin = new URL(embed.src).origin; } catch (e) {}

		/* ---------- Size: on-screen video width -> inner width + zoom ---------- */
		const media = player.querySelector('.ssp-media');
		const defaults = {
			inner: player.style.getPropertyValue('--ssp-inner-width'),
			zoom: player.style.getPropertyValue('--ssp-zoom')
		};
		const frameExtra = () => {
			const win = player.querySelector('.ssp-window');
			return win ? (parseFloat(getComputedStyle(win).borderLeftWidth) || 0) * 2 : 0;
		};
		const maxVisualNow = () => Math.min(MAX_WIDTH, window.innerWidth - 32 - frameExtra());
		const visualWidth = () => (media || player).getBoundingClientRect().width;
		const applyWidth = (visual) => {
			visual = clamp(Math.round(visual), Math.min(minVisual, maxVisualNow()), maxVisualNow());
			if (visual < minWidth) {
				player.style.setProperty('--ssp-inner-width', `${minWidth}px`);
				player.style.setProperty('--ssp-zoom', String(Math.max(minZoom, visual / minWidth)));
			} else {
				player.style.setProperty('--ssp-inner-width', `${visual}px`);
				player.style.setProperty('--ssp-zoom', '1');
			}
		};
		const resetSize = () => {
			player.style.setProperty('--ssp-inner-width', defaults.inner);
			player.style.setProperty('--ssp-zoom', defaults.zoom);
		};

		/* ---------- Offline overlay ---------- */
		const setOffline = (state) => {
			if (!embed) return;
			if (state) {
				if (offline) {
					offline.hidden = false;
					player.classList.add('ssp-stream-offline');
				} else {
					player.hidden = true;
				}
			} else {
				player.hidden = false;
				player.classList.remove('ssp-stream-offline');
				if (offline) offline.hidden = true;
			}
		};

		/* ---------- Provider messaging (no SDKs: the iframes speak postMessage) ---------- */
		const post = (message) => {
			if (!embed || !embed.contentWindow) return;
			try { embed.contentWindow.postMessage(message, embedOrigin); } catch (e) {}
		};
		const twitchCommand = (eventName, params) => post({ eventName, params: params || {}, namespace: TWITCH_NS });
		const isPlaying = () => player.dataset.sspPlayback === 'playing';
		const nudgePlay = () => {
			if (!embed || !wantsAutoplay || isPlaying() || player.dataset.sspStream === 'offline') return;
			if (provider === 'twitch') {
				twitchCommand('setMuted', { muted: true });
				twitchCommand('play');
			} else if (provider === 'youtube') {
				post(JSON.stringify({ event: 'listening', id: 'ssp-embed' }));
				post(JSON.stringify({ event: 'command', func: 'mute', args: [] }));
				post(JSON.stringify({ event: 'command', func: 'playVideo', args: [] }));
			} else if (provider === 'vimeo') {
				post({ method: 'addEventListener', value: 'play' });
				post({ method: 'setVolume', value: 0 });
				post({ method: 'play' });
			}
		};
		// Twitch latches a refused autoplay. If it reported ready but never started
		// playing, reload the embed once; a fresh iframe re-runs its autoplay checks.
		let remountTimer = 0;
		let remounted = false;
		const scheduleRemount = () => {
			if (provider !== 'twitch' || !wantsAutoplay || remounted || remountTimer) return;
			remountTimer = window.setTimeout(() => {
				remountTimer = 0;
				if (isPlaying() || player.dataset.sspStream === 'offline' || document.hidden) return;
				remounted = true;
				embed.setAttribute('src', embed.getAttribute('src'));
			}, REMOUNT_AFTER_MS);
		};
		const trackTwitch = (name, params) => {
			const playback = params && params.playback;
			if (name === 'offline') {
				player.dataset.sspStream = 'offline';
				setOffline(true);
			}
			if (name === 'online') {
				player.dataset.sspStream = 'online';
				setOffline(false);
			}
			if (name === 'playing' || playback === 'Playing') {
				player.dataset.sspPlayback = 'playing';
				player.dataset.sspStream = 'online';
				setOffline(false);
			} else if (name === 'ready' || name === 'play' || playback === 'Ready' || playback === 'Buffering') {
				player.dataset.sspPlayback = String(name || playback).toLowerCase();
				if (name === 'ready') scheduleRemount();
			} else if (name === 'pause' || playback === 'Idle') {
				if (isPlaying()) player.dataset.sspPlayback = 'paused';
			} else if (name === 'ended') {
				player.dataset.sspPlayback = 'ended';
			} else if (name === 'playbackBlocked') {
				player.dataset.sspPlayback = 'blocked';
			}
		};
		if (embed) {
			embed.addEventListener('load', nudgePlay);
			[800, 2000, 4000, 8000].forEach((delay) => window.setTimeout(nudgePlay, delay));
			window.addEventListener('message', (event) => {
				if (!embed.contentWindow || event.source !== embed.contentWindow) return;
				let data = event.data;
				if (typeof data === 'string') {
					try { data = JSON.parse(data); } catch (e) { return; }
				}
				if (!data || typeof data !== 'object') return;
				if (provider === 'twitch' && (data.namespace === TWITCH_NS || data.namespace === TWITCH_EVENT_NS)) {
					trackTwitch(data.eventName === 'UPDATE_STATE' ? '' : data.eventName, data.params);
				} else if (provider === 'youtube' && data.event === 'infoDelivery' && data.info && typeof data.info.playerState === 'number') {
					if (data.info.playerState === 1) player.dataset.sspPlayback = 'playing';
				} else if (provider === 'vimeo' && data.event === 'play') {
					player.dataset.sspPlayback = 'playing';
				}
			});
		}

		/* ---------- Remembered size/position (desktop only) ---------- */
		const save = () => {
			if (isMobile()) return;
			const rect = player.getBoundingClientRect();
			try {
				localStorage.setItem(LAYOUT_KEY, JSON.stringify({
					width: Math.round(visualWidth()),
					left: Math.round(rect.left),
					top: Math.round(rect.top)
				}));
			} catch (e) {}
		};
		const clearInline = () => {
			['left', 'right', 'top', 'bottom', 'transform'].forEach((prop) => player.style.removeProperty(prop));
			resetSize();
		};
		const restore = () => {
			if (isMobile()) {
				clearInline();
				return;
			}
			let data = null;
			try { data = JSON.parse(localStorage.getItem(LAYOUT_KEY)); } catch (e) {}
			if (!data) {
				// Nothing saved: only make sure the default size fits the window.
				if (visualWidth() > maxVisualNow()) applyWidth(maxVisualNow());
				return;
			}
			applyWidth(Number(data.width) || minVisual);
			const outerWidth = player.offsetWidth;
			const outerHeight = player.offsetHeight;
			player.style.left = `${clamp(Number(data.left) || 16, 8, window.innerWidth - outerWidth - 8)}px`;
			player.style.top = `${clamp(Number(data.top) || 16, 8, window.innerHeight - outerHeight - 8)}px`;
			player.style.right = 'auto';
			player.style.bottom = 'auto';
			player.style.transform = 'none';
		};

		/* ---------- Drag and resize ---------- */
		const startPointer = (event, mode) => {
			if (event.button !== undefined && event.button !== 0) return;
			if (event.target.closest('button, a, input, select, textarea')) return;
			if (isMobile()) return;
			event.preventDefault();
			const rect = player.getBoundingClientRect();
			const startX = event.clientX;
			const startY = event.clientY;
			const start = { left: rect.left, top: rect.top, width: visualWidth(), right: rect.right, bottom: rect.bottom };
			player.classList.add(mode === 'drag' ? 'ssp-is-dragging' : 'ssp-is-resizing');
			player.style.transform = 'none';
			player.style.left = `${rect.left}px`;
			player.style.top = `${rect.top}px`;
			player.style.right = 'auto';
			player.style.bottom = 'auto';

			let latestEvent = event;
			let frame = 0;
			const render = () => {
				frame = 0;
				const moveEvent = latestEvent;
				if (mode === 'drag') {
					player.style.left = `${clamp(start.left + moveEvent.clientX - startX, 8, window.innerWidth - player.offsetWidth - 8)}px`;
					player.style.top = `${clamp(start.top + moveEvent.clientY - startY, 8, window.innerHeight - player.offsetHeight - 8)}px`;
				} else {
					// Top-left grip: dragging up or left makes the player bigger; the bottom-right corner stays put.
					const dx = startX - moveEvent.clientX;
					const dy = (startY - moveEvent.clientY) * 16 / 9;
					const maxWidth = Math.min(maxVisualNow(), start.right - 8 - frameExtra());
					applyWidth(clamp(start.width + (Math.abs(dy) > Math.abs(dx) ? dy : dx), Math.min(minVisual, maxWidth), maxWidth));
					const outerWidth = player.offsetWidth;
					const outerHeight = player.offsetHeight;
					player.style.left = `${clamp(start.right - outerWidth, 8, window.innerWidth - outerWidth - 8)}px`;
					player.style.top = `${clamp(start.bottom - outerHeight, 8, window.innerHeight - outerHeight - 8)}px`;
				}
			};
			const move = (moveEvent) => {
				latestEvent = moveEvent;
				if (!frame) frame = window.requestAnimationFrame(render);
			};
			const end = () => {
				if (frame) window.cancelAnimationFrame(frame);
				render();
				player.classList.remove('ssp-is-dragging', 'ssp-is-resizing');
				window.removeEventListener('pointermove', move);
				window.removeEventListener('pointerup', end);
				window.removeEventListener('pointercancel', end);
				save();
			};
			window.addEventListener('pointermove', move);
			window.addEventListener('pointerup', end, { once: true });
			window.addEventListener('pointercancel', end, { once: true });
		};

		const dragHandle = player.querySelector('[data-ssp-drag-handle]');
		const resizeHandle = player.querySelector('[data-ssp-resize]');
		const closeButton = player.querySelector('[data-ssp-close]');
		const resetButton = player.querySelector('[data-ssp-reset]');
		if (dragHandle) dragHandle.addEventListener('pointerdown', (e) => startPointer(e, 'drag'));
		if (resizeHandle) resizeHandle.addEventListener('pointerdown', (e) => startPointer(e, 'resize'));
		if (closeButton) closeButton.addEventListener('click', () => {
			try { sessionStorage.setItem(CLOSED_KEY, '1'); } catch (e) {}
			player.remove();
		});
		if (resetButton) resetButton.addEventListener('click', () => {
			try { localStorage.removeItem(LAYOUT_KEY); } catch (e) {}
			clearInline();
		});
		let resizeTimer;
		window.addEventListener('resize', () => {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(restore, 100);
		}, { passive: true });
		restore();
	};

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot, { once: true });
	} else {
		boot();
	}
})();
