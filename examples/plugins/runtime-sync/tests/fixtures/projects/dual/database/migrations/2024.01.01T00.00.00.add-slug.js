module.exports = {
  async up(knex) {
    await knex.schema.alterTable('articles', (table) => { table.string('slug'); });
  },
  async down() {},
};
