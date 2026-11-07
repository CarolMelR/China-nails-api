INSERT INTO servicios
  (codigo_servicio, nombre, descripcion, duracion_minutos, precio, activo)
VALUES
  ('ACRILICO', 'Acrílico', 'Aplicación de uñas acrílicas con selección de largo.', 120, 200.00, 1),
  ('SOFTGEL', 'Soft gel', 'Aplicación de soft gel con selección de largo.', 90, 150.00, 1),
  ('GEL_SEMI', 'Gel semipermanente', 'Incluye uno o dos tonos lisos.', 90, 150.00, 1),
  ('RUBBER_GEL', 'Rubber gel', 'Incluye uno o dos tonos lisos.', 90, 200.00, 1)
ON DUPLICATE KEY UPDATE
  nombre = VALUES(nombre),
  descripcion = VALUES(descripcion),
  duracion_minutos = VALUES(duracion_minutos),
  precio = VALUES(precio),
  activo = VALUES(activo);

UPDATE servicios
SET activo = 0
WHERE codigo_servicio NOT IN ('ACRILICO', 'SOFTGEL', 'GEL_SEMI', 'RUBBER_GEL');
