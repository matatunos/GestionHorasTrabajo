import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  TouchableOpacity,
  StyleSheet,
  ActivityIndicator,
  Alert,
  ScrollView,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { entriesService } from '../services/entriesService';
import moment from 'moment';

const styles = StyleSheet.create({
  container: {
    flex: 1,
    padding: 20,
    backgroundColor: '#f5f5f5',
  },
  card: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 20,
    marginBottom: 20,
    shadowColor: '#000',
    shadowOffset: { width: 0, height: 2 },
    shadowOpacity: 0.1,
    shadowRadius: 3,
    elevation: 3,
  },
  timeText: {
    fontSize: 14,
    color: '#666',
    marginBottom: 5,
  },
  timeValue: {
    fontSize: 32,
    fontWeight: 'bold',
    color: '#333',
  },
  buttonContainer: {
    flexDirection: 'row',
    gap: 10,
    marginTop: 20,
  },
  button: {
    flex: 1,
    padding: 14,
    borderRadius: 8,
    alignItems: 'center',
    justifyContent: 'center',
  },
  checkInButton: {
    backgroundColor: '#34C759',
  },
  checkOutButton: {
    backgroundColor: '#FF3B30',
  },
  buttonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
  disabledButton: {
    opacity: 0.5,
  },
  summaryText: {
    fontSize: 16,
    color: '#666',
    marginTop: 10,
    textAlign: 'center',
  },
  hoursText: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#007AFF',
    marginTop: 10,
  },
});

export default function DashboardScreen() {
  const [entries, setEntries] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [checkinLoading, setCheckinLoading] = useState(false);

  useFocusEffect(
    React.useCallback(() => {
      loadTodayEntries();
    }, [])
  );

  const loadTodayEntries = async () => {
    try {
      setLoading(true);
      const data = await entriesService.getTodayEntries();
      setEntries(data);
    } catch (error: any) {
      Alert.alert('Error', error.message);
    } finally {
      setLoading(false);
    }
  };

  const handleCheckIn = async () => {
    try {
      setCheckinLoading(true);
      await entriesService.checkIn();
      await loadTodayEntries();
      Alert.alert('Éxito', 'Entrada registrada');
    } catch (error: any) {
      Alert.alert('Error', error.message);
    } finally {
      setCheckinLoading(false);
    }
  };

  const handleCheckOut = async () => {
    try {
      setCheckinLoading(true);
      await entriesService.checkOut();
      await loadTodayEntries();
      Alert.alert('Éxito', 'Salida registrada');
    } catch (error: any) {
      Alert.alert('Error', error.message);
    } finally {
      setCheckinLoading(false);
    }
  };

  const latestEntry = entries.length > 0 ? entries[entries.length - 1] : null;
  const hasCheckedIn = latestEntry && latestEntry.start;
  const hasCheckedOut = latestEntry && latestEntry.end;

  if (loading) {
    return (
      <View style={[styles.container, { justifyContent: 'center', alignItems: 'center' }]}>
        <ActivityIndicator size="large" color="#007AFF" />
      </View>
    );
  }

  return (
    <ScrollView style={styles.container}>
      <View style={styles.card}>
        <Text style={styles.timeText}>Entrada</Text>
        <Text style={styles.timeValue}>
          {hasCheckedIn ? moment(latestEntry.start, 'HH:mm:ss').format('HH:mm') : '--:--'}
        </Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.timeText}>Salida</Text>
        <Text style={styles.timeValue}>
          {hasCheckedOut ? moment(latestEntry.end, 'HH:mm:ss').format('HH:mm') : '--:--'}
        </Text>
      </View>

      <View style={styles.card}>
        <Text style={styles.summaryText}>
          {entries.length === 0 ? 'Aún no has registrado nada hoy' : `${entries.length} registro(s) hoy`}
        </Text>
        <Text style={styles.hoursText}>
          {hasCheckedIn && hasCheckedOut
            ? `${moment(latestEntry.end, 'HH:mm:ss').diff(moment(latestEntry.start, 'HH:mm:ss'), 'hours')}h`
            : '--'}
        </Text>
      </View>

      <View style={styles.buttonContainer}>
        <TouchableOpacity
          style={[styles.button, styles.checkInButton, !hasCheckedIn && styles.disabledButton]}
          onPress={handleCheckIn}
          disabled={hasCheckedIn || checkinLoading}
        >
          <Text style={styles.buttonText}>{checkinLoading ? 'Registrando...' : 'Entrada'}</Text>
        </TouchableOpacity>

        <TouchableOpacity
          style={[styles.button, styles.checkOutButton, !hasCheckedOut && styles.disabledButton]}
          onPress={handleCheckOut}
          disabled={!hasCheckedIn || hasCheckedOut || checkinLoading}
        >
          <Text style={styles.buttonText}>{checkinLoading ? 'Registrando...' : 'Salida'}</Text>
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
}
