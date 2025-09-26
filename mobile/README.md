# puntWork Mobile App

A React Native mobile application companion for the puntWork job board platform.

## Features

- **User Authentication**: Secure login with JWT tokens
- **Job Listings**: Browse available job opportunities
- **Job Details**: View comprehensive job information
- **Job Applications**: Apply for jobs with detailed application forms
- **Profile Management**: Update personal and professional information
- **Dashboard**: Track applications, saved jobs, and recommendations
- **Offline Support**: Local data caching with AsyncStorage

## Prerequisites

- Node.js (v14 or later)
- npm or yarn
- React Native development environment
- iOS Simulator (for iOS development) or Android Studio (for Android development)

## Installation

1. **Install dependencies:**
   ```bash
   cd mobile
   npm install
   ```

2. **iOS Setup (macOS only):**
   ```bash
   cd ios
   pod install
   cd ..
   ```

3. **Android Setup:**
   - Ensure Android Studio is installed
   - Set up Android SDK and emulator

## Configuration

Update the API base URL in `src/context/AuthContext.js`:

```javascript
axios.defaults.baseURL = 'https://your-wordpress-site.com/wp-json/puntwork-mobile/v1';
```

Replace `your-wordpress-site.com` with your actual WordPress site URL.

## Running the App

### iOS
```bash
npm run ios
# or
npx react-native run-ios
```

### Android
```bash
npm run android
# or
npx react-native run-android
```

## Project Structure

```
mobile/
├── src/
│   ├── context/
│   │   └── AuthContext.js          # Authentication state management
│   └── screens/
│       ├── LoginScreen.js          # User login interface
│       ├── JobListScreen.js        # Job listings with pagination
│       ├── JobDetailScreen.js      # Detailed job view with apply option
│       ├── ApplicationFormScreen.js # Job application form
│       ├── ProfileScreen.js        # User profile management
│       └── DashboardScreen.js      # User dashboard with stats
├── App.js                          # Main app component with navigation
├── package.json                    # Dependencies and scripts
└── README.md                       # This file
```

## API Integration

The app integrates with the puntWork WordPress plugin's mobile API endpoints:

- `POST /auth/login` - User authentication
- `GET /jobs` - Fetch job listings
- `GET /jobs/{id}` - Get job details
- `POST /applications` - Submit job applications
- `GET /dashboard` - Get user dashboard data
- `POST /profile` - Update user profile

## Authentication Flow

1. User enters credentials on LoginScreen
2. App sends login request to API
3. On success, JWT token is stored in AsyncStorage
4. AuthContext manages authentication state
5. Navigation switches to authenticated screens

## Data Persistence

- **Authentication**: JWT tokens and user data stored in AsyncStorage
- **Offline Support**: Job data cached locally for offline viewing
- **Form Data**: Draft applications saved locally

## Navigation

Uses React Navigation v6 with stack navigator:

- **Unauthenticated**: Login screen only
- **Authenticated**: Dashboard, Job List, Job Details, Application Form, Profile

## Styling

- Consistent design system with blue (#007bff) primary color
- Responsive layouts for different screen sizes
- Modern card-based UI components
- Platform-specific styling where appropriate

## Development

### Adding New Screens

1. Create screen component in `src/screens/`
2. Add screen to navigation stack in `App.js`
3. Update navigation logic if needed

### API Calls

Use axios for API communication. Authentication headers are automatically added by AuthContext.

### State Management

Authentication state is managed globally via AuthContext. Screen-specific state uses local useState hooks.

## Testing

```bash
npm test
```

## Building for Production

### iOS
```bash
npm run build:ios
```

### Android
```bash
npm run build:android
```

## Troubleshooting

### Common Issues

1. **Metro bundler issues**: Clear cache with `npx react-native start --reset-cache`
2. **iOS build fails**: Run `cd ios && pod install && cd ..`
3. **Android build fails**: Ensure Android SDK is properly configured
4. **API connection issues**: Verify WordPress site URL and API endpoints

### Debug Mode

Enable debug mode in development for additional logging and error information.

## Contributing

1. Follow React Native and JavaScript best practices
2. Test on both iOS and Android platforms
3. Update documentation for new features
4. Ensure proper error handling and user feedback

## License

This project is part of the puntWork platform. See main project license for details.