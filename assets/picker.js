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

  window.addEventListener('message', function (e) {
    var d = e.data || {};
    if (d.type !== 'ww-cmd' || !current) return;
    if (d.cmd === 'parent') {
      var p = current.parentElement;
      while (p && (p.localName === 'tbody' || p.localName === 'thead')) p = p.parentElement;
      if (p && p.localName !== 'html') select(p);
    } else if (d.cmd === 'similar') {
      similar = !similar;
      select(current);
    }
  });

  parent.postMessage({ type: 'ww-ready' }, '*');
})();
