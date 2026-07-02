const MIN_PULL_DISTANCE = 60
const MAX_PULL_DISTANCE = 120
const INDICATOR_HEIGHT = 60

const STATE = {
  IDLE: 'idle',
  PULLING: 'pulling',
  READY: 'ready',
  REFRESHING: 'refreshing',
}

let state = STATE.IDLE
let startY = 0
let currentY = 0
let pullDistance = 0
let indicatorEl = null
let refreshCallback = null
let insertBeforeEl = null

function createIndicator() {
  const el = document.createElement('div')
  el.id = 'ptr-indicator'
  el.innerHTML = `
    <div class="ptr-spinner">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
        <path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/>
      </svg>
    </div>
    <div class="ptr-text">Pull to refresh</div>
    <div class="ptr-progress"><div class="ptr-progress-bar"></div></div>
  `
  Object.assign(el.style, {
    position: 'fixed',
    top: '0',
    left: '0',
    right: '0',
    height: '0',
    display: 'flex',
    flexDirection: 'column',
    alignItems: 'center',
    justifyContent: 'center',
    overflow: 'hidden',
    zIndex: '9999',
    background: 'var(--ptr-bg, #ffffff)',
    color: 'var(--ptr-color, #64748b)',
    transition: 'none',
    willChange: 'height, transform',
  })
  document.body.appendChild(el)
  return el
}

function updateIndicator(distance) {
  if (!indicatorEl) return
  const progress = Math.min(distance / MIN_PULL_DISTANCE, 1)
  const height = Math.min(distance, INDICATOR_HEIGHT)

  indicatorEl.style.height = height + 'px'

  const spinner = indicatorEl.querySelector('.ptr-spinner')
  const text = indicatorEl.querySelector('.ptr-text')
  const progressBar = indicatorEl.querySelector('.ptr-progress-bar')

  if (spinner) {
    const rotation = distance * 3
    spinner.style.transform = `rotate(${rotation}deg)`
    spinner.style.opacity = Math.min(progress + 0.3, 1)
    spinner.style.scale = Math.min(progress + 0.3, 1)
  }

  if (text) {
    text.style.opacity = Math.min(progress, 1)
    text.style.transform = `translateY(${(1 - progress) * 10}px)`
    text.textContent = distance >= MIN_PULL_DISTANCE ? 'Release to refresh' : 'Pull to refresh'
  }

  if (progressBar) {
    progressBar.style.width = (progress * 100) + '%'
  }
}

function showRefreshing() {
  state = STATE.REFRESHING
  if (!indicatorEl) return

  indicatorEl.style.height = INDICATOR_HEIGHT + 'px'

  const spinner = indicatorEl.querySelector('.ptr-spinner')
  const text = indicatorEl.querySelector('.ptr-text')
  const progressBar = indicatorEl.querySelector('.ptr-progress')

  if (spinner) {
    spinner.style.animation = 'ptr-spin 0.8s linear infinite'
    spinner.style.transform = ''
    spinner.style.opacity = '1'
    spinner.style.scale = '1'
  }
  if (text) {
    text.textContent = 'Refreshing...'
    text.style.opacity = '1'
    text.style.transform = ''
  }
  if (progressBar) {
    progressBar.style.display = 'none'
  }
}

function hideIndicator() {
  if (!indicatorEl) return
  indicatorEl.style.height = '0'
  indicatorEl.style.transition = 'height 0.25s ease-out'

  const spinner = indicatorEl.querySelector('.ptr-spinner')
  const text = indicatorEl.querySelector('.ptr-text')
  const progressBar = indicatorEl.querySelector('.ptr-progress')

  if (spinner) {
    spinner.style.animation = ''
    spinner.style.opacity = '0'
  }
  if (text) {
    text.textContent = 'Pull to refresh'
    text.style.opacity = '0'
  }
  if (progressBar) {
    progressBar.style.display = 'block'
  }

  setTimeout(() => {
    if (indicatorEl) indicatorEl.style.transition = 'none'
    pullDistance = 0
    state = STATE.IDLE
  }, 250)
}

function onTouchStart(e) {
  if (state === STATE.REFRESHING) return
  const scrollY = window.scrollY || window.pageYOffset
  if (scrollY > 5) return

  state = STATE.PULLING
  startY = e.touches[0].clientY
  currentY = startY
  pullDistance = 0

  if (!indicatorEl) {
    indicatorEl = createIndicator()
  }
}

function onTouchMove(e) {
  if (state !== STATE.PULLING && state !== STATE.READY) return

  currentY = e.touches[0].clientY
  const rawDistance = currentY - startY

  if (rawDistance <= 0) {
    pullDistance = 0
    if (indicatorEl) indicatorEl.style.height = '0'
    return
  }

  pullDistance = Math.min(rawDistance * 0.5, MAX_PULL_DISTANCE)

  if (pullDistance >= MIN_PULL_DISTANCE) {
    state = STATE.READY
  } else {
    state = STATE.PULLING
  }

  updateIndicator(pullDistance)
}

function onTouchEnd() {
  if (state === STATE.REFRESHING) return

  if (state === STATE.READY && pullDistance >= MIN_PULL_DISTANCE) {
    showRefreshing()
    if (refreshCallback) {
      refreshCallback().finally(() => {
        hideIndicator()
      })
    } else {
      hideIndicator()
    }
  } else {
    hideIndicator()
    state = STATE.IDLE
  }
}

function addStyles() {
  if (document.getElementById('ptr-styles')) return
  const style = document.createElement('style')
  style.id = 'ptr-styles'
  style.textContent = `
    @keyframes ptr-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    #ptr-indicator {
      font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      font-size: 13px;
      font-weight: 500;
      letter-spacing: 0.01em;
      border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    #ptr-indicator .ptr-spinner {
      width: 22px;
      height: 22px;
      margin-bottom: 4px;
      transition: transform 0s, opacity 0.15s ease, scale 0.15s ease;
      color: #0f766e;
    }
    #ptr-indicator .ptr-text {
      transition: opacity 0.15s ease, transform 0.15s ease;
      font-size: 12px;
    }
    #ptr-indicator .ptr-progress {
      position: absolute;
      bottom: 0;
      left: 0;
      right: 0;
      height: 2px;
      background: rgba(15, 118, 110, 0.1);
    }
    #ptr-indicator .ptr-progress-bar {
      height: 100%;
      background: #0f766e;
      width: 0%;
      transition: width 0.05s linear;
      border-radius: 0 2px 2px 0;
    }
    @media (prefers-color-scheme: dark) {
      #ptr-indicator {
        --ptr-bg: #0f172a;
        --ptr-color: #94a3b8;
        border-bottom-color: rgba(255,255,255,0.05);
      }
      #ptr-indicator .ptr-spinner { color: #14b8a6; }
      #ptr-indicator .ptr-progress { background: rgba(20, 184, 166, 0.1); }
      #ptr-indicator .ptr-progress-bar { background: #14b8a6; }
    }
    body.ptr-refreshing {
      touch-action: none;
    }
  `
  document.head.appendChild(style)
}

export function initPullToRefresh(callback) {
  refreshCallback = callback

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      addStyles()
      bindEvents()
    })
  } else {
    addStyles()
    bindEvents()
  }
}

export function setRefreshCallback(callback) {
  refreshCallback = callback
}

export function triggerRefresh() {
  if (state === STATE.REFRESHING) return Promise.resolve()
  if (!indicatorEl) indicatorEl = createIndicator()

  showRefreshing()
  if (refreshCallback) {
    return refreshCallback().finally(() => {
      hideIndicator()
    })
  }
  return Promise.resolve()
}

export function destroyPullToRefresh() {
  if (indicatorEl) {
    indicatorEl.remove()
    indicatorEl = null
  }
  unbindEvents()
  state = STATE.IDLE
  pullDistance = 0
}

function bindEvents() {
  document.addEventListener('touchstart', onTouchStart, { passive: true })
  document.addEventListener('touchmove', onTouchMove, { passive: true })
  document.addEventListener('touchend', onTouchEnd, { passive: true })
}

function unbindEvents() {
  document.removeEventListener('touchstart', onTouchStart)
  document.removeEventListener('touchmove', onTouchMove)
  document.removeEventListener('touchend', onTouchEnd)
}
