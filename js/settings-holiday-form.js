/**
 * settings-holiday-form.js
 * Holiday date picker and form submission handling
 */

(function(){
  const monthSel = document.getElementById('hd_month');
  const daySel = document.getElementById('hd_day');
  const hidden = document.getElementById('hd_date');
  const selYear = parseInt(document.querySelector('select[name="holiday_year"]')?.value || new Date().getFullYear(), 10);
  
  if (monthSel && daySel && hidden) {
    // Populate month selector
    for (let m = 1; m <= 12; m++) {
      const v = String(m).padStart(2, '0');
      const o = document.createElement('option');
      o.value = v;
      o.textContent = v;
      monthSel.appendChild(o);
    }
    
    // Function to set available days
    function setDays(n) {
      daySel.innerHTML = '';
      for (let d = 1; d <= n; d++) {
        const v = String(d).padStart(2, '0');
        const o = document.createElement('option');
        o.value = v;
        o.textContent = v;
        daySel.appendChild(o);
      }
    }
    
    setDays(31);
    
    // Update hidden date field
    function updateHidden() {
      hidden.value = selYear + '-' + monthSel.value + '-' + daySel.value;
    }
    
    // Handle month change
    monthSel.addEventListener('change', function() {
      const m = parseInt(this.value, 10);
      const nd = new Date(2000, m, 0).getDate();
      setDays(nd);
      if (daySel.options.length < 1) setDays(31);
      if (+daySel.value > nd) daySel.value = String(nd).padStart(2, '0');
      updateHidden();
    });
    
    // Handle day change
    daySel.addEventListener('change', updateHidden);
    
    // Set current date
    const now = new Date();
    monthSel.value = String(now.getMonth() + 1).padStart(2, '0');
    daySel.value = String(now.getDate()).padStart(2, '0');
    updateHidden();
    
    // Update on form submit
    document.addEventListener('submit', function(e) {
      const f = e.target;
      if (!(f instanceof HTMLFormElement)) return;
      if (f.querySelector('#hd_date')) updateHidden();
    }, true);
  }

  // Refresh holidays list
  async function refreshList() {
    try {
      const res = await fetch(location.pathname + location.search, {
        headers: {'X-Requested-With': 'XMLHttpRequest'}
      });
      const text = await res.text();
      const tmp = document.createElement('div');
      tmp.innerHTML = text;
      const newTable = tmp.querySelector('.table-responsive');
      const cur = document.querySelector('.table-responsive');
      if (newTable && cur) cur.innerHTML = newTable.innerHTML;
    } catch(e) {
      console.error('refreshList error', e);
    }
  }

  // Handle holiday form submissions
  document.addEventListener('submit', function(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    
    const fd = new FormData(form);
    const action = fd.get('holiday_action');
    
    if (action !== 'add' && action !== 'delete') return;
    
    e.preventDefault();
    
    const submitBtn = form.querySelector('[type="submit"]');
    let origText;
    
    if (submitBtn) {
      origText = submitBtn.innerText;
      submitBtn.disabled = true;
      submitBtn.innerText = 'Enviando...';
    }
    
    fetch(location.pathname + location.search, {
      method: 'POST',
      body: fd,
      headers: {'X-Requested-With': 'XMLHttpRequest'}
    })
    .then(async r => {
      const ct = r.headers.get('Content-Type') || '';
      let data;
      
      if (ct.indexOf('application/json') !== -1) {
        data = await r.json();
      } else {
        const text = await r.text();
        try {
          data = JSON.parse(text);
        } catch(err) {
          data = { ok: false, text };
        }
      }
      
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerText = origText;
      }
      
      if (data && data.ok) {
        if (action === 'add') form.reset();
        refreshList();
      } else {
        alert('Error al procesar la solicitud');
      }
    })
    .catch(err => {
      console.error(err);
      if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerText = origText;
      }
      alert('Error de red');
    });
  }, false);
})();
