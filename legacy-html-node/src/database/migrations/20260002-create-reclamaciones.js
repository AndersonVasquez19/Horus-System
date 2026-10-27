'use strict';

module.exports = {
  async up(queryInterface, Sequelize) {
    await queryInterface.createTable('reclamaciones', {
      id:                    { type: Sequelize.INTEGER, autoIncrement: true, primaryKey: true },
      numero_reclamo:        { type: Sequelize.STRING,  unique: true },
      nombres:               { type: Sequelize.STRING,  allowNull: false },
      apellidos:             { type: Sequelize.STRING,  allowNull: false },
      tipo_doc:              { type: Sequelize.STRING,  allowNull: false },
      num_doc:               { type: Sequelize.STRING,  allowNull: false },
      email:                 { type: Sequelize.STRING,  allowNull: false },
      telefono:              { type: Sequelize.STRING,  allowNull: false },
      direccion:             { type: Sequelize.STRING },
      tipo_registro:         { type: Sequelize.ENUM('reclamo', 'queja'), allowNull: false },
      area:                  { type: Sequelize.STRING,  allowNull: false },
      fecha_incidente:       { type: Sequelize.DATEONLY, allowNull: false },
      descripcion_bien:      { type: Sequelize.TEXT,    allowNull: false },
      detalle_reclamo:       { type: Sequelize.TEXT,    allowNull: false },
      acepta_comunicaciones: { type: Sequelize.BOOLEAN, defaultValue: false },
      createdAt:             { type: Sequelize.DATE,    allowNull: false },
      updatedAt:             { type: Sequelize.DATE,    allowNull: false },
    });
  },

  async down(queryInterface) {
    await queryInterface.dropTable('reclamaciones');
  },
};
