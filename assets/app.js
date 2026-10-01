/* WebWatch kliento logika */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf"]') || {}).content || '';
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function api(action, data) {
    return fetch('api.php?action=' + encodeURIComponent(action), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF': csrf },
      body: JSON.stringify(data || {})
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, error: 'Serverio klaida (' + r.status + ')' }; });
    }).catch(function () { return { ok: false, error: 'Nėra ryšio' }; });
  }

  function toast(msg, type) {
    var t = document.createElement('div');
    t.className = 'toast ' + (type || '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('show'); }, 10);
    setTimeout(function () { t.classList.remove('show'); setTimeout(function () { t.remove(); }, 300); }, 3500);
  }

  function busy(btn, on, text) {
    if (!btn) return;
    if (on) { btn.dataset.label = btn.textContent; btn.textContent = text || '…'; btn.disabled = true; }
    else { btn.textContent = btn.dataset.label || btn.textContent; btn.disabled = false; }
  }

  /* ---------------- Service worker + push ---------------- */

  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
  var pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
  var swReg = null;

  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (reg) {
      swReg = reg;
      updatePushUI();
    }).catch(function () { updatePushUI(); });
  } else {
    updatePushUI();
  }

  function urlB64ToUint8(b64) {
    var pad = '='.repeat((4 - b64.length % 4) % 4);
    var raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
    var arr = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
    return arr;
  }

  function deviceLabel() {
    var ua = navigator.userAgent;
    var dev = /iPhone/.test(ua) ? 'iPhone' : /iPad/.test(ua) ? 'iPad' : /Android/.test(ua) ? 'Android' : /Mac/.test(ua) ? 'Mac' : /Windows/.test(ua) ? 'Windows' : 'Įrenginys';
    var br = /Edg\//.test(ua) ? 'Edge' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : '';
    return dev + (br ? ' · ' + br : '') + (standalone ? ' (programėlė)' : '');
  }

  function currentSub() {
    if (!swReg || !swReg.pushManager) return Promise.resolve(null);
    return swReg.pushManager.getSubscription();
  }

  function updatePushUI() {
    var status = $('#push-status');
    var en = $('#push-enable');
    var dis = $('#push-disable');
    var hint = $('#push-hint');

    if (hint && isIOS && !standalone) {
      hint.innerHTML = '<div class="flash warn">📲 Norėdami gauti pranešimus iPhone, pridėkite WebWatch į pradžios ekraną: <b>Bendrinti → Pridėti prie pradžios ekrano</b>, tada atidarykite iš ten. <a href="?view=settings#push">Daugiau →</a></div>';
    }
    if (!status) return;

    if (!pushSupported) {
      status.innerHTML = isIOS && !standalone
        ? '⚠️ iPhone push pranešimai veikia tik atidarius WebWatch <b>iš pradžios ekrano</b>. Žr. instrukciją žemiau.'
        : '⚠️ Ši naršyklė nepalaiko push pranešimų – bus naudojamas el. paštas.';
      return;
    }
    if (Notification.permission === 'denied') {
      status.innerHTML = '🚫 Pranešimai užblokuoti. Įjunkite juos naršyklės / iPhone nustatymuose (Settings → Notifications → WebWatch).';
      return;
    }
    currentSub().then(function (sub) {
      if (sub) {
        status.innerHTML = '✅ Pranešimai šiame įrenginyje <b>įjungti</b>.';
        if (en) en.hidden = true;
        if (dis) dis.hidden = false;
        // Užtikriname, kad serveris turi naujausią prenumeratą
        api('subscribe', { subscription: sub.toJSON(), label: deviceLabel() });
      } else {
        status.innerHTML = 'Pranešimai šiame įrenginyje <b>išjungti</b>.';
        if (en) en.hidden = false;
        if (dis) dis.hidden = true;
      }
    });
  }

  function enablePush(btn) {
    if (!swReg) { toast('Service worker neužsiregistravo. Ar naudojate HTTPS?', 'err'); return; }
    busy(btn, true, 'Įjungiama…');
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('Leidimas nesuteiktas');
      return fetchVapid().then(function (k) {
        return swReg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(k) });
      });
    }).then(function (sub) {
      return api('subscribe', { subscription: sub.toJSON(), label: deviceLabel() });
    }).then(function (r) {
      busy(btn, false);
      if (r.ok) { toast('Pranešimai įjungti ✅', 'ok'); updatePushUI(); setTimeout(function () { location.reload(); }, 800); }
      else toast(r.error || 'Klaida', 'err');
    }).catch(function (e) {
      busy(btn, false);
      toast(e.message || String(e), 'err');
    });
  }

  function fetchVapid() {
    var el = $('meta[name="vapid"]');
    return el && el.content ? Promise.resolve(el.content) : Promise.reject(new Error('Nėra VAPID rakto'));
  }

  function disablePush(btn) {
    busy(btn, true);
    currentSub().then(function (sub) {
      if (!sub) return;
      var ep = sub.endpoint;
      return sub.unsubscribe().then(function () { return api('unsubscribe', { endpoint: ep }); });
    }).then(function () { busy(btn, false); location.reload(); });
  }

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('button, a') : null;
    if (!t) return;

    if (t.id === 'push-enable') { e.preventDefault(); enablePush(t); }
    else if (t.id === 'push-disable') { e.preventDefault(); disablePush(t); }
    else if (t.id === 'push-test') {
      e.preventDefault();
      busy(t, true, 'Siunčiama…');
      api('test_push').then(function (r) {
        busy(t, false);
        toast(r.ok ? 'Išsiųsta į ' + r.sent + ' įreng. iš ' + r.total : (r.error || 'Klaida'), r.ok ? 'ok' : 'err');
      });
    } else if (t.id === 'email-test') {
      e.preventDefault();
      busy(t, true, 'Siunčiama…');
      api('test_email').then(function (r) {
        busy(t, false);
        toast(r.ok ? 'Laiškas išsiųstas – patikrinkite paštą (ir šlamštą)' : (r.error || 'Klaida'), r.ok ? 'ok' : 'err');
      });
    } else if (t.dataset.checkNow) {
      e.preventDefault();
      busy(t, true, 'Tikrinama…');
      api('check_now', { id: +t.dataset.checkNow }).then(function (r) {
        busy(t, false);
        if (!r.ok) toast(r.error || 'Klaida', 'err');
        else toast(r.changed ? 'Rastas pokytis! 🔔' : (r.first ? 'Pradinė būsena užfiksuota' : 'Pokyčių nėra'), 'ok');
        setTimeout(function () { location.reload(); }, 1200);
      });
    } else if (t.dataset.toggle) {
      e.preventDefault();
      api('toggle', { id: +t.dataset.toggle }).then(function () { location.reload(); });
    } else if (t.hasAttribute('data-check-all')) {
      e.preventDefault();
      checkAll(t);
    }
  });

  function checkAll(btn) {
    var cards = $$('[data-watch-id]').filter(function (c) { return c.dataset.active === '1'; });
    var i = 0, changed = 0;
    busy(btn, true, 'Tikrinama 0/' + cards.length);
    (function next() {
      if (i >= cards.length) {
        busy(btn, false);
        toast(changed ? 'Pokyčių rasta: ' + changed : 'Pokyčių nėra', 'ok');
        setTimeout(function () { location.reload(); }, 1000);
        return;
      }
      var card = cards[i++];
      card.classList.add('checking');
      btn.textContent = 'Tikrinama ' + i + '/' + cards.length;
      api('check_now', { id: +card.dataset.watchId }).then(function (r) {
        card.classList.remove('checking');
        if (r.changed) changed++;
        var c = card.querySelector('.wc-check');
        if (c) c.textContent = r.ok ? (r.changed ? 'PASIKEITĖ!' : 'Tikrinta: ką tik') : '⚠️ ' + (r.error || 'klaida');
        next();
      });
    })();
  }

  /* ---------------- Redagavimo forma ---------------- */

  var form = $('#watch-form');
  if (form) {
    var syncVisibility = function () {
      var scope = (form.querySelector('input[name="scope"]:checked') || {}).value;
      var mode = (form.querySelector('input[name="compare_mode"]:checked') || {}).value;
      $$('[data-show-scope]').forEach(function (el) { el.hidden = el.dataset.showScope !== scope; });
      $$('[data-show-mode]').forEach(function (el) { el.hidden = el.dataset.showMode.split(' ').indexOf(mode) < 0; });
      // Privalomi laukai tik tada, kai jie matomi (kitaip naršyklė neleistų išsaugoti)
      $('#f-selector').required = scope === 'element';
      $('#f-keyword').required = mode === 'keyword_appear' || mode === 'keyword_disappear';
    };
    form.addEventListener('change', syncVisibility);
    $('#f-url').addEventListener('change', function () { $('#f-auto-name').value = ''; });
    syncVisibility();

    var thr = $('#f-threshold');
    if (thr) thr.addEventListener('input', function () { $('#thr-val').textContent = thr.value; });

    $('#test-extract').addEventListener('click', function () {
      var btn = this;
      var box = $('#test-result');
      var scope = form.querySelector('input[name="scope"]:checked').value;
      busy(btn, true, 'Kraunama…');
      api('test_extract', {
        url: normUrl($('#f-url').value),
        headers: $('#f-headers').value,
        user_agent: $('#f-ua').value,
        render_js: $('#f-render-js').checked,
        check_from: (form.querySelector('select[name="check_from"]') || {}).value || '',
        selector: scope === 'element' ? $('#f-selector').value : '',
        compare_mode: form.querySelector('input[name="compare_mode"]:checked').value,
        keyword: $('#f-keyword').value,
        keyword_all: !!(form.querySelector('input[name="keyword_all"]') || {}).checked,
        ignore_numbers: $('#f-ignore-numbers').checked,
        ignore_regex: $('#f-ignore-regex').value,
        extract_regex: (form.querySelector('input[name="extract_regex"]') || {}).value || ''
      }).then(function (r) {
        busy(btn, false);
        box.hidden = false;
        box.innerHTML = '';
        if (!r.ok) {
          box.innerHTML = '<div class="flash err"></div>';
          box.firstChild.textContent = r.error || 'Klaida';
          return;
        }
        var head = document.createElement('div');
        head.className = 'tr-head';
        head.textContent = 'Rasta elementų: ' + r.count + ' · ' + r.length + ' simb.' + (r.info ? ' · ' + r.info : '') + (r.via ? ' · gauta: ' + r.via : '');
        var pre = document.createElement('pre');
        pre.textContent = r.content || '(tuščia)';
        box.appendChild(head);
        box.appendChild(pre);
        if (r.title) { $('#f-name').placeholder = r.title; $('#f-auto-name').value = r.title; }
        if (/JavaScript|enable js|įjunkite/i.test(r.content) && r.length < 400) {
          var w = document.createElement('div');
          w.className = 'flash warn';
          w.textContent = 'Panašu, kad puslapis turinį krauna per JavaScript – WebWatch mato tik serverio pateiktą HTML.';
          box.appendChild(w);
        }
      });
    });

    initPicker();

    // „Copy as cURL“ iš naršyklės -> slapukai, prisijungimo antraštės ir naršyklės tipas
    var curlBox = $('#curl-paste');
    if (curlBox) curlBox.addEventListener('input', function () {
      var txt = curlBox.value;
      if (!/curl\s/i.test(txt)) return;
      var keep = /^(cookie|authorization|x-[\w-]+)$/i;
      var lines = [];
      var re = /(?:-H|--header)\s+(?:\$?'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/g, m;
      while ((m = re.exec(txt))) {
        var h = (m[1] !== undefined ? m[1] : m[2]).replace(/\\(.)/g, '$1');
        var i = h.indexOf(':');
        if (i < 1) continue;
        var name = h.slice(0, i).trim(), val = h.slice(i + 1).trim();
        if (/^user-agent$/i.test(name)) { $('#f-ua').value = /iPhone|Android|Mobile/.test(val) ? 'mobile' : 'desktop'; continue; }
        if (keep.test(name) && !/^x-(requested-with|client-data)$/i.test(name)) lines.push(name.replace(/^cookie$/i, 'Cookie') + ': ' + val);
      }
      var b = /(?:-b|--cookie)\s+(?:\$?'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)")/.exec(txt);
      if (b) lines.push('Cookie: ' + (b[1] !== undefined ? b[1] : b[2]));
      var u = /curl\s+(?:\$?'([^']+)'|"([^"]+)"|(\S+))/.exec(txt);
      if (u && !$('#f-url').value) $('#f-url').value = u[1] || u[2] || u[3];
      if (!lines.length) { toast('Nerasta slapukų – ar nukopijavote „Copy as cURL (bash)“?', 'err'); return; }
      $('#f-headers').value = lines.join('\n');
      curlBox.value = '';
      toast('Perkelta: ' + lines.map(function (l) { return l.split(':')[0]; }).join(', ') + ' ✅', 'ok');
    });
  }

  function normUrl(u) {
    u = (u || '').trim();
    if (u && !/^https?:\/\//i.test(u)) u = 'https://' + u;
    return u;
  }

  /* ---------------- Elementų parinkiklis ---------------- */

  function initPicker() {
    var picker = $('#picker');
    var frame = $('#picker-frame');
    var info = $('#picker-info');
    var last = null;
    var btns = $$('[data-picker]', picker);

    $('#open-picker').addEventListener('click', function () {
      var url = normUrl($('#f-url').value);
      if (!url) { toast('Pirmiausia įrašykite adresą', 'err'); $('#f-url').focus(); return; }
      $('#f-url').value = url;
      last = null;
      info.textContent = 'Kraunamas puslapis…';
      btns.forEach(function (b) { if (b.dataset.picker !== 'close' && b.dataset.picker !== 'declutter') b.disabled = true; });
      var q = 'preview.php?url=' + encodeURIComponent(url) + '&ua=' + encodeURIComponent($('#f-ua').value);
      if ($('#f-render-js').checked) q += '&js=1';
      if ($('#f-headers').value.trim()) q += '&h=' + encodeURIComponent($('#f-headers').value);
      var cf = (form.querySelector('select[name="check_from"]') || {}).value;
      if (cf) q += '&cf=' + encodeURIComponent(cf);
      info.textContent = 'Kraunamas puslapis… (jei per namų kompiuterį – gali užtrukti kelias sekundes)';
      frame.src = q;
      picker.hidden = false;
      document.body.classList.add('noscroll');
    });

    window.addEventListener('message', function (e) {
      if (e.source !== frame.contentWindow) return;
      var d = e.data || {};
      if (d.type === 'ww-ready') {
        info.textContent = 'Bakstelėkite vietą, kurią norite stebėti.';
      } else if (d.type === 'ww-decluttered') {
        toast(d.count > 0 ? 'Paslėpta juostų/užsklandų: ' + d.count : 'Nerasta ką slėpti', 'ok');
      } else if (d.type === 'ww-select') {
        last = d;
        btns.forEach(function (b) { b.disabled = false; });
        info.innerHTML = '';
        var s = document.createElement('code');
        s.textContent = d.selector;
        var c = document.createElement('div');
        c.className = 'pi-count';
        c.textContent = (d.count > 1 ? 'Pažymėta elementų: ' + d.count : 'Pažymėtas 1 elementas') + (d.similar ? ' (visi panašūs)' : '');
        var t = document.createElement('div');
        t.className = 'pi-text';
        t.textContent = d.text || '(be teksto)';
        info.appendChild(c);
        info.appendChild(s);
        info.appendChild(t);
        var sim = picker.querySelector('[data-picker="similar"]');
        sim.classList.toggle('on', !!d.similar);
      }
    });

    picker.addEventListener('click', function (e) {
      var b = e.target.closest('[data-picker]');
      if (!b) return;
      var cmd = b.dataset.picker;
      if (cmd === 'close') close();
      else if (cmd === 'use' && last) {
        $('#f-selector').value = last.selector;
        var el = form.querySelector('input[name="scope"][value="element"]');
        el.checked = true;
        el.dispatchEvent(new Event('change', { bubbles: true }));
        close();
        $('#test-extract').click(); // iškart parodome, ką matys serveris
        $('#test-extract').scrollIntoView({ behavior: 'smooth', block: 'start' });
      } else if (frame.contentWindow) {
        frame.contentWindow.postMessage({ type: 'ww-cmd', cmd: cmd }, '*');
      }
    });

    function close() {
      picker.hidden = true;
      frame.src = 'about:blank';
      document.body.classList.remove('noscroll');
    }
  }

  /* ---------------- Vaizdinio stebėjimo nuotraukų perjungimas ---------------- */

  document.addEventListener('click', function (e) {
    var btn = e.target.closest ? e.target.closest('.st-btn') : null;
    if (!btn) return;
    var wrap = btn.closest('.shots');
    var img = wrap.querySelector('.shot-img');
    var which = btn.dataset.shot;
    if (img && img.dataset[which]) {
      img.src = img.dataset[which];
      var link = img.closest('a');
      if (link) link.href = img.dataset[which];
    }
    wrap.querySelectorAll('.st-btn').forEach(function (b) { b.classList.toggle('on', b === btn); });
  });

  /* ---------------- Pokyčių skirtumai (kraunami atidarius) ---------------- */

  function loadDiff(d) {
    if (!d.dataset.diff || d.dataset.loaded) return;
    d.dataset.loaded = '1';
    api('diff', { id: +d.dataset.diff }).then(function (r) {
      var box = d.querySelector('.diff');
      if (r.ok) box.innerHTML = r.html; // HTML sugeneruotas serveryje, turinys ištrauktas su h()
      else box.textContent = r.error || 'Klaida';
    });
  }
  $$('details[data-diff]').forEach(function (d) {
    if (d.open) loadDiff(d);
    d.addEventListener('toggle', function () { if (d.open) loadDiff(d); });
  });

  /* ---------------- Paieška ir žymos sąraše ---------------- */

  var search = $('#list-search');
  var activeTag = '';
  function filterList() {
    var q = search ? search.value.trim().toLowerCase() : '';
    $$('.watch-card').forEach(function (c) {
      var okQ = !q || c.dataset.search.indexOf(q) >= 0;
      var okT = !activeTag || c.dataset.tags.split('|').indexOf(activeTag) >= 0;
      c.hidden = !(okQ && okT);
    });
    // Paslepiam tuščių aplankų antraštes
    $$('.folder-head').forEach(function (h) {
      var vis = false, n = h.nextElementSibling;
      while (n && !n.classList.contains('folder-head')) {
        if (n.classList.contains('watch-card') && !n.hidden) { vis = true; break; }
        n = n.nextElementSibling;
      }
      h.hidden = !vis;
    });
  }
  if (search) search.addEventListener('input', filterList);
  $$('#tag-chips .chip').forEach(function (chip) {
    chip.addEventListener('click', function () {
      activeTag = chip.dataset.tag;
      $$('#tag-chips .chip').forEach(function (c) { c.classList.toggle('on', c === chip); });
      filterList();
    });
  });

  /* ---------------- Apsaugos apėjimas ---------------- */

  var prov = $('#scrape-provider');
  if (prov) {
    var syncProv = function () {
      $$('[data-provider]').forEach(function (el) { el.hidden = el.dataset.provider.split(' ').indexOf(prov.value) < 0; });
    };
    prov.addEventListener('change', syncProv);
    syncProv();
  }
  var bt = $('#bypass-test');
  if (bt) bt.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = bt.querySelector('button'), box = $('#bypass-result');
    busy(btn, true, 'Tikrinama… (iki 2 min.)');
    api('bypass_test', { url: normUrl($('#bypass-url').value) }).then(function (r) {
      busy(btn, false);
      box.hidden = false;
      box.innerHTML = '';
      if (!r.ok) { box.textContent = r.error || 'Klaida'; return; }
      r.rows.forEach(function (row) {
        var d = document.createElement('div');
        d.className = 'bt-row ' + (row.ok ? 'ok' : 'bad');
        d.textContent = (row.ok ? '✅ ' : '❌ ') + row.via + (row.status ? ' (HTTP ' + row.status + ')' : '') + ' – ' + row.detail;
        box.appendChild(d);
      });
      var s = document.createElement('div');
      s.className = 'flash ' + (r.works ? 'ok' : 'warn');
      s.textContent = r.works ? 'Veikia: ' + r.works + '. WebWatch šį būdą parinks automatiškai.' : 'Nė vienas būdas nepraėjo. Įjunkite apėjimo paslaugą (ScrapingBee ar kt.).';
      box.appendChild(s);
    });
  });

  /* ---------------- Namų kompiuterių testas ---------------- */

  var at = $('#agent-test');
  if (at) at.addEventListener('submit', function (e) {
    e.preventDefault();
    var btn = at.querySelector('button'), box = $('#agent-result');
    busy(btn, true, 'Siunčiama į kompiuterį…');
    api('agent_test', { url: normUrl($('#agent-url').value) }).then(function (r) {
      busy(btn, false);
      box.hidden = false;
      box.innerHTML = '';
      var s = document.createElement('div');
      s.className = 'flash ' + (r.ok ? 'ok' : 'warn');
      s.textContent = (r.ok ? '✅ Veikia' : '❌ Nepavyko') + (r.agent ? ' (' + r.agent + ')' : '')
        + (r.status ? ' · HTTP ' + r.status : '') + ' · ' + (r.detail || '')
        + (typeof r.online === 'number' ? ' · prisijungę: ' + r.online + '/' + r.total : '');
      box.appendChild(s);
    });
  });

  /* ---------------- Kanalų nustatymai ---------------- */

  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-test-channel], [data-tg-chats]') : null;
    if (!t) return;
    e.preventDefault();
    busy(t, true, 'Palaukite…');
    if (t.hasAttribute('data-tg-chats')) {
      api('tg_chats').then(function (r) {
        busy(t, false);
        if (!r.ok) { toast(r.error, 'err'); return; }
        var ids = Object.keys(r.chats);
        $('#tg-chat').value = ids[0];
        toast(r.saved ? 'Chat ID rastas ir išsaugotas ✅' : 'Rasti keli pokalbiai: ' + ids.map(function (k) { return k + ' (' + r.chats[k] + ')'; }).join(', ') + ' – pasirinkite ir išsaugokite', 'ok');
      });
    } else {
      api('test_channel', { channel: t.dataset.testChannel }).then(function (r) {
        busy(t, false);
        toast(r.ok ? 'Išsiųsta ✅' : (r.error || 'Klaida') + ' (ar išsaugojote nustatymus?)', r.ok ? 'ok' : 'err');
      });
    }
  });

  /* ---------------- Ženkliukas ant programėlės ikonos ---------------- */

  var unseen = +((document.querySelector('meta[name="unseen"]') || {}).content || 0);
  if (csrf && 'setAppBadge' in navigator) {
    try { (unseen > 0 ? navigator.setAppBadge(unseen) : navigator.clearAppBadge()).catch(function () {}); } catch (e) { /* nepalaikoma */ }
  }

  /* ---------------- Atnaujinimas grįžus į programėlę ---------------- */
  // iPhone programėlėje nėra „perkrauti“ mygtuko – sąrašą atnaujiname automatiškai.
  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    var onList = !/[?&]view=(edit|settings)/.test(location.search);
    if (hiddenAt && Date.now() - hiddenAt > 60000 && onList && !$('#picker:not([hidden])')) location.reload();
  });
})();
