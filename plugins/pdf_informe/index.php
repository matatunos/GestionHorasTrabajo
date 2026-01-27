<?php
// Plugin: pdf_informe - Generador de informes PDF desde base de datos
// Este archivo sirve como punto de entrada para el menú de plugins
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Generar informe PDF</title>
    <link rel="stylesheet" href="../../styles.css">
</head>
<body>
    <h2>Generador de informe PDF</h2>
    <p>Este plugin genera un informe en PDF con los datos actuales de la base de datos, replicando la estructura del documento de ejemplo.</p>
    <form method="get" action="pdf_informe/download.php">
        <label>Año:
            <input type="number" name="anio" value="<?php echo date('Y'); ?>" min="2000" max="2100" required>
        </label>
        <label>Mes:
            <select name="mes" required>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?php echo $m; ?>" <?php if ($m == date('n')) echo 'selected'; ?>><?php echo sprintf('%02d', $m); ?></option>
                <?php endfor; ?>
            </select>
        </label>
        <button type="submit" class="btn btn-primary">Generar informe PDF</button>
    </form>
    ?>
</body>
</html>
