/**
 * settings-holiday-form.js
 * Holiday form submission handling (compatible with calendar-based picker)
 */

(function(){
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

