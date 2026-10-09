'use strict';
// In <head>, before the page is drawn (Part 0.4b). If the page before this one was
// showing the waiting page, this one starts with it, at the same moment of its fade
// and its rally, and app.js fades it out once the page is ready - so a slow change
// of page is one movement, not a cut. The browser's own cross-fade is skipped then:
// it would fade the waiting page into itself, with two shuttles.
try {
  const since = Number(sessionStorage.getItem('crmb-wait'));
  sessionStorage.removeItem('crmb-wait');
  const elapsed = Date.now() - since;
  if (since && elapsed >= 0 && elapsed < 15000) {
    const root = document.documentElement;
    root.classList.add('is-arriving');
    root.dataset.waitSince = String(since);
    root.style.setProperty('--wait-elapsed', elapsed + 'ms');
    addEventListener('pagereveal', event => { event.viewTransition?.skipTransition(); });
  }
} catch (error) { /* no sessionStorage (blocked, private mode): the page simply appears */ }
