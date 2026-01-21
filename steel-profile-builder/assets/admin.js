(function(){
  function parse(json, fallback){
    try {
      const v = JSON.parse(json);
      return Array.isArray(v) ? v : fallback;
    } catch(e){
      return fallback;
    }
  }

  function initDims(){
    const table = document.getElementById('spb-dims-table');
    const hidden = document.getElementById('spb_dims_json');
    const addBtn = document.getElementById('spb-add-dim');
    if (!table || !hidden || !addBtn) return;

    let rows = parse(hidden.value || '[]', []);

    function render(){
      const tbody = table.querySelector('tbody');
      tbody.innerHTML = '';
      rows.forEach((r, i)=>{
        const tr = document.createElement('tr');
        tr.innerHTML = `
          <td><input value="${r.key||''}" class="spb-key"></td>
          <td>
            <select class="spb-type">
              <option value="length" ${r.type==='length'?'selected':''}>pikkus</option>
              <option value="angle" ${r.type==='angle'?'selected':''}>nurk</option>
            </select>
          </td>
          <td><input value="${r.label||''}" class="spb-label"></td>
          <td><input type="number" value="${r.min??''}" class="spb-min"></td>
          <td><input type="number" value="${r.max??''}" class="spb-max"></td>
          <td><input type="number" value="${r.def??''}" class="spb-def"></td>
          <td>
            <select class="spb-dir" ${r.type!=='angle'?'disabled':''}>
              <option value="L" ${r.dir!=='R'?'selected':''}>L</option>
              <option value="R" ${r.dir==='R'?'selected':''}>R</option>
            </select>
          </td>
          <td><button class="button spb-del">✕</button></td>
        `;
        tbody.appendChild(tr);

        tr.querySelector('.spb-del').onclick = ()=>{
          rows.splice(i,1);
          sync();
          render();
        };
      });
      sync();
    }

    function sync(){
      const out = [];
      table.querySelectorAll('tbody tr').forEach(tr=>{
        out.push({
          key: tr.querySelector('.spb-key').value.trim(),
          type: tr.querySelector('.spb-type').value,
          label: tr.querySelector('.spb-label').value.trim(),
          min: tr.querySelector('.spb-min').value || null,
          max: tr.querySelector('.spb-max').value || null,
          def: tr.querySelector('.spb-def').value || null,
          dir: tr.querySelector('.spb-dir').value
        });
      });
      hidden.value = JSON.stringify(out);
    }

    addBtn.onclick = ()=>{
      rows.push({key:'',type:'length',label:'',min:null,max:null,def:null,dir:'L'});
      render();
    };

    table.addEventListener('input', sync);
    render();
  }

  document.addEventListener('DOMContentLoaded', initDims);
})();
