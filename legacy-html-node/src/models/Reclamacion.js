const { DataTypes } = require('sequelize');
const sequelize = require('../config/database');

const Reclamacion = sequelize.define('Reclamacion', {
  numero_reclamo:       { type: DataTypes.STRING,  unique: true },
  nombres:              { type: DataTypes.STRING,  allowNull: false },
  apellidos:            { type: DataTypes.STRING,  allowNull: false },
  tipo_doc:             { type: DataTypes.STRING,  allowNull: false },
  num_doc:              { type: DataTypes.STRING,  allowNull: false },
  email:                { type: DataTypes.STRING,  allowNull: false },
  telefono:             { type: DataTypes.STRING,  allowNull: false },
  direccion:            { type: DataTypes.STRING },
  tipo_registro:        { type: DataTypes.ENUM('reclamo', 'queja'), allowNull: false },
  area:                 { type: DataTypes.STRING,  allowNull: false },
  fecha_incidente:      { type: DataTypes.DATEONLY, allowNull: false },
  descripcion_bien:     { type: DataTypes.TEXT,    allowNull: false },
  detalle_reclamo:      { type: DataTypes.TEXT,    allowNull: false },
  acepta_comunicaciones:{ type: DataTypes.BOOLEAN, defaultValue: false },
}, {
  tableName: 'reclamaciones',
  timestamps: true,
});

module.exports = Reclamacion;
