/**
 * @file
 * Filtered listing: submits the filters as soon as a dropdown or checkbox
 * changes, so no Apply button is needed. The Apply button stays in the form
 * (Views AJAX listens to its click) and is only hidden when this JS runs —
 * without JS the button is still there.
 */
((Drupal) => {
  /**
   * Run setup once per element. Uses Drupal's once() when it is loaded and a
   * data attribute fallback in Storybook, where core/once is not available.
   */
  function onceElements(id, selector, context) {
    if (typeof once === 'function') {
      return once(id, selector, context);
    }
    const flag = `data-once-${id}`;
    return Array.from(context.querySelectorAll(selector)).filter((el) => {
      if (el.hasAttribute(flag)) {
        return false;
      }
      el.setAttribute(flag, '');
      return true;
    });
  }

  Drupal.behaviors.resourceThemeFilteredListing = {
    attach(context) {
      onceElements('filtered-listing', '.filtered-listing__filters form', context).forEach(
        (form) => {
          const submit = form.querySelector('.form-actions [type="submit"]');
          if (!submit) {
            return;
          }
          form.classList.add('is-auto-submit');
          form.addEventListener('change', (event) => {
            if (event.target.matches('select, input[type="checkbox"], input[type="radio"]')) {
              // A click (not form.submit()) so Views AJAX handles it.
              submit.click();
            }
          });
        },
      );
    },
  };
})(Drupal);
