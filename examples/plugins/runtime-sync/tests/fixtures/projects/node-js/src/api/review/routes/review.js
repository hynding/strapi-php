module.exports = {
  routes: [
    { method: 'GET', path: '/reviews/latest', handler: 'review.find', config: { policies: ['is-owner'] } },
  ],
};
