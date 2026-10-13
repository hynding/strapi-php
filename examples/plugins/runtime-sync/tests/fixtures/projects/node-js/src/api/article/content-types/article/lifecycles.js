module.exports = {
  beforeCreate(event) {
    event.params.data.title = event.params.data.title.trim();
  },
};
