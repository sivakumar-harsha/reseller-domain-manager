/// API base URL, overridable at build/run time:
///   flutter run --dart-define=API_BASE_URL=http://192.168.1.50:8080/api/v1/
/// The default targets the Android emulator, where 10.0.2.2 is the host PC.
const String apiBaseUrl = String.fromEnvironment(
  'API_BASE_URL',
  defaultValue: 'http://10.0.2.2:8080/api/v1/',
);
