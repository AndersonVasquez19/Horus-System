const fs = require('fs');
const path = require('path');

function getFiles(dir, exts) {
  let results = [];
  const list = fs.readdirSync(dir);
  list.forEach((file) => {
    const full = path.join(dir, file);
    const stat = fs.statSync(full);
    if (stat && stat.isDirectory()) {
      results = results.concat(getFiles(full, exts));
    } else if (exts.some((ext) => file.endsWith(ext))) {
      results.push(full);
    }
  });
  return results;
}

const files = getFiles(path.join(__dirname, '../src'), ['.ts', '.tsx']);
const imageRegex = /['"](\/images\/[^'"]+)['"]/g;
const missing = [];
const found = [];

files.forEach((file) => {
  const content = fs.readFileSync(file, 'utf-8');
  let match;
  while ((match = imageRegex.exec(content)) !== null) {
    const relPath = match[1];
    const diskPath = path.join(__dirname, '../public', relPath);
    if (!fs.existsSync(diskPath)) {
      missing.push({ file: path.relative(path.join(__dirname, '..'), file), image: relPath });
    } else {
      found.push(relPath);
    }
  }
});

console.log(`Verificados ${found.length + missing.length} llamados a imágenes.`);
if (missing.length > 0) {
  console.log('IMÁGENES FALTANTES O CON RUTA INCORRECTA:', JSON.stringify(missing, null, 2));
  process.exit(1);
} else {
  console.log('¡Todas las imágenes existen en el disco en public/images/!');
}
