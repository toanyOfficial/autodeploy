document.querySelectorAll('[data-edit-project]').forEach((button) => {
  button.addEventListener('click', () => {
    const card = button.closest('[data-project-card]');
    const form = card.querySelector('[data-edit-form]');
    form.hidden = !form.hidden;
  });
});
