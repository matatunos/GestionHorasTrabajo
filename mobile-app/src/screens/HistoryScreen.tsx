import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  StyleSheet,
  FlatList,
  ActivityIndicator,
  Alert,
  RefreshControl,
} from 'react-native';
import { useFocusEffect } from '@react-navigation/native';
import { entriesService } from '../services/entriesService';
import moment from 'moment';

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f5',
  },
  item: {
    backgroundColor: '#fff',
    padding: 15,
    marginHorizontal: 10,
    marginVertical: 5,
    borderRadius: 8,
    borderLeftWidth: 4,
    borderLeftColor: '#007AFF',
  },
  date: {
    fontSize: 14,
    color: '#999',
    marginBottom: 8,
  },
  times: {
    fontSize: 16,
    fontWeight: '600',
    color: '#333',
  },
  duration: {
    fontSize: 14,
    color: '#666',
    marginTop: 8,
  },
  emptyText: {
    textAlign: 'center',
    marginTop: 50,
    fontSize: 16,
    color: '#999',
  },
  loadingContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
  },
});

export default function HistoryScreen() {
  const [entries, setEntries] = useState<any[]>([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  useFocusEffect(
    React.useCallback(() => {
      loadEntries();
    }, [])
  );

  const loadEntries = async () => {
    try {
      setLoading(true);
      const data = await entriesService.getAllEntries(50, 0);
      setEntries(data);
    } catch (error: any) {
      Alert.alert('Error', error.message);
    } finally {
      setLoading(false);
    }
  };

  const onRefresh = async () => {
    setRefreshing(true);
    try {
      await loadEntries();
    } finally {
      setRefreshing(false);
    }
  };

  const calculateDuration = (start: string | null, end: string | null) => {
    if (!start || !end) return 'En curso...';
    const startTime = moment(start, 'HH:mm:ss');
    const endTime = moment(end, 'HH:mm:ss');
    const diff = endTime.diff(startTime, 'minutes');
    const hours = Math.floor(diff / 60);
    const minutes = diff % 60;
    return `${hours}h ${minutes}m`;
  };

  const renderItem = ({ item }: { item: any }) => (
    <View style={styles.item}>
      <Text style={styles.date}>{moment(item.date).format('dddd, DD [de] MMMM')}</Text>
      <Text style={styles.times}>
        {moment(item.start, 'HH:mm:ss').format('HH:mm')} -{' '}
        {item.end ? moment(item.end, 'HH:mm:ss').format('HH:mm') : '--:--'}
      </Text>
      <Text style={styles.duration}>{calculateDuration(item.start, item.end)}</Text>
    </View>
  );

  if (loading) {
    return (
      <View style={styles.loadingContainer}>
        <ActivityIndicator size="large" color="#007AFF" />
      </View>
    );
  }

  return (
    <FlatList
      data={entries}
      renderItem={renderItem}
      keyExtractor={(item) => item.id.toString()}
      refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} />}
      ListEmptyComponent={<Text style={styles.emptyText}>No hay registros</Text>}
      contentContainerStyle={entries.length === 0 ? { flex: 1 } : undefined}
      style={styles.container}
    />
  );
}
