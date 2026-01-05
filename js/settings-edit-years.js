/**
 * settings-edit-years.js
 * Gestión de edición inline para configuración de años
 */

(function(){
  document.addEventListener('click', function(e){
    const btn = e.target.closest('.edit-year-btn');
    if (!btn) return;
    
    const tr = btn.closest('tr');
    if (!tr || tr.dataset.editing === '1') return;
    
    tr.dataset.editing = '1';
    tr._orig = {};
    
    const fields = ['year','mon_thu','friday','summer_mon_thu','summer_friday','coffee_minutes','lunch_minutes'];
    
    // Store original HTML
    fields.forEach(k => {
      const td = tr.querySelector('.yc-' + k);
      if (td) tr._orig[k] = td.innerHTML;
    });
    
    // Replace cells with inputs
    function setInput(cls, name, val){
      const td = tr.querySelector('.yc-' + cls);
      if (!td) return;
      td.innerHTML = '<input class="form-control" name="' + name + '" value="' + (val !== null && val !== undefined ? String(val) : '') + '">';
    }
    
    setInput('year', 'yearcfg_year', tr.dataset.year);
    setInput('mon_thu', 'yearcfg_mon_thu', tr.dataset.mon_thu);
    setInput('friday', 'yearcfg_friday', tr.dataset.friday);
    setInput('summer_mon_thu', 'yearcfg_summer_mon_thu', tr.dataset.summer_mon_thu);
    setInput('summer_friday', 'yearcfg_summer_friday', tr.dataset.summer_friday);
    setInput('coffee_minutes', 'yearcfg_coffee_minutes', tr.dataset.coffee_minutes);
    setInput('lunch_minutes', 'yearcfg_lunch_minutes', tr.dataset.lunch_minutes);
    
    // Replace actions with Save/Cancel buttons
    const actionsTd = btn.parentElement;
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
        const td = tr.querySelector('.yc-' + k);
        if (td) td.innerHTML = tr._orig[k] ?? '';
      });
      actionsTd.innerHTML = actionsTd._orig;
      delete tr.dataset.editing;
      delete tr._orig;
      delete actionsTd._orig;
    });
    
    // Save functionality
    saveBtn.addEventListener('click', function(){
      const fd = new FormData();
      fd.append('save_year_config', '1');
      
      const inputs = tr.querySelectorAll('input[name]');
      inputs.forEach(inp => {
        fd.append(inp.name, inp.value);
      });
      
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
          alert('Error al guardar la configuración del año');
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
