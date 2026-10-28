import 'package:flutter/material.dart';

import '../auth/auth_controller.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key, required this.auth});

  final AuthController auth;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Domain Manager'),
        actions: [
          IconButton(
            tooltip: 'Log out',
            icon: const Icon(Icons.logout),
            onPressed: auth.logout,
          ),
        ],
      ),
      body: Center(
        child: Text('Signed in as ${auth.user?.email ?? ''}'),
      ),
    );
  }
}
