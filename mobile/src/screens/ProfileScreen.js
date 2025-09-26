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

const ProfileScreen = () => {
  const { user, updateProfile } = useAuth();
  const [formData, setFormData] = useState({
    display_name: user?.display_name || '',
    user_email: user?.user_email || '',
    phone: user?.phone || '',
    location: user?.location || '',
    bio: user?.bio || '',
    linkedin_url: user?.linkedin_url || '',
    portfolio_url: user?.portfolio_url || '',
    skills: user?.skills || '',
    experience_years: user?.experience_years || '',
  });
  const [updating, setUpdating] = useState(false);

  const updateFormData = (field, value) => {
    setFormData(prev => ({
      ...prev,
      [field]: value,
    }));
  };

  const handleSave = async () => {
    try {
      setUpdating(true);
      const result = await updateProfile(formData);

      if (result.success) {
        Alert.alert('Success', 'Profile updated successfully!');
      } else {
        Alert.alert('Error', result.error);
      }
    } catch (error) {
      Alert.alert('Error', 'Failed to update profile');
    } finally {
      setUpdating(false);
    }
  };

  return (
    <KeyboardAvoidingView
      style={styles.container}
      behavior={Platform.OS === 'ios' ? 'padding' : 'height'}
    >
      <ScrollView style={styles.scrollContainer}>
        <View style={styles.header}>
          <Text style={styles.title}>My Profile</Text>
          <Text style={styles.subtitle}>Update your information</Text>
        </View>

        <View style={styles.formContainer}>
          <View style={styles.inputGroup}>
            <Text style={styles.label}>Full Name</Text>
            <TextInput
              style={styles.input}
              value={formData.display_name}
              onChangeText={(value) => updateFormData('display_name', value)}
              placeholder="Your full name"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Email</Text>
            <TextInput
              style={styles.input}
              value={formData.user_email}
              onChangeText={(value) => updateFormData('user_email', value)}
              placeholder="your.email@example.com"
              keyboardType="email-address"
              autoCapitalize="none"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Phone Number</Text>
            <TextInput
              style={styles.input}
              value={formData.phone}
              onChangeText={(value) => updateFormData('phone', value)}
              placeholder="Your phone number"
              keyboardType="phone-pad"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Location</Text>
            <TextInput
              style={styles.input}
              value={formData.location}
              onChangeText={(value) => updateFormData('location', value)}
              placeholder="City, State/Country"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Professional Bio</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              value={formData.bio}
              onChangeText={(value) => updateFormData('bio', value)}
              placeholder="Tell us about yourself, your experience, and career goals..."
              multiline
              numberOfLines={4}
              textAlignVertical="top"
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
            <Text style={styles.label}>Skills</Text>
            <TextInput
              style={[styles.input, styles.textArea]}
              value={formData.skills}
              onChangeText={(value) => updateFormData('skills', value)}
              placeholder="List your key skills (e.g., JavaScript, React, Python, Project Management)"
              multiline
              numberOfLines={3}
              textAlignVertical="top"
            />
          </View>

          <View style={styles.inputGroup}>
            <Text style={styles.label}>Years of Experience</Text>
            <TextInput
              style={styles.input}
              value={formData.experience_years}
              onChangeText={(value) => updateFormData('experience_years', value)}
              placeholder="e.g., 3 years"
              keyboardType="numeric"
            />
          </View>

          <TouchableOpacity
            style={[styles.saveButton, updating && styles.saveButtonDisabled]}
            onPress={handleSave}
            disabled={updating}
          >
            {updating ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <Text style={styles.saveButtonText}>Save Changes</Text>
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
    paddingTop: 40,
    borderBottomWidth: 1,
    borderBottomColor: '#e0e0e0',
  },
  title: {
    fontSize: 24,
    fontWeight: 'bold',
    color: '#333',
    marginBottom: 4,
  },
  subtitle: {
    fontSize: 16,
    color: '#666',
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
    height: 80,
    textAlignVertical: 'top',
  },
  saveButton: {
    backgroundColor: '#007bff',
    borderRadius: 8,
    padding: 16,
    alignItems: 'center',
    marginTop: 20,
    marginBottom: 40,
  },
  saveButtonDisabled: {
    opacity: 0.6,
  },
  saveButtonText: {
    color: '#fff',
    fontSize: 18,
    fontWeight: '600',
  },
});

export default ProfileScreen;