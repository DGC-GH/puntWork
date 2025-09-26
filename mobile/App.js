import React from 'react';
import { NavigationContainer } from '@react-navigation/native';
import { createStackNavigator } from '@react-navigation/stack';
import { StatusBar } from 'react-native';

// Screens
import LoginScreen from './src/screens/LoginScreen';
import JobListScreen from './src/screens/JobListScreen';
import JobDetailScreen from './src/screens/JobDetailScreen';
import ApplicationFormScreen from './src/screens/ApplicationFormScreen';
import ProfileScreen from './src/screens/ProfileScreen';
import DashboardScreen from './src/screens/DashboardScreen';

// Context
import { AuthProvider, useAuth } from './src/context/AuthContext';

const Stack = createStackNavigator();

const AppNavigator = () => {
  const { isAuthenticated, loading } = useAuth();

  if (loading) {
    return null; // Or a loading screen
  }

  return (
    <NavigationContainer>
      <StatusBar barStyle="light-content" backgroundColor="#007cba" />
      <Stack.Navigator
        screenOptions={{
          headerStyle: {
            backgroundColor: '#007cba',
          },
          headerTintColor: '#fff',
          headerTitleStyle: {
            fontWeight: 'bold',
          },
        }}
      >
        {isAuthenticated ? (
          // Authenticated screens
          <>
            <Stack.Screen
              name="Dashboard"
              component={DashboardScreen}
              options={{ title: 'Dashboard' }}
            />
            <Stack.Screen
              name="JobList"
              component={JobListScreen}
              options={{ title: 'Job Listings' }}
            />
            <Stack.Screen
              name="JobDetail"
              component={JobDetailScreen}
              options={{ title: 'Job Details' }}
            />
            <Stack.Screen
              name="ApplicationForm"
              component={ApplicationFormScreen}
              options={{ title: 'Apply for Job' }}
            />
            <Stack.Screen
              name="Profile"
              component={ProfileScreen}
              options={{ title: 'Profile' }}
            />
          </>
        ) : (
          // Unauthenticated screens
          <Stack.Screen
            name="Login"
            component={LoginScreen}
            options={{ headerShown: false }}
          />
        )}
      </Stack.Navigator>
    </NavigationContainer>
  );
};

const App = () => {
  return (
    <AuthProvider>
      <AppNavigator />
    </AuthProvider>
  );
};

export default App;