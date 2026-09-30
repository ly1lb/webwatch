/* WebWatch element picker - runs inside preview iframe (ASCII only). */
(function () {
  'use strict';
  var current = null;
  var similar = false;
  var hovered = null;

  function validIdent(s) {
    return /^[A-Za-z_][\w-]*$/.test(s) && !/\d{4,}/.test(s);
  }

  function goodClasses(el) {
    var out = [];
    for (var i = 0; i < el.classList.length; i++) {
      var c = el.classList[i];
      if (c.indexOf('__ww') === 0) continue;
      if (/^(active|selected|open|show|hover|focus|is-|has-|js-)/.test(c)) continue;
      if (validIdent(c)) out.push(c);
      if (out.length >= 2) break;
    }
    return out;
  }

  function segment(el, generic) {
    var tag = el.localName;
    if (!generic && el.id && validIdent(el.id) && document.querySelectorAll('#' + el.id).length === 1) {
      return { s: '#' + el.id, anchor: true };
    }
    var cls = goodClasses(el);
    var s = tag + (cls.length ? '.' + cls.join('.') : '');
    if (generic) return { s: s, anchor: false };
    var parent = el.parentElement;
    if (parent) {
      var same = [], sameCls = 0;
      for (var i = 0; i < parent.children.length; i++) {
        var c = parent.children[i];
        if (c.localName !== tag) continue;
        same.push(c);
        if (cls.every(function (k) { return c.classList.contains(k); })) sameCls++;
      }
      // nth-of-type only when classes do not identify the element among siblings
      if (same.length > 1 && (!cls.length || sameCls > 1)) s += ':nth-of-type(' + (same.indexOf(el) + 1) + ')';
    }
    return { s: s, anchor: false };
  }

  function build(el, generic) {
    var segs = [];
    var combs = [];
    var comb = ' > ';
    var e = el;
    var first = true;
    while (e && e.nodeType === 1 && e.localName !== 'html') {
      if (e.localName === 'tbody' || e.localName === 'thead') {
        // server-side parser does not always create tbody
        comb = ' ';
        e = e.parentElement;
        continue;
      }
      var seg = segment(e, first && generic);
      segs.unshift(seg.s);
      if (!first) combs.unshift(comb);
      comb = ' > ';
      first = false;
      if (seg.anchor || e.localName === 'body') break;
      e = e.parentElement;
    }
    return { segs: segs, combs: combs };
  }

  function join(p, from) {
    var s = p.segs[from];
    for (var i = from + 1; i < p.segs.length; i++) s += p.combs[i - 1] + p.segs[i];
    return s;
  }

  function sameSet(a, b) {
    if (a.length !== b.length) return false;
    for (var i = 0; i < a.length; i++) if (a[i] !== b[i]) return false;
    return true;
  }

  function selectorFor(el, generic) {
    var p = build(el, generic);
    var full = join(p, 0);
    var target;
    try { target = document.querySelectorAll(full); } catch (e) { return full; }
    var best = full;
    for (var i = 1; i < p.segs.length; i++) {
      var cand = join(p, i);
      // keep selectors specific enough to survive page changes
      if (p.segs.length - i < 2 && !/[#.]/.test(p.segs[i])) break;
      try {
        if (sameSet(document.querySelectorAll(cand), target)) best = cand; else break;
      } catch (e) { break; }
    }
    return best;
  }

  function clearMarks() {
    var old = document.querySelectorAll('.__ww_sel');
    for (var i = 0; i < old.length; i++) old[i].classList.remove('__ww_sel');
  }

  function textOf(el) {
    return (el.innerText || el.textContent || '').replace(/\s+/g, ' ').trim();
  }

  function select(el) {
    if (!el || el.nodeType !== 1) return;
    current = el;
    var sel = selectorFor(el, similar);
    var list;
    try { list = document.querySelectorAll(sel); } catch (e) { list = [el]; }
    clearMarks();
    var texts = [];
    for (var i = 0; i < list.length; i++) {
      list[i].classList.add('__ww_sel');
      if (texts.length < 5) texts.push(textOf(list[i]).slice(0, 160));
    }
    parent.postMessage({
      type: 'ww-select',
      selector: sel,
      count: list.length,
      tag: el.localName,
      similar: similar,
      text: texts.join('\n')
    }, '*');
  }

  document.addEventListener('mouseover', function (e) {
    if (hovered) hovered.classList.remove('__ww_hover');
    hovered = e.target;
    if (hovered && hovered.classList) hovered.classList.add('__ww_hover');
  }, true);
  document.addEventListener('mouseout', function () {
    if (hovered) hovered.classList.remove('__ww_hover');
    hovered = null;
  }, true);

  ['click', 'submit', 'auxclick', 'dblclick'].forEach(function (t) {
    document.addEventListener(t, function (e) {
      e.preventDefault();
      e.stopPropagation();
      if (t === 'click') {
        similar = false;
        select(e.target);
      }
    }, true);
  });

  // Slapukų juostų / užsklandų slėpimas – kitaip jos uždengia elementus ir neleidžia jų pasirinkti.
  var CONSENT_RE = /cookie|consent|gdpr|cmp|onetrust|cybot|usercentrics|didomi|truste|privacy|popup|modal|overlay|backdrop|banner|newsletter|subscribe|paywall/i;

  function isBig(el) {
    var r = el.getBoundingClientRect();
    var vw = window.innerWidth || 1, vh = window.innerHeight || 1;
    return r.width * r.height > vw * vh * 0.12; // dengia bent ~12% ekrano
  }

  function hideOverlays() {
    var removed = 0;
    // Atstatom slinkimą, kurį dažnai užblokuoja juostos
    [document.documentElement, document.body].forEach(function (el) {
      if (el) { el.style.overflow = 'auto'; el.style.position = 'static'; }
    });
    var all = document.body ? document.body.querySelectorAll('*') : [];
    for (var i = 0; i < all.length; i++) {
      var el = all[i];
      if (el.classList && el.classList.contains('__ww_sel')) continue;
      var cs = getComputedStyle(el);
      var pos = cs.position;
      var fixed = pos === 'fixed' || pos === 'sticky';
      var idcls = (el.id + ' ' + (el.className && el.className.baseVal !== undefined ? el.className.baseVal : el.className || '')).toString();
      var looksConsent = CONSENT_RE.test(idcls);
      var highZ = (parseInt(cs.zIndex, 10) || 0) >= 100;
      // Pilno ekrano permatoma užsklanda (backdrop)
      var isBackdrop = fixed && isBig(el) && (parseFloat(cs.backgroundColor.replace(/.*,\s*([\d.]+)\)/, '$1')) > 0 || cs.backdropFilter !== 'none');
      if ((looksConsent && (fixed || highZ || isBig(el))) || (fixed && highZ && isBig(el)) || isBackdrop) {
        el.style.setProperty('display', 'none', 'important');
        removed++;
      }
    }
    return removed;
  }

  window.addEventListener('message', function (e) {
    var d = e.data || {};
    if (d.type !== 'ww-cmd') return;
    if (d.cmd === 'declutter') {
      var n = hideOverlays();
      parent.postMessage({ type: 'ww-decluttered', count: n }, '*');
      return;
    }
    if (!current) return;
    if (d.cmd === 'parent') {
      var p = current.parentElement;
      while (p && (p.localName === 'tbody' || p.localName === 'thead')) p = p.parentElement;
      if (p && p.localName !== 'html') select(p);
    } else if (d.cmd === 'similar') {
      similar = !similar;
      select(current);
    }
  });

  // Automatiškai paslepiam akivaizdžias slapukų juostas iškart įkėlus
  function autoHide() {
    var hit = document.body ? document.body.querySelectorAll('[id*="cookie" i],[class*="cookie" i],[id*="consent" i],[class*="consent" i],[id*="onetrust" i],[id*="CybotCookiebot" i],[class*="gdpr" i],[aria-label*="cookie" i]') : [];
    for (var i = 0; i < hit.length; i++) {
      var cs = getComputedStyle(hit[i]);
      if (cs.position === 'fixed' || cs.position === 'sticky' || (parseInt(cs.zIndex, 10) || 0) >= 100) {
        hit[i].style.setProperty('display', 'none', 'important');
      }
    }
    [document.documentElement, document.body].forEach(function (el) { if (el) el.style.overflow = 'auto'; });
  }
  autoHide();
  setTimeout(autoHide, 600); // jei juosta atsiranda su uzlaikymu

  parent.postMessage({ type: 'ww-ready' }, '*');
})();
