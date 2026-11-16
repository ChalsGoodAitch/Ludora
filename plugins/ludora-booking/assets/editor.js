(function (blocks, element, i18n) {
  'use strict';
  const titles = {search: i18n.__('Room and date search', 'ludora-booking'), booking: i18n.__('Room reservation', 'ludora-booking'), rooms: i18n.__('Room collection', 'ludora-booking'), business: i18n.__('Business details', 'ludora-booking'), extras: i18n.__('Extras collection', 'ludora-booking'), packages: i18n.__('Birthday packages', 'ludora-booking')};
  Object.entries(titles).forEach(([name, title]) => blocks.registerBlockType('ludora/' + name, {
    apiVersion: 3, title: title, icon: 'calendar-alt', category: 'widgets',
    edit: () => element.createElement('div', wp.blockEditor.useBlockProps(), element.createElement('strong', null, title), element.createElement('p', null, i18n.__('Live venue content is rendered on the website. Configure it under Ludora.', 'ludora-booking'))),
    save: () => null
  }));
})(wp.blocks, wp.element, wp.i18n);
