require('dotenv').config();
const app = require('./src/app');
// IMPORTANTE: Esta línea debe estar aquí para que sequelize esté definido
const sequelize = require('./src/config/database'); 

const PORT = process.env.PORT || 3000;

async function iniciar() {
  try {
    // Esto crea las tablas automáticamente
    await sequelize.sync({ alter: true }); 
    console.log('✅ Tablas migradas a horus_db correctamente');

    await sequelize.authenticate();
    console.log('✅ Conexión exitosa a MySQL');

    app.listen(PORT, () => {
      console.log(`🚀 Servidor corriendo en http://localhost:${PORT}`);
    });
  } catch (error) {
    console.error('❌ Error al iniciar el servidor:', error.message);
    // No salgas del proceso para que nodemon siga intentando si corriges algo
  }
}

iniciar();