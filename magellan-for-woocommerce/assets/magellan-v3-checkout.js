(function (w,d) {
  'use strict';
  if (!w.MagellanV3) return;
  var start=false;
  function begin() { if (!start) { w.MagellanV3.checkout('started',null,'woocommerce-checkout-1'); start=true; } }
  w.addEventListener('magellan:ready',begin);
  // Core exposes no shopper data; calling before consent is a harmless no-op.
  w.MagellanV3.whenReady(begin);
  d.addEventListener('submit',function (event) { if (event.target.matches('form.checkout')) w.MagellanV3.checkout('submitted',null,'classic-1'); });
  if (w.jQuery) {
    w.jQuery(d.body).on('checkout_error.magellan',function () { w.MagellanV3.checkout('unknown','unknown','classic-1'); });
  }
  // Store API response observes real outcomes; never parses error messages or form values.
  if (w.wp && w.wp.apiFetch && typeof w.wp.apiFetch.use === 'function') {
    w.wp.apiFetch.use(function (options,next) {
      var route=options.path || options.url || '';
      var checkout=/\/wc\/store\/v[0-9]+\/checkout(?:\?|$)/.test(route);
      if (checkout && String(options.method || 'GET').toUpperCase()==='POST') {
        w.MagellanV3.checkout('submitted',null,'blocks-store-api-1');
        return next(options).catch(function (error) {
          w.MagellanV3.checkout('unknown','unknown','blocks-store-api-1'); throw error;
        });
      }
      return next(options);
    });
  }
})(window,document);
