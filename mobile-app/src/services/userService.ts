import axios from 'axios';
import { authService } from './authService';
import { config } from '../config';

const API_BASE_URL = config.API_URL;

interface User {
  id: number;
  username: string;
  email: string;
  name: string;
}

class UserService {
  private client = axios.create({
    baseURL: API_BASE_URL,
    timeout: 10000,
  });

  async getCurrentUser(): Promise<User> {
    try {
      const token = await authService.getToken();
      const response = await this.client.get<{
        ok: boolean;
        data?: User;
        error?: string;
      }>('/api.php/me', {
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
        },
        data: { token },
      });

      if (!response.data.ok || !response.data.data) {
        throw new Error(response.data.error || 'Error obteniendo usuario');
      }

      return response.data.data;
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }
}

export const userService = new UserService();
