import axios from 'axios';
import { authService } from './authService';
import { config } from '../config';

const API_BASE_URL = config.API_URL;

interface Entry {
  id: number;
  user_id: number;
  date: string;
  start: string | null;
  end: string | null;
  lunch_out?: string | null;
  lunch_in?: string | null;
  coffee_out?: string | null;
  coffee_in?: string | null;
  note?: string;
  absence_type?: string | null;
}

class EntriesService {
  private client = axios.create({
    baseURL: API_BASE_URL,
    timeout: 10000,
  });

  async getTodayEntries(): Promise<Entry[]> {
    try {
      const token = await authService.getToken();
      const response = await this.client.get<{
        ok: boolean;
        data?: Entry[];
        error?: string;
      }>('/api.php/entries/today', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        data: { token },
      });

      if (!response.data.ok) {
        throw new Error(response.data.error || 'Error obteniendo fichajes');
      }

      return response.data.data || [];
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }

  async getAllEntries(limit = 30, offset = 0): Promise<Entry[]> {
    try {
      const token = await authService.getToken();
      const response = await this.client.get<{
        ok: boolean;
        data?: Entry[];
        error?: string;
      }>(`/api.php/entries?limit=${limit}&offset=${offset}`, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        data: { token },
      });

      if (!response.data.ok) {
        throw new Error(response.data.error || 'Error obteniendo historial');
      }

      return response.data.data || [];
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }

  async checkIn(): Promise<Entry> {
    try {
      const token = await authService.getToken();
      const response = await this.client.post<{
        ok: boolean;
        data?: Entry;
        error?: string;
      }>('/api.php/entries/checkin', 
        { token },
        {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          },
        }
      );

      if (!response.data.ok || !response.data.data) {
        throw new Error(response.data.error || 'Error al registrar entrada');
      }

      return response.data.data;
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }

  async checkOut(): Promise<Entry> {
    try {
      const token = await authService.getToken();
      const response = await this.client.post<{
        ok: boolean;
        data?: Entry;
        error?: string;
      }>('/api.php/entries/checkout', 
        { token },
        {
          headers: {
            'X-Requested-With': 'XMLHttpRequest',
          },
        }
      );

      if (!response.data.ok || !response.data.data) {
        throw new Error(response.data.error || 'Error al registrar salida');
      }

      return response.data.data;
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }

  async deleteEntry(date: string): Promise<void> {
    try {
      const token = await authService.getToken();
      const response = await this.client.delete<{
        ok: boolean;
        error?: string;
      }>(`/api.php/entry/${date}`, {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        data: { token },
      });

      if (!response.data.ok) {
        throw new Error(response.data.error || 'Error al eliminar fichaje');
      }
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }
}

export const entriesService = new EntriesService();
