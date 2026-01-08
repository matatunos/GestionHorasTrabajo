# 🚀 Usar Expo Snack (Online - Más rápido)

Si la compilación local está lenta, usa **Expo Snack** que es online:

## Opción 1: Snack Online (5 segundos de carga)

1. Ve a: https://snack.expo.dev

2. Pega este código en `App.js`:

```javascript
import React, { useState } from 'react';
import { View, Text, TouchableOpacity, TextInput, StyleSheet, Alert } from 'react-native';
import axios from 'axios';

const API_URL = 'https://calendar.favala.es';

export default function App() {
  const [username, setUsername] = useState('');
  const [password, setPassword] = useState('');
  const [token, setToken] = useState(null);
  const [user, setUser] = useState(null);
  const [loading, setLoading] = useState(false);

  const handleLogin = async () => {
    if (!username || !password) {
      Alert.alert('Error', 'Completa usuario y contraseña');
      return;
    }

    setLoading(true);
    try {
      const response = await axios.post(`${API_URL}/api.php/login`, {
        username,
        password,
      });

      const receivedToken = response.data.token;
      setToken(receivedToken);

      // Obtener datos del usuario
      const userResponse = await axios.get(`${API_URL}/api.php/me`, {
        headers: { Authorization: `Bearer ${receivedToken}` },
      });

      setUser(userResponse.data);
      Alert.alert('Éxito', `¡Hola ${userResponse.data.name}!`);
    } catch (error) {
      Alert.alert('Error', error.response?.data?.message || 'Login fallido');
    } finally {
      setLoading(false);
    }
  };

  if (token) {
    return (
      <View style={styles.container}>
        <Text style={styles.title}>✅ ¡Conectado!</Text>
        <Text style={styles.text}>Hola: {user?.name}</Text>
        <Text style={styles.text}>Email: {user?.email}</Text>
        <TouchableOpacity
          style={styles.button}
          onPress={() => {
            setToken(null);
            setUser(null);
            setUsername('');
            setPassword('');
          }}
        >
          <Text style={styles.buttonText}>Logout</Text>
        </TouchableOpacity>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <Text style={styles.title}>Gestión Horas</Text>

      <TextInput
        style={styles.input}
        placeholder="Usuario"
        value={username}
        onChangeText={setUsername}
      />

      <TextInput
        style={styles.input}
        placeholder="Contraseña"
        value={password}
        onChangeText={setPassword}
        secureTextEntry
      />

      <TouchableOpacity
        style={[styles.button, loading && styles.buttonDisabled]}
        onPress={handleLogin}
        disabled={loading}
      >
        <Text style={styles.buttonText}>
          {loading ? 'Cargando...' : 'Login'}
        </Text>
      </TouchableOpacity>

      <Text style={styles.hint}>📱 App en Expo Snack</Text>
    </View>
  );
}

const styles = StyleSheet.create({
  container: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    padding: 20,
    backgroundColor: '#f5f5f5',
  },
  title: {
    fontSize: 28,
    fontWeight: 'bold',
    marginBottom: 30,
    color: '#333',
  },
  input: {
    width: '100%',
    padding: 12,
    marginBottom: 15,
    borderWidth: 1,
    borderColor: '#ddd',
    borderRadius: 8,
    backgroundColor: '#fff',
  },
  button: {
    width: '100%',
    padding: 15,
    backgroundColor: '#007AFF',
    borderRadius: 8,
    alignItems: 'center',
    marginTop: 20,
  },
  buttonDisabled: {
    opacity: 0.5,
  },
  buttonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: 'bold',
  },
  text: {
    fontSize: 16,
    marginBottom: 10,
    color: '#333',
  },
  hint: {
    marginTop: 20,
    fontSize: 14,
    color: '#999',
  },
});
```

3. Escanea el QR en **Expo Go** desde tu iPhone

4. ¡Debería cargar en 3-5 segundos!

---

## Opción 2: Volver a compilación local (si quieres)

Si prefieres compilación local, pero más rápido:

```bash
cd mobile-app

# Limpiar cache totalmente
rm -rf .expo node_modules package-lock.json

# Reinstalar sin las dependencias pesadas
npm install --legacy-peer-deps --no-optional

# Usar metro bundler con menos optimizaciones
npx expo start --max-workers=2
```

---

## ¿Cuál elegir?

| Opción | Velocidad | Funciones | Mejor para |
|--------|-----------|-----------|-----------|
| **Snack Online** | ⚡⚡⚡ Muy rápido | Básicas | Testing rápido |
| **Local** | ⚠️ Lento | Todas | Producción |

**Mi recomendación:** Usa **Snack Online** ahora para probar, luego optimizamos local.
