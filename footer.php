<?php
?>
    <footer class="footer">
      <div class="container small muted">&copy; <?php echo date('Y'); ?> <a href="https://github.com/matatunos/GestionHorasTrabajo" target="_blank" rel="noopener noreferrer">GestionHoras</a></div>
    </footer>
  </div> <!-- .main-content -->
</div> <!-- .app-container -->

<!-- Modal de Sugerencias de Horario -->
<div id="scheduleSuggestionsModal" class="modal-overlay" style="display: none;">
  <div class="modal-dialog" style="max-width: 600px;">
    <div class="modal-header">
      <h2>⚡ Sugerencias de Horario (Experimental)</h2>
      <button class="modal-close" onclick="closeScheduleSuggestions()">✕</button>
    </div>
    <div class="modal-body" id="suggestionsContent">
      <div style="text-align: center; padding: 2rem;">
        <p>Cargando sugerencias...</p>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeScheduleSuggestions()">Cerrar</button>
    </div>
  </div>
</div>

<style>
.modal-overlay {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0,0,0,0.5);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1000;
}

.modal-dialog {
  background: white;
  border-radius: 8px;
  box-shadow: 0 10px 40px rgba(0,0,0,0.3);
  max-height: 80vh;
  overflow-y: auto;
  position: relative;
}

.modal-header {
  padding: 1.5rem;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.modal-header h2 {
  margin: 0;
  font-size: 1.5rem;
}

.modal-close {
  background: none;
  border: none;
  font-size: 1.5rem;
  cursor: pointer;
  color: #64748b;
}

.modal-body {
  padding: 1.5rem;
}

.modal-footer {
  padding: 1.5rem;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: flex-end;
  gap: 1rem;
}

.suggestion-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 1rem;
  margin-bottom: 1rem;
}

.suggestion-card h4 {
  margin: 0 0 0.5rem 0;
  color: #1e293b;
}

.suggestion-times {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 1rem;
  margin-top: 0.5rem;
}

.time-box {
  background: white;
  padding: 0.5rem;
  border-radius: 4px;
  text-align: center;
  border: 1px solid #cbd5e1;
}

.time-box label {
  display: block;
  font-size: 0.75rem;
  color: #64748b;
  margin-bottom: 0.25rem;
}

.time-box input {
  width: 100%;
  padding: 0.5rem;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 0.875rem;
}

.stats-box {
  background: #eff6ff;
  border: 1px solid #bfdbfe;
  border-radius: 6px;
  padding: 1rem;
  margin-bottom: 1.5rem;
}

.stats-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 1rem;
}

.stat-item {
  text-align: center;
}

.stat-value {
  font-size: 1.5rem;
  font-weight: bold;
  color: #1e40af;
}

.stat-label {
  font-size: 0.875rem;
  color: #64748b;
}
</style>

<script>
function openScheduleSuggestions(e) {
  e.preventDefault();
  const modal = document.getElementById('scheduleSuggestionsModal');
  modal.style.display = 'flex';
  
  // Fetch suggestions
  fetch('schedule_suggestions.php')
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        renderSuggestions(data);
      } else {
        document.getElementById('suggestionsContent').innerHTML = 
          '<div style="color: #e11d48; padding: 1rem;">Error: ' + (data.error || 'Desconocido') + '</div>';
      }
    })
    .catch(err => {
      document.getElementById('suggestionsContent').innerHTML = 
        '<div style="color: #e11d48; padding: 1rem;">Error al cargar: ' + err.message + '</div>';
    });
}

function closeScheduleSuggestions() {
  document.getElementById('scheduleSuggestionsModal').style.display = 'none';
}

function renderSuggestions(data) {
  let html = `
    <div class="stats-box" style="margin-bottom: 0.4rem;">
      <div class="stats-grid" style="gap: 0.4rem;">
        <div class="stat-item" style="padding: 0.3rem;">
          <div class="stat-value" style="font-size: 1rem; line-height: 1;">Trab: ${data.worked_this_week}h</div>
        </div>
        <div class="stat-item" style="padding: 0.3rem;">
          <div class="stat-value" style="font-size: 1rem; line-height: 1;">Obj: ${data.target_weekly_hours}h</div>
        </div>
        <div class="stat-item" style="padding: 0.3rem;">
          <div class="stat-value" style="font-size: 1rem; line-height: 1;">Pend: ${data.remaining_hours}h</div>
        </div>
      </div>
    </div>
    
    <p style="color: #64748b; margin: 0.1rem 0 0.2rem 0; font-size: 0.75rem; line-height: 1.1;">${data.message}</p>
    
    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 3px; padding: 0.25rem; margin-bottom: 0.2rem;">
      <label style="display: flex; align-items: center; gap: 0.3rem; margin: 0; cursor: pointer; font-size: 0.75rem;">
        <input type="checkbox" id="forceStartTimeCheckbox" onchange="toggleForceStartTime(event)" style="margin: 0; width: 13px; height: 13px; flex-shrink: 0;">
        <span style="font-weight: 500;">Forzar entrada a 07:30</span>
      </label>
      <small style="color: #666; display: block; margin-left: 1.2rem; font-size: 0.65rem; margin-top: 0.1rem; line-height: 1.1;">Recalcula.</small>
    </div>
  `;
  
  if (data.suggestions.length > 0) {
    html += '<h3 style="margin: 0.2rem 0; font-size: 0.8rem; font-weight: 600;">Sugerencias:</h3>';
    html += '<table style="width: 100%; border-collapse: collapse; font-size: 0.75rem; line-height: 1.2;">';
    html += '<thead><tr style="background: #f1f5f9; border-bottom: 1px solid #cbd5e1;">';
    html += '<th style="padding: 0.15rem 0.3rem; text-align: left; font-weight: 600; font-size: 0.75rem;">Día</th>';
    html += '<th style="padding: 0.15rem 0.3rem; text-align: center; font-weight: 600; font-size: 0.75rem;">Entrada</th>';
    html += '<th style="padding: 0.15rem 0.3rem; text-align: center; font-weight: 600; font-size: 0.75rem;">Salida</th>';
    html += '<th style="padding: 0.15rem 0.3rem; text-align: center; font-weight: 600; font-size: 0.75rem;">Horas</th>';
    html += '</tr></thead>';
    html += '<tbody>';
    
    const meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    data.suggestions.forEach((sug, idx) => {
      const d = new Date(sug.date);
      const fechaES = `${d.getDate()} ${meses[d.getMonth()]}`;
      html += '<tr style="border-bottom: 1px solid #e2e8f0;">';
      html += `<td style="padding: 0.15rem 0.3rem; font-weight: 500; font-size: 0.7rem;">${sug.day_name}<br><small style="color: #64748b; font-size: 0.6rem;">${fechaES}</small></td>`;
      html += `<td style="padding: 0.15rem 0.3rem; text-align: center; font-family: monospace; font-size: 0.7rem;">${sug.start}</td>`;
      html += `<td style="padding: 0.15rem 0.3rem; text-align: center; font-family: monospace; font-size: 0.7rem;">${sug.end}</td>`;
      html += `<td style="padding: 0.15rem 0.3rem; text-align: center; font-weight: 500; font-size: 0.7rem;">${sug.hours}</td>`;
      html += '</tr>';
    });
    
    html += '</tbody></table>';
  } else {
    html += '<p style="color: #059669; margin: 0; font-size: 0.85rem;">✓ Semana completada.</p>';
  }
  
  document.getElementById('suggestionsContent').innerHTML = html;
}

function toggleForceStartTime(event) {
  const isChecked = event.target.checked;
  const suggestionsContent = document.getElementById('suggestionsContent');
  
  // Show loading state
  const originalContent = suggestionsContent.innerHTML;
  suggestionsContent.innerHTML = '<div style="text-align: center; padding: 2rem;"><p>Recalculando sugerencias...</p></div>';
  
  // Build URL with force_start_time parameter if checked
  let url = 'schedule_suggestions.php';
  if (isChecked) {
    url += '?force_start_time=07:30';
  }
  
  // Fetch suggestions with or without forced start time
  fetch(url)
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        renderSuggestions(data);
        // Re-set checkbox state after render
        document.getElementById('forceStartTimeCheckbox').checked = isChecked;
        
        // Show info message if forced
        if (isChecked && data.analysis && data.analysis.forced_start_time) {
          const infoDiv = document.querySelector('[style*="background: #fff3cd"]');
          if (infoDiv) {
            infoDiv.innerHTML += '<div style="color: #856404; margin-top: 0.5rem; font-size: 0.875rem;">✓ Sugerencias recalculadas con entrada forzada a ' + data.analysis.forced_start_time + '</div>';
          }
        }
      } else {
        suggestionsContent.innerHTML = '<div style="color: #e11d48; padding: 1rem;">Error: ' + (data.error || 'Desconocido') + '</div>';
        document.getElementById('forceStartTimeCheckbox').checked = false;
      }
    })
    .catch(err => {
      suggestionsContent.innerHTML = '<div style="color: #e11d48; padding: 1rem;">Error al cargar: ' + err.message + '</div>';
      document.getElementById('forceStartTimeCheckbox').checked = false;
    });
}

// Cerrar modal al hacer click fuera
document.addEventListener('click', function(e) {
  const modal = document.getElementById('scheduleSuggestionsModal');
  if (e.target === modal) {
    closeScheduleSuggestions();
  }
});
</script>

</body>
</html>

