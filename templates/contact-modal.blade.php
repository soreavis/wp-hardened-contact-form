@php
  $validation = json_decode(file_get_contents(get_theme_file_path('resources/data/validation.json')), true) ?? [];
  $emailMax = $validation['email']['maxLength'] ?? 120;
  $msgMax = $validation['message']['maxLength'] ?? 5000;
  $msgMin = $validation['message']['minLength'] ?? 10;
@endphp

{{--
  Glass lightbox — contact form.
  Hidden by default; JS toggles the `data-open` attribute to reveal.
  Hardened with: CSRF nonce (X-{Slug}-Nonce), honeypot (`website`), timing (`_ts`),
  interaction count (`_interactions`), Turnstile CAPTCHA (when configured),
  shared validation rules (resources/data/validation.json).

  INSTALL-TIME PARAMETERISATION:
    Replace {SLUG} with the UPPERCASE slug used in setup-rest-route.php's
    window.{SLUG} config injection (e.g. `MYBRAND`). Only the Turnstile
    slot comment needs it.
--}}
<div
  data-contact-modal
  class="fixed inset-0 z-50 hidden items-center justify-center p-4 [&[data-open=true]]:flex"
  role="dialog"
  aria-modal="true"
  aria-labelledby="contact-heading"
  aria-hidden="true"
>
  <div
    data-contact-backdrop
    class="absolute inset-0 bg-black/55 [backdrop-filter:blur(14px)_saturate(1.15)] opacity-0 [transition:opacity_400ms_cubic-bezier(0.22,1,0.36,1)]"
  ></div>

  <div
    data-contact-pane
    class="relative z-10 w-full max-w-[480px] opacity-0 translate-y-2 scale-[0.96] [transition:opacity_600ms_cubic-bezier(0.22,1,0.36,1),transform_600ms_cubic-bezier(0.22,1,0.36,1)]"
  >
    <div class="relative overflow-hidden bg-[rgba(18,20,26,0.5)] [backdrop-filter:blur(28px)_saturate(1.3)] [border:1px_solid_rgba(255,255,255,0.12)] [box-shadow:0_32px_120px_rgba(0,0,0,0.45)] rounded-[2px] p-10 sm:p-12">
      <div class="absolute top-0 left-0 right-0 h-px bg-gold" aria-hidden="true"></div>

      <button
        data-contact-close
        type="button"
        class="absolute top-2 right-2 w-11 h-11 flex items-center justify-center text-white/60 hover:text-white text-2xl leading-none cursor-pointer [transition:color_300ms_cubic-bezier(0.22,1,0.36,1)]"
        aria-label="Close"
      >&times;</button>

      <h2 id="contact-heading" class="font-display font-medium text-[2rem] leading-tight tracking-tight text-white mb-8">
        Write.
      </h2>

      <form data-contact-form class="flex flex-col gap-6" novalidate>
        <input type="hidden" name="_ts" value="{{ time() }}">
        <input type="hidden" name="_interactions" value="0">
        <input type="hidden" name="cf_turnstile_token" value="">

        {{-- Honeypot — real users never fill this. Named "website" (a
             legitimate-looking form field) to avoid easy detection by bots
             trained to skip `_gotcha` / `honeypot` names. --}}
        <label class="sr-only" aria-hidden="true">
          Leave this blank
          <input type="text" name="website" tabindex="-1" autocomplete="off">
        </label>

        <label class="flex flex-col gap-2">
          <span class="text-white/55 text-[11px] uppercase tracking-[0.15em] font-sans">Email</span>
          <input
            type="email"
            name="email"
            required
            autocomplete="email"
            maxlength="{{ $emailMax }}"
            class="bg-transparent border-0 border-b border-white/20 focus:border-gold outline-none text-white py-2 [transition:border-color_400ms_cubic-bezier(0.22,1,0.36,1)]"
          >
          <span data-field-error="email" class="hidden text-[#f4a5a5] text-[11px] font-sans" role="alert" aria-live="polite"></span>
        </label>

        <label class="flex flex-col gap-2">
          <span class="text-white/55 text-[11px] uppercase tracking-[0.15em] font-sans">Message</span>
          <textarea
            name="message"
            rows="4"
            required
            minlength="{{ $msgMin }}"
            maxlength="{{ $msgMax }}"
            class="bg-transparent border-0 border-b border-white/20 focus:border-gold outline-none text-white py-2 resize-none font-sans [transition:border-color_400ms_cubic-bezier(0.22,1,0.36,1)]"
          ></textarea>
          <span data-field-error="message" class="hidden text-[#f4a5a5] text-[11px] font-sans" role="alert" aria-live="polite"></span>
        </label>

        {{-- Turnstile slot — only rendered by JS if window.{SLUG}.turnstileSiteKey is set. --}}
        <div data-contact-turnstile class="flex justify-start"></div>

        <div data-contact-error class="hidden text-[#f4a5a5] text-sm font-sans" role="alert" aria-live="polite"></div>

        <button
          type="submit"
          data-contact-submit
          class="group mt-4 self-start inline-flex items-center gap-3 border border-white/20 hover:border-gold hover:bg-white/[0.03] px-7 py-3 font-sans text-white/80 hover:text-gold uppercase tracking-[0.25em] text-xs cursor-pointer disabled:opacity-40 disabled:cursor-wait [transition:color_400ms_cubic-bezier(0.22,1,0.36,1),border-color_400ms_cubic-bezier(0.22,1,0.36,1),background-color_400ms_cubic-bezier(0.22,1,0.36,1)]"
        >
          <span>Send</span>
          <span aria-hidden="true" class="text-base leading-none [transition:transform_400ms_cubic-bezier(0.22,1,0.36,1)] group-hover:translate-x-1">→</span>
        </button>

        <p class="mt-3 text-[11px] leading-relaxed text-white/50 font-sans">
          By sending, you agree to the processing of your email and message as
          described in the
          <a
            href="#"
            data-legal-trigger="privacy-policy"
            class="text-white/80 hover:text-gold underline underline-offset-2 decoration-white/30 hover:decoration-gold/60 [transition:color_300ms_cubic-bezier(0.22,1,0.36,1),text-decoration-color_300ms_cubic-bezier(0.22,1,0.36,1)]"
          >Privacy Policy</a>.
        </p>
      </form>

      <div data-contact-success class="hidden font-display text-[1.5rem] leading-snug text-gold">
        Received.<br><span class="text-white/70 text-[1rem] font-sans">You&rsquo;ll hear back.</span>
      </div>
    </div>
  </div>
</div>
