import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  StyleSheet,
  ActivityIndicator,
  RefreshControl,
  Alert,
} from 'react-native';
import axios from 'axios';
import { useAuth } from '../context/AuthContext';

const DashboardScreen = ({ navigation }) => {
  const { user, logout } = useAuth();
  const [dashboardData, setDashboardData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);

  useEffect(() => {
    fetchDashboardData();
  }, []);

  const fetchDashboardData = async (isRefresh = false) => {
    try {
      if (isRefresh) {
        setRefreshing(true);
      } else {
        setLoading(true);
      }

      const response = await axios.get('/dashboard');
      setDashboardData(response.data);
    } catch (error) {
      Alert.alert('Error', 'Failed to load dashboard data');
      console.error('Error fetching dashboard:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const handleRefresh = () => {
    fetchDashboardData(true);
  };

  const handleLogout = () => {
    Alert.alert(
      'Logout',
      'Are you sure you want to logout?',
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Logout', onPress: logout },
      ]
    );
  };

  const navigateToApplications = () => {
    navigation.navigate('Applications');
  };

  const navigateToSavedJobs = () => {
    navigation.navigate('SavedJobs');
  };

  const navigateToProfile = () => {
    navigation.navigate('Profile');
  };

  if (loading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#007bff" />
        <Text style={styles.loadingText}>Loading dashboard...</Text>
      </View>
    );
  }

  return (
    <ScrollView
      style={styles.container}
      refreshControl={
        <RefreshControl
          refreshing={refreshing}
          onRefresh={handleRefresh}
          colors={['#007bff']}
        />
      }
    >
      <View style={styles.header}>
        <Text style={styles.welcomeText}>Welcome back,</Text>
        <Text style={styles.userName}>{user?.display_name || 'User'}</Text>
      </View>

      <View style={styles.statsContainer}>
        <View style={styles.statCard}>
          <Text style={styles.statNumber}>{dashboardData?.stats?.applications_sent || 0}</Text>
          <Text style={styles.statLabel}>Applications Sent</Text>
        </View>

        <View style={styles.statCard}>
          <Text style={styles.statNumber}>{dashboardData?.stats?.jobs_saved || 0}</Text>
          <Text style={styles.statLabel}>Jobs Saved</Text>
        </View>

        <View style={styles.statCard}>
          <Text style={styles.statNumber}>{dashboardData?.stats?.interviews || 0}</Text>
          <Text style={styles.statLabel}>Interviews</Text>
        </View>
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Quick Actions</Text>

        <TouchableOpacity style={styles.actionButton} onPress={navigateToApplications}>
          <Text style={styles.actionButtonText}>View My Applications</Text>
          <Text style={styles.actionButtonSubtext}>
            Track your application status
          </Text>
        </TouchableOpacity>

        <TouchableOpacity style={styles.actionButton} onPress={navigateToSavedJobs}>
          <Text style={styles.actionButtonText}>Saved Jobs</Text>
          <Text style={styles.actionButtonSubtext}>
            Review your saved positions
          </Text>
        </TouchableOpacity>

        <TouchableOpacity style={styles.actionButton} onPress={navigateToProfile}>
          <Text style={styles.actionButtonText}>Update Profile</Text>
          <Text style={styles.actionButtonSubtext}>
            Keep your information current
          </Text>
        </TouchableOpacity>
      </View>

      {dashboardData?.recent_applications && dashboardData.recent_applications.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Recent Applications</Text>
          {dashboardData.recent_applications.map((application) => (
            <View key={application.id} style={styles.applicationCard}>
              <View style={styles.applicationHeader}>
                <Text style={styles.jobTitle}>{application.job_title}</Text>
                <Text style={styles.companyName}>{application.company}</Text>
              </View>
              <View style={styles.applicationDetails}>
                <Text style={styles.applicationDate}>
                  Applied: {new Date(application.applied_date).toLocaleDateString()}
                </Text>
                <Text style={styles.applicationStatus}>
                  Status: {application.status}
                </Text>
              </View>
            </View>
          ))}
        </View>
      )}

      {dashboardData?.recommended_jobs && dashboardData.recommended_jobs.length > 0 && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Recommended for You</Text>
          {dashboardData.recommended_jobs.slice(0, 3).map((job) => (
            <TouchableOpacity
              key={job.id}
              style={styles.jobCard}
              onPress={() => navigation.navigate('JobDetail', { job })}
            >
              <Text style={styles.jobTitle}>{job.title}</Text>
              <Text style={styles.companyName}>{job.company}</Text>
              <Text style={styles.jobLocation}>{job.location}</Text>
            </TouchableOpacity>
          ))}
        </View>
      )}

      <View style={styles.section}>
        <TouchableOpacity style={styles.logoutButton} onPress={handleLogout}>
          <Text style={styles.logoutButtonText}>Logout</Text>
        </TouchableOpacity>
      </View>
    </ScrollView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f5',
  },
  centerContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    backgroundColor: '#f5f5f5',
  },
  loadingText: {
    marginTop: 10,
    fontSize: 16,
    color: '#666',
  },
  header: {
    backgroundColor: '#fff',
    padding: 20,
    paddingTop: 40,
    borderBottomWidth: 1,
    borderBottomColor: '#e0e0e0',
  },
  welcomeText: {
    fontSize: 16,
    color: '#666',
  },
  userName: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    marginTop: 4,
  },
  statsContainer: {
    flexDirection: 'row',
    padding: 20,
    backgroundColor: '#fff',
    marginTop: 12,
  },
  statCard: {
    flex: 1,
    alignItems: 'center',
    padding: 16,
    backgroundColor: '#f8f9fa',
    borderRadius: 8,
    marginHorizontal: 4,
  },
  statNumber: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#007bff',
    marginBottom: 4,
  },
  statLabel: {
    fontSize: 12,
    color: '#666',
    textAlign: 'center',
  },
  section: {
    backgroundColor: '#fff',
    marginTop: 12,
    padding: 20,
  },
  sectionTitle: {
    fontSize: 20,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 16,
  },
  actionButton: {
    backgroundColor: '#f8f9fa',
    borderRadius: 8,
    padding: 16,
    marginBottom: 12,
    borderLeftWidth: 4,
    borderLeftColor: '#007bff',
  },
  actionButtonText: {
    fontSize: 16,
    fontWeight: '600',
    color: '#333',
    marginBottom: 4,
  },
  actionButtonSubtext: {
    fontSize: 14,
    color: '#666',
  },
  applicationCard: {
    backgroundColor: '#f8f9fa',
    borderRadius: 8,
    padding: 16,
    marginBottom: 12,
  },
  applicationHeader: {
    marginBottom: 8,
  },
  jobTitle: {
    fontSize: 16,
    fontWeight: '600',
    color: '#333',
    marginBottom: 4,
  },
  companyName: {
    fontSize: 14,
    color: '#007bff',
  },
  applicationDetails: {
    flexDirection: 'row',
    justifyContent: 'space-between',
  },
  applicationDate: {
    fontSize: 12,
    color: '#666',
  },
  applicationStatus: {
    fontSize: 12,
    color: '#28a745',
    fontWeight: '600',
  },
  jobCard: {
    backgroundColor: '#f8f9fa',
    borderRadius: 8,
    padding: 16,
    marginBottom: 12,
  },
  jobLocation: {
    fontSize: 12,
    color: '#666',
    marginTop: 4,
  },
  logoutButton: {
    backgroundColor: '#dc3545',
    borderRadius: 8,
    padding: 16,
    alignItems: 'center',
  },
  logoutButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
});

export default DashboardScreen;