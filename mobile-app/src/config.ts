/**
 * Configuración de la aplicación
 * Cambiar API_URL según tu entorno
 */

const ENV = {
  dev: {
    API_URL: 'http://localhost:8000',
    // Para testing local
  },
  staging: {
    API_URL: 'https://staging.tu-dominio.com',
  },
  prod: {
    API_URL: 'https://tu-dominio.com',
  },
};

// Seleccionar entorno (cambiar según necesites)
const CURRENT_ENV = 'prod'; // Cambiar a 'dev' o 'staging' si es necesario

export const config = ENV[CURRENT_ENV as keyof typeof ENV];
