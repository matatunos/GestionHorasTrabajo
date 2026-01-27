// Honeycomb Widget for Zabbix 6.0
// Versión inicial: estructura base

class HoneycombWidget {
    constructor(container, options) {
        this.container = container;
        this.options = options;
        this.data = [];
    }

    setData(data) {
        this.data = data;
        this.render();
    }

    render() {
        this.container.innerHTML = '';
        const wrapper = document.createElement('div');
        wrapper.className = 'honeycomb-wrapper';
        // Render hexagons (placeholder)
        this.data.forEach((item, idx) => {
            const hex = document.createElement('div');
            hex.className = 'honeycomb-hex';
            hex.innerText = item.label || idx;
            wrapper.appendChild(hex);
        });
        this.container.appendChild(wrapper);
    }
}

// CSS básico para el honeycomb
const style = document.createElement('style');
style.innerHTML = `
.honeycomb-wrapper {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
  align-items: center;
}
.honeycomb-hex {
  width: 80px;
  height: 70px;
  background: #f6c343;
  clip-path: polygon(25% 6%, 75% 6%, 100% 50%, 75% 94%, 25% 94%, 0% 50%);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: bold;
  color: #333;
  margin: 4px;
  box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
`;
document.head.appendChild(style);

// Export para integración
window.HoneycombWidget = HoneycombWidget;
