const express = require('express');
const router = express.Router();
const { registrarReclamo } = require('../controllers/reclamacionController');

router.post('/', registrarReclamo);

module.exports = router;
