# Domain Manager (mobile)

Flutter app for the ResellerClub Domain Manager backend. Currently: login, session restore, logout and a placeholder home screen. No ResellerClub integration.

- Tokens (access + refresh) are kept in `flutter_secure_storage` (Android Keystore). Nothing is hardcoded.
- On start the app reuses the stored session; if the access token expired it rotates the refresh token (single-use on the server) and stores the new pair.

## API base URL

Default: `http://10.0.2.2:8080/api/v1/` (Android emulator -> host PC). Override at run time:

```powershell
flutter run --dart-define=API_BASE_URL=http://192.168.1.50:8080/api/v1/
```

### Physical phone

1. Use the PC's LAN IP (`ipconfig`), phone and PC on the same Wi-Fi.
2. Serve the backend on all interfaces, e.g. `php spark serve --host 0.0.0.0 --port 8080` (or bind Apache to the LAN), and allow the port in Windows Firewall.
3. Pass the URL with `--dart-define` as above.

Debug builds allow cleartext HTTP (`android/app/src/debug/AndroidManifest.xml`); release builds require HTTPS.

## Run

```powershell
cd mobile
flutter pub get
flutter run            # backend must be running and the owner created (php spark auth:create-owner)
flutter analyze
flutter test
```

## Layout

`lib/core` config + API client, `lib/auth` token storage + auth state (`ChangeNotifier`), `lib/screens` login and home.
