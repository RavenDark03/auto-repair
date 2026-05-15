(function () {
  'use strict';

  var list = document.getElementById('branding-services-list');
  var template = document.getElementById('branding-service-row-template');
  var addBtn = document.querySelector('[data-add-branding-service]');

  if (!list || !template || !addBtn) {
    return;
  }

  function nextIndex() {
    var inputs = list.querySelectorAll('input[name^="service_name"]');
    var max = -1;
    for (var i = 0; i < inputs.length; i++) {
      var m = /service_name\[(\d+)\]/.exec(inputs[i].name);
      if (m) {
        var n = parseInt(m[1], 10);
        if (!isNaN(n) && n > max) max = n;
      }
    }
    return max + 1;
  }

  function bindRemove(row) {
    var btn = row.querySelector('[data-remove-branding-service]');
    if (!btn) return;
    btn.addEventListener('click', function () {
      var rows = list.querySelectorAll('.branding-service-row');
      if (rows.length <= 1) {
        row.querySelectorAll('input, textarea').forEach(function (el) {
          el.value = '';
        });
        return;
      }
      row.remove();
    });
  }

  list.querySelectorAll('.branding-service-row').forEach(bindRemove);

  addBtn.addEventListener('click', function () {
    var rows = list.querySelectorAll('.branding-service-row');
    if (rows.length >= 30) {
      return;
    }
    var ix = nextIndex();
    var html = template.innerHTML.replace(/__INDEX__/g, String(ix));
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    var row = wrap.firstElementChild;
    if (!row) return;
    list.appendChild(row);
    bindRemove(row);
  });
})();
