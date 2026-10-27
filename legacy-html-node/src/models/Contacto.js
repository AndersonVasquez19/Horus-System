const { DataTypes } = require('sequelize');
const sequelize = require('../config/database');

const Contacto = sequelize.define('Contacto', {
  nombre:   { type: DataTypes.STRING,  allowNull: false },
  email:    { type: DataTypes.STRING,  allowNull: false },
  telefono: { type: DataTypes.STRING,  allowNull: false },
  asunto:   { type: DataTypes.STRING,  allowNull: false },
  mensaje:  { type: DataTypes.TEXT,    allowNull: false },
}, {
  tableName: 'contactos',
  timestamps: true,
});

module.exports = Contacto;
