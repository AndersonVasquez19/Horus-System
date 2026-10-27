const { Sequelize } = require('sequelize');
require('./env');

for (const name of ['DB_NAME', 'DB_USER', 'DB_PASS', 'DB_HOST']) {
  if (!process.env[name]) {
    throw new Error(`Falta ${name} en legacy-html-node/.env`);
  }
}

const sequelize = new Sequelize(
  process.env.DB_NAME,
  process.env.DB_USER,
  process.env.DB_PASS,
  {
    host:    process.env.DB_HOST,
    port:    process.env.DB_PORT || 3306,
    dialect: 'mysql',
    logging: false,
  }
);

module.exports = sequelize;
