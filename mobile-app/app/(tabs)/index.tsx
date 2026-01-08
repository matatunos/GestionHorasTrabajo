import React, { useState, useEffect } from 'react';
import { View, Text, TouchableOpacity, StyleSheet, ActivityIndicator, Alert } from 'react-native';
import axios from 'axios';
import AsyncStorage from '@react-native-async-storage/async-storage';
import { useRouter } from 'expo-router';

const API_URL = 'https://calendar.favala.es';

export default function DashboardScreen() {
  const router = useRouter();
  const [user, setUser] = useState<any>(null);
  const [today, setToday] = useState<any>(null);
  const [loading, setLoading] = useState(true);
  const [token, setToken] = useState('');

  useEffect(() => {
    loadData();
  }, []);

  const loadData = async () => {
    try {
      const userToken = await AsyncStorage.getItem('userToken');
      if (!userToken) {
        router.replace('/login');
        return;
      }
      setToken(userToken);

      const headers = {
        'Authorization': `Bearer ${userToken}`,
        'X-Requested-With': 'XMLHttpRequest',
      };

      // Get user data
      const userRes = await axios.get(`${API_URL}/api.php/me`, { headers });
      setUser(userRes.data.data || userRes.data);

      // Get today's entries
      const todayRes = await axios.get(`${API_URL}/api.php/entries/today`, { headers });
      setToday(todayRes.data.data?.[0] || todayRes.data);
    } catch (error: any) {
      console.error('Load error:', error);
      Alert.alert('Error', 'No se pudo cargar los datos');
      router.replace('/login');
    } finally {
      setLoading(false);
    }
  };

  const handleCheckIn = async () => {
    try {
      setLoading(true);
      await axios.post(`${API_URL}/api.php/entries/checkin`, {}, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      Alert.alert('✅ Entrada registrada');
      loadData();
    } catch (error: any) {
      console.error('CheckIn error:', error);
      Alert.alert('Error', error.response?.data?.message || 'Error al registrar entrada');
    } finally {
      setLoading(false);
    }
  };

  const handleCheckOut = async () => {
    try {
      setLoading(true);
      await axios.post(`${API_URL}/api.php/entries/checkout`, {}, {
        headers: {
          'Authorization': `Bearer ${token}`,
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      Alert.alert('✅ Salida registrada');
      loadData();
    } catch (error: any) {
      console.error('CheckOut error:', error);
      Alert.alert('Error', error.response?.data?.message || 'Error al registrar salida');
    } finally {
      setLoading(false);
    }
  };

  const handleLogout = async () => {
    await AsyncStorage.removeItem('userToken');
    await AsyncStorage.removeItem('username');
    router.replace('/login');
  };

  if (loading) {
    return (
      <View style={styles.container}>
        <ActivityIndicator size="large" color="#007AFF" />
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.greeting}>Hola, {user?.name || 'Usuario'}! 👋</Text>
      
      <View style={styles.card}>
        <Text style={styles.cardTitle}>Hoy</Text>
        {today?.entrada && (
          <Text style={styles.time}>📍 Entrada: {today.entrada}</Text>
        )}
        {today?.salida ? (
          <Text style={styles.time}>🚪 Salida: {today.salida}</Text>
        ) : (
          <Text style={styles.info}>Sin salida registrada</Text>
        )}
        {today?.horas_trabajadas && (
          <Text style={styles.time}>⏱️ Horas: {today.horas_trabajadas}</Text>
        )}
      </View>

      <View style={styles.buttonContainer}>
        <TouchableOpacity
          style={[styles.button, styles.checkInButton]}
          onPress={handleCheckIn}
          disabled={loading}
        >
          <Text style={styles.buttonText}>✅ Entrada</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.button, styles.checkOutButton]}
          onPress={handleCheckOut}
          disabled={loading}
        >
          <Text style={styles.buttonText}>🚪 Salida</Text>
        </TouchableOpacity>
      </View>

      <TouchableOpacity
        style={[styles.button, styles.logoutButton]}
        onPress={handleLogout}
      >
        <Text style={styles.buttonText}>Logout</Text>
      </TouchableOpacity>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    padding: 20,
    backgroundColor: '#f5f5f5',
    justifyContent: 'flex-start',
    paddingTop: 60,
  },
  greeting: {
    fontSize: 28,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 30,
  },
  card: {
    backgroundColor: '#fff',
    padding: 20,
    borderRadius: 12,
    marginBottom: 30,
    shadowColor: '#000',
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  cardTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 15,
  },
  time: {
    fontSize: 16,
    color: '#666',
    marginBottom: 10,
  },
  info: {
    fontSize: 14,
    color: '#999',
    fontStyle: 'italic',
  },
  buttonContainer: {
    flexDirection: 'row',
    gap: 10,
    marginBottom: 20,
  },
  button: {
    flex: 1,
    padding: 15,
    borderRadius: 8,
    alignItems: 'center',
  },
  checkInButton: {
    backgroundColor: '#34C759',
  },
  checkOutButton: {
    backgroundColor: '#FF3B30',
  },
  logoutButton: {
    backgroundColor: '#007AFF',
  },
  buttonText: {
    color: '#fff',
    fontSize: 14,
    fontWeight: 'bold',
  },
});
