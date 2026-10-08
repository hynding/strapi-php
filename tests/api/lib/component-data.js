'use strict';

/**
 * Stand-in for upstream's core/src/services/document-service/components, which
 * api-tests/models.js requires directly: the PHP port's Components class, through the bridge.
 */
const CLASS = 'Strapi\\Core\\Services\\DocumentService\\Components';

const ref = () => {
  if (!global.strapi) throw new Error('component-data: no Strapi instance yet');
  return global.strapi.__class(CLASS);
};

module.exports = {
  createComponents: (uid, data) => ref().createComponents(uid, data),
  omitComponentData: (contentType, data) => {
    // sync upstream; contentType here is a bridge value or a plain schema
    const attributes = (contentType && contentType.attributes) || {};
    const out = {};
    for (const [key, value] of Object.entries(data)) {
      const attr = attributes[key];
      if (attr && (attr.type === 'component' || attr.type === 'dynamiczone')) continue;
      out[key] = value;
    }
    return out;
  },
};
