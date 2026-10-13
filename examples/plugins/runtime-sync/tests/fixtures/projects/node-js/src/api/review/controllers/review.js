'use strict';

const { createCoreController } = require('@strapi/strapi').factories;

module.exports = createCoreController('api::review.review', ({ strapi }) => ({
  async find(ctx) {
    ctx.query = { ...ctx.query, sort: 'createdAt:desc' };
    return super.find(ctx);
  },
}));
