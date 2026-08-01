/**
 * Emoji Replacer v1.2.0
 * Replaces mapped emoji glyphs with <img> icons safely.
 *
 * Usage:
 *   // Default init (auto-detects base path)
 *   window.EmojiReplacer.init();
 *
 *   // Custom mapping or basePath:
 *   window.EmojiReplacer.init({
 *     basePath: '/assets/images/',
 *     map: { '🏠': 'icon_home.png', '💳': 'icon_wallet.png' },
 *     replaceInline: false
 *   });
 *
 * Features:
 *  - Replaces standalone emojis, leading emoji+text, or inline emoji (configurable).
 *  - Uses TreeWalker to operate on text nodes (safe).
 *  - Skips inputs, textareas, contenteditable, script/style/pre/code, svg.
 *  - Preloads images, supports MutationObserver to handle dynamic content.
 *  - Exposes start() / stop() and updateMap() APIs.
 */

(function (global) {
  'use strict';

  const DEFAULT_OPTIONS = {
    basePath: null,           // default: auto-detect (window.location + 'assets/images/')
    map: {
      '🏠': 'icon_home.png',
      '💳': 'icon_wallet.png',
      '🎁': 'icon_refer.png',
      '📋': 'icon_history.png',
      '👤': 'icon_profile.png',
      '🔑': 'icon_login.png',
      '📝': 'icon_register.png',
      '🎲': 'icon_game.png',
      '🏆': 'icon_trophy.png',
      '✅': 'icon_ok.png',
      '❌': 'icon_error.png',
      '🔒': 'icon_lock.png'
    },
    imgClass: 'emoji-img',    // CSS class added to created <img>
    imgSize: 20,              // default width/height in px (applies if no CSS)
    replaceStandalone: true,  // replace when text node is exactly emoji
    replaceLeading: true,     // replace when text node starts with emoji + space/text
    replaceInline: false,     // replace emojis inside text (e.g., "Play 🎲 now") — can be noisy
    maxReplacesPerNode: 5,    // safety limit per text node
    observeMutations: true,   // watch DOM for new nodes
    mutationObserverOptions: { childList: true, subtree: true }
  };

  // Utility: escape emoji for regex (we'll build alternation)
  function buildEmojiRegex(keys, flags = 'g') {
    // Sort longer keys first to avoid partial matching if any multi-char sequences exist
    const sorted = keys.slice().sort((a, b) => b.length - a.length);
    // Build alternation, some emoji require proper escaping inside regex; use simple join
    const pattern = sorted.map(k => k.replace(/([.*+?^${}()|\[\]\/\\])/g, '\\$1')).join('|');
    return new RegExp(pattern, flags);
  }

  function detectBasePath() {
    // Try to find a reasonable base path to assets/images relative to current script or location.
    // 1) If script tag present with this filename, use its directory.
    try {
      const scripts = document.getElementsByTagName('script');
      for (let i = scripts.length - 1; i >= 0; i--) {
        const s = scripts[i].src || '';
        if (s && s.indexOf('emoji-replacer.js') !== -1) {
          const idx = s.lastIndexOf('/');
          if (idx !== -1) return s.substring(0, idx) + '/';
        }
      }
    } catch (e) { /* ignore */ }
    // 2) Fallback: assume assets/images/ at site root relative to current pathname
    const base = window.location.pathname.replace(/\/[^\/]*$/, '/');
    return window.location.origin + base + 'assets/images/';
  }

  // DOM helpers
  const SKIP_TAGS = new Set(['SCRIPT', 'STYLE', 'CODE', 'PRE', 'TEXTAREA', 'INPUT', 'OPTION', 'SELECT', 'SVG']);

  function isSkippableNode(node) {
    if (!node || !node.parentNode) return true;
    const p = node.parentNode;
    if (!p.tagName) return false;
    if (SKIP_TAGS.has(p.tagName)) return true;
    if (p.isContentEditable) return true;
    return false;
  }

  // Replace logic: operates on a text node and returns number of replacements made
  function processTextNode(textNode, emojiRegex, options, map, basePath) {
    if (!textNode || !textNode.nodeValue) return 0;
    if (isSkippableNode(textNode)) return 0;

    let text = textNode.nodeValue;
    const original = text;
    let replaces = 0;

    // Case A: standalone (entire text equals emoji)
    if (options.replaceStandalone) {
      const trimmed = text.trim();
      if (map[trimmed]) {
        const img = createImgElement(map[trimmed], basePath, options, trimmed);
        textNode.parentNode.replaceChild(img, textNode);
        return 1;
      }
    }

    // Case B: leading emoji (emoji at start followed by whitespace or punctuation)
    if (options.replaceLeading) {
      // check first emoji match at beginning
      const m = text.match(emojiRegex);
      if (m && m.index === 0) {
        const key = m[0];
        if (map[key]) {
          // create container span: img + remaining text node
          const img = createImgElement(map[key], basePath, options, key);
          const remainder = text.substring(key.length);
          const frag = document.createDocumentFragment();
          frag.appendChild(img);
          if (remainder.length > 0) frag.appendChild(document.createTextNode(remainder));
          textNode.parentNode.replaceChild(frag, textNode);
          return 1;
        }
      }
    }

    // Case C: inline replacements (replace all mapped emoji occurrences inside text)
    if (options.replaceInline) {
      // Use regex to replace emojis within the text node by creating nodes
      // We'll iterate and build a DocumentFragment
      const rx = emojiRegex;
      let lastIndex = 0;
      let match;
      const frag = document.createDocumentFragment();
      rx.lastIndex = 0;
      while ((match = rx.exec(text)) && replaces < options.maxReplacesPerNode) {
        const idx = match.index;
        const key = match[0];
        // Append preceding text
        if (idx > lastIndex) {
          frag.appendChild(document.createTextNode(text.substring(lastIndex, idx)));
        }
        // Append image for emoji (if mapped)
        if (map[key]) {
          frag.appendChild(createImgElement(map[key], basePath, options, key));
          replaces++;
        } else {
          frag.appendChild(document.createTextNode(key));
        }
        lastIndex = idx + key.length;
      }
      if (replaces > 0) {
        // append remaining text
        if (lastIndex < text.length) frag.appendChild(document.createTextNode(text.substring(lastIndex)));
        textNode.parentNode.replaceChild(frag, textNode);
        return replaces;
      }
    }

    return 0;
  }

  function createImgElement(filenameOrUrl, basePath, options, emojiChar) {
    const img = document.createElement('img');
    const src = filenameOrUrl.indexOf('http') === 0 || filenameOrUrl.indexOf('/') === 0
      ? filenameOrUrl
      : (basePath + filenameOrUrl);
    img.src = src;
    img.alt = emojiChar || '';
    img.className = options.imgClass || 'emoji-img';
    img.width = options.imgSize || 20;
    img.height = options.imgSize || 20;
    img.loading = 'lazy';
    img.decoding = 'async';
    // inline style fallback (only if CSS not present)
    img.style.width = img.style.width || (options.imgSize ? options.imgSize + 'px' : '');
    img.style.height = img.style.height || (options.imgSize ? options.imgSize + 'px' : '');
    img.style.display = 'inline-block';
    img.style.verticalAlign = 'middle';
    return img;
  }

  // Preload images for smoother UI
  function preloadImages(map, basePath) {
    const keys = Object.keys(map || {});
    keys.forEach(k => {
      const v = map[k];
      const img = new Image();
      img.src = (v.indexOf('http') === 0 || v.indexOf('/') === 0) ? v : (basePath + v);
      // no need to wait; browser caches it
    });
  }

  // Walk the DOM and process text nodes
  function walkAndReplace(root, emojiRegex, options, map, basePath) {
    let total = 0;
    try {
      const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false);
      const nodesToProcess = [];
      while (walker.nextNode()) {
        nodesToProcess.push(walker.currentNode);
      }
      for (let i = 0; i < nodesToProcess.length; i++) {
        total += processTextNode(nodesToProcess[i], emojiRegex, options, map, basePath);
      }
    } catch (e) {
      console.warn('EmojiReplacer: walk error', e);
    }
    return total;
  }

  // Public API object
  const EmojiReplacer = {
    _options: null,
    _map: null,
    _emojiRegex: null,
    _basePath: null,
    _observer: null,
    _running: false,

    init: function (opts) {
      this._options = Object.assign({}, DEFAULT_OPTIONS, opts || {});
      this._basePath = this._options.basePath || detectBasePath();
      this._map = Object.assign({}, DEFAULT_OPTIONS.map, this._options.map || {});
      this._emojiRegex = buildEmojiRegex(Object.keys(this._map));
      // Preload
      preloadImages(this._map, this._basePath);
      // Initial replace on document body
      this.start();
      return this;
    },

    start: function () {
      if (this._running) return this;
      // initial pass
      try {
        walkAndReplace(document.body, this._emojiRegex, this._options, this._map, this._basePath);
      } catch (e) { /* ignore */ }
      // Observe DOM mutations for dynamic content
      if (this._options.observeMutations && window.MutationObserver) {
        this._observer = new MutationObserver(mutations => {
          for (const m of mutations) {
            // process added nodes
            if (m.addedNodes && m.addedNodes.length) {
              m.addedNodes.forEach(node => {
                if (node.nodeType === Node.TEXT_NODE) {
                  processTextNode(node, this._emojiRegex, this._options, this._map, this._basePath);
                } else if (node.nodeType === Node.ELEMENT_NODE) {
                  walkAndReplace(node, this._emojiRegex, this._options, this._map, this._basePath);
                }
              });
            }
            // for characterData changes, process target if text node
            if (m.type === 'characterData' && m.target && m.target.nodeType === Node.TEXT_NODE) {
              processTextNode(m.target, this._emojiRegex, this._options, this._map, this._basePath);
            }
          }
        });
        this._observer.observe(document.body, this._options.mutationObserverOptions);
      }
      this._running = true;
      return this;
    },

    stop: function () {
      if (this._observer) {
        this._observer.disconnect();
        this._observer = null;
      }
      this._running = false;
      return this;
    },

    updateMap: function (newMap) {
      this._map = Object.assign({}, this._map, newMap || {});
      this._emojiRegex = buildEmojiRegex(Object.keys(this._map));
      preloadImages(this._map, this._basePath);
      return this;
    },

    replaceNow: function (rootElement) {
      rootElement = rootElement || document.body;
      return walkAndReplace(rootElement, this._emojiRegex, this._options, this._map, this._basePath);
    }
  };

  // expose globally
  global.EmojiReplacer = EmojiReplacer;

  // Auto-init with default options after DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
      try { window.EmojiReplacer.init(); } catch (e) { /* ignore */ }
    });
  } else {
    try { window.EmojiReplacer.init(); } catch (e) { /* ignore */ }
  }

})(window);
