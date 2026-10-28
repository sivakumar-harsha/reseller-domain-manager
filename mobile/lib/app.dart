import 'package:flutter/material.dart';

import 'auth/auth_controller.dart';
import 'screens/home_screen.dart';
import 'screens/login_screen.dart';

class App extends StatelessWidget {
  const App({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'Domain Manager',
      theme: ThemeData(colorSchemeSeed: Colors.indigo, useMaterial3: true),
      home: ListenableBuilder(
        listenable: auth,
        builder: (context, _) {
          switch (auth.status) {
            case AuthStatus.unknown:
              return const Scaffold(
                  body: Center(child: CircularProgressIndicator()));
            case AuthStatus.signedOut:
              return LoginScreen(auth: auth);
            case AuthStatus.signedIn:
              return HomeScreen(auth: auth);
          }
        },
      ),
    );
  }
}
