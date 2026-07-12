/// Backend base URL. Override at build time, e.g.
///   flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1
///
/// Defaults suit the iOS simulator and web (localhost). The Android emulator
/// reaches the host at 10.0.2.2, and a physical device needs the machine's LAN
/// IP — set API_BASE_URL accordingly.
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://localhost:8000/api/v1',
);
