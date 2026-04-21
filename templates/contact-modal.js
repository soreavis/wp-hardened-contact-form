/**
 * Contact modal controller — glass lightbox + hardened submission pipeline.
 *
 * INSTALL-TIME PARAMETERISATION:
 *   Replace {SLUG} with the UPPERCASE slug used by setup-rest-route.php
 *   (e.g. `MYBRAND`). That slug matches the `window.{SLUG}` config blob
 *   and the `X-{Slug}-Nonce` custom header.
 *
 * Pairs with:
 *   - templates/contact-modal.blade.php (markup + data-* hooks)
 *   - templates/ContactHandler.php      (REST endpoint)
 *   - templates/validation.json         (shared rules)
 *   - templates/setup-rest-route.php    (wp_head config injection)
 *
 * Invoke from your theme's main JS entry point after DOM ready:
 *   import { initContactModal } from './contact-modal.js';
 *   initContactModal();
 *
 * All triggers use `[data-contact-trigger]` — any number of openers on the
 * page all open the same single modal.
 */

const CONTACT_ENTRANCE_MS = 600;
const CONTACT_EXIT_MS = 400;

const CONFIG = window.{SLUG} || {};
const CONTACT_ENDPOINT = CONFIG.contactUrl || '/wp-json/{slug}/v1/contact';

let VALIDATION = null;
const loadValidation = async () => {
  if (VALIDATION) return VALIDATION;
  try {
    // Adjust the path to wherever validation.json lives inside your theme's build output.
    const url = new URL('/wp-content/themes/{slug}/resources/data/validation.json', window.location.origin);
    const res = await fetch(url, { credentials: 'omit' });
    VALIDATION = await res.json();
  } catch {
    VALIDATION = { email: { maxLength: 120, pattern: '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$' }, message: { minLength: 10, maxLength: 5000 } };
  }
  return VALIDATION;
};

const contactErrorMessage = (code) => ({
  invalid_email: 'Please enter a valid email.',
  message_too_short: 'A bit more, please.',
  message_too_long: 'That’s a novel. Trim it down.',
  rate_limited: 'One moment. Please try again soon.',
  captcha_failed: 'CAPTCHA check failed. Refresh and try again.',
  not_configured: 'Mail isn’t configured yet.',
  network: 'Network wobble. Try again.',
  upstream: 'Signal lost. Try again.',
}[code] || 'Something went wrong. Try again.');

export const initContactModal = () => {
  const modal = document.querySelector('[data-contact-modal]');
  // Multiple triggers: any element with [data-contact-trigger] becomes an opener.
  const triggers = document.querySelectorAll('[data-contact-trigger]');
  if (!modal || triggers.length === 0) return;

  const backdrop = modal.querySelector('[data-contact-backdrop]');
  const pane = modal.querySelector('[data-contact-pane]');
  const closeBtn = modal.querySelector('[data-contact-close]');
  const form = modal.querySelector('[data-contact-form]');
  const errorEl = modal.querySelector('[data-contact-error]');
  const successEl = modal.querySelector('[data-contact-success]');
  const submitBtn = form.querySelector('[data-contact-submit]');
  const emailInput = form.querySelector('input[name="email"]');
  const messageInput = form.querySelector('textarea[name="message"]');
  const interactionsInput = form.querySelector('input[name="_interactions"]');
  const turnstileSlot = form.querySelector('[data-contact-turnstile]');
  const turnstileTokenInput = form.querySelector('input[name="cf_turnstile_token"]');

  // Interaction tracking — real users mousemove/keydown a lot before submitting.
  let interactions = 0;
  const bumpInteraction = () => {
    interactions++;
    if (interactionsInput) interactionsInput.value = String(interactions);
  };
  ['mousemove', 'keydown', 'touchstart', 'pointermove'].forEach((evt) =>
    form.addEventListener(evt, bumpInteraction, { passive: true })
  );

  // Turnstile — render the widget in Managed + interaction-only mode.
  //
  // IMPORTANT: the Cloudflare dashboard widget mode must be "Managed". Don't
  // set size: 'invisible' or size: 'flexible' — they return HTTP 400 from
  // challenges.cloudflare.com. The `interaction-only` appearance keeps the
  // widget invisible unless the user needs to solve a challenge.
  let turnstileWidgetId = null;
  const ensureTurnstile = () => {
    if (!CONFIG.turnstileSiteKey || turnstileWidgetId !== null) return;
    const go = () => {
      if (!window.turnstile || !turnstileSlot) {
        window.setTimeout(go, 250);
        return;
      }
      turnstileWidgetId = window.turnstile.render(turnstileSlot, {
        sitekey: CONFIG.turnstileSiteKey,
        appearance: 'interaction-only',
        callback: (token) => { if (turnstileTokenInput) turnstileTokenInput.value = token; },
        'error-callback': () => { if (turnstileTokenInput) turnstileTokenInput.value = ''; },
        'expired-callback': () => { if (turnstileTokenInput) turnstileTokenInput.value = ''; },
      });
    };
    go();
  };

  // Resolves when a Turnstile token is available (or immediately if Turnstile
  // isn't configured — dev mode). Gives the widget up to ~8s to issue a token;
  // if nothing arrives, rejects so the caller can surface a sane error.
  const getTurnstileToken = () => new Promise((resolve, reject) => {
    if (!CONFIG.turnstileSiteKey) { resolve(''); return; }
    if (turnstileTokenInput?.value) { resolve(turnstileTokenInput.value); return; }
    try {
      if (window.turnstile && turnstileWidgetId !== null) {
        window.turnstile.execute(turnstileWidgetId);
      }
    } catch { /* ignore — execute isn't always available */ }
    const started = Date.now();
    const tick = () => {
      if (turnstileTokenInput?.value) return resolve(turnstileTokenInput.value);
      if (Date.now() - started > 8000) return reject(new Error('turnstile_timeout'));
      window.setTimeout(tick, 150);
    };
    tick();
  });

  let lastFocused = null;
  let isOpen = false;

  const showFieldError = (name, msg) => {
    const el = form.querySelector(`[data-field-error="${name}"]`);
    if (!el) return;
    el.textContent = msg;
    el.classList.remove('hidden');
  };
  const clearFieldErrors = () => {
    form.querySelectorAll('[data-field-error]').forEach((el) => {
      el.textContent = '';
      el.classList.add('hidden');
    });
  };

  const validateClient = (data) => {
    clearFieldErrors();
    const v = VALIDATION || {};
    let ok = true;
    const email = (data.email || '').trim();
    const emailRe = new RegExp(v.email?.pattern || '^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$');
    if (!email || !emailRe.test(email) || email.length > (v.email?.maxLength || 120)) {
      showFieldError('email', 'Enter a valid email.');
      ok = false;
    }
    const msg = (data.message || '').trim();
    const minL = v.message?.minLength || 10;
    const maxL = v.message?.maxLength || 5000;
    if (msg.length < minL) {
      showFieldError('message', `Message too short (min ${minL} characters).`);
      ok = false;
    } else if (msg.length > maxL) {
      showFieldError('message', `Message too long (max ${maxL} characters).`);
      ok = false;
    }
    return ok;
  };

  const resetForm = () => {
    form.reset();
    form.classList.remove('hidden');
    successEl.classList.add('hidden');
    errorEl.classList.add('hidden');
    errorEl.textContent = '';
    submitBtn.disabled = false;
    submitBtn.textContent = 'Send';
    clearFieldErrors();
    interactions = 0;
    if (interactionsInput) interactionsInput.value = '0';
    if (turnstileTokenInput) turnstileTokenInput.value = '';
    if (window.turnstile && turnstileWidgetId !== null) {
      try { window.turnstile.reset(turnstileWidgetId); } catch { /* noop */ }
    }
    const ts = form.querySelector('input[name="_ts"]');
    if (ts) ts.value = String(Math.floor(Date.now() / 1000));
  };

  const focusableSelector = 'a[href],button:not([disabled]),input:not([disabled]):not([tabindex="-1"]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

  const trapFocus = (e) => {
    if (e.key !== 'Tab') return;
    const items = [...modal.querySelectorAll(focusableSelector)]
      .filter((el) => el.offsetParent !== null);
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
      e.preventDefault();
      last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
      e.preventDefault();
      first.focus();
    }
  };

  const onKey = (e) => {
    if (e.key === 'Escape') close();
    else trapFocus(e);
  };

  const open = () => {
    if (isOpen) return;
    isOpen = true;
    lastFocused = document.activeElement;
    resetForm();
    ensureTurnstile();
    loadValidation();
    modal.setAttribute('data-open', 'true');
    modal.setAttribute('aria-hidden', 'false');
    // next frame: trigger entrance
    requestAnimationFrame(() => {
      backdrop.style.opacity = '1';
      pane.style.opacity = '1';
      pane.style.transform = 'scale(1) translateY(0)';
    });
    window.setTimeout(() => emailInput?.focus(), CONTACT_ENTRANCE_MS);
    document.addEventListener('keydown', onKey);
  };

  const close = () => {
    if (!isOpen) return;
    isOpen = false;
    backdrop.style.opacity = '0';
    pane.style.opacity = '0';
    pane.style.transform = 'scale(0.96) translateY(8px)';
    window.setTimeout(() => {
      modal.removeAttribute('data-open');
      modal.setAttribute('aria-hidden', 'true');
      lastFocused?.focus?.();
    }, CONTACT_EXIT_MS);
    document.removeEventListener('keydown', onKey);
  };

  triggers.forEach((trigger) => {
    trigger.addEventListener('click', (e) => {
      e.preventDefault();
      open();
    });
  });
  closeBtn.addEventListener('click', close);
  backdrop.addEventListener('click', close);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    errorEl.classList.add('hidden');
    errorEl.textContent = '';

    const payload = Object.fromEntries(new FormData(form).entries());

    // Client-side validation first (shared rules from validation.json).
    await loadValidation();
    if (!validateClient(payload)) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';

    try {
      // Optionally await Turnstile token before POST (uncomment if you want
      // to fail-fast on CAPTCHA timeout instead of letting the server decide):
      // const captchaToken = await getTurnstileToken();
      // if (captchaToken) payload.cf_turnstile_token = captchaToken;

      const res = await fetch(CONTACT_ENDPOINT, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-{Slug}-Nonce': CONFIG.nonce || '',
        },
        body: JSON.stringify(payload),
      });
      const body = await res.json().catch(() => ({}));

      if (res.ok && body.ok) {
        form.classList.add('hidden');
        successEl.classList.remove('hidden');
        window.setTimeout(close, 2200);
      } else {
        errorEl.textContent = contactErrorMessage(body.error);
        errorEl.classList.remove('hidden');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send';
      }
    } catch {
      errorEl.textContent = contactErrorMessage('network');
      errorEl.classList.remove('hidden');
      submitBtn.disabled = false;
      submitBtn.textContent = 'Send';
    }
  });
};
