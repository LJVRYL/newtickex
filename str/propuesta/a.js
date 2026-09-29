(() => {
  const dialog = document.querySelector('.screen-dialog');
  const trigger = document.querySelector('[data-open-screen]');
  if (!dialog || !trigger) return;
  trigger.addEventListener('click', () => dialog.showModal());
  dialog.querySelector('[data-close-screen]').addEventListener('click', () => dialog.close());
  dialog.addEventListener('click', event => {
    if (event.target !== dialog) return;
    const rect = dialog.getBoundingClientRect();
    if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) dialog.close();
  });
  dialog.addEventListener('close', () => trigger.focus({preventScroll:true}));
})();
