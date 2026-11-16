/* Progressive enhancement: guided configuration remains usable without JavaScript. */
(() => {
  document.querySelectorAll('.ludora-configuration').forEach((form) => {
    const advanced = form.querySelector('[name="ludora_advanced"]');
    const update = () => {
      form.querySelectorAll('[name^="ludora_fields["]').forEach((field) => {
        field.disabled = advanced.checked;
      });
    };
    if (advanced) {
      advanced.addEventListener('change', update);
      update();
    }
  });
})();

(() => {
  document.querySelectorAll('.ludora-admin-request').forEach((form) => {
    const room = form.querySelector('[name="room_id"]');
    const mode = form.querySelector('[name="mode"]');
    const shifts = form.querySelector('[name="shift"]');
    const update = () => {
      const selected = shifts.selectedOptions[0];
      shifts.querySelectorAll('optgroup[data-room], option[data-room]').forEach((option) => {
        option.hidden = option.dataset.room !== room.value;
        option.disabled = option.dataset.room !== room.value;
      });
      if (selected && selected.dataset.room && selected.dataset.room !== room.value) shifts.value = '';
      form.querySelectorAll('[data-ludora-mode]').forEach((group) => {
        const visible = group.dataset.ludoraMode === mode.value;
        group.hidden = !visible;
        group.querySelectorAll('input,select').forEach((input) => { input.required = visible; });
      });
    };
    room.addEventListener('change', update);
    mode.addEventListener('change', update);
    update();
  });
})();
