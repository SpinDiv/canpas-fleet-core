/* Canpas Fleet portal: sends forms and action buttons to the canpas/v1 REST API. No dependencies. */
(function () {
  'use strict';
  var cfg = window.CF || {};

  function api(endpoint, body) {
    var headers = {};
    if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
    var opts = { method: 'POST', credentials: 'same-origin', headers: headers };
    if (body instanceof FormData) {
      opts.body = body;
    } else {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body || {});
    }
    return fetch(cfg.rest + endpoint.replace(/^\//, ''), opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (!r.ok) {
          var err = new Error((data && data.message) || 'Something went wrong. Please try again.');
          err.field = data && data.data && data.data.field;
          throw err;
        }
        return data;
      });
    }, function () {
      throw new Error('No connection. Check your internet and try again.');
    });
  }

  function toast(msg) {
    var t = document.createElement('div');
    t.className = 'toast';
    t.setAttribute('role', 'status');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 4500);
  }

  function formBody(form) {
    var fd = new FormData(form);
    form.querySelectorAll('input[type=checkbox][name]').forEach(function (c) { if (!c.checked) fd.set(c.name, '0'); });
    var hasFile = Array.prototype.some.call(form.querySelectorAll('input[type=file]'), function (f) { return f.files.length; });
    if (hasFile) return fd;
    var body = {};
    fd.forEach(function (v, k) { body[k] = v; });
    return body;
  }

  function showError(form, e) {
    var errBox = form.querySelector('.form-error');
    if (errBox) { errBox.textContent = e.message; errBox.hidden = false; }
    if (e.field) {
      var el = form.querySelector('[name="' + e.field + '"]') || form.querySelector('[name^="' + e.field + '["]');
      if (el) { el.setAttribute('aria-invalid', 'true'); el.focus(); }
    }
  }

  function clearErrors(form) {
    form.querySelectorAll('[aria-invalid]').forEach(function (el) { el.removeAttribute('aria-invalid'); });
    var errBox = form.querySelector('.form-error');
    if (errBox) errBox.hidden = true;
  }

  function done(after, message, form, redirect) {
    if (redirect) {
      try { sessionStorage.setItem('cf-toast', message || 'Saved.'); } catch (e) {}
      location.href = redirect;
      return;
    }
    if (after === 'message' && form) {
      form.innerHTML = '';
      var p = document.createElement('div');
      p.className = 'success';
      p.setAttribute('role', 'status');
      p.textContent = message || 'Done.';
      form.appendChild(p);
      return;
    }
    try { sessionStorage.setItem('cf-toast', message || 'Saved.'); } catch (e) {}
    if (after && after.indexOf('redirect:') === 0) {
      location.href = after.slice(9);
    } else {
      location.reload();
    }
  }

  document.addEventListener('submit', function (ev) {
    var form = ev.target;
    if (!form.matches('form[data-endpoint]')) return;
    ev.preventDefault();
    // Signature pads: copy the drawing into their hidden field (empty if nothing was drawn).
    form.querySelectorAll('canvas[data-signature]').forEach(function (c) {
      var input = form.querySelector('input[name="' + c.dataset.signature + '"]');
      if (input) input.value = c.dataset.drawn === '1' ? c.toDataURL('image/png') : '';
    });
    var btn = ev.submitter && ev.submitter.matches('[type=submit]') ? ev.submitter : form.querySelector('[type=submit]');
    clearErrors(form);
    if (btn) { btn.disabled = true; btn.dataset.label = btn.textContent; btn.textContent = 'Saving…'; }
    api(form.dataset.endpoint, formBody(form)).then(function (data) {
      done(form.dataset.after, data.message, form, data.redirect);
    }).catch(function (e) {
      showError(form, e);
    }).finally(function () {
      if (btn && document.body.contains(btn)) { btn.disabled = false; btn.textContent = btn.dataset.label; }
    });
  });

  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-endpoint]');
    if (!b) return;
    var body = {};
    if (b.dataset.confirm && !window.confirm(b.dataset.confirm)) return;
    if (b.dataset.prompt) {
      var reason = window.prompt(b.dataset.prompt);
      if (reason === null) return;
      body.reason = reason;
    }
    b.disabled = true;
    api(b.dataset.endpoint, body).then(function (data) {
      done(b.dataset.after || 'reload', data.message, null, data.redirect);
    }).catch(function (e) {
      b.disabled = false;
      toast(e.message);
    });
  });

  // "Get price": send the form to a quote endpoint and show the breakdown without saving anything.
  document.addEventListener('click', function (ev) {
    var b = ev.target.closest('button[data-quote]');
    if (!b) return;
    var form = b.closest('form');
    var box = form && form.querySelector('.quote-box');
    if (!form || !box) return;
    clearErrors(form);
    b.disabled = true;
    var label = b.textContent;
    b.textContent = 'Working out the price…';
    api(b.dataset.quote, formBody(form)).then(function (data) {
      box.innerHTML = data.html;
      box.hidden = false;
      var book = form.querySelector('[data-needs-quote]');
      if (book) book.disabled = false;
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }).catch(function (e) {
      box.hidden = true;
      showError(form, e);
    }).finally(function () { b.disabled = false; b.textContent = label; });
  });

  // Changing anything after a quote hides the old price until it is worked out again.
  document.addEventListener('input', function (ev) {
    var form = ev.target.closest && ev.target.closest('form[data-endpoint]');
    if (!form) return;
    var box = form.querySelector('.quote-box');
    var book = form.querySelector('[data-needs-quote]');
    if (box && !box.hidden) { box.hidden = true; }
    if (book) book.disabled = true;
  });

  document.addEventListener('click', function (ev) {
    var t = ev.target.closest('[data-toggle-password]');
    if (!t) return;
    var input = document.getElementById(t.getAttribute('aria-controls'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    t.textContent = show ? 'Hide' : 'Show';
    t.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
  });

  // Signature pad: draw with finger, stylus or mouse. The canvas keeps its own resolution;
  // pointer positions are scaled from the displayed size.
  function setupPad(c) {
    var ctx = c.getContext('2d');
    var drawing = false;
    var last = null;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#141D30';
    ctx.lineWidth = 4;
    function pos(ev) {
      var r = c.getBoundingClientRect();
      return { x: (ev.clientX - r.left) * (c.width / r.width), y: (ev.clientY - r.top) * (c.height / r.height) };
    }
    c.addEventListener('pointerdown', function (ev) {
      ev.preventDefault();
      c.setPointerCapture(ev.pointerId);
      drawing = true;
      last = pos(ev);
      ctx.beginPath();
      ctx.arc(last.x, last.y, 2, 0, Math.PI * 2);
      ctx.fillStyle = ctx.strokeStyle;
      ctx.fill();
    });
    c.addEventListener('pointermove', function (ev) {
      if (!drawing) return;
      var p = pos(ev);
      ctx.beginPath();
      ctx.moveTo(last.x, last.y);
      ctx.lineTo(p.x, p.y);
      ctx.stroke();
      last = p;
      c.dataset.drawn = '1';
    });
    ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (t) {
      c.addEventListener(t, function () { drawing = false; });
    });
    var clear = c.parentNode.querySelector('[data-sig-clear]');
    if (clear) clear.addEventListener('click', function () {
      ctx.clearRect(0, 0, c.width, c.height);
      c.dataset.drawn = '';
    });
  }
  document.querySelectorAll('canvas[data-signature]').forEach(setupPad);

  try {
    var msg = sessionStorage.getItem('cf-toast');
    if (msg) { sessionStorage.removeItem('cf-toast'); toast(msg); }
  } catch (e) {}
})();
