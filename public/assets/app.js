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

  // Forms that do something irreversible ask first.
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (!window.confirm(form.getAttribute('data-confirm'))) e.preventDefault();
    });
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
