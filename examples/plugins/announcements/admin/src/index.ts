/**
 * Admin entry. One React codebase for both backends: strapi-php serves the same upstream admin
 * bundle, so this file is built once (`strapi-plugin build`) and never ported.
 */
import { Bell } from '@strapi/icons';

import { PLUGIN_ID } from './pluginId';

export default {
  register(app: any) {
    app.addMenuLink({
      to: `plugins/${PLUGIN_ID}`,
      icon: Bell,
      intlLabel: { id: `${PLUGIN_ID}.plugin.name`, defaultMessage: 'Announcements' },
      Component: async () => {
        const { App } = await import('./pages/App');
        return App;
      },
    });

    app.registerPlugin({ id: PLUGIN_ID, name: PLUGIN_ID });
  },

  async registerTrads() {
    return [];
  },
};
