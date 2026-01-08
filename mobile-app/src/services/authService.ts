import axios from 'axios';
import * as SecureStore from 'expo-secure-store';
import { config } from '../config';

const API_BASE_URL = config.API_URL;

interface LoginResponse {
  ok: boolean;
  token?: string;
  error?: string;
}

interface CheckinResponse {
  ok: boolean;
  entry?: {
    id: number;
    user_id: number;
    date: string;
    entrada: string;
    salida: string | null;
  };
  error?: string;
}

class AuthService {
  private client = axios.create({
    baseURL: API_BASE_URL,
    timeout: 10000,
  });

  async login(username: string, password: string): Promise<string> {
    try {
      const response = await this.client.post<LoginResponse>('/login.php', {
        username,
        password,
      });

      if (!response.data.ok || !response.data.token) {
        throw new Error(response.data.error || 'Login fallido');
      }

      return response.data.token;
    } catch (error: any) {
      throw new Error(error.response?.data?.error || error.message);
    }
  }

  async saveToken(token: string): Promise<void> {
    try {
      await SecureStore.setItemAsync('userToken', token);
    } catch (error) {
      console.error('Error saving token:', error);
      throw error;
    }
  }

  async getToken(): Promise<string | null> {
    try {
      return await SecureStore.getItemAsync('userToken');
    } catch (error) {
      console.error('Error getting token:', error);
      return null;
    }
  }

  async removeToken(): Promise<void> {
    try {
      await SecureStore.deleteItemAsync('userToken');
    } catch (error) {
      console.error('Error removing token:', error);
    }
  }

  getClient() {
    return this.client;
  }
}

export const authService = new AuthService();
