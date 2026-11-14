import 'package:flutter/material.dart';

import 'auth/auth_controller.dart';
import 'domains/auto_refresh.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';
import 'theme/app_spacing.dart';
import 'theme/app_theme.dart';

class App extends StatelessWidget {
  const App({super.key, required this.auth, this.autoRefresh});

  final AuthController auth;

  /// Optional: automatic inventory refresh is off when null (e.g. in tests).
  final AutoRefreshController? autoRefresh;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Domain Manager',
      // Light-only by design: no darkTheme, no toggle, never follows the system.
      theme: AppTheme.light,
      themeMode: ThemeMode.light,
      home: ListenableBuilder(
        listenable: auth,
        builder: (context, _) {
          switch (auth.status) {
            case AuthStatus.unknown:
              return const _StartupScreen();
            case AuthStatus.signedOut:
              return LoginScreen(auth: auth);
            case AuthStatus.signedIn:
              return HomeScreen(auth: auth, autoRefresh: autoRefresh);
          }
        },
      ),
    );
  }
}

/// Shown while a stored session is being restored. Static: the brand and one
/// spinner, nothing else, so startup looks the same as before but on-theme.
class _StartupScreen extends StatelessWidget {
  const _StartupScreen();

  @override
  Widget build(BuildContext context) {
    return const Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: EdgeInsets.all(AppSpacing.lg),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                AuthBrand(),
                SizedBox(height: AppSpacing.lg),
                CircularProgressIndicator(),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
