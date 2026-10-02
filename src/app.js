const express = require('express');
const cors = require('cors');
const app = express();

app.use(cors({ origin: '*' }));
app.use(express.json());

app.use('/api/contacto',      require('./routes/contactoRoutes'));
app.use('/api/reclamaciones', require('./routes/reclamacionRoutes'));

app.get('/', (req, res) => {
  res.json({ mensaje: '🚀 API Horus Group funcionando' });
});

module.exports = app;
