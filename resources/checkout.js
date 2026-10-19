/*! Payxy PHP SDK - binds Pay buttons to the official Payxy checkout overlay. */
(function (w, d) {
  'use strict';

  // The overlay only reports a UI signal; the callback page verifies server-side, so every
  // outcome except "closed" goes there. Without the official SDK the link simply redirects.
  function open(el) {
    var Sdk = w.Payxy && w.Payxy.Payxy;
    // Payxy's checkout only allows framing by https pages (CSP frame-ancestors), so plain
    // http pages (e.g. local development) redirect instead of showing a blank overlay.
    if (!Sdk || w.location.protocol !== 'https:') {
      return false;
    }
    var finish = function () { w.location.assign(el.getAttribute('data-payxy-complete')); };
    new Sdk({
      accessCode: el.getAttribute('data-payxy-access-code'),
      environment: el.getAttribute('data-payxy-environment'),
      onSuccess: finish,
      onError: finish,
      onClose: function () {}
    }).init();
    return true;
  }

  function bind(root) {
    var buttons = root.querySelectorAll('[data-payxy-checkout][data-payxy-display="popup"]');
    for (var i = 0; i < buttons.length; i++) {
      if (buttons[i].getAttribute('data-payxy-bound') === '1') {
        continue;
      }
      buttons[i].setAttribute('data-payxy-bound', '1');
      buttons[i].addEventListener('click', function (event) {
        if (open(event.currentTarget)) {
          event.preventDefault();
        }
      });
    }
  }

  w.PayxyCheckoutButtons = { bind: bind };
  if (d.readyState === 'loading') {
    d.addEventListener('DOMContentLoaded', function () { bind(d); });
  } else {
    bind(d);
  }
})(window, document);
