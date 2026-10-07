/**
 * Server entry: assembles the plugin module from the shared JSON spec and the hand-written code.
 * Mirrors index.php line for line.
 */
import config from './config';
import contentTypes from './content-types';
import controllers from './controllers';
import routes from './routes';
import services from './services';

export default {
  config,
  contentTypes,
  controllers,
  routes,
  services,
};
