/* Progressive interactions for the independent Órbita landing. No network or storage. */
(function () {
  'use strict';

  function exampleFor(rate) {
    var ticket = 10000;
    var service = Math.round(ticket * rate / 100);
    var total = ticket + service;
    var cost = Math.round(total * 3 / 100);
    return { ticket: ticket, service: service, total: total, cost: cost, margin: service - cost };
  }

  function initExample() {
    var buttons = Array.from(document.querySelectorAll('[data-service-rate]'));
    if (!buttons.length) return;
    var currency = new Intl.NumberFormat('es-AR', {
      style: 'currency', currency: 'ARS', minimumFractionDigits: 0, maximumFractionDigits: 0
    });
    var status = document.querySelector('[data-model-status]');

    function update(rate, announce) {
      if (rate !== 15 && rate !== 10) return;
      var example = exampleFor(rate);
      var values = {
        'buyer-total': currency.format(example.total),
        'service-fee': currency.format(example.service),
        'operating-cost': currency.format(example.cost),
        margin: currency.format(example.margin),
        rate: rate + '%'
      };
      Object.keys(values).forEach(function (name) {
        document.querySelectorAll('[data-example="' + name + '"]').forEach(function (node) {
          node.textContent = values[name];
        });
      });
      buttons.forEach(function (button) {
        button.setAttribute('aria-pressed', String(Number(button.getAttribute('data-service-rate')) === rate));
      });
      if (announce && status) {
        status.textContent = 'Con un servicio del ' + rate + '%: el comprador paga ' +
          currency.format(example.total) + ', el productor recibe ' + currency.format(example.ticket) +
          ', el costo operativo es ' + currency.format(example.cost) + ' y el margen de la venta es ' +
          currency.format(example.margin) + ', antes de impuestos, otros gastos y remuneración del capital.';
      }
    }

    if (status) {
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      status.setAttribute('aria-atomic', 'true');
    }
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        update(Number(button.getAttribute('data-service-rate')), true);
      });
    });
    update(15, false);
    var controls = document.querySelector("[data-model-controls]");
    if (controls) controls.hidden = false;
  }

  function initPartners() {
    var tablist = document.querySelector('[data-partner-tabs]');
    if (!tablist) return;
    var keys = ['capital', 'wallet', 'estrategico'];
    var pairs = keys.map(function (key) {
      return {
        key: key,
        tab: tablist.querySelector('[data-partner-tab="' + key + '"]'),
        panel: document.getElementById('partner-' + key)
      };
    });
    // Incomplete markup stays fully readable instead of hiding a missing tab's content.
    if (pairs.some(function (pair) { return !pair.tab || !pair.panel; })) return;

    function activate(index, moveFocus) {
      pairs.forEach(function (pair, current) {
        var selected = current === index;
        pair.tab.setAttribute('aria-selected', String(selected));
        pair.tab.tabIndex = selected ? 0 : -1;
        pair.panel.hidden = !selected;
      });
      if (moveFocus) pairs[index].tab.focus({ preventScroll: true });
    }

    tablist.setAttribute('role', 'tablist');
    var mobileTabs = window.matchMedia('(max-width: 560px)');
    function orientTabs() {
      tablist.setAttribute('aria-orientation', mobileTabs.matches ? 'vertical' : 'horizontal');
    }
    orientTabs();
    if (mobileTabs.addEventListener) mobileTabs.addEventListener('change', orientTabs);
    pairs.forEach(function (pair, index) {
      pair.tab.id = pair.tab.id || 'tab-' + pair.key;
      pair.tab.setAttribute('role', 'tab');
      pair.tab.setAttribute('aria-controls', pair.panel.id);
      pair.panel.setAttribute('role', 'tabpanel');
      pair.panel.setAttribute('aria-labelledby', pair.tab.id);
      pair.panel.tabIndex = 0;
      pair.tab.addEventListener('click', function () { activate(index, false); });
      pair.tab.addEventListener('keydown', function (event) {
        var next;
        var forward = mobileTabs.matches ? 'ArrowDown' : 'ArrowRight';
        var backward = mobileTabs.matches ? 'ArrowUp' : 'ArrowLeft';
        if (event.key === forward) next = (index + 1) % pairs.length;
        else if (event.key === backward) next = (index - 1 + pairs.length) % pairs.length;
        else if (event.key === 'Home') next = 0;
        else if (event.key === 'End') next = pairs.length - 1;
        else return;
        event.preventDefault();
        activate(next, true);
      });
    });
    activate(0, false);
    tablist.hidden = false;
  }

  function initSectionLinks() {
    var pairs = Array.from(document.querySelectorAll('[data-section-link]')).map(function (link) {
      var href = link.getAttribute('href') || '';
      if (href.charAt(0) !== '#') return null;
      try { return { link: link, section: document.getElementById(decodeURIComponent(href.slice(1))) }; }
      catch (error) { return null; }
    }).filter(function (pair) { return pair && pair.section; });
    if (!pairs.length) return;
    var scheduled = false;
    var previous;
    function update() {
      scheduled = false;
      var active = null;
      pairs.forEach(function (pair) {
        if (pair.section.getBoundingClientRect().top <= window.innerHeight * 0.3) active = pair;
      });
      if (active === previous) return;
      previous = active;
      pairs.forEach(function (pair) {
        if (pair === active) pair.link.setAttribute('aria-current', 'location');
        else pair.link.removeAttribute('aria-current');
      });
    }
    function schedule() {
      if (scheduled) return;
      scheduled = true;
      window.requestAnimationFrame(update);
    }
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule, { passive: true });
    update();
  }

  function ready() {
    initExample();
    initPartners();
    initSectionLinks();
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', ready, { once: true });
  else ready();
}());
