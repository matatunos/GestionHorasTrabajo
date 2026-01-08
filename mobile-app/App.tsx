import React, { useEffect, useState } from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createNativeStackNavigator } from '@react-navigation/native-stack';
import { createBottomTabNavigator } from '@react-navigation/bottom-tabs';
import { ActivityIndicator, View } from 'react-native';

import AuthContext from './src/context/AuthContext';
import LoginScreen from './src/screens/LoginScreen';
import DashboardScreen from './src/screens/DashboardScreen';
import HistoryScreen from './src/screens/HistoryScreen';
import ProfileScreen from './src/screens/ProfileScreen';
import { authService } from './src/services/authService';

const Stack = createNativeStackNavigator();
const Tab = createBottomTabNavigator();

function DashboardNavigator() {
  return (
    <Tab.Navigator
      screenOptions={{
        headerShown: true,
        tabBarActiveTintColor: '#007AFF',
        tabBarInactiveTintColor: '#999',
      }}
    >
      <Tab.Screen
        name="Dashboard"
        component={DashboardScreen}
        options={{
          title: 'Hoy',
          tabBarLabel: 'Hoy',
          tabBarIcon: ({ color }) => <View style={{ width: 24, height: 24, backgroundColor: color }} />,
        }}
      />
      <Tab.Screen
        name="History"
        component={HistoryScreen}
        options={{
          title: 'Historial',
          tabBarLabel: 'Historial',
          tabBarIcon: ({ color }) => <View style={{ width: 24, height: 24, backgroundColor: color }} />,
        }}
      />
      <Tab.Screen
        name="Profile"
        component={ProfileScreen}
        options={{
          title: 'Perfil',
          tabBarLabel: 'Perfil',
          tabBarIcon: ({ color }) => <View style={{ width: 24, height: 24, backgroundColor: color }} />,
        }}
      />
    </Tab.Navigator>
  );
}

export default function App() {
  const [state, dispatch] = React.useReducer(
    (prevState, action) => {
      switch (action.type) {
        case 'RESTORE_TOKEN':
          return {
            ...prevState,
            userToken: action.token,
            isLoading: false,
          };
        case 'SIGN_IN':
          return {
            ...prevState,
            isSignout: false,
            userToken: action.token,
          };
        case 'SIGN_OUT':
          return {
            ...prevState,
            isSignout: true,
            userToken: null,
          };
      }
    },
    {
      isLoading: true,
      isSignout: false,
      userToken: null,
    }
  );

  useEffect(() => {
    const bootstrapAsync = async () => {
      let userToken;
      try {
        // Restaurar token guardado
        userToken = await authService.getToken();
      } catch (e) {
        // Error al restaurar token
        console.log(e);
      }

      dispatch({ type: 'RESTORE_TOKEN', token: userToken });
    };

    bootstrapAsync();
  }, []);

  const authContext = React.useMemo(
    () => ({
      signIn: async (username: string, password: string) => {
        try {
          const token = await authService.login(username, password);
          await authService.saveToken(token);
          dispatch({ type: 'SIGN_IN', token });
          return { ok: true };
        } catch (error: any) {
          return { ok: false, error: error.message };
        }
      },
      signOut: async () => {
        await authService.removeToken();
        dispatch({ type: 'SIGN_OUT' });
      },
      signUp: async (username: string, password: string, email: string) => {
        // TODO: Implementar signup
        return { ok: false, error: 'No implementado' };
      },
    }),
    []
  );

  if (state.isLoading) {
    return (
      <View style={{ flex: 1, justifyContent: 'center', alignItems: 'center' }}>
        <ActivityIndicator size="large" />
      </View>
    );
  }

  return (
    <AuthContext.Provider value={authContext}>
      <NavigationContainer>
        <Stack.Navigator screenOptions={{ headerShown: false }}>
          {state.userToken == null ? (
            <Stack.Screen
              name="SignIn"
              component={LoginScreen}
            />
          ) : (
            <Stack.Screen
              name="Root"
              component={DashboardNavigator}
            />
          )}
        </Stack.Navigator>
      </NavigationContainer>
    </AuthContext.Provider>
  );
}
