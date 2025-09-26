import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  FlatList,
  TouchableOpacity,
  StyleSheet,
  ActivityIndicator,
  RefreshControl,
  Alert,
} from 'react-native';
import axios from 'axios';
import { useAuth } from '../context/AuthContext';

const JobListScreen = ({ navigation }) => {
  const [jobs, setJobs] = useState([]);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(true);
  const { user } = useAuth();

  useEffect(() => {
    fetchJobs();
  }, []);

  const fetchJobs = async (pageNum = 1, isRefresh = false) => {
    try {
      if (isRefresh) {
        setRefreshing(true);
      } else if (pageNum === 1) {
        setLoading(true);
      }

      const response = await axios.get('/jobs', {
        params: {
          page: pageNum,
          per_page: 20,
        },
      });

      const newJobs = response.data.jobs || [];

      if (pageNum === 1) {
        setJobs(newJobs);
      } else {
        setJobs(prevJobs => [...prevJobs, ...newJobs]);
      }

      setHasMore(newJobs.length === 20);
      setPage(pageNum);
    } catch (error) {
      Alert.alert('Error', 'Failed to load jobs');
      console.error('Error fetching jobs:', error);
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  };

  const handleRefresh = () => {
    fetchJobs(1, true);
  };

  const handleLoadMore = () => {
    if (hasMore && !loading && !refreshing) {
      fetchJobs(page + 1);
    }
  };

  const handleJobPress = (job) => {
    navigation.navigate('JobDetail', { job });
  };

  const renderJobItem = ({ item }) => (
    <TouchableOpacity style={styles.jobCard} onPress={() => handleJobPress(item)}>
      <View style={styles.jobHeader}>
        <Text style={styles.jobTitle} numberOfLines={2}>
          {item.title}
        </Text>
        <Text style={styles.companyName}>{item.company}</Text>
      </View>

      <View style={styles.jobDetails}>
        <Text style={styles.location}>{item.location}</Text>
        <Text style={styles.salary}>
          {item.salary ? `$${item.salary}` : 'Salary not specified'}
        </Text>
      </View>

      <Text style={styles.jobDescription} numberOfLines={3}>
        {item.description}
      </Text>

      <View style={styles.jobFooter}>
        <Text style={styles.postedDate}>
          Posted {new Date(item.posted_date).toLocaleDateString()}
        </Text>
        <Text style={styles.jobType}>{item.job_type}</Text>
      </View>
    </TouchableOpacity>
  );

  const renderFooter = () => {
    if (!loading || refreshing) return null;

    return (
      <View style={styles.footerLoader}>
        <ActivityIndicator size="small" color="#007bff" />
        <Text style={styles.footerText}>Loading more jobs...</Text>
      </View>
    );
  };

  if (loading && page === 1) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#007bff" />
        <Text style={styles.loadingText}>Loading jobs...</Text>
      </View>
    );
  }

  return (
    <View style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.headerTitle}>Available Jobs</Text>
        <Text style={styles.welcomeText}>Welcome back, {user?.display_name || 'User'}!</Text>
      </View>

      <FlatList
        data={jobs}
        renderItem={renderJobItem}
        keyExtractor={(item) => item.id.toString()}
        contentContainerStyle={styles.listContainer}
        refreshControl={
          <RefreshControl
            refreshing={refreshing}
            onRefresh={handleRefresh}
            colors={['#007bff']}
          />
        }
        onEndReached={handleLoadMore}
        onEndReachedThreshold={0.1}
        ListFooterComponent={renderFooter}
        ListEmptyComponent={
          <View style={styles.emptyContainer}>
            <Text style={styles.emptyText}>No jobs available at the moment</Text>
            <TouchableOpacity style={styles.retryButton} onPress={handleRefresh}>
              <Text style={styles.retryButtonText}>Retry</Text>
            </TouchableOpacity>
          </View>
        }
      />
    </View>
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
  headerTitle: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 4,
  },
  welcomeText: {
    fontSize: 16,
    color: '#666',
  },
  listContainer: {
    padding: 16,
  },
  jobCard: {
    backgroundColor: '#fff',
    borderRadius: 12,
    padding: 16,
    marginBottom: 12,
    shadowColor: '#000',
    shadowOffset: {
      width: 0,
      height: 2,
    },
    shadowOpacity: 0.1,
    shadowRadius: 4,
    elevation: 3,
  },
  jobHeader: {
    marginBottom: 8,
  },
  jobTitle: {
    fontSize: 18,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 4,
  },
  companyName: {
    fontSize: 16,
    color: '#007bff',
    fontWeight: '600',
  },
  jobDetails: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    marginBottom: 8,
  },
  location: {
    fontSize: 14,
    color: '#666',
  },
  salary: {
    fontSize: 14,
    color: '#28a745',
    fontWeight: '600',
  },
  jobDescription: {
    fontSize: 14,
    color: '#666',
    lineHeight: 20,
    marginBottom: 12,
  },
  jobFooter: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
  },
  postedDate: {
    fontSize: 12,
    color: '#999',
  },
  jobType: {
    fontSize: 12,
    color: '#007bff',
    backgroundColor: '#e7f3ff',
    paddingHorizontal: 8,
    paddingVertical: 4,
    borderRadius: 12,
  },
  footerLoader: {
    padding: 20,
    alignItems: 'center',
  },
  footerText: {
    marginTop: 8,
    fontSize: 14,
    color: '#666',
  },
  emptyContainer: {
    flex: 1,
    justifyContent: 'center',
    alignItems: 'center',
    paddingTop: 100,
  },
  emptyText: {
    fontSize: 16,
    color: '#666',
    marginBottom: 20,
    textAlign: 'center',
  },
  retryButton: {
    backgroundColor: '#007bff',
    paddingHorizontal: 20,
    paddingVertical: 10,
    borderRadius: 8,
  },
  retryButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
});

export default JobListScreen;