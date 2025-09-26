import React, { useState } from 'react';
import {
  View,
  Text,
  TextInput,
  TouchableOpacity,
  StyleSheet,
  ScrollView,
  Alert,
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
} from 'react-native';
import { useAuth } from '../context/AuthContext';
import axios from 'axios';

const ApplicationFormScreen = ({ route, navigation }) => {
  const { job } = route.params;
  const { user } = useAuth();
  const [formData, setFormData] = useState({
    cover_letter: '',
    expected_salary: '',
    available_date: '',
    phone: user?.phone || '',
    linkedin_url: '',
    portfolio_url: '',
    additional_info: '',
  });
  const [submitting, setSubmitting] = useState(false);

  const updateFormData = (field, value) => {
    setFormData(prev => ({
      ...prev,
      [field]: value,
    }));
  };

  const validateForm = () => {
    if (!formData.cover_letter.trim()) {
      Alert.alert('Validation Error', 'Please provide a cover letter');
      return false;
    }
    if (!formData.phone.trim()) {
      Alert.alert('Validation Error', 'Please provide your phone number');
      return false;
    }
    return true;
  };

  const handleSubmit = async () => {
    if (!validateForm()) return;

    Alert.alert(
      'Submit Application',
      `Are you sure you want to apply for "${job.title}" at ${job.company}?`,
      [
        { text: 'Cancel', style: 'cancel' },
        { text: 'Submit', onPress: submitApplication },
      ]
    );
  };

  const submitApplication = async () => {
    try {
      setSubmitting(true);

      const applicationData = {
        job_id: job.id,
        applicant_name: user.display_name,
        applicant_email: user.user_email,
        phone: formData.phone,
        cover_letter: formData.cover_letter,
        expected_salary: formData.expected_salary,
        available_date: formData.available_date,
        linkedin_url: formData.linkedin_url,
        portfolio_url: formData.portfolio_url,
        additional_info: formData.additional_info,
      };

      const response = await axios.post('/applications', applicationData);

      Alert.alert(
        'Application Submitted!',
        'Your application has been submitted successfully. You will be notified of any updates.',
        [
          {
            text: 'OK',
            onPress: () => navigation.navigate('JobList'),
          },
        ]
      );
    } catch (error) {
      Alert.alert(
        'Submission Failed',
        error.response?.data?.message || 'Failed to submit application. Please try again.'
      );
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView style={styles.scrollContainer}>
        <View style={styles.header}>
          <Text style={styles.title}>Apply for Job</Text>
          <Text style={styles.jobTitle}>{job.title}</Text>
          <Text style={styles.companyName}>{job.company}</Text>
        </View>

        <View style={styles.formContainer}>
          <View style={styles.inputGroup}>
            <Text style={styles.label}>Cover Letter *</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              value={formData.cover_letter}
              onChangeText={(value) => updateFormData('cover_letter', value)}
              placeholder="Tell us why you're interested in this position and what makes you a great fit..."
              multiline
              numberOfLines={6}
              textAlignVertical="top"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Phone Number *</Text>
            <TextInput
              style={styles.input}
              value={formData.phone}
              onChangeText={(value) => updateFormData('phone', value)}
              placeholder="Your phone number"
              keyboardType="phone-pad"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Expected Salary</Text>
            <TextInput
              style={styles.input}
              value={formData.expected_salary}
              onChangeText={(value) => updateFormData('expected_salary', value)}
              placeholder="e.g., $50,000 - $60,000 per year"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Available Start Date</Text>
            <TextInput
              style={styles.input}
              value={formData.available_date}
              onChangeText={(value) => updateFormData('available_date', value)}
              placeholder="e.g., Immediately, 2 weeks, 1 month"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>LinkedIn Profile URL</Text>
            <TextInput
              style={styles.input}
              value={formData.linkedin_url}
              onChangeText={(value) => updateFormData('linkedin_url', value)}
              placeholder="https://linkedin.com/in/yourprofile"
              autoCapitalize="none"
              keyboardType="url"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Portfolio/Website URL</Text>
            <TextInput
              style={styles.input}
              value={formData.portfolio_url}
              onChangeText={(value) => updateFormData('portfolio_url', value)}
              placeholder="https://yourportfolio.com"
              autoCapitalize="none"
              keyboardType="url"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Additional Information</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              value={formData.additional_info}
              onChangeText={(value) => updateFormData('additional_info', value)}
              placeholder="Any additional information you'd like to share..."
              multiline
              numberOfLines={4}
              textAlignVertical="top"
            />
          </View>

          <TouchableOpacity
            style={[styles.submitButton, submitting && styles.submitButtonDisabled]}
            onPress={handleSubmit}
            disabled={submitting}
          >
            {submitting ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.submitButtonText}>Submit Application</Text>
            )}
          </TouchableOpacity>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  );
};

const styles = StyleSheet.create({
  container: {
    flex: 1,
    backgroundColor: '#f5f5f5',
  },
  scrollContainer: {
    flex: 1,
  },
  header: {
    backgroundColor: '#fff',
    padding: 20,
    borderBottomWidth: 1,
    borderBottomColor: '#e0e0e0',
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 8,
  },
  jobTitle: {
    fontSize: 18,
    fontWeight: '600',
    color: '#333',
    marginBottom: 4,
  },
  companyName: {
    fontSize: 16,
    color: '#007bff',
  },
  formContainer: {
    padding: 20,
  },
  inputGroup: {
    marginBottom: 20,
  },
  label: {
    fontSize: 16,
    fontWeight: '600',
    color: '#333',
    marginBottom: 8,
  },
  input: {
    borderWidth: 1,
    borderColor: '#ddd',
    borderRadius: 8,
    padding: 12,
    fontSize: 16,
    backgroundColor: '#f9f9f9',
  },
  textArea: {
    height: 100,
    textAlignVertical: 'top',
  },
  submitButton: {
    backgroundColor: '#28a745',
    borderRadius: 8,
    padding: 16,
    alignItems: 'center',
    marginTop: 20,
    marginBottom: 40,
  },
  submitButtonDisabled: {
    opacity: 0.6,
  },
  submitButtonText: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '600',
  },
});

export default ApplicationFormScreen;