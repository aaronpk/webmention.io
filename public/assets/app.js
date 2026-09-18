// Add https:// to URL fields when someone types just a domain.
// https://aaronparecki.com/2018/06/03/3/
document.addEventListener('DOMContentLoaded', function () {
  function addDefaultScheme(input) {
    if (/^(?!https?:).+\..+/.test(input.value)) {
      input.value = 'https://' + input.value;
    }
  }

  document.querySelectorAll('input[type=url]').forEach(function (input) {
    input.addEventListener('blur', function () { addDefaultScheme(input); });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') addDefaultScheme(input);
    });
  });

  // Forms, or particular submit buttons, that do something irreversible ask first.
  document.querySelectorAll('form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var message = (e.submitter && e.submitter.getAttribute('data-confirm')) || form.getAttribute('data-confirm');
      if (message && !window.confirm(message)) e.preventDefault();
    });
  });

  // Bulk moderation: "select all" and the buttons that need a selection.
  document.querySelectorAll('[data-select-all]').forEach(function (all) {
    var form = document.getElementById(all.getAttribute('data-select-all'));
    if (!form) return;
    var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('input.select[form="' + form.id + '"]')); };
    var count = form.querySelector('[data-selected-count]');
    var update = function () {
      var checked = boxes().filter(function (b) { return b.checked; }).length;
      form.querySelectorAll('[data-needs-selection]').forEach(function (button) { button.disabled = checked === 0; });
      if (count) {
        count.hidden = checked === 0;
        count.textContent = checked + ' selected';
      }
      all.checked = checked > 0 && checked === boxes().length;
    };
    all.addEventListener('change', function () {
      boxes().forEach(function (b) { b.checked = all.checked; });
      update();
    });
    boxes().forEach(function (b) { b.addEventListener('change', update); });
    update();
  });

  // Copy buttons next to code snippets.
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var target = document.getElementById(button.getAttribute('data-copy'));
      if (!target || !navigator.clipboard) return;
      navigator.clipboard.writeText(target.textContent.trim()).then(function () {
        var label = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function () { button.textContent = label; }, 1500);
      });
    });
  });
});
