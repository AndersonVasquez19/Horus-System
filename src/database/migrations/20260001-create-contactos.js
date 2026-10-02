'use strict';

module.exports = {
  async up(queryInterface, Sequelize) {
    await queryInterface.createTable('contactos', {
      id:        { type: Sequelize.INTEGER, autoIncrement: true, primaryKey: true },
      nombre:    { type: Sequelize.STRING,  allowNull: false },
      email:     { type: Sequelize.STRING,  allowNull: false },
      telefono:  { type: Sequelize.STRING,  allowNull: false },
      asunto:    { type: Sequelize.STRING,  allowNull: false },
      mensaje:   { type: Sequelize.TEXT,    allowNull: false },
      createdAt: { type: Sequelize.DATE,    allowNull: false },
      updatedAt: { type: Sequelize.DATE,    allowNull: false },
    });
  },

  async down(queryInterface) {
    await queryInterface.dropTable('contactos');
  },
};
