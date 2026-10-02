require('dotenv').config();

module.exports = {
  development: {
    username: 'root',
    password: '123456789',
    database: 'horus_db',
    host:     'localhost',
    port:     3306,
    dialect:  'mysql',
    logging:  false,
  },
  production: {
    username: process.env.DB_USER,
    password: process.env.DB_PASS,
    database: process.env.DB_NAME,
    host:     process.env.DB_HOST,
    port:     process.env.DB_PORT,
    dialect:  'mysql',
    logging:  false,
  },
};
