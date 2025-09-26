import React, { useState, useEffect } from 'react';
import {
  View,
  Text,
  ScrollView,
  TouchableOpacity,
  StyleSheet,
  ActivityIndicator,
  Alert,
  Linking,
} from 'react-native';
import axios from 'axios';
import { useAuth } from '../context/AuthContext';

const JobDetailScreen = ({ route, navigation }) => {
  const { job: initialJob } = route.params;
  const [job, setJob] = useState(initialJob);
  const [loading, setLoading] = useState(false);
  const [applying, setApplying] = useState(false);
  const { user } = useAuth();

  useEffect(() => {
    fetchJobDetails();
  }, []);

  const fetchJobDetails = async () => {
    try {
      setLoading(true);
      const response = await axios.get(`/jobs/${initialJob.id}`);
      setJob(response.data.job);
    } catch (error) {
      console.error('Error fetching job details:', error);
      // Keep initial job data if detailed fetch fails
    } finally {
      setLoading(false);
    }
  };

  const handleApply = async () => {
    Alert.alert(
      'Apply for Job',
      `Are you sure you want to apply for "${job.title}" at ${job.company}?`,
      [
        { text: 'Cancel', style: 'cancel' },
        {
          text: 'Apply',
          onPress: submitApplication,
        },
      ]
    );
  };

  const submitApplication = async () => {
    try {
      setApplying(true);
      const response = await axios.post('/applications', {
        job_id: job.id,
        applicant_name: user.display_name,
        applicant_email: user.user_email,
        cover_letter: '', // Could be added later with a form
      });

      Alert.alert(
        'Application Submitted',
        'Your application has been submitted successfully!',
        [
          {
            text: 'OK',
            onPress: () => navigation.goBack(),
          },
        ]
      );
    } catch (error) {
      Alert.alert(
        'Application Failed',
        error.response?.data?.message || 'Failed to submit application. Please try again.'
      );
    } finally {
      setApplying(false);
    }
  };

  const handleShare = () => {
    const shareUrl = `https://your-wordpress-site.com/job/${job.id}`;
    const message = `Check out this job: ${job.title} at ${job.company}`;

    // Using Linking to open share options
    Linking.openURL(`mailto:?subject=${encodeURIComponent(message)}&body=${encodeURIComponent(shareUrl)}`)
      .catch(() => {
        Alert.alert('Share', 'Sharing options not available');
      });
  };

  const formatSalary = (salary) => {
    if (!salary) return 'Salary not specified';
    return salary.includes('-')
      ? `$${salary}`
      : `$${salary} per year`;
  };

  if (loading) {
    return (
      <View style={styles.centerContainer}>
        <ActivityIndicator size="large" color="#007bff" />
        <Text style={styles.loadingText}>Loading job details...</Text>
      </View>
    );
  }

  return (
    <ScrollView style={styles.container}>
      <View style={styles.header}>
        <Text style={styles.jobTitle}>{job.title}</Text>
        <Text style={styles.companyName}>{job.company}</Text>
      </View>

      <View style={styles.detailsContainer}>
        <View style={styles.detailRow}>
          <Text style={styles.detailLabel}>Location:</Text>
          <Text style={styles.detailValue}>{job.location}</Text>
        </View>

        <View style={styles.detailRow}>
          <Text style={styles.detailLabel}>Salary:</Text>
          <Text style={styles.detailValue}>{formatSalary(job.salary)}</Text>
        </View>

        <View style={styles.detailRow}>
          <Text style={styles.detailLabel}>Job Type:</Text>
          <Text style={styles.detailValue}>{job.job_type}</Text>
        </View>

        <View style={styles.detailRow}>
          <Text style={styles.detailLabel}>Posted:</Text>
          <Text style={styles.detailValue}>
            {new Date(job.posted_date).toLocaleDateString()}
          </Text>
        </View>

        {job.application_deadline && (
          <View style={styles.detailRow}>
            <Text style={styles.detailLabel}>Application Deadline:</Text>
            <Text style={styles.detailValue}>
              {new Date(job.application_deadline).toLocaleDateString()}
            </Text>
          </View>
        )}
      </View>

      <View style={styles.section}>
        <Text style={styles.sectionTitle}>Job Description</Text>
        <Text style={styles.description}>{job.description}</Text>
      </View>

      {job.requirements && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Requirements</Text>
          <Text style={styles.description}>{job.requirements}</Text>
        </View>
      )}

      {job.benefits && (
        <View style={styles.section}>
          <Text style={styles.sectionTitle}>Benefits</Text>
          <Text style={styles.description}>{job.benefits}</Text>
        </View>
      )}

      <View style={styles.actionsContainer}>
        <TouchableOpacity
          style={[styles.applyButton, applying && styles.buttonDisabled]}
          onPress={handleApply}
          disabled={applying}
        >
          {applying ? (
            <ActivityIndicator color="#fff" />
          ) : (
            <Text style={styles.applyButtonText}>Apply Now</Text>
          )}
        </TouchableOpacity>

        <TouchableOpacity style={styles.shareButton} onPress={handleShare}>
          <Text style={styles.shareButtonText}>Share Job</Text>
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
    borderBottomWidth: 1,
    borderBottomColor: '#e0e0e0',
  },
  jobTitle: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 8,
  },
  companyName: {
    fontSize: 18,
    color: '#007bff',
    fontWeight: '600',
  },
  detailsContainer: {
    backgroundColor: '#fff',
    marginTop: 12,
    padding: 20,
  },
  detailRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 8,
    borderBottomWidth: 1,
    borderBottomColor: '#f0f0f0',
  },
  detailLabel: {
    fontSize: 16,
    fontWeight: '600',
    color: '#333',
    flex: 1,
  },
  detailValue: {
    fontSize: 16,
    color: '#666',
    flex: 2,
    textAlign: 'right',
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
    marginBottom: 12,
  },
  description: {
    fontSize: 16,
    color: '#666',
    lineHeight: 24,
  },
  actionsContainer: {
    padding: 20,
    backgroundColor: '#fff',
    marginTop: 12,
    marginBottom: 20,
  },
  applyButton: {
    backgroundColor: '#28a745',
    borderRadius: 8,
    padding: 16,
    alignItems: 'center',
    marginBottom: 12,
  },
  buttonDisabled: {
    opacity: 0.6,
  },
  applyButtonText: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '600',
  },
  shareButton: {
    backgroundColor: '#6c757d',
    borderRadius: 8,
    padding: 16,
    alignItems: 'center',
  },
  shareButtonText: {
    color: '#fff',
    fontSize: 16,
    fontWeight: '600',
  },
});

export default JobDetailScreen;