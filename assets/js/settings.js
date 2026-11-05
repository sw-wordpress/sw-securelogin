/* global swslRemoval */
(() => {
 'use strict';
 const form = document.getElementById('swsl-removal-settings');
 const choice = document.getElementById('swsl-delete-data');
 if (!form || !choice) return;
 form.addEventListener('submit', event => {
  if (choice.checked && !window.confirm(swslRemoval.confirm)) {
   event.preventDefault();
   choice.focus();
  }
 });
})();
