<?php
require_once __DIR__ . '/../../auth.php';
require_login();
$user = current_user();
?>
<h2>Generar informe PDF</h2>
<p>Este plugin genera un informe en PDF con los datos del mes seleccionado.</p>
<form method="get" action="/plugins/pdf_informe/download.php">
    <label>Año:
        <input type="number" name="anio" value="<?php echo date('Y'); ?>" min="2000" max="2100" required>
    </label>
    <label>Mes:
        <select name="mes" required>
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>" <?php if ($m == date('n')) echo 'selected'; ?>>
                    <?php echo sprintf('%02d - ', $m); ?>
                    <?php echo ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'][$m]; ?>
                </option>
            <?php endfor; ?>
        </select>
    </label>
    <button type="submit" class="btn btn-primary">Generar informe PDF</button>
</form>
