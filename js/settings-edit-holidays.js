/**
 * settings-edit-holidays.js
 * Gestión de edición inline para festivos
 */

(function(){
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.edit-holiday-btn');
    if (!btn) return;
    
    const tr = btn.closest('tr');
    if (!tr || tr.dataset.editing === '1') return;
    
    tr.dataset.editing = '1';
    const hid = tr.dataset.hid;
    tr._orig = {};
    
    const fields = ['date', 'annual', 'type', 'label'];
    
    // Store original HTML
    fields.forEach(k => {
      const td = tr.querySelector('.holiday-' + k);
      if (td) tr._orig[k] = td.innerHTML;
    });
    
    const globalTd = tr.querySelector('.holiday-global');
    if (globalTd) tr._orig.global = globalTd.innerHTML;
    
    // Get values from data attributes
    const dateRaw = tr.dataset.date || '';
    const annualRaw = tr.dataset.annual === '1';
    const typeRaw = tr.dataset.type || 'holiday';
    const labelRaw = tr.dataset.label || '';
    
    // Replace cells with inputs
    const dateTd = tr.querySelector('.holiday-date');
    if (dateTd) dateTd.innerHTML = '<input class="form-control" type="date" name="date" value="' + (dateRaw || '') + '">';
    
    const annualTd = tr.querySelector('.holiday-annual');
    if (annualTd) annualTd.innerHTML = '<input type="checkbox" name="annual" ' + (annualRaw ? 'checked' : '') + '>';
    
    const typeTd = tr.querySelector('.holiday-type');
    if (typeTd) {
      typeTd.innerHTML = '<select name="type" class="form-control"><option value="holiday">Festivo</option><option value="vacation">Vacaciones</option><option value="personal">Asuntos propios</option><option value="enfermedad">Enfermedad</option><option value="permiso">Permiso</option></select>';
      typeTd.querySelector('select').value = typeRaw;
    }
    
    const labelTd = tr.querySelector('.holiday-label');
    if (labelTd) labelTd.innerHTML = '<input class="form-control" name="label" value="' + (labelRaw.replace(/"/g, '&quot;')) + '">';
    
    if (globalTd) {
      const checked = (tr.dataset.global === '1') ? 'checked' : '';
      globalTd.innerHTML = '<label class="form-check"><input type="checkbox" name="global" ' + checked + '><span>Global</span></label>';
    }
    
    // Replace actions with Save/Cancel buttons
    const actionsTd = tr.querySelector('.holiday-actions');
    actionsTd._orig = actionsTd.innerHTML;
    actionsTd.innerHTML = '';
    
    const saveBtn = document.createElement('button');
    saveBtn.className = 'btn btn-sm btn-success icon-btn';
    saveBtn.type = 'button';
    saveBtn.title = 'Guardar';
    saveBtn.innerHTML = '<i class="fas fa-save"></i>';
    
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'btn btn-sm btn-secondary icon-btn';
    cancelBtn.type = 'button';
    cancelBtn.title = 'Cancelar';
    cancelBtn.innerHTML = '<i class="fas fa-times"></i>';
    
    actionsTd.appendChild(saveBtn);
    actionsTd.appendChild(cancelBtn);
    
    // Cancel functionality
    cancelBtn.addEventListener('click', function(){
      fields.forEach(k => {
        const td = tr.querySelector('.holiday-' + k);
        if (td) td.innerHTML = tr._orig[k] ?? '';
      });
      const gTd = tr.querySelector('.holiday-global');
      if (gTd) gTd.innerHTML = tr._orig.global ?? '';
      actionsTd.innerHTML = actionsTd._orig;
      delete tr._orig;
      delete tr.dataset.editing;
    });
    
    // Save functionality
    saveBtn.addEventListener('click', function(){
      const fd = new FormData();
      fd.append('holiday_action', 'update');
      fd.append('id', hid);
      
      const dateVal = tr.querySelector('.holiday-date [name="date"]')?.value || '';
      const annualVal = tr.querySelector('.holiday-annual [name="annual"]')?.checked ? '1' : '';
      const typeVal = tr.querySelector('.holiday-type [name="type"]')?.value || 'holiday';
      const labelVal = tr.querySelector('.holiday-label [name="label"]')?.value || '';
      const globalEl = tr.querySelector('.holiday-global [name="global"]');
      const globalVal = globalEl ? (globalEl.checked ? '1' : '0') : '0';
      
      fd.append('date', dateVal);
      fd.append('annual', annualVal);
      fd.append('type', typeVal);
      fd.append('label', labelVal);
      fd.append('global', globalVal);
      
      saveBtn.disabled = true;
      saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
      
      fetch(location.pathname + location.search, {
        method: 'POST',
        body: fd,
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      })
      .then(r => r.json().catch(() => ({ok: false})))
      .then(data => {
        if (data && data.ok) {
          location.reload();
        } else {
          alert('Error al guardar festivo');
          saveBtn.disabled = false;
          saveBtn.innerHTML = '<i class="fas fa-save"></i>';
        }
      })
      .catch(err => {
        console.error(err);
        alert('Error de red');
        saveBtn.disabled = false;
        saveBtn.innerHTML = '<i class="fas fa-save"></i>';
      });
    });
  });
})();
